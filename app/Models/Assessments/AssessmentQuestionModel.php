<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AssessmentQuestionModel extends Model
{
    protected $table            = 'assessment_questions';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'assessment_id',
        'question_text',
        'question_type',
        'points',
        'is_required',
        'order_index',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'status'
    ];
}