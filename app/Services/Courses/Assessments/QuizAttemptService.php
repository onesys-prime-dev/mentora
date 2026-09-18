<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Exceptions\PageNotFoundException;
use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentSubmissionModel;
use App\Models\Assessments\AssessmentQuestionModel;
use App\Models\Assessments\AssessmentQuestionResponseModel;

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

        $submission = $this->submissionModel
            ->where('assessment_id', $assessmentId)
            ->where('student_id', $user->id)
            ->where('status', 'In Progress')
            ->first();

        $blocked = false;

        if (!$submission) {

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

            foreach ($questions as &$question) {

                foreach ($question['choices'] as &$choice) {
                    unset($choice['is_correct']);
                }

            }
            unset($question, $choice);

            $responses = $this->responseModel
                ->where('submission_id', $submission['id'])
                ->findAll();

            foreach ($responses as $response) {
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

        $responseData = [
            'submission_id' => $submissionId,
            'question_id' => $questionId,
            'selected_choice_id' => $question['question_type'] === 'Multiple Choice'
                ? ($data['selected_choice_id'] ?? null)
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

        $allAutoGradable = true;

        foreach ($questions as $question) {

            if ($question['question_type'] !== 'Multiple Choice') {
                $allAutoGradable = false;
                continue;
            }

            $response = $responsesByQuestion[$question['id']] ?? null;

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
            'status' => $allAutoGradable ? 'Graded' : 'Submitted',
        ];

        if ($allAutoGradable) {
            $submissionData['is_graded'] = true;
            $submissionData['graded_at'] = date('Y-m-d H:i:s');
        }

        $this->submissionModel->update($submissionId, $submissionData);

        return [
            'success' => true,
            'message' => 'Quiz submitted successfully.',
            'submission' => $this->submissionModel->find($submissionId),
        ];
    }
}
