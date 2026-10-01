<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;

use App\Models\Assessments\AssessmentFeedbackModel;
use App\Models\Assessments\AssessmentSubmissionModel;

/**
 * A submission's feedback thread: the instructor starts it (bundled with
 * grading, in GradeWorkService::submitGrade()); once it exists and the
 * submission is graded, either side can keep posting through here.
 */
class AssessmentFeedbackService
{
    protected AssessmentFeedbackModel $feedbackModel;
    protected AssessmentSubmissionModel $submissionModel;

    public function __construct()
    {
        $this->feedbackModel = new AssessmentFeedbackModel();
        $this->submissionModel = new AssessmentSubmissionModel();
    }

    public function postMessage(User $user, int $submissionId, string $message): array
    {
        $message = trim($message);

        if ($message === '') {
            return ['success' => false, 'message' => 'Feedback message cannot be empty.'];
        }

        $submission = $this->submissionModel->find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'Submission not found.'];
        }

        $isInstructor = $user->inGroup('instructor');

        if (!$isInstructor && (int) $submission['student_id'] !== (int) $user->id) {
            return ['success' => false, 'message' => 'You can only reply to your own submission.'];
        }

        if (!$isInstructor) {

            if (empty($submission['is_graded'])) {
                return ['success' => false, 'message' => 'You can reply once this submission has been graded.'];
            }

            if (!$this->feedbackModel->hasAuthorRole($submissionId, 'instructor')) {
                return ['success' => false, 'message' => 'Wait for your instructor\'s feedback before replying.'];
            }

        }

        $this->feedbackModel->insert([
            'submission_id' => $submissionId,
            'author_id' => $user->id,
            'author_role' => $isInstructor ? 'instructor' : 'student',
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'success' => true,
            'thread' => $this->feedbackModel->listForSubmission($submissionId),
        ];
    }

    /**
     * The instructor's grading-panel textarea appends a new thread entry
     * rather than overwriting one — but resubmitting the same score with
     * unchanged text (e.g. only the score changed on a regrade) shouldn't
     * duplicate the last message.
     */
    public function appendInstructorMessage(User $user, int $submissionId, string $message): void
    {
        $message = trim($message);

        if ($message === '') {
            return;
        }

        $last = $this->feedbackModel->latestAuthorRole($submissionId, 'instructor');

        if ($last && trim((string) $last['message']) === $message) {
            return;
        }

        $this->feedbackModel->insert([
            'submission_id' => $submissionId,
            'author_id' => $user->id,
            'author_role' => 'instructor',
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
