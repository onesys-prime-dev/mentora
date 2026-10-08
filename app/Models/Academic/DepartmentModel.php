<?php

namespace App\Models\Academic;

use CodeIgniter\Model;

class DepartmentModel extends Model
{
    protected $table = 'departments';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'institution_id',
        'code',
        'name',
        'location',
        'established_year',

    ];

    protected $validationRules = [

        'institution_id' => [
            'rules' => 'required|is_natural_no_zero',
        ],

        'code' => [
            'rules' => 'required|max_length[50]',
        ],

        'name' => [
            'rules' => 'required|min_length[3]|max_length[255]',
        ],

        'location' => [
            'rules' => 'permit_empty|max_length[255]',
        ],

        'established_year' => [
            'rules' => 'permit_empty|is_natural_no_zero|exact_length[4]',
        ],

    ];

    protected $validationMessages = [

        'institution_id' => [
            'required' => 'Institution is required.',
        ],

        'code' => [
            'required' => 'Department code is required.',
        ],

        'name' => [
            'required' => 'Department name is required.',
        ],

    ];
}
