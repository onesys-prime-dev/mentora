<?php

declare(strict_types=1);

namespace App\Services\Courses;

/**
 * Nobody is both a student and staff of one course (audit decisions 9.2–9.5, D7, D10).
 *
 * Each check gives the reason a role would clash, as the words that follow the
 * person's name ("Ann Lee teaches or has taught this course"), or null when it
 * would not. Every writer of enrolments and staff roles asks it: the enrolment
 * page, the SIS import and CSV upload, instructor, chairperson and dean
 * assignment, and granting the institutional administrator role.
 *
 * "Course" is the course, all its sections in every term (they share a question
 * bank). Enrolling is refused to anyone who teaches or ever taught it (an
 * instructor assignment that still exists), chairs or ever chaired its
 * sub-department, is or was the dean of its department, or administers its
 * institution. A staff role is refused to a student enrolled there now: in the
 * same course in the same term for teaching, in any course of the
 * sub-department, department or institution for the others. An enrolment that
 * ended (completed, dropped, withdrawn) never blocks a staff role in a later
 * term, so a former student may teach or chair the course later.
 *
 * Queries only; nothing is written.
 */
class CourseRoleConflicts
{
    public const TAUGHT  = 'teaches or has taught this course';
    public const CHAIRED = 'chairs or has chaired the sub-department of this course';
    public const LED     = "is or was the dean of this course's department";
    public const ADMIN   = 'is an administrator of this institution';
    public const STUDIES_COURSE      = 'is enrolled in this course this term';
    public const STUDIES_SUB         = 'is enrolled in a course of this sub-department';
    public const STUDIES_DEPARTMENT  = 'is enrolled in a course of this department';
    public const STUDIES_INSTITUTION = 'is enrolled in a course of this institution';

    /** The refusal for one person: "{name} {predicate}." */
    public static function sentence(int $userId, string $predicate): string
    {
        $profile = db_connect()->table('user_profiles')->select('first_name, last_name')->where('user_id', $userId)->get()->getRowArray();
        $name    = $profile === null ? 'That person' : trim($profile['first_name'] . ' ' . $profile['last_name']);

        return "{$name} {$predicate}.";
    }

    public function forEnrolment(int $userId, int $offeringId): ?string
    {
        $place = $this->place($offeringId);

        if ($place === null) {
            return null;
        }

        $db = db_connect();

        $taught = $db->table('instructor_assignments ia')
            ->join('course_offerings co', 'co.id = ia.offering_id')
            ->where(['ia.instructor_user_id' => $userId, 'co.course_id' => $place['course_id']])
            ->countAllResults() > 0;

        if ($taught) {
            return self::TAUGHT;
        }

        if ($db->table('chairperson_assignments')->where(['chairperson_user_id' => $userId, 'sub_department_id' => $place['sub_department_id']])->countAllResults() > 0) {
            return self::CHAIRED;
        }

        if ($db->table('dean_assignments')->where(['dean_user_id' => $userId, 'department_id' => $place['department_id']])->countAllResults() > 0) {
            return self::LED;
        }

        $admin = $db->table('auth_groups_users g')
            ->join('institution_users iu', 'iu.user_id = g.user_id')
            ->where(['g.user_id' => $userId, 'g.group' => 'institutional_admin', 'iu.institution_id' => $place['institution_id']])
            ->countAllResults() > 0;

        return $admin ? self::ADMIN : null;
    }

    /**
     * An enrolment that exists, judged by its status. A current one (`enrolled`) by every rule of
     * forEnrolment. A finished one (`completed`) is history: it clashes only with teaching the same
     * course in the same term, the one tie that dates a staff role to it. A former student who teaches,
     * chairs, leads or administers it later keeps their record (D10).
     */
    public function forExistingEnrolment(int $userId, int $offeringId, string $status): ?string
    {
        if ($status === 'enrolled') {
            return $this->forEnrolment($userId, $offeringId);
        }

        if ($status !== 'completed' || ($place = $this->place($offeringId)) === null) {
            return null;
        }

        $taughtThatTerm = db_connect()->table('instructor_assignments ia')
            ->join('course_offerings co', 'co.id = ia.offering_id')
            ->where(['ia.instructor_user_id' => $userId, 'co.course_id' => $place['course_id'], 'co.term_id' => $place['term_id']])
            ->countAllResults() > 0;

        return $taughtThatTerm ? self::TAUGHT : null;
    }

    public function forTeaching(int $userId, int $offeringId): ?string
    {
        $place = $this->place($offeringId);

        if ($place === null) {
            return null;
        }

        $studies = db_connect()->table('enrollments e')
            ->join('course_offerings co', 'co.id = e.offering_id')
            ->where(['e.student_user_id' => $userId, 'co.course_id' => $place['course_id'], 'co.term_id' => $place['term_id']])
            ->whereIn('e.status', ['enrolled', 'completed'])
            ->countAllResults() > 0;

        return $studies ? self::STUDIES_COURSE : null;
    }

    public function forChairing(int $userId, int $subDepartmentId): ?string
    {
        return $this->studiesWhere($userId, 'sd.id', $subDepartmentId) ? self::STUDIES_SUB : null;
    }

    public function forDean(int $userId, int $departmentId): ?string
    {
        return $this->studiesWhere($userId, 'sd.department_id', $departmentId) ? self::STUDIES_DEPARTMENT : null;
    }

    public function forAdministrator(int $userId, int $institutionId): ?string
    {
        return $this->studiesWhere($userId, 'd.institution_id', $institutionId) ? self::STUDIES_INSTITUTION : null;
    }

    /** Enrolled now (not completed or ended) in a course under this sub-department, department or institution. */
    private function studiesWhere(int $userId, string $column, int $id): bool
    {
        return db_connect()->table('enrollments e')
            ->join('course_offerings co', 'co.id = e.offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where(['e.student_user_id' => $userId, 'e.status' => 'enrolled', $column => $id])
            ->countAllResults() > 0;
    }

    /** @return array{course_id: int, term_id: int, sub_department_id: int, department_id: int, institution_id: int}|null */
    private function place(int $offeringId): ?array
    {
        $row = db_connect()->table('course_offerings co')
            ->select(['co.course_id', 'co.term_id', 'sd.id AS sub_department_id', 'd.id AS department_id', 'd.institution_id'])
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('sub_departments sd', 'sd.id = p.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('co.id', $offeringId)
            ->get()->getRowArray();

        return $row === null ? null : array_map('intval', $row);
    }
}
