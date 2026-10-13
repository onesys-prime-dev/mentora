<?php

namespace App\Services\Courses;

use CodeIgniter\Shield\Entities\User;

use App\Models\Courses\CourseOfferingModel;
use App\Models\Courses\CourseScheduleModel;
use App\Models\Learning\CourseModuleModel;
use App\Services\Courses\Assessments\AssessmentOverviewService;
use App\Services\Courses\Assessments\AssessmentScopeService;

use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * The course Overview tab — the first thing anyone sees after opening a
 * course, so it answers a different question per role:
 *
 *  - student:    what should I do next, and how am I doing?
 *                (deadlines, progress, current grade)
 *  - instructor: what needs me?
 *                (to grade, closing soon, where the class is stalling)
 *
 * The assessment numbers come from AssessmentOverviewService, the same
 * source the Assessments tab uses, so the two tabs can't disagree about
 * what's due or waiting to be graded. Who counts as at risk comes from
 * RosterRiskService, shared with the dashboard.
 *
 * Recent Activity comes from ActivityFeedService (derived from existing
 * timestamps; there is no event log).
 *
 * Each role's page leads with one card that answers its question:
 * "Continue where you left off" for a student, "Needs Your Attention" for
 * an instructor.
 */
class OverviewService
{
    /** Deadlines listed on the Overview; the Assessments tab has the rest. */
    public const DEADLINE_LIMIT = 5;

    /** Rows per list on the instructor's "Needs Your Attention" card. */
    public const ATTENTION_LIMIT = 4;

    protected CourseOfferingModel $courseOfferingModel;
    protected CourseScheduleModel $courseScheduleModel;
    protected CourseModuleModel $courseModuleModel;
    protected AssessmentOverviewService $assessmentOverviewService;
    protected GradeService $gradeService;
    protected RosterRiskService $rosterRiskService;
    protected ActivityFeedService $activityFeedService;

    public function __construct()
    {
        $this->courseOfferingModel = new CourseOfferingModel();
        $this->courseScheduleModel = new CourseScheduleModel();
        $this->courseModuleModel = new CourseModuleModel();
        $this->assessmentOverviewService = new AssessmentOverviewService();
        $this->gradeService = new GradeService();
        $this->rosterRiskService = new RosterRiskService();
        $this->activityFeedService = new ActivityFeedService();
    }

