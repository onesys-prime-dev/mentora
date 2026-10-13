<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * Reads a SIS export (CSV) into a SisBatch, with the rules every inbound
 * dataset follows. Pure: text in, batch out.
 *
 * Column names are matched case-insensitively with spaces as underscores;
 * unknown columns are ignored; a missing required column rejects the whole
 * file. A row that breaks a rule is rejected on its own, with its row number
 * (the header is row 1) and the reason.
 */
final class SisCsvParser
{
    public const MAX_ROWS = 50000;

    public const ENROLLMENT_STATUSES = ['enrolled', 'dropped', 'withdrawn', 'completed'];

    /**
     * required columns, optional columns, and which columns make a row unique.
     *
     * @var array<string, array{required: list<string>, optional: list<string>, unique: list<string>}>
     */
    private const SPECS = [
        'students'    => ['required' => ['external_id', 'email', 'first_name', 'last_name'], 'optional' => ['student_number'], 'unique' => ['external_id']],
        'instructors' => ['required' => ['external_id', 'email', 'first_name', 'last_name'], 'optional' => [], 'unique' => ['external_id']],
        'courses'     => ['required' => ['external_id', 'course_code', 'course_title'], 'optional' => ['units', 'program_code'], 'unique' => ['external_id']],
        'enrollments' => ['required' => ['course_external_id', 'student_external_id'], 'optional' => ['section', 'status'], 'unique' => ['course_external_id', 'student_external_id', 'section']],
    ];

    /** @return list<string> */
    public static function datasets(): array
    {
        return array_keys(self::SPECS);
    }

    /**
     * @return array{required: list<string>, optional: list<string>, unique: list<string>}
     */
    public static function spec(string $dataset): array
    {
        return self::SPECS[$dataset] ?? throw new SisException("Unknown dataset \"{$dataset}\".");
    }

    /**
     * @throws SisException when the file as a whole cannot be used
     */
    public static function parse(string $dataset, string $csv): SisBatch
    {
        $spec = self::spec($dataset);

        // A byte order mark from a spreadsheet export would end up in the first column name.
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream, 0, ',', '"', '');

        if ($header === false || $header === [null]) {
            fclose($stream);

            throw new SisException("{$dataset}.csv is empty.");
        }

        $columns = array_map(static fn ($cell): string => str_replace(' ', '_', strtolower(trim((string) $cell))), $header);
        $missing = array_values(array_diff($spec['required'], $columns));

        if ($missing !== []) {
            fclose($stream);

            throw new SisException("{$dataset}.csv is missing the column(s): " . implode(', ', $missing) . '.');
        }

        $keep     = array_merge($spec['required'], $spec['optional']);
        $records  = [];
        $rejected = [];
        $rowNos   = [];
        $seen     = [];
        $row      = 1;

        while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $row++;

            if ($cells === [null]) {
                continue; // a blank line
            }

            if ($row > self::MAX_ROWS + 1) {
                fclose($stream);

                throw new SisException("{$dataset}.csv has more than " . number_format(self::MAX_ROWS) . ' rows; split it.');
            }

            if (count($cells) !== count($columns)) {
                $rejected[] = ['row' => $row, 'reason' => 'Expected ' . count($columns) . ' columns, found ' . count($cells) . '.'];

                continue;
            }

            $values = [];

            foreach ($columns as $i => $column) {
                if (in_array($column, $keep, true)) {
                    $values[$column] = trim((string) $cells[$i]);
                }
            }

            foreach ($spec['optional'] as $column) {
                $values[$column] ??= '';
            }

            $problem = self::problem($values, $spec['required']);

            if ($problem === null) {
                $key = strtolower(implode("\x1f", array_map(static fn (string $c): string => $values[$c] ?? '', $spec['unique'])));

                if (isset($seen[$key])) {
                    $problem = 'Repeats row ' . $seen[$key] . ' (' . implode(' + ', $spec['unique']) . ').';
                } else {
                    $seen[$key] = $row;
                }
            }

            if ($problem !== null) {
                $rejected[] = ['row' => $row, 'reason' => $problem];

                continue;
            }

            $records[] = array_intersect_key($values, array_flip($keep));
            $rowNos[]  = $row;
        }

        fclose($stream);

        return new SisBatch($dataset, $records, $rejected, $rowNos);
    }

    /**
     * @param array<string, string> $values
     * @param list<string>          $required
     */
    private static function problem(array $values, array $required): ?string
    {
        foreach ($required as $column) {
            if (($values[$column] ?? '') === '') {
                return "{$column} is empty.";
            }
        }

        foreach ($values as $column => $value) {
            if (mb_strlen($value) > 255) {
                return "{$column} is longer than 255 characters.";
            }
        }

        if (isset($values['email']) && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'email is not a valid address.';
        }

        if (($values['units'] ?? '') !== '' && (! is_numeric($values['units']) || (float) $values['units'] < 0 || (float) $values['units'] > 99.99)) {
            return 'units must be a number from 0 to 99.99.';
        }

        if (($values['status'] ?? '') !== '' && ! in_array(strtolower($values['status']), self::ENROLLMENT_STATUSES, true)) {
            return 'status must be one of: ' . implode(', ', self::ENROLLMENT_STATUSES) . '.';
        }

        return null;
    }
}
