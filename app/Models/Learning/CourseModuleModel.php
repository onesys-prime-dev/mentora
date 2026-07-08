<?php

namespace App\Models\Learning;

use CodeIgniter\Model;

class CourseModuleModel extends Model
{
    protected $table = 'course_modules';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = true;

    protected $useTimestamps = true;

    protected $allowedFields = [
        'offering_id',
        'title',
        'description',
        'order_index',
        'status',
    ];
}