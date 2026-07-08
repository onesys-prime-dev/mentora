<?php

namespace App\Models\Academic;

use CodeIgniter\Model;

class InstitutionModel extends Model
{
    protected $table = 'institutions';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $allowedFields = [

        'name',
        'code',
        'status',

    ];

    protected $validationRules = [

        'name' => [
            'rules' => 'required|min_length[3]|max_length[255]',
        ],

        'code' => [
            'rules' => 'required|max_length[50]|is_unique[institutions.code,id,{id}]',
        ],

        'status' => [
            'rules' => 'required|in_list[active,inactive]',
        ],

    ];

    protected $validationMessages = [

        'name' => [
            'required' => 'Institution name is required.',
        ],

        'code' => [
            'required'  => 'Institution code is required.',
            'is_unique' => 'Institution code already exists.',
        ],

    ];
}