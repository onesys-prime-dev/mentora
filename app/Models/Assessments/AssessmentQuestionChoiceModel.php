<?php

namespace App\Models\Assessments;

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
        'created_at',
        'updated_at'
    ];
}