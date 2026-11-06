<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * Reads a SIS export (CSV) into a SisBatch, with the rules every inbound
 * dataset follows. Pure: text in, batch out.
 *
 * Two sets of columns. A SIS names records by its own ids (KEYS_SIS:
 * external_id, course_external_id and so on, matched through sis_links). An
 * administrator's upload names them the way people know them (KEYS_UPLOAD):
 * a student by student number or email, an instructor by email, a course by
 * its code (and program), a section by its code.
 *
 * Column names are matched case-insensitively with spaces as underscores;
 * unknown columns are ignored; a missing required column rejects the whole
 * file. A row that breaks a rule is rejected on its own, with its row number
 * (the header is row 1) and the reason.
 */
final class SisCsvParser
{
    public const MAX_ROWS = 50000;

    /** Records named by the SIS's own ids, through sis_links. */
    public const KEYS_SIS = 'sis';

    /** Records named the way an administrator knows them: email, student number, course code. */
    public const KEYS_UPLOAD = 'upload';

    public const ENROLLMENT_STATUSES = ['enrolled', 'dropped', 'withdrawn', 'completed'];

    /** What a staff assignment can be, and the column that says what they lead or teach. */
    public const STAFF_TARGET_COLUMN = ['dean' => 'college_code', 'chairperson' => 'department_code', 'instructor' => 'course_external_id'];

    /** The same in an upload, where an instructor's section is named by its course code. */
    public const UPLOAD_STAFF_TARGET_COLUMN = ['dean' => 'college_code', 'chairperson' => 'department_code', 'instructor' => 'course_code'];

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

        // Who leads a college, who chairs a department, who teaches which section. Which of the three
        // target columns a row needs depends on its role (see problem()).
        'staff_assignments' => ['required' => ['role', 'person_external_id'], 'optional' => ['college_code', 'department_code', 'course_external_id', 'section'], 'unique' => ['role', 'person_external_id', 'college_code', 'department_code', 'course_external_id', 'section']],

        // A section's weekly class times (D8, D9): the rows the file lists for a section are its whole week.
        'class_schedules' => ['required' => ['course_external_id', 'day_of_week', 'start_time', 'end_time'], 'optional' => ['section', 'room', 'delivery_mode'], 'unique' => ['course_external_id', 'section', 'day_of_week', 'start_time']],
    ];

    /**
     * An upload's columns (KEYS_UPLOAD). `template` is their order in the downloadable template; `one_of` lists
     * columns of which a row needs at least one.
     *
     * @var array<string, array{required: list<string>, optional: list<string>, unique: list<string>, template: list<string>, one_of?: list<string>}>
     */
    private const UPLOAD_SPECS = [
        'students'    => ['required' => ['email', 'first_name', 'last_name'], 'optional' => ['student_number', 'middle_name', 'suffix'], 'unique' => ['email'], 'template' => ['student_number', 'email', 'first_name', 'middle_name', 'last_name', 'suffix']],
        'instructors' => ['required' => ['email', 'first_name', 'last_name'], 'optional' => ['middle_name', 'suffix'], 'unique' => ['email'], 'template' => ['email', 'first_name', 'middle_name', 'last_name', 'suffix']],
        'courses'     => ['required' => ['program_code', 'course_code', 'course_title'], 'optional' => ['units'], 'unique' => ['program_code', 'course_code'], 'template' => ['program_code', 'course_code', 'course_title', 'units']],

        // The student by student number or email (either is enough); the course by its code, with its program
        // when the code is used in more than one.
        'enrollments' => ['required' => ['course_code'], 'optional' => ['student_number', 'email', 'program_code', 'section', 'status'], 'one_of' => ['student_number', 'email'], 'unique' => ['student_number', 'email', 'program_code', 'course_code', 'section'], 'template' => ['student_number', 'email', 'program_code', 'course_code', 'section', 'status']],

        'staff_assignments' => ['required' => ['role', 'email'], 'optional' => ['college_code', 'department_code', 'program_code', 'course_code', 'section'], 'unique' => ['role', 'email', 'college_code', 'department_code', 'program_code', 'course_code', 'section'], 'template' => ['role', 'email', 'college_code', 'department_code', 'program_code', 'course_code', 'section']],

        'class_schedules' => ['required' => ['course_code', 'day_of_week', 'start_time', 'end_time'], 'optional' => ['program_code', 'section', 'room', 'delivery_mode'], 'unique' => ['program_code', 'course_code', 'section', 'day_of_week', 'start_time'], 'template' => ['program_code', 'course_code', 'section', 'day_of_week', 'start_time', 'end_time', 'room', 'delivery_mode']],
    ];

    /** @return list<string> */
    public static function datasets(): array
    {
        return array_keys(self::SPECS);
    }

    /**
     * @return array{required: list<string>, optional: list<string>, unique: list<string>, template?: list<string>, one_of?: list<string>}
     */
    public static function spec(string $dataset, string $keys = self::KEYS_SIS): array
    {
        $specs = $keys === self::KEYS_UPLOAD ? self::UPLOAD_SPECS : self::SPECS;

        return $specs[$dataset] ?? throw new SisException("Unknown dataset \"{$dataset}\".");
    }

    /**
     * The column that says what each staff role leads or teaches.
     *
     * @return array<string, string> role => column
     */
    public static function staffTargetColumns(string $keys = self::KEYS_SIS): array
    {
        return $keys === self::KEYS_UPLOAD ? self::UPLOAD_STAFF_TARGET_COLUMN : self::STAFF_TARGET_COLUMN;
    }

    /**
     * @throws SisException when the file as a whole cannot be used
     */
    public static function parse(string $dataset, string $csv, string $keys = self::KEYS_SIS): SisBatch
    {
        $spec = self::spec($dataset, $keys);

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

            $problem = self::problem($values, $spec['required'], $spec['one_of'] ?? [], self::staffTargetColumns($keys));

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
     * @param list<string>          $oneOf    columns of which at least one must have a value
     * @param array<string, string> $targets  role => the column naming what it leads or teaches
     */
    private static function problem(array $values, array $required, array $oneOf, array $targets): ?string
    {
        foreach ($required as $column) {
            if (($values[$column] ?? '') === '') {
                return "{$column} is empty.";
            }
        }

        if ($oneOf !== [] && array_filter($oneOf, static fn (string $c): bool => ($values[$c] ?? '') !== '') === []) {
            return implode(' or ', $oneOf) . ' is required.';
        }

        foreach ($values as $column => $value) {
            if (mb_strlen($value) > 255) {
                return "{$column} is longer than 255 characters.";
            }
        }

        // An empty email is only possible where it is optional (an upload's enrolment names the student number).
        if (($values['email'] ?? '') !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'email is not a valid address.';
        }

        if (($values['units'] ?? '') !== '' && (! is_numeric($values['units']) || (float) $values['units'] < 0 || (float) $values['units'] > 99.99)) {
            return 'units must be a number from 0 to 99.99.';
        }

        if (($values['status'] ?? '') !== '' && ! in_array(strtolower($values['status']), self::ENROLLMENT_STATUSES, true)) {
            return 'status must be one of: ' . implode(', ', self::ENROLLMENT_STATUSES) . '.';
        }

        if (array_key_exists('role', $values)) {
            $role = strtolower($values['role']);

            if (! isset($targets[$role])) {
                return 'role must be one of: ' . implode(', ', array_keys($targets)) . '.';
            }

            $needs = $targets[$role];

            if (($values[$needs] ?? '') === '') {
                return "{$needs} is required for " . ($role === 'instructor' ? 'an' : 'a') . " {$role}.";
            }
        }

        return null;
    }
}
