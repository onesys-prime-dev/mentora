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

class GradeWorkService
{
    protected AssessmentModel $assessmentModel;
    protected AssessmentSubmissionModel $submissionModel;
    protected AssessmentRubricModel $rubricModel;
    protected AssessmentQuestionModel $questionModel;
    protected AssessmentQuestionResponseModel $responseModel;
    protected AssessmentTypeModel $assessmentTypeModel;

    protected EnrollmentModel $enrollmentModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->submissionModel = new AssessmentSubmissionModel();
        $this->rubricModel = new AssessmentRubricModel();
        $this->questionModel = new AssessmentQuestionModel();
        $this->responseModel = new AssessmentQuestionResponseModel();
        $this->assessmentTypeModel = new AssessmentTypeModel();

        $this->enrollmentModel = new EnrollmentModel();
    }

    public function gradeWork(User $user, int $assessmentId, ?int $studentId = null): array
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment) {
            throw PageNotFoundException::forPageNotFound('Assessment not found.');
        }

        $rubric = $this->rubricModel->findByAssessment($assessmentId);

        $currentRubric = $rubric
            ? $this->rubricModel->getWithCriteria($rubric['id'])
            : null;

        $students = $this->listStudentsForAssessment($assessment);

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

        if ($selectedStudent && $selectedStudent['submission_id']) {

            $submission = $this->submissionModel->find($selectedStudent['submission_id']);

            if ($submission) {

                $submission['rubric_scores'] = $submission['rubric_scores']
                    ? (json_decode($submission['rubric_scores'], true) ?? [])
                    : [];

                if ($this->isQuizAssessment($assessment)) {

                    $questions = $this->buildQuestionResponses(
                        $assessmentId,
                        $submission['id']
                    );

                }

            }

        }

        return [
            'html' => view(
                'pages/courses/features/assessments/grade-work',
                [
                    'user' => $user,
                    'currentAssessment' => $assessment,
                    'currentRubric' => $currentRubric,
                    'students' => $students,
                    'selectedStudentId' => $selectedStudentId,
                    'selectedStudent' => $selectedStudent,
                    'submission' => $submission,
                    'questionResponses' => $questions,
                ]
            ),
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

        if ($submission['status'] === 'Submitted') {

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

        $assessment = $this->assessmentModel->find($submission['assessment_id']);

        if (!$assessment) {
            return [
                'success' => false,
                'message' => 'Assessment not found.'
            ];
        }

        $totalPoints = (float) $assessment['total_points'];

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
                'feedback' => $data['feedback'] ?? $submission['feedback'],
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

        return [
            'success' => true,
            'message' => 'Grade submitted successfully.',
            'submission' => $this->submissionModel->find($submissionId),
        ];
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
            $responsesByQuestion[$response['question_id']] = $response;
        }

        foreach ($questions as &$question) {
            $question['response'] = $responsesByQuestion[$question['id']] ?? null;
        }

        return $questions;
    }

    private function listStudentsForAssessment(array $assessment): array
    {
        /*
        |--------------------------------------------------------------------------
        | Scope by course_id (always present) rather than module_id, which is
        | nullable and can be cleared if its module is later deleted.
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

        $builder->join(
            'assessment_submissions',
            'assessment_submissions.student_id = enrollments.student_user_id'
                . ' AND assessment_submissions.assessment_id = ' . (int) $assessment['id'],
            'left'
        );

        $builder->where('course_offerings.course_id', $assessment['course_id']);

        $builder->where('enrollments.status', 'enrolled');

        $builder->groupBy('enrollments.student_user_id');

        $builder->orderBy('student_name', 'ASC');

        return $builder->get()->getResultArray();
    }
}
