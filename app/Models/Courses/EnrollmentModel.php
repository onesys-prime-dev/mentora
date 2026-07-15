<?php

namespace App\Models\Courses;

use CodeIgniter\Model;
use CodeIgniter\Shield\Entities\User;

class EnrollmentModel extends Model
{
    protected $table = 'enrollments';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'enrolled_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'offering_id',
        'student_user_id',
        'status',
        'enrolled_at',

    ];

    protected $validationRules = [

        'offering_id' => 'required|is_natural_no_zero',

        'student_user_id' => 'required|is_natural_no_zero',

        'status' => 'required|in_list[enrolled,dropped,completed,withdrawn]',

    ];

    public function retrieveStudentCourseOfferings(int $userId): array
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
                'course_offerings.id = enrollments.offering_id'
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
            ->where('enrollments.student_user_id', $userId)
            ->where('enrollments.status', 'enrolled')
            ->where('course_offerings.status', 'active')
            ->orderBy('courses.course_code', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function retrieveStudentCourseOffering(int $userId, int $offeringId): array
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
                'course_offerings.id = enrollments.offering_id'
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
            ->where('enrollments.student_user_id', $userId)
            ->where('course_offerings.id', $offeringId)
            ->where('enrollments.status', 'enrolled')
            ->where('course_offerings.status', 'active')
            ->orderBy('courses.course_code', 'ASC')
            ->get()
            ->getRowArray();
    }

    public function retrieveTotalCoursesEnrolled(int $userId): int
    {
        return $this->builder()
            ->join(
                'course_offerings',
                'course_offerings.id = enrollments.offering_id'
            )
            ->where('enrollments.student_user_id', $userId)
            ->where('enrollments.status', 'enrolled')
            ->where('course_offerings.status', 'active')
            ->countAllResults();
    }
}