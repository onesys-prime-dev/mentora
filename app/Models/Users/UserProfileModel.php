<?php

namespace App\Models\Users;

use CodeIgniter\Model;

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
    ];

    protected $useSoftDeletes = true;
    protected $useTimestamps = true;

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
    ];

    protected $validationMessages = [
        'first_name' => [
            'required' => 'First name is required.',
        ],
        'last_name' => [
            'required' => 'Last name is required.',
        ],
    ];
}