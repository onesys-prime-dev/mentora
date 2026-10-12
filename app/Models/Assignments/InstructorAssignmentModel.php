<?php

namespace App\Models\Assignments;

use CodeIgniter\Model;

class InstructorAssignmentModel extends Model
{
    protected $table = 'instructor_assignments';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'assigned_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [
        'offering_id',
        'instructor_user_id',
        'assigned_at',
    ];

    /** The courses taught now, or with $past the ones whose term was closed. */
    public function retrieveInstructorCourseOfferings(int $userId, bool $past = false): array
    {
        $builder = $this->builder();

        return $builder
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
                     AND enrollments.status IN ('enrolled', 'completed')) AS enrolled",
                false
            )
            ->join(
                'course_offerings',
                'course_offerings.id = instructor_assignments.offering_id'
            )
            ->join(
                'courses',
                'courses.id = course_offerings.course_id'
            )
            ->join(
                'programs',
                'programs.id = courses.program_id'
            )
            ->join(
                'academic_terms',
                'academic_terms.id = course_offerings.term_id'
            )
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->join(
                'institutions',
                'institutions.id = academic_terms.institution_id
                AND institutions.id = departments.institution_id'
            )
            ->join(
                'users',
                'users.id = instructor_assignments.instructor_user_id'
            )

            ->join(
                'user_profiles',
                'user_profiles.user_id = users.id'
            )

            ->join(
                'auth_identities',
                "auth_identities.user_id = users.id
                AND auth_identities.type = 'email_password'"
            )
            ->where('instructor_assignments.instructor_user_id', $userId)
            ->where('course_offerings.status', $past ? 'completed' : 'active')
            ->orderBy('courses.course_code', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function retrieveInstructorCourseOffering(int $userId, int $offeringId): ?array
    {
        $builder = $this->builder();

        return $builder
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
            ->join(
                'course_offerings',
                'course_offerings.id = instructor_assignments.offering_id'
            )
            ->join(
                'courses',
                'courses.id = course_offerings.course_id'
            )
            ->join(
                'programs',
                'programs.id = courses.program_id'
            )
            ->join(
                'academic_terms',
                'academic_terms.id = course_offerings.term_id'
            )
            ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->join(
                'institutions',
                'institutions.id = academic_terms.institution_id
                AND institutions.id = departments.institution_id'
            )
            ->where('instructor_assignments.instructor_user_id', $userId)
            ->where('course_offerings.id', $offeringId)
            ->whereIn('course_offerings.status', ['active', 'completed'])
            ->orderBy('courses.course_code', 'ASC')
            ->get()
            ->getRowArray();
    }

    /**
     * Every active section this instructor teaches, with its live roster count.
     *
     * This is the base join the whole instructor dashboard hangs off — Teaching
     * Load renders it directly, and Class Progress, At-Risk and Content Status
     * all scope themselves to the offering ids it returns.
     *
     * The roster count is a correlated subquery rather than a JOIN + GROUP BY
     * so that adding further aggregates here never risks multiplying rows.
     */
    public function retrieveInstructorSections(int $userId): array
    {
        return $this->builder()
            ->select([
                'course_offerings.id AS offering_id',
                'course_offerings.section_code',
                'course_offerings.capacity',

                'courses.id AS course_id',
                'courses.course_code',
                'courses.course_title',

                'academic_terms.id AS term_id',
                'academic_terms.name AS term_name',
            ])
            ->select(
                "(SELECT COUNT(*)
                    FROM enrollments
                   WHERE enrollments.offering_id = course_offerings.id
                     AND enrollments.status IN ('enrolled', 'completed')) AS enrolled",
                false
            )
            ->join(
                'course_offerings',
                'course_offerings.id = instructor_assignments.offering_id'
            )
            ->join(
                'courses',
                'courses.id = course_offerings.course_id'
            )
            ->join(
                'academic_terms',
                'academic_terms.id = course_offerings.term_id'
            )
            ->where('instructor_assignments.instructor_user_id', $userId)
            ->where('course_offerings.status', 'active')
            ->orderBy('courses.course_code', 'ASC')
            ->orderBy('course_offerings.section_code', 'ASC')
            ->get()
            ->getResultArray();
    }
}
