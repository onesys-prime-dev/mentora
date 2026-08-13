<?php

namespace App\Models\Courses\Assessments;

use CodeIgniter\Model;

class AssessmentRubricCriteriaModel extends Model
{
    protected $table            = 'assessment_rubric_criteria';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'rubricId',
        'criteriaTitle',
        'description',
        'points',
        'performanceLevel',
        'orderIndex',
        'createdAt',
        'updatedAt'
    ];
}