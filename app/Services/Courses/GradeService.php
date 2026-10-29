<?php

namespace App\Services\Courses;

use App\Models\Courses\CourseOfferingModel;
use App\Models\Courses\EnrollmentModel;
use App\Models\Assessments\GradebookRecordModel;
use App\Services\Courses\Assessments\AssessmentOverviewService;
use App\Services\Courses\Grades\GradingSchemeService;
use App\Services\Courses\Grades\GradingEngineService;
use App\Models\Grades\GradeScaleItemModel;

use CodeIgniter\Shield\Entities\User;

use CodeIgniter\Exceptions\PageNotFoundException;

class GradeService
{
    protected CourseOfferingModel $courseOfferingModel;
    protected EnrollmentModel $enrollmentModel;
    protected GradebookRecordModel $gradebookRecordModel;

    public function __construct()
    {
        $this->courseOfferingModel = new CourseOfferingModel();
        $this->enrollmentModel = new EnrollmentModel();
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

        if (\App\Services\Courses\CourseViewer::seesInstructorView($user)) {

            $viewData += $this->buildInstructorGradebook($user, $offering);

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
     *
     * "Needs review" is sourced from AssessmentOverviewService — the same
     * per-attempt/latest-submission computation the Assessments tab's "To
     * Grade" metric uses — instead of a separate raw submission-row count,
     * so the two tabs can never disagree on how much grading is left.
     */
    protected function buildInstructorGradebook(User $user, array $offering): array
    {
        // Resolves (and on first use creates) the section's grading scheme.
        $gradebook = (new GradingSchemeService())->describe((int) $offering['id']);

        $overview = (new AssessmentOverviewService())
            ->build($user, (int) $offering['id'], (int) $offering['course_id']);

        $rosterIds = array_map('intval', array_column(
            $this->enrollmentModel->retrieveGradebookRoster($offering['id']),
            'student_id'
        ));

        $totalStudents = count($rosterIds);

        $groups = [];
        $needsReview = 0;

        foreach ($overview['assessments'] as $assessment) {

            $type = $assessment['assessment_type'] ?? 'Other';

            $toGrade = (int) ($assessment['section']['toGrade'] ?? 0);
            $submitted = (int) ($assessment['section']['submitted'] ?? 0);

            $assessment['needs_review_count'] = $toGrade;
            $assessment['graded_count'] = max(0, $submitted - $toGrade);

            $groups[$type][] = $assessment;

            // Archived assessments are retired from the grading queue —
            // mirrors AssessmentOverviewService::forInstructor()'s own
            // toGradeTotal exclusion, so the class-level number matches
            // the Assessments tab's "To Grade" metric exactly.
            if (($assessment['status'] ?? null) !== 'Archived') {
                $needsReview += $toGrade;
            }

        }

        // Final grades as GradingEngineService last computed them.
        $records = $totalStudents > 0
            ? $this->gradebookRecordModel
                ->where('course_offering_id', $offering['id'])
                ->whereIn('student_id', $rosterIds)
                ->findAll()
            : [];

        $percentages = [];
        $passingCount = 0;

        foreach ($records as $record) {

            if ($record['remarks'] === 'Passed') {
                $passingCount++;
            }

            $effective = GradebookRecordModel::effectivePercentage($record);

            if ($effective !== null) {
                $percentages[] = $effective;
            }

        }

        return [
            'assessmentGroups' => $groups,
            'classSummary'     => [
                'average_percentage' => $percentages !== []
                    ? array_sum($percentages) / count($percentages)
                    : null,
                'passing_count'  => $passingCount,
                'graded_students' => count($percentages),
                'total_students' => $totalStudents,
                'needs_review'   => $needsReview,
            ],
            'gradebook' => $gradebook,
        ];
    }

    /**
     * A student's Grades page: every assessment of this section they can
     * see, in the same state and counted score the Assessments tab shows
     * (AssessmentOverviewService — best graded quiz attempt, latest graded
     * assignment), plus their final grade from the gradebook
     * (GradingEngineService) with a per-category breakdown.
     *
     * The final grade shows only once the instructor has published the
     * grading scheme; an instructor's override wins.
     */
    public function buildStudentGrades(User $user, array $offering, ?array $overview = null): array
    {
        $offeringId = (int) $offering['id'];

        $scheme = (new GradingSchemeService())->forOffering($offeringId);

        $engine = new GradingEngineService();

        $isPublished = $scheme['status'] === 'Published';

        $record = $this->gradebookRecordModel->findForStudent($offeringId, (int) $user->id);

        // Never calculated for this student yet (e.g. enrolled later).
        if (!$record && $isPublished) {
            $engine->recalculateStudent($offeringId, (int) $user->id);
            $record = $this->gradebookRecordModel->findForStudent($offeringId, (int) $user->id);
        }

        $context = $engine->context($offeringId, [(int) $user->id]);

        $breakdown = $context ? $engine->compute($context, (int) $user->id) : null;

        $percentage = $isPublished ? GradebookRecordModel::effectivePercentage($record) : null;

        $bandId = !empty($record['is_override'])
            ? $record['overridden_grade_scale_item_id']
            : ($record['calculated_grade_scale_item_id'] ?? null);

        $band = $isPublished && $bandId
            ? (new GradeScaleItemModel())->find((int) $bandId)
            : null;

        $overviewService = new AssessmentOverviewService();

        // The course Overview already builds this for its own metrics and
        // hands it in, so the same queries aren't run twice per page load.
        $overview ??= $overviewService->build($user, (int) $offering['id'], (int) $offering['course_id']);

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

        return [
            'assessments' => $assessments,
            'summary' => [
                'is_published' => $isPublished,
                'overall_percentage' => $percentage,
                'grade_label' => $band['name'] ?? null,
                'points_earned' => $breakdown['points_earned'] ?? 0,
                'points_possible' => $breakdown['points_possible'] ?? 0,
                'remarks' => $isPublished ? ($record['remarks'] ?? 'Incomplete') : 'Incomplete',
                'is_final' => $isPublished && !empty($record['finalized_at']),
                'is_adjusted' => $isPublished && $context !== null && ($context['adjustments']['items'] !== [] || !empty($context['adjustments']['final'])),
                'tag' => $isPublished
                    ? \App\Services\Courses\Grades\GradingEngineService::displayTag($record['remarks'] ?? 'Incomplete', !empty($record['finalized_at']))
                    : 'Incomplete',
                'inc_reason' => $isPublished ? ($record['inc_reason'] ?? null) : null,
                'inc_until' => $isPublished ? ($record['inc_until'] ?? null) : null,
                'graded_count' => count($graded),
                'total_count' => count($assessments),
                'is_override' => $isPublished && !empty($record['is_override']),
                'passing_percentage' => (float) $scheme['passing_percentage'],
            ],
            'manualItems' => $isPublished && $context ? $this->manualItemsFor($context, (int) $user->id) : [],
            'categoryBreakdown' => $isPublished ? ($breakdown['categories'] ?? []) : [],
            'periodBreakdown' => $isPublished ? ($breakdown['periods'] ?? []) : [],
        ];
    }
    /**
     * The student's scores on the items the instructor enters by hand, for
     * the categories that count toward the grade.
     *
     * @return list<array{name: string, category: string, score: ?float, max: float, excused: bool}>
     */
    private function manualItemsFor(array $context, int $studentId): array
    {
        $categories = array_column($context['categories'], 'name', 'id');

        $rows = [];

        foreach ($context['items'] as $item) {

            if (!empty($item['assessment_id']) || !isset($categories[(int) $item['category_id']])) {
                continue;
            }

            $cell = $context['scores'][(int) $item['id']][$studentId] ?? null;

            $rows[] = [
                'name' => (string) $item['name'],
                'category' => (string) $categories[(int) $item['category_id']],
                'score' => $cell && $cell['raw_score'] !== null ? (float) $cell['raw_score'] : null,
                'max' => (float) $item['max_points'],
                'excused' => !empty($cell['is_missing']),
            ];

        }

        return $rows;
    }
}
