<?php

declare(strict_types=1);

namespace App\Models\Courses;

use CodeIgniter\Database\BaseBuilder;

/**
 * The one row shape every "offerings with their course, program, term,
 * institution and first instructor" read returns, whatever decides which
 * offerings are in scope (a chairperson's sub-departments, a set of
 * programs, a whole institution). The chairperson's Courses page, Monitor
 * Program and the institution reports all read these columns, so they cannot
 * drift apart.
 *
 * The caller joins the tables (course_offerings, courses, programs,
 * sub_departments, departments, academic_terms, institutions,
 * instructor_assignments as the first assignment, users and
 * user_profiles AS instructor_profiles) and applies its own scope; or uses
 * inPrograms() which does both for a set of programs.
 */
final class OfferingDetail
{
    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'course_offerings.id AS offering_id',
            'course_offerings.section_code',
            'course_offerings.capacity',
            'course_offerings.status AS offering_status',

            'courses.id AS course_id',
            'courses.course_code',
            'courses.course_title',
            'courses.description',
            'courses.units',

            'programs.id AS program_id',
            'programs.name AS program_name',

            'academic_terms.id AS term_id',
            'academic_terms.name AS term_name',
            'academic_terms.academic_year',

            'sub_departments.id AS sub_department_id',
            'sub_departments.name AS sub_department_name',
            'departments.id AS department_id',
            'departments.name AS department_name',

            'institutions.id AS institution_id',
            'institutions.name AS institution_name',
        ];
    }

    /**
     * Adds the columns, the enrolled count and the instructor to a builder
     * that has the joins. One instructor is shown (the first assigned): a
     * co-taught course is not listed once per teacher, and a course with
     * nobody assigned yet is not dropped.
     */
    public static function select(BaseBuilder $builder): BaseBuilder
    {
        return $builder
            ->select(self::columns())
            ->select(
                "(SELECT COUNT(*)
                    FROM enrollments
                   WHERE enrollments.offering_id = course_offerings.id
                     AND enrollments.status IN ('enrolled', 'completed')) AS enrolled",
                false
            )
            ->select(
                "(SELECT COUNT(*)
                    FROM instructor_assignments
                   WHERE instructor_assignments.offering_id = course_offerings.id) AS instructor_count",
                false
            )
            ->select([
                "CONCAT(instructor_profiles.first_name, ' ', instructor_profiles.last_name) AS instructor_name",
                'users.id AS instructor_id',
            ]);
    }

    /**
     * One offering in the shared shape, or null when it does not exist. Not a
     * permission check: whoever calls this has already decided the person may
     * look at it.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $offeringId): ?array
    {
        $programId = (int) db_connect()->table('course_offerings o')->select('c.program_id')->join('courses c', 'c.id = o.course_id')->where('o.id', $offeringId)->get()->getRow('program_id');

        return self::inPrograms([$programId])->where('course_offerings.id', $offeringId)->get()->getRowArray();
    }

    /**
     * Offerings in a set of programs, in the shared shape. Not narrowed by
     * status or term: the caller does that. An empty set is no offerings,
     * never "all of them".
     *
     * @param list<int> $programIds
     */
    public static function inPrograms(array $programIds): BaseBuilder
    {
        $builder = self::select(db_connect()->table('course_offerings'))
            ->join('courses', 'courses.id = course_offerings.course_id')
            ->join('programs', 'programs.id = courses.program_id')
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->join('academic_terms', 'academic_terms.id = course_offerings.term_id')
            ->join(
                'institutions',
                'institutions.id = academic_terms.institution_id
                AND institutions.id = departments.institution_id'
            )
            ->join(
                'instructor_assignments',
                'instructor_assignments.id = (
                    SELECT MIN(first_assignment.id)
                      FROM instructor_assignments first_assignment
                     WHERE first_assignment.offering_id = course_offerings.id
                )',
                'left',
                false
            )
            ->join('users', 'users.id = instructor_assignments.instructor_user_id', 'left')
            ->join('user_profiles AS instructor_profiles', 'instructor_profiles.user_id = users.id', 'left');

        $programIds = array_values(array_unique(array_filter(array_map('intval', $programIds))));

        return $programIds === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('programs.id', $programIds);
    }
}
