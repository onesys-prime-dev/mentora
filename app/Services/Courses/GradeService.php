<?php

namespace App\Services\Courses;

use App\Models\Courses\CourseOfferingModel;
use App\Models\Courses\EnrollmentModel;
use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\GradebookRecordModel;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Exceptions\PageNotFoundException;

class GradeService
{
    protected CourseOfferingModel $courseOfferingModel;
    protected EnrollmentModel $enrollmentModel;
    protected AssessmentModel $assessmentModel;
    protected GradebookRecordModel $gradebookRecordModel;

    public function __construct()
    {
        $this->courseOfferingModel = new CourseOfferingModel();
        $this->enrollmentModel = new EnrollmentModel();
        $this->assessmentModel = new AssessmentModel();
        $this->gradebookRecordModel = new GradebookRecordModel();
    }

    public function show(
        User $user,
        int $offeringId
    ): array {

        $offering = $this->courseOfferingModel->find($offeringId);

        if ($offering === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $viewData = ['user' => $user];

        if ($user->inGroup('instructor')) {

            $viewData += $this->buildInstructorGradebook($offering);

        } else {

            $assessments = $this->assessmentModel
                ->getAssessmentsWithSubmissions($offering['course_id'], $user->id);

            $graded = array_filter(
                $assessments,
                static fn (array $a) => ($a['submission_status'] ?? null) === 'Graded'
            );

            $pointsEarned = array_sum(array_column($graded, 'submission_score'));
            $pointsPossible = array_sum(array_column($graded, 'total_points'));

            $record = $this->gradebookRecordModel
                ->where('course_id', $offering['course_id'])
                ->where('student_id', $user->id)
                ->first();

            $viewData['assessments'] = $assessments;

            $viewData['summary'] = [
                'overall_percentage' => $record['is_override_final_grade'] ?? false
                    ? $record['overridden_final_grade']
                    : ($record['calculated_final_grade'] ?? null),
                'points_earned'   => $pointsEarned,
                'points_possible' => $pointsPossible,
                'remarks'         => $record['remarks'] ?? null,
            ];

        }

        return [
            'html' => view(
                'pages/courses/features/grades/index',
                $viewData
            ),
        ];

    }

    /**
     * Assembles the instructor Grades page: assessments grouped by type
     * (each one a clickable item that opens Grade Work for it), plus the
     * three summary numbers above the list.
     *
     * This used to build a roster x assessment grid instead — that traded
     * away the one thing an instructor actually does on this page, which
     * is open a specific assessment and grade it, for a spreadsheet view
     * of scores that already exist. Grading happens per assessment, so
     * the page now lists assessments; per-student review happens inside
     * Grade Work, which already has its own student sidebar.
     */
    protected function buildInstructorGradebook(array $offering): array
    {
        $courseId = $offering['course_id'];

        $assessments = $this->assessmentModel
            ->getAssessmentSummaryForCourse($courseId);

        $totalStudents = $this->enrollmentModel
            ->retrieveGradebookRoster($offering['id']);

        $totalStudents = count($totalStudents);

        $groups = [];
        $needsReview = 0;

        foreach ($assessments as $assessment) {

            $type = $assessment['assessment_type'] ?? 'Other';

            $groups[$type][] = $assessment;

            $needsReview += (int) $assessment['needs_review_count'];

        }

        $records = $totalStudents > 0
            ? $this->gradebookRecordModel
                ->where('course_id', $courseId)
                ->findAll()
            : [];

        $percentages = [];
        $passingCount = 0;

        foreach ($records as $record) {

            if ($record['remarks'] === 'Passed') {
                $passingCount++;
            }

            if ($record['remarks'] !== 'Incomplete') {

                $percentages[] = (float) (
                    $record['is_override_final_grade']
                        ? $record['overridden_final_grade']
                        : $record['calculated_final_grade']
                );

            }

        }

        return [
            'assessmentGroups' => $groups,
            'classSummary'     => [
                'average_percentage' => $percentages !== []
                    ? array_sum($percentages) / count($percentages)
                    : null,
                'passing_count'  => $passingCount,
                'total_students' => $totalStudents,
                'needs_review'   => $needsReview,
            ],
        ];
    }
}
