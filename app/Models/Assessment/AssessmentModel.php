<?php

namespace App\Models\Courses\Assessments;

use CodeIgniter\Model;

class AssessmentModel extends Model
{
    protected $table            = 'assessments';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'course_id',
        'module_id',
        'learning_item_id',
        'assessment_type_id',
        'title',
        'description',
        'instructions',
        'available_from',
        'due_date',
        'duration',
        'total_points',
        'weight_percentage',
        'attempt_limit',
        'passing_score',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'status'
    ];
}