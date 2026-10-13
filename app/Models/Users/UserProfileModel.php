<?php

namespace App\Models\Users;

use CodeIgniter\Model;
use CodeIgniter\Shield\Entities\User;

class UserProfileModel extends Model
{
    protected $table            = 'user_profiles';
    protected $primaryKey       = 'user_id';
    protected $useAutoIncrement = false;

    protected $returnType = 'array';

    protected $allowedFields = [
        'user_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'student_number',
        'program_id',
        'active_role',
    ];

    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $validationRules = [
        'user_id' => [
            'rules' => 'required|is_natural_no_zero',
        ],

        'first_name' => [
            'rules' => 'required|min_length[2]|max_length[100]',
        ],

        'middle_name' => [
            'rules' => 'permit_empty|max_length[100]',
        ],

        'last_name' => [
            'rules' => 'required|min_length[2]|max_length[100]',
        ],

        'suffix' => [
            'rules' => 'permit_empty|max_length[20]',
        ],

        'student_number' => [
            'rules' => 'permit_empty|max_length[20]',
        ],

        'program_id' => [
            'rules' => 'permit_empty|is_natural_no_zero',
        ],

        'active_role' => [
            'rules' => 'permit_empty|max_length[50]',
        ],
    ];

    protected $validationMessages = [
        'first_name' => [
            'required' => 'First name is required.',
        ],
        'last_name' => [
            'required' => 'Last name is required.',
        ],
    ];

    public function retrieveUserProfile(User $user): ?array
    {
        return $this->builder()
            ->select([
                'users.id AS user_id',

                'auth_identities.secret AS email',

                'auth_groups_users.group AS role',

                'user_profiles.first_name',
                'user_profiles.middle_name',
                'user_profiles.last_name',
                'user_profiles.suffix',
                'user_profiles.student_number',

                'programs.name AS program_name',
                'programs.code AS program_code',

                'sub_departments.name AS sub_department_name',
                'sub_departments.code AS sub_department_code',
                'departments.name AS department_name',
                'departments.code AS department_code',
            ])
            ->select(self::teachingHomeColumns(), false)
            ->join(
                'users',
                'users.id = user_profiles.user_id'
            )
            ->join(
                'auth_identities',
                'auth_identities.user_id = users.id'
            )
            ->join(
                'auth_groups_users',
                'auth_groups_users.user_id = users.id'
            )
            ->join(
                'programs',
                'programs.id = user_profiles.program_id',
                'left'
            )
            ->join(
                'sub_departments',
                'sub_departments.id = programs.sub_department_id',
                'left'
            )
            ->join(
                'departments',
                'departments.id = sub_departments.department_id',
                'left'
            )
            ->where('user_profiles.user_id', $user->id)
            ->get()
            ->getRowArray();
    }

    /**
     * An instructor has no program of their own: the department they belong to
     * is the one that owns the program of the first course they were assigned
     * to teach. Returned as extra columns (null for someone who teaches
     * nothing yet) so the profile still costs a single query.
     */
    private static function teachingHomeColumns(): string
    {
        $firstTaught = static fn (string $column): string => "(SELECT {$column}
                FROM instructor_assignments
                JOIN course_offerings ON course_offerings.id = instructor_assignments.offering_id
                JOIN courses ON courses.id = course_offerings.course_id
                JOIN programs AS taught_programs ON taught_programs.id = courses.program_id
                JOIN sub_departments AS taught_sub_departments ON taught_sub_departments.id = taught_programs.sub_department_id
                JOIN departments AS taught_departments ON taught_departments.id = taught_sub_departments.department_id
               WHERE instructor_assignments.instructor_user_id = users.id
               ORDER BY instructor_assignments.assigned_at, instructor_assignments.id
               LIMIT 1)";

        return implode(', ', [
            $firstTaught('taught_sub_departments.name') . ' AS teaching_sub_department_name',
            $firstTaught('taught_sub_departments.code') . ' AS teaching_sub_department_code',
            $firstTaught('taught_departments.name') . ' AS teaching_department_name',
            $firstTaught('taught_departments.code') . ' AS teaching_department_code',
        ]);
    }
}