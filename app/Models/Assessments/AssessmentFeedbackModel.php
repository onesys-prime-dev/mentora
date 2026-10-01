<?php

namespace App\Models\Assessments;

use CodeIgniter\Model;

/**
 * One row per message in a submission's feedback thread — instructor
 * comments (posted with grading, or standalone) and student replies,
 * in the order they were posted.
 */
class AssessmentFeedbackModel extends Model
{
    protected $table            = 'assessment_feedbacks';
    protected $primaryKey       = 'id';

    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'submission_id',
        'author_id',
        'author_role',
        'message',
        'created_at',
    ];

    public function listForSubmission(int $submissionId): array
    {
        return $this->select([
            'assessment_feedbacks.*',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS author_name",
        ])
            ->join('user_profiles', 'user_profiles.user_id = assessment_feedbacks.author_id', 'left')
            ->where('submission_id', $submissionId)
            ->orderBy('assessment_feedbacks.id', 'ASC')
            ->findAll();
    }

    public function latestAuthorRole(int $submissionId, string $authorRole): ?array
    {
        return $this->where('submission_id', $submissionId)
            ->where('author_role', $authorRole)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function hasAuthorRole(int $submissionId, string $authorRole): bool
    {
        return $this->where('submission_id', $submissionId)
            ->where('author_role', $authorRole)
            ->countAllResults() > 0;
    }
}
