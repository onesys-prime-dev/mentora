<?php

declare(strict_types=1);

namespace App\Services\Sis;

use App\Services\Institutions\ClassScheduleService;

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
     * @param array<string, string> $record a staff_assignments.csv record
     *
     * @return array{role: string, person_external_id: string, target: string, section: string}
     *         `target` is the department code, sub-department code or course id the role's column names;
     *         `section` only means something for an instructor (the default section when none is named).
     */
    public static function staffAssignment(array $record): array
    {
        $role = strtolower($record['role']);

        return [
            'role'               => $role,
            'person_external_id' => $record['person_external_id'],
            'target'             => $record[SisCsvParser::STAFF_TARGET_COLUMN[$role]],
            'section'            => $role === 'instructor' && ($record['section'] ?? '') !== '' ? $record['section'] : self::DEFAULT_SECTION,
        ];
    }

    /**
     * @param array<string, string> $record a class_schedules.csv record
     *
     * @return array{course_external_id: string, section: string, day: ?string, start: string, end: string, room: string, mode: string}
     *         `day` is mon–sun, from that or the day's English name in any case, or null when it is neither
     */
    public static function classSchedule(array $record): array
    {
        $day   = strtolower(trim($record['day_of_week']));
        $names = array_map('strtolower', ClassScheduleService::DAYS);

        return [
            'course_external_id' => $record['course_external_id'],
            'section'            => ($record['section'] ?? '') === '' ? self::DEFAULT_SECTION : $record['section'],
            'day'                => isset($names[$day]) ? $day : (array_search($day, $names, true) ?: null),
            'start'              => $record['start_time'],
            'end'                => $record['end_time'],
            'room'               => $record['room'] ?? '',
            'mode'               => $record['delivery_mode'] ?? '',
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
