<?php

namespace App\Models\Courses;

use CodeIgniter\Model;

class EnrollmentModel extends Model
{
    protected $table = 'enrollments';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useAutoIncrement = true;

    protected $useSoftDeletes = false;

    protected $useTimestamps = true;

    protected $dateFormat = 'datetime';

    protected $createdField = 'enrolled_at';

    protected $updatedField = 'updated_at';

    protected $allowedFields = [

        'offering_id',
        'student_user_id',
        'status',
        'enrolled_at',

    ];

    protected $validationRules = [

        'offering_id' => 'required|is_natural_no_zero',

        'student_user_id' => 'required|is_natural_no_zero',

        'status' => 'required|in_list[enrolled,dropped,completed,withdrawn]',

    ];
}