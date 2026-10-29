<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Sis\FileConnector;
use App\Services\Sis\SisConnectionService;
use App\Services\Sis\SisCsvParser;
use App\Services\Sis\SisException;
use App\Services\Sis\SisImporter;
use App\Services\Sis\SisImportResult;
use App\Services\Sis\UploadConnections;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use InvalidArgumentException;

/**
 * Bulk entry from CSV files an institutional administrator uploads: students,
 * instructors, courses and enrolments, in the same columns as the SIS files.
 *
 * It is the SIS import with a different source (UploadConnections stands in
 * for the connection, so SisImporter runs unchanged), which gives it the same
 * guarantees: a preview that writes nothing and reports exactly what applying
 * will do, one transaction, all or nothing, and a bad row refused with its
 * reason while the rest still go in.
 *
 * Rules:
 *  - Refused while a SIS connection is enabled: one source of truth at a time.
 *  - The files are checked as a whole first (columns, size); a file that
 *    cannot be used is refused beside its field and nothing is stored.
 *  - A preview is kept (files and result) under a random token, for an hour,
 *    in a folder of the institution, so applying needs no second upload and
 *    another institution's token is simply unknown (404).
 *  - Applying uses the stored files and removes them.
 */
class CsvUploadService
{
    /** How long a preview can still be applied. */
    public const KEEP_SECONDS = 3600;

    private string $base;

    public function __construct(?string $base = null, private readonly ?SisConnectionService $connections = null)
    {
        $this->base = rtrim($base ?? (string) env('upload.csvPath', WRITEPATH . 'csv-uploads'), '/\\');
    }

    /** @return list<string> */
    public static function datasets(): array
    {
        return SisCsvParser::datasets();
    }

    /** Whether uploads are off because the SIS is the source of truth. */
    public function blockedBySis(int $institutionId): bool
    {
        return ($this->connections ?? new SisConnectionService())->view($institutionId)['enabled'];
    }

    /** The header line of a template file. */
    public static function template(string $dataset): string
    {
        $spec = SisCsvParser::spec($dataset);

        return implode(',', array_merge($spec['required'], $spec['optional'])) . "\r\n";
    }

    /**
     * Checks the files, keeps them and previews what they would do.
     *
     * @param array<string, string> $files dataset => file contents (a dataset not uploaded is left out)
     *
     * @return array{token: string, result: SisImportResult}
     *
     * @throws StructureException refused: nothing stored, nothing written
     */
    public function preview(int $institutionId, array $files, ?User $actor = null): array
    {
        $this->mustBeAllowed($institutionId);
        $this->forget($institutionId);

        $files = array_filter($files, static fn (string $c): bool => trim($c) !== '');

        if ($files === []) {
            throw new StructureException('Choose at least one file to upload.');
        }

        foreach ($files as $dataset => $contents) {
            if (! in_array($dataset, self::datasets(), true)) {
                throw new StructureException('That is not a file this upload takes.');
            }

            if (strlen($contents) > FileConnector::MAX_BYTES) {
                throw StructureException::field($dataset, "{$dataset}.csv is larger than " . (FileConnector::MAX_BYTES / 1024 / 1024) . ' MB.');
            }

            try {
                SisCsvParser::parse($dataset, $contents);
            } catch (SisException $e) {
                throw StructureException::field($dataset, $e->getMessage());
            }
        }

        $token  = bin2hex(random_bytes(16));
        $folder = $this->folder($institutionId, $token);

        if (! @mkdir($folder, 0770, true) && ! is_dir($folder)) {
            throw new StructureException('The files could not be kept for the preview. Try again.');
        }

        foreach ($files as $dataset => $contents) {
            file_put_contents($folder . DIRECTORY_SEPARATOR . $dataset . '.csv', $contents);
        }

        $result = $this->run($institutionId, $folder, true, $actor);

        if (! $result->ok) {
            $this->remove($folder);

            throw new StructureException($result->message);
        }

        file_put_contents($folder . DIRECTORY_SEPARATOR . 'preview.json', json_encode([
            'at'       => time(),
            'datasets' => $result->datasets,
            'issues'   => $result->issues,
            'message'  => $result->message,
            'files'    => array_keys($files),
        ]));

        return ['token' => $token, 'result' => $result];
    }