    public function show(
        User $user,
        int $offeringId
    ): array {

        $offering = $this->courseOfferingModel->find($offeringId);

        // Only the section's own people: its assigned instructors and
        // enrolled students. Everything below is that section's data.
        if ($offering === null || ! (new AssessmentScopeService())->canAccess($user, $offeringId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $info = $this->courseOfferingModel->getCourseOverviewData($offeringId);

        $assessments = $this->assessmentOverviewService
            ->build($user, $offeringId, (int) $offering['course_id']);

        $isInstructor = \App\Services\Courses\CourseViewer::seesInstructorView($user);

        $viewData = [
            'user'     => $user,
            'role'     => $isInstructor ? 'instructor' : 'student',
            'overview' => $info,
            'schedule' => $this->scheduleBlocks($offeringId),
        ];

        // Same slug the course page URL uses (see CourseController).
        $modulesUrl = site_url(
            CourseViewer::urlPrefix($user, $viewData['role']) . '/courses/' . $offeringId . '/'
            . url_title((string) ($info['course_title'] ?? ''), '-', true) . '/modules'
        );

        $viewData += $isInstructor
            ? $this->forInstructor($offeringId, $info, $assessments)
            : $this->forStudent($user, $offering, $assessments, $modulesUrl);

        return [

            'html' => view(
                'pages/courses/features/overview/index',
                $viewData
            ),

        ];

    }

    /*
    |--------------------------------------------------------------------------
    | Student
    |--------------------------------------------------------------------------
    */

    protected function forStudent(User $user, array $offering, array $assessments, string $modulesUrl): array
    {
        $offeringId = (int) $offering['id'];

        $lessons = $this->lessonProgress((int) $user->id, $offeringId);

        $tasksTotal = count($assessments['assessments']);

        $tasksDone = count(array_filter(
            $assessments['assessments'],
            static fn (array $row) => $row['student']['submitted']
        ));

        // One number for the whole course: lessons and assessments count the
        // same, so a student can't sit at 100% with a quiz unsubmitted.
        $total = $lessons['total'] + $tasksTotal;

        $done = $lessons['completed'] + $tasksDone;

        $grade = $this->gradeService
            ->buildStudentGrades($user, $offering, $assessments)['summary'];

        return [
            'activity'  => $this->activityFeedService->forStudent((int) $user->id, $offeringId, $assessments['assessments']),
            'resume'    => $this->resumePoint((int) $user->id, $offeringId, $modulesUrl),
            'metrics'   => $this->studentMetrics($assessments['metrics'], $grade),
            'deadlines' => $this->studentDeadlines($assessments['upNext']),
            'progress'  => [
                'percent'           => $total > 0 ? (int) round($done / $total * 100) : 0,
                'lessons_completed' => $lessons['completed'],
                'lessons_total'     => $lessons['total'],
                'tasks_completed'   => $tasksDone,
                'tasks_total'       => $tasksTotal,
            ],
        ];
    }

    /**
     * Due This Week and Overdue come straight from the Assessments tab's
     * own metrics; Completed is dropped (the progress card already says how
     * many tasks are done) in favour of the student's current grade.
     */
    protected function studentMetrics(array $assessmentMetrics, array $grade): array
    {
        $byLabel = array_column($assessmentMetrics, null, 'label');

        $percentage = $grade['overall_percentage'];

        return array_values(array_filter([
            $byLabel['Due This Week'] ?? null,
            $byLabel['Overdue'] ?? null,
            [
                'label'  => 'Current Grade',
                'icon'   => 'points',
                'tone'   => $grade['remarks'] === 'Failed' ? 'danger' : 'neutral',
                'value'  => $percentage !== null ? number_format((float) $percentage, 1) . '%' : '—',
                'detail' => $percentage !== null
                    ? $grade['remarks'] . ' · ' . $grade['graded_count'] . ' of ' . $grade['total_count'] . ' graded'
                    : 'Nothing graded yet',
            ],
        ]));
    }

    /**
     * "Up Next" from the Assessments tab already orders actionable work
     * (in progress first, then overdue, then by due date); this only
     * shapes it for the date tiles.
     */
    protected function studentDeadlines(array $upNext): array
    {
        $now = time();

        $soon = $now + AssessmentOverviewService::SOON_DAYS * 86400;

        return array_map(static function (array $row) use ($soon) {

            $due = $row['due_ts'];

            $tone = match (true) {
                $row['student']['key'] === 'overdue' => 'danger',
                $due !== null && $due <= $soon => 'warning',
                default => 'neutral',
            };

            return [
                'id'     => (int) $row['id'],
                'title'  => $row['title'],
                'month'  => $due !== null ? date('M', $due) : '',
                'day'    => $due !== null ? date('j', $due) : '—',
                'tone'   => $tone,
                'detail' => $row['nextDetail'],
            ];

        }, array_slice($upNext, 0, self::DEADLINE_LIMIT));
    }

    /**
     * Where a student picks the course back up.
     *
     * The item they were last in the middle of (most recently touched); if
     * nothing is in progress, the first item in course order they haven't
     * completed. That item is never locked: prerequisites only ever point at
     * *earlier* items in the same unit (see LearningProgressService), and
     * everything before the first incomplete item is complete by definition.
     *
     * state: resume | next → link to the item; done → every item completed;
     * empty → nothing published yet.
     *
     * @return array<string, mixed>
     */
    protected function resumePoint(int $userId, int $offeringId, string $modulesUrl): array
    {
        $items = db_connect()->table('learning_items li')
            ->select([
                'li.id',
                'li.title',
                'li.item_type',
                'lu.title AS unit_title',
                'cm.title AS module_title',
                'lc.status AS completion_status',
                'lc.progress_percent',
                'lc.updated_at AS touched_at',
            ])
            ->join('learning_units lu', 'lu.id = li.learning_unit_id')
            ->join('course_modules cm', 'cm.id = lu.module_id')
            ->join(
                'learning_completions lc',
                "lc.learning_item_id = li.id AND lc.student_user_id = {$userId}",
                'left'
            )
            ->where('cm.offering_id', $offeringId)
            ->where('cm.status', 'published')
            ->where('lu.status', 'published')
            ->where('li.status', 'published')
            ->orderBy('cm.order_index', 'ASC')
            ->orderBy('lu.order_index', 'ASC')
            ->orderBy('li.order_index', 'ASC')
            ->get()
            ->getResultArray();

        if ($items === []) {
            return ['state' => 'empty'];
        }

        $completed = count(array_filter($items, static fn (array $i) => $i['completion_status'] === 'completed'));

        $inProgress = array_filter($items, static fn (array $i) => $i['completion_status'] === 'in_progress');

        usort($inProgress, static fn (array $a, array $b) => strcmp((string) $b['touched_at'], (string) $a['touched_at']));

        $target = $inProgress[0] ?? null;

        if ($target === null) {

            foreach ($items as $item) {

                if ($item['completion_status'] !== 'completed') {
                    $target = $item;
                    break;
                }

            }

        }

        if ($target === null) {
            return ['state' => 'done', 'completed' => $completed, 'total' => count($items)];
        }

        return [
            'state'     => $inProgress !== [] ? 'resume' : 'next',
            'title'     => $target['title'],
            'module'    => $target['module_title'],
            'unit'      => $target['unit_title'],
            'kind'      => $this->itemKind((string) $target['item_type']),
            'progress'  => (int) $target['progress_percent'],
            'started'   => $completed > 0 || $inProgress !== [],
            'completed' => $completed,
            'total'     => count($items),
            'href'      => $modulesUrl . '?item=' . (int) $target['id'],
        ];
    }

    protected function itemKind(string $type): string
    {
        return match ($type) {
            'pdf'   => 'PDF',
            'link'  => 'Link',
            default => ucfirst($type),
        };
    }

    /**
     * Published lessons/items in the section and how many this student has
     * completed — one query, rather than the per-item lookups the Modules
     * tab makes, since this only needs the two counts.
     *
     * Quizzes and assignments are left out here: they're counted as tasks
     * (from the assessment rows), and counting them in both places would
     * weight them twice.
     *
     * @return array{total: int, completed: int}
     */
    protected function lessonProgress(int $userId, int $offeringId): array
    {
        $row = db_connect()->table('learning_items li')
            ->select([
                'COUNT(*) AS total',
                "SUM(CASE WHEN lc.status = 'completed' THEN 1 ELSE 0 END) AS completed",
            ], false)
            ->join('learning_units lu', 'lu.id = li.learning_unit_id')
            ->join('course_modules cm', 'cm.id = lu.module_id')
            ->join(
                'learning_completions lc',
                "lc.learning_item_id = li.id AND lc.student_user_id = {$userId}",
                'left'
            )
            ->where('cm.offering_id', $offeringId)
            ->where('cm.status', 'published')
            ->where('lu.status', 'published')
            ->where('li.status', 'published')
            ->whereNotIn('li.item_type', ['quiz', 'assignment'])
            ->get()
            ->getRowArray();

        return [
            'total'     => (int) ($row['total'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Instructor
    |--------------------------------------------------------------------------
    */

    protected function forInstructor(int $offeringId, array $info, array $assessments): array
    {
        return [
            'activity'       => $this->activityFeedService->forInstructor($offeringId),
            'attention'      => $this->attention($offeringId, $assessments),
            'metrics'        => $assessments['metrics'],
            'deadlines'      => $this->instructorDeadlines($assessments['assessments']),
            'class_progress' => $this->classProgress($offeringId, (int) ($info['enrolled'] ?? 0)),
        ];
    }

    /**
     * "Needs Your Attention": everything waiting on this instructor, in
     * three lists, each row saying where it leads (`action`):
     *
     *   grading → assessments with work handed in and ungraded (Grade Work)
     *   behind  → students who've gone quiet or are well behind (no student
     *             page exists to open, so these rows don't link anywhere)
     *   drafts  → content students can't see yet: assessments (editor) and
     *             modules (Modules tab)
     *
     * Each list is capped for the card; `*_total` is the real count.
     *
     * @return array<string, mixed>
     */
    protected function attention(int $offeringId, array $assessments): array
    {
        $grading = array_map(static fn (array $row) => [
            'id'     => (int) $row['id'],
            'title'  => $row['title'],
            'detail' => $row['section']['toGrade'] . ' to grade'
                . ($row['waiting'] ? ' · oldest waiting ' . $row['waiting'] : ''),
            'tone'   => 'warning',
            'action' => 'grade',
        ], $assessments['readyForGrading']);

        $atRisk = $this->rosterRiskService->atRisk([$offeringId]);

        $behind = array_map(static fn (array $student) => [
            'title'  => $student['name'],
            'detail' => $student['reason'] === 'inactive'
                ? ($student['days_since'] === null
                    ? 'No activity yet'
                    : 'Inactive for ' . $student['days_since'] . ' days')
                : 'Behind — ' . $student['progress'] . '% complete',
            'tone'   => $student['reason'] === 'inactive' ? 'danger' : 'warning',
            'action' => 'none',
        ], array_slice($atRisk, 0, self::ATTENTION_LIMIT));

        $assessmentDrafts = array_map(static fn (array $draft) => [
            'id'     => (int) $draft['id'],
            'title'  => $draft['title'],
            'detail' => $draft['dueWarning'] ?? ('Assessment draft · edited ' . $draft['edited']),
            'tone'   => $draft['dueWarning'] !== null ? 'danger' : 'neutral',
            'action' => 'edit',
        ], $assessments['drafts']);

        $moduleDrafts = array_map(static fn (array $module) => [
            'title'  => $module['title'],
            'detail' => 'Module draft · ' . $module['child_count'] . ' '
                . ((int) $module['child_count'] === 1 ? 'unit' : 'units'),
            'tone'   => 'neutral',
            'action' => 'modules',
        ], $this->courseModuleModel->retrieveDrafts([$offeringId]));

        $drafts = array_merge($assessmentDrafts, $moduleDrafts);

        return [
            'grading'       => array_slice($grading, 0, self::ATTENTION_LIMIT),
            'grading_total' => (int) $assessments['readyCount'],
            'behind'        => $behind,
            'behind_total'  => count($atRisk),
            'drafts'        => array_slice($drafts, 0, self::ATTENTION_LIMIT),
            'drafts_total'  => (int) $assessments['draftCount'] + count($moduleDrafts),
        ];
    }

    /**
     * Published assessments still to close, soonest first, each with how
     * many of the section has handed in so far — the instructor's version
     * of a deadline is "who's still missing", not "what do I owe".
     */
    protected function instructorDeadlines(array $rows): array
    {
        $now = time();

        $soon = $now + AssessmentOverviewService::SOON_DAYS * 86400;

        $upcoming = array_filter(
            $rows,
            static fn (array $row) => $row['status'] === 'Published'
                && $row['due_ts'] !== null
                && $row['due_ts'] >= $now
        );

        usort($upcoming, static fn (array $a, array $b) => [$a['due_ts'], (int) $a['id']] <=> [$b['due_ts'], (int) $b['id']]);

        return array_map(function (array $row) use ($soon, $now) {

            $section = $row['section'];

            return [
                'id'     => (int) $row['id'],
                'title'  => $row['title'],
                'month'  => date('M', $row['due_ts']),
                'day'    => date('j', $row['due_ts']),
                'tone'   => $row['due_ts'] <= $soon ? 'warning' : 'neutral',
                'detail' => $section['submitted'] . ' of ' . $section['students'] . ' submitted · due '
                    . $this->assessmentOverviewService->dueLabel($row['due_ts'], $now),
            ];

        }, array_slice($upcoming, 0, self::DEADLINE_LIMIT));
    }

    /**
     * Per-module completion across the section's roster. A row that drops
     * well below the ones above it says the module is the problem, not the
     * students.
     *
     * @return array{roster: int, modules: list<array{title: string, completion: int, tone: string}>}
     */
    protected function classProgress(int $offeringId, int $roster): array
    {
        if ($roster === 0) {
            return ['roster' => 0, 'modules' => []];
        }

        $modules = array_map(function (array $module) use ($roster) {

            $denominator = $roster * (int) $module['item_count'];

            $completion = $denominator > 0
                ? (int) round((int) $module['completed_count'] / $denominator * 100)
                : 0;

            return [
                'title'      => $module['title'],
                'completion' => $completion,
                'tone'       => match (true) {
                    $completion < 40 => 'danger',
                    $completion < 70 => 'warning',
                    default => 'success',
                },
            ];

        }, $this->courseModuleModel->retrieveModuleProgress([$offeringId]));

        return ['roster' => $roster, 'modules' => $modules];
    }

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    */

    /**
     * Meeting blocks as display strings, folding days that share a time
     * into one line ("Mon, Wed, Fri · 9:00 AM – 10:30 AM").
     *
     * @return list<array{days: string, time: string, room: string, mode: string}>
     */
    protected function scheduleBlocks(int $offeringId): array
    {
        $dayNames = [
            'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu',
            'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun',
        ];

        $blocks = [];

        // Rows arrive in week order, so days inside a block stay in order.
        foreach ($this->courseScheduleModel->retrieveForOfferings([$offeringId]) as $row) {

            $key = $row['start_time'] . '|' . $row['end_time'] . '|' . $row['room'];

            $blocks[$key]['days'][] = $dayNames[$row['day_of_week']] ?? $row['day_of_week'];

            $blocks[$key]['time'] ??= date('g:i A', strtotime($row['start_time']))
                . ' – ' . date('g:i A', strtotime($row['end_time']));

            $blocks[$key]['room'] = (string) $row['room'];

            $blocks[$key]['mode'] = match ($row['delivery_mode']) {
                'online' => 'Online',
                'hybrid' => 'Hybrid',
                default  => 'On-site',
            };

        }

        return array_values(array_map(static fn (array $block) => [
            'days' => implode(', ', $block['days']),
            'time' => $block['time'],
            'room' => $block['room'],
            'mode' => $block['mode'],
        ], $blocks));
    }
}
