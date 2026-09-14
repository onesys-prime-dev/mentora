<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AssessmentRubricCriteriaModel extends Model
{
    protected $table            = 'assessment_rubric_criteria';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'rubric_id',
        'criteria_title',
        'description',
        'max_points',
        'performance_level',
        'order_index',
        'created_at',
        'updated_at'
    ];
}