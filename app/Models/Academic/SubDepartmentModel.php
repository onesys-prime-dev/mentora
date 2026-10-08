<?php

namespace App\Models\Academic;

use CodeIgniter\Model;

class SubDepartmentModel extends Model
{
    protected $table = 'sub_departments';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'department_id',
        'code',
        'name',
        'is_active',

    ];

    protected $validationRules = [

        'department_id' => [
            'rules' => 'required|is_natural_no_zero',
        ],

        'code' => [
            'rules' => 'required|max_length[50]',
        ],

        'name' => [
            'rules' => 'required|min_length[3]|max_length[255]',
        ],

        'is_active' => [
            'rules' => 'permit_empty|in_list[0,1]',
        ],

    ];

    protected $validationMessages = [

        'department_id' => [
            'required' => 'Department is required.',
        ],

        'code' => [
            'required' => 'Sub-department code is required.',
        ],

        'name' => [
            'required' => 'Sub-department name is required.',
        ],

    ];
}
