<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentSubmissionModel;
use App\Models\Assessments\AssessmentQuestionModel;
use App\Models\Assessments\AssessmentQuestionResponseModel;
use App\Services\Admin\Impersonation;
use App\Services\Courses\CourseMembership;
use App\Services\Courses\LearningProgressService;
use App\Services\Courses\Grades\GradebookSyncService;

class QuizAttemptService
{
    protected AssessmentModel $assessmentModel;
    protected AssessmentSubmissionModel $submissionModel;
    protected AssessmentQuestionModel $questionModel;
    protected AssessmentQuestionResponseModel $responseModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->submissionModel = new AssessmentSubmissionModel();
        $this->questionModel = new AssessmentQuestionModel();
        $this->responseModel = new AssessmentQuestionResponseModel();
    }

    /**
     * What the quiz's landing shows someone viewing as this person: who they are, the attempts used of the
     * limit, and the finished attempts.
     *
     * @param array<string, mixed> $assessment
     *
     * @return array{name: string, used: int, limit: int, attempts: list<array<string, mixed>>} (the caller adds `teaches`)
     */
    private function viewAsLanding(User $user, array $assessment): array
    {
        $profile = db_connect()->table('user_profiles')->select('first_name, last_name')->where('user_id', $user->id)->get()->getRowArray();

        $attempts = $this->submissionModel
            ->select('attempt_number, submitted_at, score, percentage, status')
            ->where('assessment_id', $assessment['id'])
            ->where('student_id', $user->id)
            ->where('status !=', 'In Progress')
            ->orderBy('attempt_number')
            ->findAll();

        return [
            'name'     => $profile === null ? 'this person' : trim($profile['first_name'] . ' ' . $profile['last_name']),
            'used'     => count($attempts),
            'limit'    => (int) ($assessment['attempt_limit'] ?? 1),
            'attempts' => $attempts,
        ];
    }

    /** This person's attempt at the quiz that is still running, if any. */
    protected function inProgressAttempt(int $assessmentId, int $userId): ?array
    {
        return $this->submissionModel
            ->where('assessment_id', $assessmentId)
            ->where('student_id', $userId)
            ->where('status', 'In Progress')
            ->first();
    }

    /** Locks the attempt row for the transaction; true while it is still in progress. */
    private function lockInProgress(int $submissionId): bool
    {
        $row = db_connect()->query(
            'SELECT status FROM ' . db_connect()->prefixTable('assessment_submissions') . ' WHERE id = ? FOR UPDATE',
            [$submissionId]
        )->getRowArray();

        return ($row['status'] ?? null) === 'In Progress';
    }

    /** The attempt as it is now. */
    protected function submissionRow(int $submissionId): ?array
    {
        return $this->submissionModel->find($submissionId);
    }

    /** Seconds past the timer a late save or submit is still accepted (the browser's own delay). */
    public const TIME_LIMIT_GRACE_SECONDS = 60;

    /** What the student is told when the database refuses the write. */
    public const START_FAILED = 'The quiz could not be started. Reload the page to try again.';

    public const ANSWER_NOT_SAVED = 'Your answer could not be saved. Try again.';

    public const NOT_SUBMITTED = 'The quiz could not be submitted. Your answers are kept; try submitting again.';

    public function startAttempt(User $user, int $assessmentId, ?int $offeringId = null): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            throw PageNotFoundException::forPageNotFound('Assessment not found.');
        }

        // The quiz must belong to the course it's opened from, and the person
        // must be in that course: what they are there decides the rules (9.1).
        $assessmentOffering = $this->assessmentModel->offeringIdOf($assessmentId);
        $role = $assessmentOffering === null ? null : (new CourseMembership())->roleIn($user, $assessmentOffering);
        $teaches = in_array($role, ['instructor', 'chairperson'], true);

        if (
            $assessmentOffering === null
            || ($offeringId !== null && $assessmentOffering !== $offeringId)
            || $role === null
        ) {
            throw PageNotFoundException::forPageNotFound('Assessment not found.');
        }

        // A quiz behind an incomplete prerequisite (Modules lock) can't be
        // started from the Assessments tab either.
        $lock = !empty($assessment['learning_item_id'])
            ? (new LearningProgressService())->lockFor($user, (int) $assessment['learning_item_id'])
            : null;

        $submission = $lock ? null : $this->inProgressAttempt($assessmentId, (int) $user->id);

        // Students can only open quizzes they're allowed to see, and can
        // only start a new attempt while the quiz is open (an attempt that's
        // already running can still be resumed).
        $unavailable = null;

        if (!$teaches) {

            $assessments = new AssessmentService();

            if (!$assessments->isVisibleToStudent($assessment)) {
                throw PageNotFoundException::forPageNotFound('Assessment not found.');
            }

            $unavailable = $submission || $lock ? null : $assessments->quizAvailabilityReason($assessment);

        }

        $blocked = $lock !== null || $unavailable !== null;

        $startFailed = false;

        // Viewing as this person saves nothing for them (3.2): no attempt is started, and the page shows the
        // quiz's landing, or the attempt already running, read-only (3.1).
        $viewAs = Impersonation::current() === null ? null : $this->viewAsLanding($user, $assessment) + ['teaches' => $teaches];

        if (!$submission && !$blocked && $viewAs === null) {

            $attemptsTaken = $this->submissionModel
                ->where('assessment_id', $assessmentId)
                ->where('student_id', $user->id)
                ->where('status !=', 'In Progress')
                ->countAllResults();

            $attemptLimit = (int) ($assessment['attempt_limit'] ?? 1);

            if ($attemptsTaken >= $attemptLimit) {

                $blocked = true;

            } else {

                // Another tab may start the same attempt at the same moment: the
                // unique (quiz, student, attempt number) key lets one in, and
                // this one resumes it (15.1).
                try {
                    $inserted = $this->submissionModel->insert([
                        'assessment_id' => $assessmentId,
                        'student_id' => $user->id,
                        'attempt_number' => $attemptsTaken + 1,
                        'started_at' => date('Y-m-d H:i:s'),
                        'status' => 'In Progress',
                    ]);
                } catch (DatabaseException) {
                    $inserted = false;
                }

                $submission = $inserted
                    ? $this->submissionModel->find($this->submissionModel->getInsertID())
                    : $this->inProgressAttempt($assessmentId, (int) $user->id);

                // Not the other tab's attempt either: the database refused it, and the page says so.
                $startFailed = ! $submission;

            }

        }

        $questions = [];
        $responsesByQuestion = [];
        $endsAt = null;

        if ($submission) {

            $questions = $this->questionModel->listForAssessment($assessmentId);

            // Answer keys never reach the student's page.
            foreach ($questions as &$question) {

                unset($question['accepted_answers'], $question['case_sensitive']);

                foreach ($question['choices'] as &$choice) {
                    unset($choice['is_correct']);
                }

            }
            unset($question, $choice);

            $responses = $this->responseModel
                ->where('submission_id', $submission['id'])
                ->findAll();

            foreach ($responses as $response) {

                $response['selected_choice_ids'] = $response['selected_choice_ids']
                    ? (json_decode($response['selected_choice_ids'], true) ?? [])
                    : [];

                $responsesByQuestion[$response['question_id']] = $response;

            }

            if (!empty($assessment['duration']) && !empty($submission['started_at'])) {

                $endsAt = date(
                    'c',
                    strtotime($submission['started_at']) + ((int) $assessment['duration'] * 60)
                );

            }

        }

        return [
            'html' => view(
                'pages/courses/features/assessments/quiz-answer-form',
                [
                    'user' => $user,
                    'currentAssessment' => $assessment,
                    'submission' => $submission,
                    'blocked' => $blocked,
                    'lock' => $lock,
                    'unavailableReason' => $unavailable,
                    'questions' => $questions,
                    'responsesByQuestion' => $responsesByQuestion,
                    'endsAt' => $endsAt,
                    'viewAs' => $viewAs,
                    'startFailed' => $startFailed,
                ]
            ),
        ];
    }

    public function saveAnswer(User $user, int $submissionId, int $questionId, array $data): array
    {
        $submission = $this->submissionRow($submissionId);

        if (!$submission || (int) $submission['student_id'] !== (int) $user->id) {
            return ['success' => false, 'message' => 'Submission not found.'];
        }

        if ($submission['status'] !== 'In Progress') {
            return ['success' => false, 'message' => 'This attempt is no longer in progress.'];
        }

        // The question must be one of this attempt's quiz.
        $question = $this->questionModel->find($questionId);

        if (
            !$question
            || (int) $question['assessment_id'] !== (int) $submission['assessment_id']
            || $question['status'] !== 'Active'
        ) {
            return ['success' => false, 'message' => 'Question not found.'];
        }

        // The timer is the browser's, but the server holds the line too: no
        // new answers once the time limit (plus a moment's grace) is over.
        // Submitting stays possible, so a late auto-submit keeps the work
        // that was saved in time.
        if ($this->timeIsUp($submission)) {
            return ['success' => false, 'message' => 'Time is up for this attempt.'];
        }

        $isMultipleChoice = $question['question_type'] === 'Multiple Choice';
        $isMultipleAnswer = $isMultipleChoice && !empty($question['allow_multiple_answers']);

        $responseData = [
            'submission_id' => $submissionId,
            'question_id' => $questionId,
            'selected_choice_id' => ($isMultipleChoice && !$isMultipleAnswer)
                ? ($data['selected_choice_id'] ?? null)
                : null,
            'selected_choice_ids' => $isMultipleAnswer
                ? json_encode(array_map('intval', $data['selected_choice_ids'] ?? []))
                : null,
            'response_text' => in_array($question['question_type'], ['Short Answer', 'Essay'], true)
                ? ($data['response_text'] ?? null)
                : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // One upsert on (attempt, question), under a lock on the attempt that
        // submitting takes too: the last save wins, and none lands once the
        // attempt is handed in (15.2).
        $db = db_connect();

        try {
            $db->transException(true)->transStart();

            if (! $this->lockInProgress($submissionId)) {
                $db->transComplete();

                return ['success' => false, 'message' => 'This attempt is no longer in progress.'];
            }

            $db->table('assessment_question_responses')
                ->setData($responseData + ['created_at' => date('Y-m-d H:i:s')])
                ->updateFields(['selected_choice_id', 'selected_choice_ids', 'response_text', 'updated_at'])
                ->upsert();

            $saved = $db->transComplete();
        } catch (DatabaseException) {
            $saved = false;
        } finally {
            $db->transException(false);
        }

        if (! $saved) {
            return ['success' => false, 'message' => self::ANSWER_NOT_SAVED];
        }

        return ['success' => true, 'message' => 'Answer saved.'];
    }

    /** True when the quiz has a time limit and this attempt has used it up. */
    private function timeIsUp(array $submission): bool
    {
        $assessment = $this->assessmentModel->find((int) $submission['assessment_id']);

        $minutes = (int) ($assessment['duration'] ?? 0);

        if ($minutes <= 0 || empty($submission['started_at'])) {
            return false;
        }

        return time() > strtotime($submission['started_at']) + $minutes * 60 + self::TIME_LIMIT_GRACE_SECONDS;
    }

    /**
     * Multiple Choice and Short Answer (with accepted answers) grade
     * themselves; Essay, File Upload and a Short Answer with no answer key
     * wait for the instructor.
     */
    public function isAutoGradable(array $question, ?ShortAnswerGrader $shortAnswers = null): bool
    {
        $shortAnswers ??= new ShortAnswerGrader();

        return $question['question_type'] === 'Multiple Choice'
            || ($question['question_type'] === 'Short Answer' && $shortAnswers->isAutoGradable($question));
    }

    /**
     * The auto-graded score for one question's response — used both at
     * submission time and whenever an instructor reverts a manual override
     * back to the auto-computed grade (GradeWorkService::gradeQuestion()),
     * so both paths agree by construction rather than by copy-paste.
     */
    public function computeAutoScore(array $question, ?array $response, ?ShortAnswerGrader $shortAnswers = null): float
    {
        $shortAnswers ??= new ShortAnswerGrader();

        if ($question['question_type'] === 'Short Answer') {

            $isCorrect = $response && $shortAnswers->isCorrect($response['response_text'] ?? '', $question);

        } elseif (!empty($question['allow_multiple_answers'])) {

            // Exact match required — the selected set must equal the
            // correct set precisely, no partial credit. Keeps grading
            // as unambiguous all-or-nothing as the single-choice case.
            $correctIds = array_values(array_map(
                static fn (array $choice) => (int) $choice['id'],
                array_filter($question['choices'], static fn (array $choice) => !empty($choice['is_correct']))
            ));

            $selectedIds = $response && $response['selected_choice_ids']
                ? (json_decode($response['selected_choice_ids'], true) ?? [])
                : [];

            $selectedIds = array_values(array_map('intval', $selectedIds));

            sort($correctIds);
            sort($selectedIds);

            $isCorrect = $response && !empty($correctIds) && $correctIds === $selectedIds;

        } else {

            $correctChoice = null;

            foreach ($question['choices'] as $choice) {
                if (!empty($choice['is_correct'])) {
                    $correctChoice = $choice;
                    break;
                }
            }

            $isCorrect = $response
                && $correctChoice
                && (int) $response['selected_choice_id'] === (int) $correctChoice['id'];

        }

        return $isCorrect ? (float) $question['points'] : 0.0;
    }

    public function submitAttempt(User $user, int $submissionId): array
    {
        $submission = $this->submissionRow($submissionId);

        if (!$submission || (int) $submission['student_id'] !== (int) $user->id) {
            return ['success' => false, 'message' => 'Submission not found.'];
        }

        if ($submission['status'] !== 'In Progress') {
            return ['success' => false, 'message' => 'This attempt has already been submitted.'];
        }

        // The same lock as a save: a save waiting on it is refused once this
        // commits, and a second submit (another tab) finds it handed in.
        $db = db_connect();

        try {
            $db->transException(true)->transStart();

            if (! $this->lockInProgress($submissionId)) {
                $db->transComplete();

                return ['success' => false, 'message' => 'This attempt has already been submitted.'];
            }

            $submitted = $this->handIn($submissionId, $submission, $db);
        } catch (DatabaseException) {
            $submitted = false;
        } finally {
            $db->transException(false);
        }

        // Rolled back: nothing counts, nothing completes, and the student can submit again.
        if (! $submitted) {
            return ['success' => false, 'message' => self::NOT_SUBMITTED];
        }

        // Fully auto-graded → it counts now (no-op while partly/un-graded).
        (new GradebookSyncService())->syncItemScore((int) $submission['assessment_id'], (int) $user->id);

        // Handing the quiz in completes its item in the module, whatever its grade state.
        (new LearningProgressService())->completeOnSubmission((int) $user->id, (int) $submission['assessment_id']);

        return [
            'success' => true,
            'message' => 'Quiz submitted successfully.',
            'submission' => $this->submissionModel->find($submissionId),
        ];
    }

    /**
     * Scores what grades itself and hands the attempt in, inside the caller's transaction.
     *
     * @return bool whether the transaction committed
     */
    private function handIn(int $submissionId, array $submission, \CodeIgniter\Database\BaseConnection $db): bool
    {
        $assessment = $this->assessmentModel->find($submission['assessment_id']);

        $questions = $this->questionModel->listForAssessment((int) $submission['assessment_id']);

        $responses = $this->responseModel
            ->where('submission_id', $submissionId)
            ->findAll();

        $responsesByQuestion = [];

        foreach ($responses as $response) {
            $responsesByQuestion[$response['question_id']] = $response;
        }

        $autoScore = 0;

        // Multiple Choice and Short Answer (with accepted answers) grade
        // themselves; Essay, File Upload and a Short Answer with no answer
        // key wait for the instructor.
        $shortAnswers = new ShortAnswerGrader();

        $autoGradedCount = 0;

        $manualCount = 0;

        foreach ($questions as $question) {

            $response = $responsesByQuestion[$question['id']] ?? null;

            if (!$this->isAutoGradable($question, $shortAnswers)) {
                $manualCount++;
                continue;
            }

            $awarded = $this->computeAutoScore($question, $response, $shortAnswers);

            $autoGradedCount++;

            if ($response) {

                $this->responseModel->update(
                    $response['id'],
                    [
                        'score' => $awarded,
                        'is_auto_graded' => true,
                        'graded_at' => date('Y-m-d H:i:s'),
                    ]
                );

            }

            $autoScore += $awarded;

        }

        $totalPoints = (float) ($assessment['total_points'] ?? 0);

        $percentage = $totalPoints > 0
            ? round(($autoScore / $totalPoints) * 100, 2)
            : 0;

        $isLate = !empty($assessment['due_date'])
            && strtotime(date('Y-m-d H:i:s')) > strtotime($assessment['due_date']);

        $submissionData = [
            'submitted_at' => date('Y-m-d H:i:s'),
            'score' => $autoScore,
            'percentage' => $percentage,
            'is_late_submission' => $isLate,
            // Everything scored → Graded. Some scored, some waiting →
            // Partially Graded (the auto score shows as "so far"). Nothing
            // scored → Submitted.
            'status' => match (true) {
                $manualCount === 0 => 'Graded',
                $autoGradedCount > 0 => 'Partially Graded',
                default => 'Submitted',
            },
        ];

        if ($manualCount === 0) {
            $submissionData['is_graded'] = true;
            $submissionData['graded_at'] = date('Y-m-d H:i:s');
        }

        $this->submissionModel->update($submissionId, $submissionData);

        return $db->transComplete();
    }
}
