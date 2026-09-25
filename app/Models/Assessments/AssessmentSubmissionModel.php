<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AssessmentSubmissionModel extends Model
{
    protected $table            = 'assessment_submissions';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'assessment_id',
        'student_id',
        'attempt_number',
        'started_at',
        'submitted_at',
        'time_spent',
        'submission_text',
        'submission_link',
        'submission_file_id',
        'score',
        'percentage',
        'is_late_submission',
        'is_graded',
        'graded_by',
        'graded_at',
        'feedback',
        'status',
        'grading_mode',
        'rubric_scores',
    ];
}