<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Exceptions\PageNotFoundException;
use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentSubmissionModel;
use App\Models\Assessments\AssessmentQuestionModel;
use App\Models\Assessments\AssessmentQuestionResponseModel;
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

    public function startAttempt(User $user, int $assessmentId): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            throw PageNotFoundException::forPageNotFound('Assessment not found.');
        }

        // A quiz behind an incomplete prerequisite (Modules lock) can't be
        // started from the Assessments tab either.
        $lock = !empty($assessment['learning_item_id'])
            ? (new LearningProgressService())->lockFor($user, (int) $assessment['learning_item_id'])
            : null;

        $submission = $lock ? null : $this->submissionModel
            ->where('assessment_id', $assessmentId)
            ->where('student_id', $user->id)
            ->where('status', 'In Progress')
            ->first();

        // Students can only open quizzes they're allowed to see, and can
        // only start a new attempt while the quiz is open (an attempt that's
        // already running can still be resumed).
        $unavailable = null;

        if (!$user->inGroup('instructor')) {

            $assessments = new AssessmentService();

            if (!$assessments->isVisibleToStudent($assessment)) {
                throw PageNotFoundException::forPageNotFound('Assessment not found.');
            }

            $unavailable = $submission || $lock ? null : $assessments->quizAvailabilityReason($assessment);

        }

        $blocked = $lock !== null || $unavailable !== null;

        if (!$submission && !$blocked) {

            $attemptsTaken = $this->submissionModel
                ->where('assessment_id', $assessmentId)
                ->where('student_id', $user->id)
                ->where('status !=', 'In Progress')
                ->countAllResults();

            $attemptLimit = (int) ($assessment['attempt_limit'] ?? 1);

            if ($attemptsTaken >= $attemptLimit) {

                $blocked = true;

            } else {

                $this->submissionModel->insert([
                    'assessment_id' => $assessmentId,
                    'student_id' => $user->id,
                    'attempt_number' => $attemptsTaken + 1,
                    'started_at' => date('Y-m-d H:i:s'),
                    'status' => 'In Progress',
                ]);

                $submission = $this->submissionModel->find(
                    $this->submissionModel->getInsertID()
                );

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
                ]
            ),
        ];
    }

    public function saveAnswer(User $user, int $submissionId, int $questionId, array $data): array
    {
        $submission = $this->submissionModel->find($submissionId);

        if (!$submission || (int) $submission['student_id'] !== (int) $user->id) {
            return ['success' => false, 'message' => 'Submission not found.'];
        }

        if ($submission['status'] !== 'In Progress') {
            return ['success' => false, 'message' => 'This attempt is no longer in progress.'];
        }

        $question = $this->questionModel->find($questionId);

        if (!$question) {
            return ['success' => false, 'message' => 'Question not found.'];
        }

        $existing = $this->responseModel
            ->where('submission_id', $submissionId)
            ->where('question_id', $questionId)
            ->first();

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

        if ($existing) {
            $this->responseModel->update($existing['id'], $responseData);
        } else {
            $responseData['created_at'] = date('Y-m-d H:i:s');
            $this->responseModel->insert($responseData);
        }

        return ['success' => true, 'message' => 'Answer saved.'];
    }

    public function submitAttempt(User $user, int $submissionId): array
    {
        $submission = $this->submissionModel->find($submissionId);

        if (!$submission || (int) $submission['student_id'] !== (int) $user->id) {
            return ['success' => false, 'message' => 'Submission not found.'];
        }

        if ($submission['status'] !== 'In Progress') {
            return ['success' => false, 'message' => 'This attempt has already been submitted.'];
        }

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

            if ($question['question_type'] === 'Short Answer' && $shortAnswers->isAutoGradable($question)) {

                $isCorrect = $response && $shortAnswers->isCorrect($response['response_text'] ?? '', $question);

            } elseif ($question['question_type'] !== 'Multiple Choice') {

                $manualCount++;

                continue;

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

            $autoGradedCount++;

            $awarded = $isCorrect ? (float) $question['points'] : 0;

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

        // Fully auto-graded → it counts now (no-op while partly/un-graded).
        (new GradebookSyncService())->syncItemScore((int) $submission['assessment_id'], (int) $user->id);

        return [
            'success' => true,
            'message' => 'Quiz submitted successfully.',
            'submission' => $this->submissionModel->find($submissionId),
        ];
    }
}
