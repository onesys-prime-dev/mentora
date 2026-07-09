<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class CourseModel extends Model
{
    protected $table = 'courses';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'program_id',
        'course_code',
        'course_title',
        'units',
        'description',
        'status',

    ];

    protected $validationRules = [

        'program_id' => 'required|is_natural_no_zero',

        'course_code' => 'required|max_length[50]',

        'course_title' => 'required|max_length[255]',

        'units' => 'required|decimal',

        'status' => 'required|in_list[active,inactive,archived]',

    ];
}