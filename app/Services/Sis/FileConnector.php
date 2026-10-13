<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * The first connector: the SIS (or someone on its behalf) drops CSV exports
 * into a folder, and grades are written to another folder for it to pick up.
 *
 *   inbound/students.csv  instructors.csv  courses.csv  enrollments.csv
 *   outbound/grades-<batch key>.csv
 *
 * With a signing key set, every inbound file must come with
 * `<name>.csv.sig` holding the hex HMAC-SHA256 of its contents, and every
 * outbound file is written with one, so neither side acts on a file that was
 * altered or came from elsewhere.
 */
final class FileConnector implements SisConnector
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const GRADE_COLUMNS = ['course_external_id', 'student_external_id', 'percentage', 'remarks'];

    public function __construct(
        private readonly string $inbound,
        private readonly string $outbound,
        private readonly ?string $signingKey = null,
    ) {
    }

    public function testConnection(): ConnectionResult
    {
        if (! is_dir($this->inbound)) {
            return new ConnectionResult(false, "The inbound folder does not exist yet: {$this->inbound}");
        }

        $found    = [];
        $problems = [];

        foreach (SisCsvParser::datasets() as $dataset) {
            if (! is_file($this->path($dataset))) {
                continue;
            }

            try {
                $batch   = $this->read($dataset);
                $found[] = "{$dataset}.csv: " . count($batch->records) . ' usable, ' . count($batch->rejected) . ' rejected';
            } catch (SisException $e) {
                $problems[] = $e->getMessage();
            }
        }

        if ($problems !== []) {
            return new ConnectionResult(false, implode(' ', $problems));
        }

        if ($found === []) {
            return new ConnectionResult(false, 'The inbound folder is reachable but holds none of: ' . implode(', ', array_map(static fn (string $d): string => "{$d}.csv", SisCsvParser::datasets())) . '.');
        }

        return new ConnectionResult(true, implode('; ', $found) . '.');
    }

    public function has(string $dataset): bool
    {
        return in_array($dataset, SisCsvParser::datasets(), true) && is_file($this->path($dataset));
    }

    public function read(string $dataset): SisBatch
    {
        SisCsvParser::spec($dataset);

        $path = $this->path($dataset);

        if (! is_file($path)) {
            throw new SisException("{$dataset}.csv was not found in the inbound folder.");
        }

        if ((int) filesize($path) > self::MAX_BYTES) {
            throw new SisException("{$dataset}.csv is larger than " . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }

        $contents = (string) file_get_contents($path);

        if ($this->signingKey !== null) {
            $signature = is_file($path . '.sig') ? trim((string) file_get_contents($path . '.sig')) : '';

            if ($signature === '') {
                throw new SisException("{$dataset}.csv has no signature file, and a signing key is set.");
            }

            if (! hash_equals($this->sign($contents), strtolower($signature))) {
                throw new SisException("The signature of {$dataset}.csv does not match its contents.");
            }
        }

        return SisCsvParser::parse($dataset, $contents);
    }

    public function publishGrades(string $batchKey, array $rows): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $batchKey) !== 1) {
            throw new SisException('The batch key may only hold letters, numbers, dashes and underscores (64 at most).');
        }

        if (! is_dir($this->outbound) && ! @mkdir($this->outbound, 0770, true) && ! is_dir($this->outbound)) {
            throw new SisException('The outbound folder could not be created.');
        }

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, self::GRADE_COLUMNS, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (string $c) => $row[$c] ?? '', self::GRADE_COLUMNS), ',', '"', '');
        }

        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        $target = $this->outbound . DIRECTORY_SEPARATOR . "grades-{$batchKey}.csv";

        // Written beside the target and renamed, so a reader never sees half a file.
        $partial = $target . '.part';

        if (@file_put_contents($partial, $contents) === false || ! @rename($partial, $target)) {
            @unlink($partial);

            throw new SisException('The grades could not be written to the outbound folder.');
        }

        if ($this->signingKey !== null && @file_put_contents($target . '.sig', $this->sign($contents)) === false) {
            throw new SisException('The grades were written but their signature could not be.');
        }
    }

    private function path(string $dataset): string
    {
        return $this->inbound . DIRECTORY_SEPARATOR . $dataset . '.csv';
    }

    private function sign(string $contents): string
    {
        return hash_hmac('sha256', $contents, (string) $this->signingKey);
    }
}
