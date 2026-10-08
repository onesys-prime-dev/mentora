<?php

namespace App\Models\Academic;

use CodeIgniter\Model;

class ProgramModel extends Model
{
    protected $table = 'programs';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'sub_department_id',
        'name',
        'code',
        'status',

    ];

    protected $validationRules = [

        'sub_department_id' => [
            'rules' => 'required|is_natural_no_zero',
        ],

        'name' => [
            'rules' => 'required|min_length[3]|max_length[255]',
        ],

        'code' => [
            'rules' => 'required|max_length[50]',
        ],

        'status' => [
            'rules' => 'required|in_list[active,inactive,archived]',
        ],

    ];

    protected $validationMessages = [

        'sub_department_id' => [
            'required' => 'Sub-department is required.',
        ],

        'name' => [
            'required' => 'Program name is required.',
        ],

        'code' => [
            'required' => 'Program code is required.',
        ],

    ];
}