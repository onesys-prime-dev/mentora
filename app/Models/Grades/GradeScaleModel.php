<?php

namespace App\Models\Grades;

use CodeIgniter\Model;

class GradeScaleModel extends Model
{
    protected $table            = 'grade_scales';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'name',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'status',
    ];
}
