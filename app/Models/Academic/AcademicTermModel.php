<?php

namespace App\Models\Academic;

use CodeIgniter\Model;

class AcademicTermModel extends Model
{
    protected $table = 'academic_terms';

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
        'name',
        'academic_year',
        'start_date',
        'end_date',
        'status',

    ];

    protected $validationRules = [

        'institution_id' => 'required|is_natural_no_zero',

        'name' => 'required|max_length[100]',

        'academic_year' => 'required|max_length[20]',

        'start_date' => 'required|valid_date',

        'end_date' => 'required|valid_date',

        'status' => 'required|in_list[upcoming,active,completed,archived]',

    ];
}