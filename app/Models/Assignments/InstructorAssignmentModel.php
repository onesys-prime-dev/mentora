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

    public function retrieveInstructorCourseOfferings(int $userId): array
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
            ->join(
                'institutions',
                'institutions.id = academic_terms.institution_id
                AND institutions.id = programs.institution_id'
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
            ->where('course_offerings.status', 'active')
            ->orderBy('courses.course_code', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function retrieveInstructorCourseOffering(int $userId, int $offeringId): array
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
            ->join(
                'institutions',
                'institutions.id = academic_terms.institution_id
                AND institutions.id = programs.institution_id'
            )
            ->where('instructor_assignments.instructor_user_id', $userId)
            ->where('course_offerings.id', $offeringId)
            ->where('course_offerings.status', 'active')
            ->orderBy('courses.course_code', 'ASC')
            ->get()
            ->getRowArray();
    }
}