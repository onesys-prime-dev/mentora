<?php

declare(strict_types=1);

namespace App\Services\Sis;

/**
 * Which things the SIS owns. While an institution's connection is enabled,
 * the people and courses it supplied are managed by the SIS: their name and
 * email (people), and code, title and units (courses), are not editable in
 * Mentora, because the next sync would put them back.
 *
 * Enrolments are the SIS's too, when it knows both the course and the
 * student: the next sync would undo a hand change.
 */
class SisOwnership
{
    /** The person fields the SIS decides. */
    public const PERSON_FIELDS = ['first_name', 'last_name', 'email'];

    /** The course fields the SIS decides (the description stays Mentora's). */
    public const COURSE_FIELDS = ['course_code', 'course_title', 'units'];

    /** Whether the SIS is currently the owner of this course's code, title and units. */
    public function managesCourse(int $courseId): bool
    {
        return db_connect()->table('sis_links')
            ->join('sis_connections', 'sis_connections.institution_id = sis_links.institution_id')
            ->where('sis_links.entity', 'course')
            ->where('sis_links.local_id', $courseId)
            ->where('sis_connections.enabled', 1)
            ->countAllResults() > 0;
    }

    /** Whether the SIS is currently the owner of this person's details. */
    public function managesPerson(int $userId): bool
    {
        return db_connect()->table('sis_links')
            ->join('sis_connections', 'sis_connections.institution_id = sis_links.institution_id')
            ->where('sis_links.entity', 'person')
            ->where('sis_links.local_id', $userId)
            ->where('sis_connections.enabled', 1)
            ->countAllResults() > 0;
    }

    /** Whether the SIS owns this student's place in this course (it knows both). */
    public function managesEnrolment(int $courseId, int $studentId): bool
    {
        return $this->managesCourse($courseId) && $this->managesPerson($studentId);
    }
}
