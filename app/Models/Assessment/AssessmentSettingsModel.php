<?php

namespace App\Models\Courses\Assessments;

use CodeIgniter\Model;

class AssessmentSettingsModel extends Model
{
    protected $table            = 'assessment_settings';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'assessment_id',
        'allow_late_submission',
        'show_results_immediately',
        'show_correct_answers',
        'allow_review_after_submission',
        'require_password',
        'password',
        'randomize_questions',
        'randomize_choices',
        'browser_lockdown',
        'allow_multiple_attempts',
        'created_at',
        'updated_at'
    ];
}