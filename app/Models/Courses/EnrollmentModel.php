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

                "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS instructor_name",
                'auth_identities.secret AS instructor_email',
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
                        // The card shows one instructor (the first assigned). Joined as
            // an optional single row so a co-taught course is not listed
            // once per teacher and a course with nobody assigned yet is not
            // dropped from the student's list.
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
            ->join(
                'users',
                'users.id = instructor_assignments.instructor_user_id',
                'left'
            )
            ->join(
                'user_profiles',
                'user_profiles.user_id = users.id',
                'left'
            )
            ->join(
                'auth_identities',
                "auth_identities.user_id = users.id
                AND auth_identities.type = 'email_password'",
                'left'
            )
            ->where('enrollments.student_user_id', $userId)
            ->where('enrollments.status', 'enrolled')
            ->where('course_offerings.status', 'active')
            ->orderBy('courses.course_code', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function retrieveStudentCourseOffering(int $userId, int $offeringId): ?array
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

    /**
     * Per-student progress across a set of offerings: how many published items
     * each enrolled student has completed, and when they last completed one.
     *
     * Returns raw counts and a timestamp; deciding who counts as "at risk"
     * needs the per-offering item total, which lives in the module query, so
     * that judgement is made by the caller rather than baked into SQL.
     */
    public function retrieveRosterProgress(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        return $this->builder()
            ->select([
                'enrollments.offering_id',
                'enrollments.student_user_id',

                'user_profiles.first_name',
                'user_profiles.last_name',
            ])
            ->select(
                "(SELECT COUNT(*)
                    FROM learning_completions
                    JOIN learning_items
                      ON learning_items.id = learning_completions.learning_item_id
                    JOIN learning_units
                      ON learning_units.id = learning_items.learning_unit_id
                    JOIN course_modules
                      ON course_modules.id = learning_units.module_id
                   WHERE course_modules.offering_id = enrollments.offering_id
                     AND learning_completions.student_user_id = enrollments.student_user_id
                     AND learning_completions.status = 'completed') AS completed_count",
                false
            )
            ->select(
                "(SELECT MAX(learning_completions.completed_at)
                    FROM learning_completions
                    JOIN learning_items
                      ON learning_items.id = learning_completions.learning_item_id
                    JOIN learning_units
                      ON learning_units.id = learning_items.learning_unit_id
                    JOIN course_modules
                      ON course_modules.id = learning_units.module_id
                   WHERE course_modules.offering_id = enrollments.offering_id
                     AND learning_completions.student_user_id = enrollments.student_user_id) AS last_active",
                false
            )
            ->join(
                'user_profiles',
                'user_profiles.user_id = enrollments.student_user_id'
            )
            ->whereIn('enrollments.offering_id', $offeringIds)
            ->where('enrollments.status', 'enrolled')
            ->get()
            ->getResultArray();
    }

    /**
     * The enrolled roster for one offering — just who's in the class, no
     * scores. The gradebook table joins this against per-assessment
     * submissions separately, since a roster x assessment grid is a lot
     * cheaper built as two flat queries than one join that repeats every
     * student row once per assessment.
     */
    public function retrieveGradebookRoster(int $offeringId): array
    {
        return $this->builder()
            ->select([
                'enrollments.student_user_id AS student_id',
                "CONCAT(user_profiles.last_name, ', ', user_profiles.first_name) AS student_name",
            ])
            ->join(
                'user_profiles',
                'user_profiles.user_id = enrollments.student_user_id'
            )
            ->where('enrollments.offering_id', $offeringId)
            ->where('enrollments.status', 'enrolled')
            ->orderBy('student_name', 'ASC')
            ->get()
            ->getResultArray();
    }
}
