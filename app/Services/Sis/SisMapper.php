<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * Turns a record read from the SIS into the values Mentora stores, and says
 * which of them changed. Pure: no database, no clock.
 *
 * The SIS vocabulary stops here; the importer only deals in these shapes.
 */
final class SisMapper
{
    public const DEFAULT_UNITS   = 3.0;
    public const DEFAULT_SECTION = 'A';

    /**
     * @param array<string, string> $record a students.csv or instructors.csv record
     *
     * @return array{external_id: string, first_name: string, last_name: string, email: string, student_number: ?string}
     */
    public static function person(array $record): array
    {
        return [
            'external_id'    => $record['external_id'],
            'first_name'     => $record['first_name'],
            'last_name'      => $record['last_name'],
            'email'          => strtolower($record['email']),
            'student_number' => ($record['student_number'] ?? '') === '' ? null : $record['student_number'],
        ];
    }

    /**
     * @param array<string, string> $record a courses.csv record
     *
     * @return array{external_id: string, course_code: string, course_title: string, units: ?float, program_code: ?string}
     */
    public static function course(array $record): array
    {
        return [
            'external_id'  => $record['external_id'],
            'course_code'  => $record['course_code'],
            'course_title' => $record['course_title'],
            'units'        => ($record['units'] ?? '') === '' ? null : round((float) $record['units'], 1),
            'program_code' => ($record['program_code'] ?? '') === '' ? null : $record['program_code'],
        ];
    }

    /**
     * @param array<string, string> $record an enrollments.csv record
     *
     * @return array{course_external_id: string, student_external_id: string, section: string, status: string}
     */
    public static function enrollment(array $record): array
    {
        return [
            'course_external_id'  => $record['course_external_id'],
            'student_external_id' => $record['student_external_id'],
            'section'             => ($record['section'] ?? '') === '' ? self::DEFAULT_SECTION : $record['section'],
            'status'              => ($record['status'] ?? '') === '' ? 'enrolled' : strtolower($record['status']),
        ];
    }

    /**
     * The fields of `$incoming` that differ from `$current`. A field the SIS
     * left empty (null) is not a change: the SIS only overrides what it says.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @param list<string>         $fields
     *
     * @return array<string, mixed> field => new value
     */
    public static function changes(array $current, array $incoming, array $fields): array
    {
        $changed = [];

        foreach ($fields as $field) {
            $new = $incoming[$field] ?? null;

            if ($new === null) {
                continue;
            }

            $old = $current[$field] ?? null;

            $same = is_numeric($new) && is_numeric($old) ? abs((float) $new - (float) $old) < 0.0001 : (string) $new === (string) $old;

            if (! $same) {
                $changed[$field] = $new;
            }
        }

        return $changed;
    }
}
