<?php

namespace App\Models\Assignments;

use CodeIgniter\Model;

class ChairpersonAssignmentModel extends Model
{
    protected $table = 'chairperson_assignments';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'assigned_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'sub_department_id',
        'chairperson_user_id',
        'status',
        'assigned_at',
    ];

    /** Every sub-department this user actively chairs — the basis for all of their program-wide access. */
    public function subDepartmentIdsFor(int $userId): array
    {
        return array_map(
            'intval',
            array_column(
                $this->select('sub_department_id')
                    ->where('chairperson_user_id', $userId)
                    ->where('status', 'active')
                    ->findAll(),
                'sub_department_id'
            )
        );
    }

    /** Whether this user actively chairs the given sub-department. */
    public function isChairOf(int $userId, int $subDepartmentId): bool
    {
        return $this->where('chairperson_user_id', $userId)
            ->where('sub_department_id', $subDepartmentId)
            ->where('status', 'active')
            ->countAllResults() > 0;
    }

    /**
     * Every course offering under any sub-department this user actively
     * chairs — every program in it, including ones added later, since this
     * joins live off chairperson_assignments rather than a stored list of
     * courses. Same column shape as
     * InstructorAssignmentModel::retrieveInstructorCourseOfferings(), so a
     * view built for one can read the other.
     */
    public function retrieveChairedCourseOfferings(int $userId): array
    {
        return $this->chairedOfferingsBuilder($userId)
            ->orderBy('courses.course_code', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * One course offering, only if this user actively chairs the
     * sub-department it belongs to. Same column shape and null-when-not-
     * theirs contract as InstructorAssignmentModel::retrieveInstructorCourseOffering().
     */
    public function retrieveChairedCourseOffering(int $userId, int $offeringId): ?array
    {
        return $this->chairedOfferingsBuilder($userId)
            ->where('course_offerings.id', $offeringId)
            ->get()
            ->getRowArray();
    }

    /**
     * The base join behind every chaired-offering query — public so
     * search/filter services can extend it. $status defaults to 'active'
     * to keep every existing caller's behavior unchanged; pass null for
     * offerings of any status (what the chairperson Courses page shows
     * until its own status filter narrows that), or a specific status to
     * match only that one.
     */
    public function chairedOfferingsBuilder(int $userId, ?string $status = 'active'): \CodeIgniter\Database\BaseBuilder
    {
        $builder = $this->builder()
            ->select([
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
            ])
            ->select(
                "(SELECT COUNT(*)
                    FROM enrollments
                   WHERE enrollments.offering_id = course_offerings.id
                     AND enrollments.status = 'enrolled') AS enrolled",
                false
            )
            // The card shows one instructor (the first assigned), same
            // convention as EnrollmentModel's student-facing list: a
            // co-taught course isn't listed once per teacher, and a course
            // with nobody assigned yet isn't dropped.
            ->select(
                "(SELECT COUNT(*)
                    FROM instructor_assignments
                   WHERE instructor_assignments.offering_id = course_offerings.id) AS instructor_count",
                false
            )
            ->select([
                "CONCAT(instructor_profiles.first_name, ' ', instructor_profiles.last_name) AS instructor_name",
                'users.id AS instructor_id',
            ])
            // course_offerings must be joined before anything whose ON
            // clause references course_offerings.id (MySQL resolves a
            // JOIN's ON clause only against tables already named earlier
            // in the FROM/JOIN sequence, not later ones).
            ->join('sub_departments', 'sub_departments.id = chairperson_assignments.sub_department_id')
            ->join('programs', 'programs.sub_department_id = sub_departments.id')
            ->join('courses', 'courses.program_id = programs.id')
            ->join('course_offerings', 'course_offerings.course_id = courses.id')
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
            ->join('user_profiles AS instructor_profiles', 'instructor_profiles.user_id = users.id', 'left')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->join('academic_terms', 'academic_terms.id = course_offerings.term_id')
            ->join(
                'institutions',
                'institutions.id = academic_terms.institution_id
                AND institutions.id = departments.institution_id'
            )
            ->where('chairperson_assignments.chairperson_user_id', $userId)
            ->where('chairperson_assignments.status', 'active');

        if ($status !== null) {
            $builder->where('course_offerings.status', $status);
        }

        return $builder;
    }
}