    /**
     * What a kept preview found.
     *
     * @return array{datasets: array<string, array<string, int>>, issues: list<array<string, string>>, message: string, files: list<string>, totals: array{created: int, updated: int, skipped: int, failed: int, ended: int}}
     *
     * @throws PageNotFoundException when the token is not this institution's, or has expired
     */
    public function kept(int $institutionId, string $token): array
    {
        $file = $this->folderOf($institutionId, $token) . DIRECTORY_SEPARATOR . 'preview.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($data) || (int) ($data['at'] ?? 0) < time() - self::KEEP_SECONDS) {
            throw PageNotFoundException::forPageNotFound();
        }

        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'ended' => 0, 'removed' => 0];

        foreach ($data['datasets'] as $counts) {
            foreach (array_keys($totals) as $key) {
                // Only staff assignments have an "ended" count, and only class times a "removed" one.
                $totals[$key] += (int) ($counts[$key] ?? 0);
            }
        }

        return $data + ['totals' => $totals];
    }

    /**
     * Applies the kept files for real, all or nothing, and removes them.
     *
     * @throws StructureException     refused or failed: nothing was changed
     * @throws PageNotFoundException when the token is not this institution's, or has expired
     */
    public function apply(int $institutionId, string $token, ?User $actor = null): SisImportResult
    {
        $this->mustBeAllowed($institutionId);
        $this->kept($institutionId, $token);

        $folder = $this->folderOf($institutionId, $token);

        try {
            $result = $this->run($institutionId, $folder, false, $actor);
        } catch (InvalidArgumentException $e) {
            throw new StructureException($e->getMessage());
        }

        if (! $result->ok) {
            throw new StructureException($result->message);
        }

        $this->remove($folder);

        return $result;
    }

    /** Throws a kept preview away. */
    public function discard(int $institutionId, string $token): void
    {
        $this->remove($this->folderOf($institutionId, $token));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @throws StructureException
     */
    private function mustBeAllowed(int $institutionId): void
    {
        if ($this->blockedBySis($institutionId)) {
            throw new StructureException('Your student information system supplies this data, so files cannot be uploaded while it is connected. Change it there; the next sync brings it here.');
        }
    }

    private function run(int $institutionId, string $folder, bool $dryRun, ?User $actor): SisImportResult
    {
        return (new SisImporter(new UploadConnections($folder)))->run($institutionId, $actor ?? auth()->user(), $dryRun);
    }

    private function folder(int $institutionId, string $token): string
    {
        return $this->base . DIRECTORY_SEPARATOR . $institutionId . DIRECTORY_SEPARATOR . $token;
    }

    /**
     * @throws PageNotFoundException for a token that is not 32 hex characters or has no folder
     */
    private function folderOf(int $institutionId, string $token): string
    {
        $folder = $this->folder($institutionId, $token);

        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1 || ! is_dir($folder)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $folder;
    }

    /**
     * Removes every institution's previews that are past their hour (QA AMB-INST-06). The server's every-minute
     * job runs it, so an abandoned preview's personal data does not wait for the next upload.
     *
     * @return int how many were removed
     */
    public function forgetExpired(): int
    {
        $removed = 0;

        foreach (is_dir($this->base) ? (scandir($this->base) ?: []) : [] as $entry) {
            if (ctype_digit($entry)) {
                $removed += $this->forget((int) $entry);
            }
        }

        return $removed;
    }

    /** Removes this institution's previews that are past their time. */
    private function forget(int $institutionId): int
    {
        $dir     = $this->base . DIRECTORY_SEPARATOR . $institutionId;
        $removed = 0;

        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if ($entry !== '.' && $entry !== '..' && is_dir($path) && (int) filemtime($path) < time() - self::KEEP_SECONDS) {
                $removed += $this->remove($path) ? 1 : 0;
            }
        }

        return $removed;
    }

    /** False, and logged, when something could not be removed; the next sweep tries again. */
    private function remove(string $folder): bool
    {
        foreach (glob($folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        if (! @rmdir($folder) && is_dir($folder)) {
            log_message('error', 'A CSV upload preview could not be removed: {folder}. The next sweep tries again.', ['folder' => basename(dirname($folder)) . '/' . basename($folder)]);

            return false;
        }

        return true;
    }
}
