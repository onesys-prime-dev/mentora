<?php

namespace App\Models\Courses\Assessments;

use CodeIgniter\Model;

class AssessmentQuestionChoiceModel extends Model
{
    protected $table            = 'assessment_question_choices';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'question_id',
        'choice_text',
        'is_correct',
        'order_index',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at'
    ];
}