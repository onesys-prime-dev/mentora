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
                     AND enrollments.status = 'enrolled') AS enrolled",
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