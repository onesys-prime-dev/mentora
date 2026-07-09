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
        'status',

    ];

    protected $validationRules = [

        'course_id' => 'required|is_natural_no_zero',

        'term_id' => 'required|is_natural_no_zero',

        'section_code' => 'required|max_length[50]',

        'capacity' => 'required|integer',

        'status' => 'required|in_list[active,completed,archived]',

    ];
}