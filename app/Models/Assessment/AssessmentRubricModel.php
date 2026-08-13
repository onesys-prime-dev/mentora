<?php

namespace App\Models\Courses\Assessments;

use CodeIgniter\Model;

class AssessmentRubricModel extends Model
{
    protected $table            = 'assessment_rubrics';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'question_id',
        'title',
        'description',
        'total_points',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'status'
    ];
}