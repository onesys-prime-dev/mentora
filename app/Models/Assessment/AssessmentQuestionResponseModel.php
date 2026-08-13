<?php

namespace App\Models\Courses\Assessments;

use CodeIgniter\Model;

class AssessmentQuestionResponseModel extends Model
{
    protected $table            = 'assessment_question_responses';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'submission_id',
        'question_id',
        'selected_choice_id',
        'response_text',
        'uploaded_file_id',
        'score',
        'feedback',
        'is_auto_graded',
        'graded_at',
        'created_at',
        'updated_at'
    ];
}