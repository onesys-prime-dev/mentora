<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

class AssessmentSettingsModel extends Model
{
    protected $table            = 'assessment_settings';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    // The quiz password is kept as a hash and the hash never leaves the server: every row this model returns has
    // `has_password` instead of `password`. Checking a password reads the hash directly (AssessmentService).
    protected $afterFind = ['withoutPassword'];

    protected $allowedFields = [
        'assessment_id',
        'allow_file_upload',
        'allow_text_entry',
        'allow_link_entry',
        'allow_late_submission',
        'show_results_immediately',
        'show_correct_answers',
        'allow_review_after_submission',
        'require_password',
        'password',
        'randomize_questions',
        'randomize_choices',
        'browser_lockdown',
        'detect_tab_switch',
        'assign_to_specific_students',
        'allow_multiple_attempts',
        'created_at',
        'updated_at'
    ];

    /**
     * @param array{data: mixed, singleton?: bool} $result
     *
     * @return array{data: mixed, singleton?: bool}
     */
    protected function withoutPassword(array $result): array
    {
        if (empty($result['data']) || ! is_array($result['data'])) {
            return $result;
        }

        $strip = static function (array $row): array {
            if (array_key_exists('password', $row)) {
                $row['has_password'] = ! empty($row['password']);
                unset($row['password']);
            }

            return $row;
        };

        $result['data'] = ($result['singleton'] ?? false) ? $strip($result['data']) : array_map($strip, $result['data']);

        return $result;
    }
}