<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Models\Courses\OfferingDetail;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Shield\Entities\User;

/**
 * The dean Courses page: every course offering under the dean's department,
 * searchable, filterable and sortable. The programs in scope come from
 * DeanAccessService on every call, so a program added later shows up here at
 * once and an ended assignment shows nothing. Read-only: this class only
 * selects.
 */
class DeanCourseSearch
{
    private const SORT_COLUMNS = [
        'name'            => 'courses.course_code',
        'instructor'      => 'instructor_profiles.last_name',
        'enrollment'      => 'enrolled',
        'recent_activity' => 'recent_activity',
    ];

    private DeanAccessService $access;

    public function __construct(?DeanAccessService $access = null)
    {
        $this->access = $access ?? new DeanAccessService();
    }

    /**
     * One page of offerings and the number matching in total.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(User $user, DeanCourseFilters $filters): array
    {
        $total = $this->filtered($user, $filters)->countAllResults();

        $rows = $this->filtered($user, $filters)
            ->orderBy(self::SORT_COLUMNS[$filters->sort], $filters->dir)
            ->orderBy('course_offerings.id', $filters->dir)
            ->limit($filters->perPage, ($filters->page - 1) * $filters->perPage)
            ->get()
            ->getResultArray();

        // The same "Class progress" figure the instructor's and chairperson's cards show.
        $progress = (new CourseService())->classProgress($rows);

        foreach ($rows as &$row) {
            $row['progress'] = $progress[(int) $row['offering_id']] ?? 0;
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The terms the department's courses actually run in, newest first.
     *
     * @return list<array{id: int, name: string, academic_year: string}>
     */
    public function terms(User $user): array
    {
        return $this->scoped($user)
            ->select(['academic_terms.id', 'academic_terms.name', 'academic_terms.academic_year', 'academic_terms.start_date'])
            ->join('academic_terms', 'academic_terms.id = course_offerings.term_id')
            ->distinct()
            ->orderBy('academic_terms.start_date', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * The department's sub-departments, for the filter.
     *
     * @return list<array{id: int, name: string}>
     */
    public function subDepartments(User $user): array
    {
        $department = $this->access->departmentFor($user);

        if ($department === null) {
            return [];
        }

        return db_connect()->table('sub_departments')
            ->select(['id', 'name'])
            ->where('department_id', (int) $department['department_id'])
            ->orderBy('name', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * The department's programs, for the filter.
     *
     * @return list<array{id: int, name: string, sub_department_id: int}>
     */
    public function programs(User $user): array
    {
        $ids = $this->access->programIds($user);

        if ($ids === []) {
            return [];
        }

        return db_connect()->table('programs')
            ->select(['id', 'name', 'sub_department_id'])
            ->whereIn('id', $ids)
            ->orderBy('name', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * The instructors teaching the department's courses, for the filter.
     *
     * @return list<array{id: int, name: string}>
     */
    public function instructors(User $user): array
    {
        return $this->scoped($user)
            ->select(['users.id', "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS name"])
            ->join('instructor_assignments', 'instructor_assignments.offering_id = course_offerings.id')
            ->join('users', 'users.id = instructor_assignments.instructor_user_id')
            ->join('user_profiles', 'user_profiles.user_id = users.id')
            ->distinct()
            // Order by the selected alias: ordering by a raw column that is not in a DISTINCT select list is rejected by some MySQL configs.
            ->orderBy('name', 'ASC')
            ->get()
            ->getResultArray();
    }

    /** The bare join from the department's programs down to course_offerings, with no select of its own. */
    private function scoped(User $user): BaseBuilder
    {
        $programIds = $this->access->programIds($user);

        $builder = db_connect()->table('course_offerings')
            ->join('courses', 'courses.id = course_offerings.course_id');

        return $programIds === []
            ? $builder->where('1 = 0', null, false)
            : $builder->whereIn('courses.program_id', $programIds);
    }

    private function filtered(User $user, DeanCourseFilters $filters): BaseBuilder
    {
        $builder = OfferingDetail::inPrograms($this->access->programIds($user))
            ->select(
                "(SELECT MAX(course_modules.updated_at)
                    FROM course_modules
                   WHERE course_modules.offering_id = course_offerings.id) AS recent_activity",
                false
            )
            ->select(
                "(SELECT CONCAT(chair_profiles.first_name, ' ', chair_profiles.last_name)
                    FROM chairperson_assignments
                    JOIN user_profiles AS chair_profiles ON chair_profiles.user_id = chairperson_assignments.chairperson_user_id
                   WHERE chairperson_assignments.sub_department_id = sub_departments.id
                     AND chairperson_assignments.status = 'active'
                   ORDER BY chairperson_assignments.id
                   LIMIT 1) AS chairperson_name",
                false
            );

        if ($filters->status !== null) {
            $builder->where('course_offerings.status', $filters->status);
        }

        if ($filters->termId !== null) {
            $builder->where('academic_terms.id', $filters->termId);
        }

        if ($filters->subDepartmentId !== null) {
            $builder->where('sub_departments.id', $filters->subDepartmentId);
        }

        if ($filters->programId !== null) {
            $builder->where('programs.id', $filters->programId);
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
