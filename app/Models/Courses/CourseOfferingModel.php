<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class CourseOfferingModel extends Model
{
    protected $table = 'course_offerings';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'course_id',
        'term_id',
        'section_code',
        'capacity',
        'description',
        'status',

    ];

    protected $validationRules = [

        'course_id' => 'required|is_natural_no_zero',

        'term_id' => 'required|is_natural_no_zero',

        'section_code' => 'required|max_length[50]',

        'capacity' => 'required|integer',

        'status' => 'required|in_list[active,completed,archived]',

    ];

    /**
     * Everything the course Overview's info cards show about one section.
     *
     * The instructor joins are LEFT joins on purpose: a section with nobody
     * assigned yet is a normal state, and an inner join would return no row
     * at all — which the page would then crash on.
     */
    public function getCourseOverviewData(int $offeringId): array
    {
        return $this->builder()
            ->select([
                'course_offerings.id AS offering_id',
                'course_offerings.description',
                'course_offerings.section_code',
                'course_offerings.capacity',

                'courses.course_code',
                'courses.course_title',

                'academic_terms.name AS term_name',
                'academic_terms.academic_year',

                "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS instructor_name",
                'auth_identities.secret AS instructor_email',
            ])
            ->select(
                "(SELECT COUNT(*) FROM enrollments
                   WHERE enrollments.offering_id = course_offerings.id
                     AND enrollments.status = 'enrolled') AS enrolled",
                false
            )
            ->join('courses', 'courses.id = course_offerings.course_id')
            ->join('academic_terms', 'academic_terms.id = course_offerings.term_id')
            // The first-assigned instructor, so a co-taught course always
            // names the same person rather than whichever row comes first.
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
                'user_profiles',
                'user_profiles.user_id = instructor_assignments.instructor_user_id',
                'left'
            )
            ->join(
                'auth_identities',
                "auth_identities.user_id = instructor_assignments.instructor_user_id
                AND auth_identities.type = 'email_password'",
                'left'
            )
            ->where('course_offerings.id', $offeringId)
            ->get()
            ->getRowArray() ?? [];
    }
}