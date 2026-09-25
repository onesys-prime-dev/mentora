<?php

namespace App\Services\Courses;

use App\Models\Courses\CourseOfferingModel;
use App\Models\Courses\EnrollmentModel;
use App\Models\Assessments\AssessmentModel;
use App\Models\Assessments\GradebookRecordModel;
use App\Services\Courses\Assessments\AssessmentOverviewService;

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

            $viewData += $this->buildStudentGrades($user, $offering);

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
            ->getAssessmentSummaryForCourse($courseId, (int) $offering['id']);

        $rosterIds = array_map('intval', array_column(
            $this->enrollmentModel->retrieveGradebookRoster($offering['id']),
            'student_id'
        ));

        $totalStudents = count($rosterIds);

        $groups = [];
        $needsReview = 0;

        foreach ($assessments as $assessment) {

            $type = $assessment['assessment_type'] ?? 'Other';

            $groups[$type][] = $assessment;

            $needsReview += (int) $assessment['needs_review_count'];

        }

        // gradebook_records is per course; only this section's students count.
        $records = $totalStudents > 0
            ? $this->gradebookRecordModel
                ->where('course_id', $courseId)
                ->whereIn('student_id', $rosterIds)
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

    /**
     * A student's Grades page: every assessment of this section they can
     * see, in the same state and counted score the Assessments tab shows
     * (AssessmentOverviewService — best graded quiz attempt, latest graded
     * assignment), plus a live summary over their graded work.
     *
     * gradebook_records isn't recalculated when work is graded, so the
     * headline is computed here; an instructor's override on that record
     * still wins.
     */
    protected function buildStudentGrades(User $user, array $offering): array
    {
        $overviewService = new AssessmentOverviewService();

        $overview = $overviewService->build($user, (int) $offering['id'], (int) $offering['course_id']);

        $now = time();

        $assessments = array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'assessment_type' => $row['assessment_type'] ?: 'Other',
            'due_label' => $row['due_ts'] !== null ? 'Due ' . $overviewService->dueLabel($row['due_ts'], $now) : 'No due date',
            'total_points' => (float) $row['total_points'],
            'state' => $row['student']['key'],
            'label' => $row['student']['label'],
            'tone' => $row['student']['tone'],
            'score' => $row['student']['score'],
            // A grade that counts — also true while a resubmission waits.
            'has_grade' => ($row['student']['result']['counted'] ?? null) !== null,
        ], $overview['assessments']);

        $graded = array_values(array_filter($assessments, static fn (array $a) => $a['has_grade']));

        $pointsEarned = array_sum(array_column($graded, 'score'));
        $pointsPossible = array_sum(array_column($graded, 'total_points'));

        $percentage = $pointsPossible > 0 ? round($pointsEarned / $pointsPossible * 100, 2) : null;

        $remarks = match (true) {
            $percentage === null => 'Incomplete',
            $percentage >= 75 => 'Passed',
            default => 'Failed',
        };

        $record = $this->gradebookRecordModel
            ->where('course_id', $offering['course_id'])
            ->where('student_id', $user->id)
            ->first();

        if (!empty($record['is_override_final_grade'])) {
            $percentage = (float) $record['overridden_final_grade'];
            $remarks = $record['remarks'];
        }

        return [
            'assessments' => $assessments,
            'summary' => [
                'overall_percentage' => $percentage,
                'points_earned' => $pointsEarned,
                'points_possible' => $pointsPossible,
                'remarks' => $remarks,
                'graded_count' => count($graded),
                'total_count' => count($assessments),
                'is_override' => !empty($record['is_override_final_grade']),
            ],
        ];
    }
}
