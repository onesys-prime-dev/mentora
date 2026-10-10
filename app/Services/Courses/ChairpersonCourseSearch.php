<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Models\Assignments\ChairpersonAssignmentModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Shield\Entities\User;

/**
 * The chairperson Courses page: every course offering across every
 * sub-department the signed-in chairperson actively chairs, searchable,
 * filterable and sortable. Built on
 * ChairpersonAssignmentModel::chairedOfferingsBuilder(), so a program or
 * course added to their sub-department later shows up here with no
 * further wiring.
 */
class ChairpersonCourseSearch
{
    private const SORT_COLUMNS = [
        'name'            => 'courses.course_code',
        'instructor'      => 'instructor_profiles.last_name',
        'enrollment'      => 'enrolled',
        'recent_activity' => 'recent_activity',
    ];

    protected ChairpersonAssignmentModel $assignments;

    public function __construct()
    {
        $this->assignments = new ChairpersonAssignmentModel();
    }

    /**
     * One page of offerings and the number matching in total.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(User $user, ChairpersonCourseFilters $filters): array
    {
        $total = $this->filtered($user, $filters)->countAllResults();

        $column = self::SORT_COLUMNS[$filters->sort];

        $rows = $this->filtered($user, $filters)
            ->orderBy($column, $filters->dir)
            ->orderBy('course_offerings.id', $filters->dir)
            ->limit($filters->perPage, ($filters->page - 1) * $filters->perPage)
            ->get()
            ->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The terms this chairperson's courses actually run in, for the filter
     * dropdown — not every term in the institution, just the ones that
     * would return results. A lean query of its own rather than reusing
     * chairedOfferingsBuilder(), since its full select list would defeat
     * DISTINCT here.
     *
     * @return list<array{id: int, name: string, academic_year: string}>
     */
    public function terms(User $user): array
    {
        return $this->programScopedOfferings((int) $user->id)
            ->select(['academic_terms.id', 'academic_terms.name', 'academic_terms.academic_year', 'academic_terms.start_date'])
            ->join('academic_terms', 'academic_terms.id = course_offerings.term_id')
            ->distinct()
            ->orderBy('academic_terms.start_date', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * The instructors actually teaching this chairperson's courses, for the
     * filter dropdown.
     *
     * @return list<array{id: int, name: string}>
     */
    public function instructors(User $user): array
    {
        return $this->programScopedOfferings((int) $user->id)
            ->select(['users.id', "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS name"])
            ->join('instructor_assignments', 'instructor_assignments.offering_id = course_offerings.id')
            ->join('users', 'users.id = instructor_assignments.instructor_user_id')
            ->join('user_profiles', 'user_profiles.user_id = users.id')
            ->distinct()
            ->orderBy('user_profiles.last_name', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** The bare join from chairperson_assignments down to course_offerings, with no select of its own. */
    private function programScopedOfferings(int $userId): BaseBuilder
    {
        return db_connect()->table('chairperson_assignments')
            ->join('sub_departments', 'sub_departments.id = chairperson_assignments.sub_department_id')
            ->join('programs', 'programs.sub_department_id = sub_departments.id')
            ->join('courses', 'courses.program_id = programs.id')
            ->join('course_offerings', 'course_offerings.course_id = courses.id')
            ->where('chairperson_assignments.chairperson_user_id', $userId)
            ->where('chairperson_assignments.status', 'active');
    }

    private function filtered(User $user, ChairpersonCourseFilters $filters): BaseBuilder
    {
        $builder = $this->assignments->chairedOfferingsBuilder((int) $user->id, $filters->status)
            ->select(
                "(SELECT MAX(course_modules.updated_at)
                    FROM course_modules
                   WHERE course_modules.offering_id = course_offerings.id) AS recent_activity",
                false
            );

        if ($filters->termId !== null) {
            $builder->where('academic_terms.id', $filters->termId);
        }

        if ($filters->instructorId !== null) {
            $builder->where('users.id', $filters->instructorId);
        }

        if ($filters->q !== null) {
            $literal = db_connect()->escapeLikeString($filters->q);

            $builder->groupStart()
                ->like('courses.course_code', $literal, 'both', true)
                ->orLike('courses.course_title', $literal, 'both', true)
                ->orLike('course_offerings.section_code', $literal, 'both', true)
                ->groupEnd();
        }

        return $builder;
    }
}
