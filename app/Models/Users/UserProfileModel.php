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
            ])
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
            ->where('user_profiles.user_id', $user->id)
            ->get()
            ->getRowArray();
    }
}