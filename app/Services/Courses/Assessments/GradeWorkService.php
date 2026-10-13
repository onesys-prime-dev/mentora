<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Exceptions\PageNotFoundException;
use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\AssessmentSubmissionModel;
use App\Models\Assessments\AssessmentRubricModel;
use App\Models\Assessments\AssessmentQuestionModel;
use App\Models\Assessments\AssessmentQuestionResponseModel;
use App\Models\Assessments\AssessmentTypeModel;

use App\Models\Courses\EnrollmentModel;
use App\Models\Files\FileModel;
use App\Models\Assessments\AssessmentFeedbackModel;
use App\Services\Courses\Grades\GradebookSyncService;

class GradeWorkService
{
    protected AssessmentModel $assessmentModel;
    protected AssessmentSubmissionModel $submissionModel;
    protected AssessmentRubricModel $rubricModel;
    protected AssessmentQuestionModel $questionModel;
    protected AssessmentQuestionResponseModel $responseModel;
    protected AssessmentTypeModel $assessmentTypeModel;
    protected AssessmentFeedbackModel $feedbackModel;

    protected EnrollmentModel $enrollmentModel;
    protected FileModel $fileModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->submissionModel = new AssessmentSubmissionModel();
        $this->rubricModel = new AssessmentRubricModel();
        $this->questionModel = new AssessmentQuestionModel();
        $this->responseModel = new AssessmentQuestionResponseModel();
        $this->assessmentTypeModel = new AssessmentTypeModel();
        $this->feedbackModel = new AssessmentFeedbackModel();

