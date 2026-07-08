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

    protected $allowedFields = [

        'institution_id',
        'name',
        'code',
        'status',

    ];

    protected $validationRules = [

        'institution_id' => [
            'rules' => 'required|is_natural_no_zero',
        ],

        'name' => [
            'rules' => 'required|min_length[3]|max_length[255]',
        ],

        'code' => [
            'rules' => 'required|max_length[50]',
        ],

        'status' => [
            'rules' => 'required|in_list[active,inactive]',
        ],

    ];

    protected $validationMessages = [

        'institution_id' => [
            'required' => 'Institution is required.',
        ],

        'name' => [
            'required' => 'Program name is required.',
        ],

        'code' => [
            'required' => 'Program code is required.',
        ],

    ];
}