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

    public function getCourseOverviewData(int $offeringId): array
    {
        $builder = $this->builder();

        return $builder
            ->select([
                'course_offerings.id AS offering_id',
                'course_offerings.description',

                "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS instructor_name",
                'auth_identities.secret AS instructor_email',
            ])

            ->join(
                'instructor_assignments',
                'instructor_assignments.offering_id = course_offerings.id'
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

            ->where('course_offerings.id', $offeringId)

            ->get()
            ->getRowArray();
    }
}