        $this->enrollmentModel = new EnrollmentModel();
        $this->fileModel = new FileModel();
    }

    public function gradeWork(User $user, int $offeringId, int $assessmentId, ?int $studentId = null, ?int $attemptId = null): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            throw PageNotFoundException::forPageNotFound('Assessment not found.');
        }

        $isQuiz = $this->isQuizAssessment($assessment);

        $rubric = $this->rubricModel->findByAssessment($assessmentId);

        $currentRubric = $rubric
            ? $this->rubricModel->getWithCriteria($rubric['id'])
            : null;

        $students = $this->listStudentsForAssessment($assessment, $offeringId);

        /*
        |--------------------------------------------------------------------------
        | Each student's recorded result (AssessmentResultService): the best
        | quiz attempt / latest graded submission, plus what still needs
        | grading. The list shows that result; opening a student lands on the
        | attempt that needs grading, otherwise the one that counts.
        |--------------------------------------------------------------------------
        */

        $results = new AssessmentResultService();

        $attemptsByStudent = (new AssessmentOverviewService())->latestAttempts(
            [$assessmentId],
            array_map(static fn (array $row) => (int) $row['student_id'], $students)
        )[$assessmentId] ?? [];

        foreach ($students as &$student) {

            $all = $attemptsByStudent[(int) $student['student_id']]['attempts'] ?? [];

            $result = $results->resultFor($isQuiz, $all);

            $focus = $result['pending'][0] ?? $result['counted'] ?? $result['latest'];

            $student['result'] = $result;

            $student['all_attempts'] = $all;

            $student['submission_score'] = $result['score'];

            $student['is_graded'] = $result['counted'] !== null ? 1 : 0;

            // Waiting work sets the row's status; otherwise the counted grade.
            if ($focus !== null) {
                $student['submission_status'] = $focus['status'];
                $student['submission_id'] = $focus['id'];
            }

        }
        unset($student);

        $selectedStudentId = $studentId ?? ($students[0]['student_id'] ?? null);

        $selectedStudent = null;

        foreach ($students as $student) {

            if ((int) $student['student_id'] === (int) $selectedStudentId) {
                $selectedStudent = $student;
                break;
            }

        }

        $submission = null;

        $questions = [];

        $attemptTabs = [];

        if ($selectedStudent) {

            // ?attempt= only counts when it's one of this student's attempts here.
            $ownAttemptIds = array_map(static fn (array $a) => (int) $a['id'], $selectedStudent['all_attempts']);

            $submissionId = $attemptId !== null && in_array($attemptId, $ownAttemptIds, true)
                ? $attemptId
                : ($selectedStudent['submission_id'] ?? null);

            $countedId = (int) ($selectedStudent['result']['counted']['id'] ?? 0);

            $pendingIds = array_map(static fn (array $a) => (int) $a['id'], $selectedStudent['result']['pending']);

            foreach ($selectedStudent['result']['finished'] as $attempt) {
                $attemptTabs[] = [
                    'id' => (int) $attempt['id'],
                    'number' => (int) $attempt['attempt_number'],
                    'score' => !empty($attempt['is_graded']) ? (float) $attempt['score'] : null,
                    'counts' => (int) $attempt['id'] === $countedId,
                    'needsGrading' => in_array((int) $attempt['id'], $pendingIds, true),
                    'active' => (int) $attempt['id'] === (int) $submissionId,
                ];
            }

            $submission = $submissionId ? $this->submissionModel->find($submissionId) : null;

            if ($submission) {

                $submission['rubric_scores'] = $submission['rubric_scores']
                    ? (json_decode($submission['rubric_scores'], true) ?? [])
                    : [];

                if ($isQuiz) {

                    $questions = $this->buildQuestionResponses(
                        $assessmentId,
                        $submission['id']
                    );

                }

                $submission['file'] = !empty($submission['submission_file_id'])
                    ? $this->fileModel->retrieveFile((int) $submission['submission_file_id'])
                    : null;

                $submission['feedback_thread'] = $this->feedbackModel->listForSubmission($submission['id']);

            }

        }

        // What auto-grading already scored on this attempt, and what's left
        // for the instructor (Essay, File Upload, Short Answer with no key).
        $autoSummary = null;

        if ($isQuiz && $questions !== []) {

            $shortAnswers = new ShortAnswerGrader();

            $autoSummary = ['earned' => 0.0, 'possible' => 0.0, 'manual' => 0];

            foreach ($questions as &$question) {

                $question['auto_gradable'] = $question['question_type'] === 'Multiple Choice'
                    || $shortAnswers->isAutoGradable($question);

                if ($question['auto_gradable']) {
                    $autoSummary['possible'] += (float) $question['points'];
                    $autoSummary['earned'] += (float) ($question['response']['score'] ?? 0);
                } else {
                    $autoSummary['manual']++;
                }

            }
            unset($question);

        }

        return [
            'html' => view(
                'pages/courses/features/assessments/grade-work',
                [
                    'user' => $user,
                    'isAssignment' => !$isQuiz,
                    'autoSummary' => $autoSummary,
                    'currentAssessment' => $assessment,
                    'currentRubric' => $currentRubric,
                    'students' => $students,
                    'selectedStudentId' => $selectedStudentId,
                    'selectedStudent' => $selectedStudent,
                    'submission' => $submission,
                    'attemptTabs' => count($attemptTabs) > 1 ? $attemptTabs : [],
                    'scoreResult' => $selectedStudent['result'] ?? null,
                    'questionResponses' => $questions,
                ]
            ),
        ];
    }

    /**
     * Reopens a graded submission so its grade can be changed. It goes back
     * to Grading and stops counting as graded (the student sees "Being
     * graded" with no score); the score and rubric values are kept so the
     * grading form opens pre-filled. Feedback is a thread now, not a field
     * on the submission — prior messages stay visible regardless, and the
     * feedback textarea opens empty, ready for a new message if the
     * regrade warrants one.
     */
    public function reopenGrading(User $user, int $submissionId): array
    {
        $submission = $this->submissionModel->find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'Submission not found.'];
        }

        if ($submission['status'] !== 'Graded') {
            return ['success' => false, 'message' => 'Only graded work can be reopened.'];
        }

        $this->submissionModel->update($submissionId, [
            'status' => 'Grading',
            'is_graded' => 0,
        ]);

        // Another attempt may count now (or none) — keep the gradebook in step.
        (new GradebookSyncService())->syncItemScore((int) $submission['assessment_id'], (int) $submission['student_id']);

        return [
            'success' => true,
            'message' => 'Grading reopened.',
            'submission' => $this->submissionModel->find($submissionId),
        ];
    }

        public function startGrading(User $user, int $submissionId): array
    {
        $submission = $this->submissionModel->find($submissionId);

        if (!$submission) {
            return [
                'success' => false,
                'message' => 'Submission not found.'
            ];
        }

        if (!$this->wasHandedIn($submission)) {
            return $this->notHandedIn();
        }


        // Opening work to grade — including a quiz whose auto-gradable part
        // is already scored — moves it to Grading.
        if (in_array($submission['status'], ['Submitted', 'Partially Graded'], true)) {

            $this->submissionModel->update(
                $submissionId,
                ['status' => 'Grading']
            );

        }

        return [
            'success' => true,
            'submission' => $this->submissionModel->find($submissionId),
        ];
    }

        public function submitGrade(User $user, int $submissionId, array $data): array
    {
        $submission = $this->submissionModel->find($submissionId);

        if (!$submission) {
            return [
                'success' => false,
                'message' => 'Submission not found.'
            ];
        }

        if (!$this->wasHandedIn($submission)) {
            return $this->notHandedIn();
        }


        $assessment = $this->assessmentModel->find($submission['assessment_id']);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.'
            ];
        }

        $totalPoints = (float) $assessment['total_points'];

        // Quizzes are graded per-question now (gradeQuestion()); the overall
        // score here is just the sum of those, not something the instructor
        // types in — there's no Default/Rubric choice for a quiz.
        if ($this->isQuizAssessment($assessment)) {

            [$score, $percentage] = $this->recomputeSubmissionTotal($submissionId, $totalPoints);

            $this->submissionModel->update($submissionId, [
                'score' => $score,
                'percentage' => $percentage,
                'is_graded' => true,
                'graded_by' => $user->id,
                'graded_at' => date('Y-m-d H:i:s'),
                'status' => 'Graded',
            ]);

            if (array_key_exists('feedback', $data)) {
                (new AssessmentFeedbackService())->appendInstructorMessage($user, $submissionId, (string) $data['feedback']);
            }

            (new GradebookSyncService())->syncItemScore((int) $submission['assessment_id'], (int) $submission['student_id']);

            return [
                'success' => true,
                'message' => 'Grade submitted successfully.',
                'submission' => $this->submissionModel->find($submissionId),
            ];

        }

        $gradingMode = ($data['grading_mode'] ?? 'Default') === 'Rubric' ? 'Rubric' : 'Default';

        $rubricScores = [];

        $score = 0.0;

        if ($gradingMode === 'Rubric') {

            $rubric = $this->rubricModel->findByAssessment((int) $assessment['id']);

            if (!$rubric) {
                return [
                    'success' => false,
                    'message' => 'No rubric is linked to this assessment.'
                ];
            }

            $rubricWithCriteria = $this->rubricModel->getWithCriteria($rubric['id']);

            $submittedScores = $data['rubric_scores'] ?? [];

            foreach (($rubricWithCriteria['criteria'] ?? []) as $criterion) {

                $awarded = (float) ($submittedScores[$criterion['id']] ?? 0);

                $maxPoints = (float) $criterion['max_points'];

                $awarded = max(0, min($awarded, $maxPoints));

                $rubricScores[$criterion['id']] = $awarded;

                $score += $awarded;

            }

        } else {

            $score = (float) ($data['score'] ?? 0);

            $score = max(0, min($score, $totalPoints));

        }

        $percentage = $totalPoints > 0
            ? round(($score / $totalPoints) * 100, 2)
            : 0;

        $this->submissionModel->update(
            $submissionId,
            [
                'score' => $score,
                'percentage' => $percentage,
                'grading_mode' => $gradingMode,
                'rubric_scores' => $gradingMode === 'Rubric'
                    ? json_encode($rubricScores)
                    : null,
                'is_graded' => true,
                'graded_by' => $user->id,
                'graded_at' => date('Y-m-d H:i:s'),
                'status' => 'Graded',
            ]
        );

        if (array_key_exists('feedback', $data)) {
            (new AssessmentFeedbackService())->appendInstructorMessage($user, $submissionId, (string) $data['feedback']);
        }

        // Mirror the counted score into the gradebook (and the final grade).
        (new GradebookSyncService())->syncItemScore((int) $submission['assessment_id'], (int) $submission['student_id']);

        return [
            'success' => true,
            'message' => 'Grade submitted successfully.',
            'submission' => $this->submissionModel->find($submissionId),
        ];
    }

    /**
     * Grades (or reverts) one question's response within a quiz submission.
     * Scoped by submission_id + question_id so a stray id can't touch
     * another student's work. Keeps the submission's own score/percentage
     * and the gradebook in step immediately, rather than waiting for
     * "Submit Grade".
     */
    public function gradeQuestion(User $user, int $submissionId, int $questionId, array $data): array
    {
                $submission = $this->submissionModel->find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'Submission not found.'];
        }

        if (!$this->wasHandedIn($submission)) {
            return $this->notHandedIn();
        }

        $assessment = $this->assessmentModel->find($submission['assessment_id']);

        if (!$assessment || !$this->isQuizAssessment($assessment)) {
            return ['success' => false, 'message' => 'Per-question grading only applies to quizzes.'];
        }

        $question = $this->questionModel->getWithChoices($questionId);

        if (!$question || (int) $question['assessment_id'] !== (int) $assessment['id']) {
            return ['success' => false, 'message' => 'Question not found.'];
        }

        $response = $this->responseModel
            ->where('submission_id', $submissionId)
            ->where('question_id', $questionId)
            ->first();

        if (!$response) {
            return ['success' => false, 'message' => 'No response from this student to grade.'];
        }

        $quizAttempts = new QuizAttemptService();

        $autoGradable = $quizAttempts->isAutoGradable($question);

        $mode = $data['mode'] ?? null;

        $update = ['graded_at' => date('Y-m-d H:i:s')];

        switch ($mode) {

            case 'auto':

                if (!$autoGradable) {
                    return ['success' => false, 'message' => 'This question has no auto-grade to revert to.'];
                }

                $update['score'] = $quizAttempts->computeAutoScore($question, $response);
                $update['is_auto_graded'] = true;

                break;

            case 'correct':
            case 'incorrect':

                if (!$autoGradable) {
                    return ['success' => false, 'message' => 'Correct/Incorrect only applies to auto-gradable questions — use a points score instead.'];
                }

                $update['score'] = $mode === 'correct' ? (float) $question['points'] : 0.0;
                $update['is_auto_graded'] = false;

                break;

            case 'points':

                if ($autoGradable) {
                    return ['success' => false, 'message' => 'This question is auto-graded — use Correct/Incorrect instead.'];
                }

                $points = (float) ($data['points'] ?? -1);

                if ($points < 0 || $points > (float) $question['points']) {
                    return ['success' => false, 'message' => 'Points must be between 0 and ' . rtrim(rtrim(number_format((float) $question['points'], 2), '0'), '.') . '.'];
                }

                $update['score'] = $points;
                $update['is_auto_graded'] = false;

                break;

            default:
                return ['success' => false, 'message' => 'Unknown grading mode.'];

        }

        $this->responseModel->update($response['id'], $update);

        [$score, $percentage] = $this->recomputeSubmissionTotal($submissionId, (float) $assessment['total_points']);

        $this->submissionModel->update($submissionId, [
            'score' => $score,
            'percentage' => $percentage,
        ]);

        (new GradebookSyncService())->syncItemScore((int) $submission['assessment_id'], (int) $submission['student_id']);

        return [
            'success' => true,
            'response' => $this->responseModel->find($response['id']),
            'submission' => $this->submissionModel->find($submissionId),
        ];
    }

    /** @return array{0: float, 1: float} [score, percentage] */
    private function recomputeSubmissionTotal(int $submissionId, float $totalPoints): array
    {
        $score = (float) ($this->responseModel
            ->selectSum('score')
            ->where('submission_id', $submissionId)
            ->get()
            ->getRow()
            ->score ?? 0);

        $percentage = $totalPoints > 0 ? round(($score / $totalPoints) * 100, 2) : 0.0;

        return [$score, $percentage];
    }

        /** Only work the student handed in can be graded (not a draft attempt, not a cancelled one). */
    private function wasHandedIn(array $submission): bool
    {
        return in_array($submission['status'], AssessmentResultService::FINISHED_STATUSES, true);
    }

    private function notHandedIn(): array
    {
        return ['success' => false, 'message' => 'This work has not been handed in yet.'];
    }

    private function isQuizAssessment(array $assessment): bool
    {
        $type = $this->assessmentTypeModel->find($assessment['assessment_type_id']);

        return strtolower($type['name'] ?? '') === 'quiz';
    }

    private function buildQuestionResponses(int $assessmentId, int $submissionId): array
    {
        $questions = $this->questionModel->listForAssessment($assessmentId);

        $responses = $this->responseModel
            ->where('submission_id', $submissionId)
            ->findAll();

        $responsesByQuestion = [];

        foreach ($responses as $response) {

            $response['selected_choice_ids'] = $response['selected_choice_ids']
                ? (json_decode($response['selected_choice_ids'], true) ?? [])
                : [];

            $responsesByQuestion[$response['question_id']] = $response;

        }

        foreach ($questions as &$question) {
            $question['response'] = $responsesByQuestion[$question['id']] ?? null;
        }

        return $questions;
    }

    private function listStudentsForAssessment(array $assessment, int $offeringId): array
    {
        /*
        |--------------------------------------------------------------------------
        | Students of this section (offering) only. An assessment belongs to
        | the course, which can have several sections; the instructor grades
        | the one they're viewing — the same scope as the Assessments tab's
        | To Grade / Ready for Grading numbers.
        |--------------------------------------------------------------------------
        */

        $builder = $this->enrollmentModel->builder();

        $builder->select([
            'enrollments.student_user_id AS student_id',
            "CONCAT(user_profiles.first_name, ' ', user_profiles.last_name) AS student_name",
            'assessment_submissions.id AS submission_id',
            'assessment_submissions.status AS submission_status',
            'assessment_submissions.score AS submission_score',
            'assessment_submissions.is_graded AS is_graded',
            'assessment_submissions.submitted_at AS submitted_at',
        ]);

        $builder->join(
            'course_offerings',
            'course_offerings.id = enrollments.offering_id'
        );

        $builder->join(
            'user_profiles',
            'user_profiles.user_id = enrollments.student_user_id'
        );

        // One row per student: their latest submitted attempt, or (if they
        // have nothing submitted yet) their latest attempt of any status.
        // Joining every attempt and relying on GROUP BY picked an arbitrary
        // one, which breaks once students can resubmit.
        $assessmentId = (int) $assessment['id'];

        $builder->join(
            'assessment_submissions',
            "assessment_submissions.id = COALESCE(
                (SELECT MAX(finished.id) FROM assessment_submissions finished
                  WHERE finished.student_id = enrollments.student_user_id
                    AND finished.assessment_id = {$assessmentId}
                    AND finished.status <> 'In Progress'),
                (SELECT MAX(anyattempt.id) FROM assessment_submissions anyattempt
                  WHERE anyattempt.student_id = enrollments.student_user_id
                    AND anyattempt.assessment_id = {$assessmentId})
            )",
            'left',
            false
        );

        $builder->where('course_offerings.course_id', $assessment['course_id']);

        $builder->where('enrollments.offering_id', $offeringId);

        $builder->whereIn('enrollments.status', ['enrolled', 'completed']);


        $builder->orderBy('student_name', 'ASC');

        return $builder->get()->getResultArray();
    }
}
