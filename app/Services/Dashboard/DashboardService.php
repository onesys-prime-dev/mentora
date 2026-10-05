<?php

namespace App\Services\Dashboard;

use App\Models\Users\UserProfileModel;
use App\Models\Users\ReminderModel;
use App\Models\Courses\EnrollmentModel;
use App\Models\Courses\AnnouncementModel;
use App\Models\Academic\InstitutionUserModel;
use App\Models\Academic\AcademicTermModel;
use App\Models\Courses\CourseScheduleModel;
use App\Models\Assignments\InstructorAssignmentModel;
use App\Models\Learning\CourseModuleModel;
use App\Models\Learning\LearningUnitModel;
use App\Models\Assessments\GradebookRecordModel;
use App\Services\Courses\RosterRiskService;
use App\Services\Courses\Assessments\AssessmentOverviewService;
use App\Services\Courses\Grades\GradingSchemeService;
use CodeIgniter\Shield\Entities\User;

class DashboardService
{
    protected UserProfileModel $userProfileModel;
    protected EnrollmentModel $enrollmentModel;
    protected AnnouncementModel $announcementModel;
    protected CourseScheduleModel $courseScheduleModel;
    protected InstructorAssignmentModel $instructorAssignmentModel;
    protected CourseModuleModel $courseModuleModel;
    protected LearningUnitModel $learningUnitModel;
    protected \App\Models\Learning\LearningItemModel $learningItemModel;
    protected RosterRiskService $rosterRiskService;
    protected GradebookRecordModel $gradebookRecordModel;

    protected InstitutionUserModel $institutionUserModel;
    protected AcademicTermModel $academicTermModel;

    /**
     * Four instructor widgets all start from the same "sections I teach"
     * join, so it is resolved once per request and reused.
     */
    protected ?array $instructorSections = null;

    /**
     * Which widget slugs a role is allowed to SEE, and nothing more.
     *
     * A slug listed here gets its data resolved and reaches the view; where
     * the widget then sits on the grid is decided by the $layouts map in
     * pages/dashboard/index.php. Adding a widget means a line in both:
     * one for access, one for placement. A slug listed here but absent from
     * the layout is simply never rendered.
     *
     * Note that a user in several groups gets the union of their lists.
     */
    protected array $widgetsByRole = [
        'student' => [
            'profile-card',
            'key-metrics',
            'upcoming-tasks',
            'my-courses',
            'announcements',
            'reminders',
            'quick-access',
        ],
        'instructor' => [
            'profile-card',
            'grading-queue',
            'at-risk-students',
            'teaching-load',
            'class-progress',
            'content-status',
            'quick-access',
        ],
    ];

    public function __construct()
    {
        $this->userProfileModel = new UserProfileModel();
        $this->enrollmentModel = new EnrollmentModel();
        $this->announcementModel = new AnnouncementModel();
        $this->institutionUserModel = new InstitutionUserModel();
        $this->academicTermModel = new AcademicTermModel();
        $this->courseScheduleModel = new CourseScheduleModel();
        $this->instructorAssignmentModel = new InstructorAssignmentModel();
        $this->courseModuleModel = new CourseModuleModel();
        $this->learningUnitModel = new LearningUnitModel();
        $this->learningItemModel = new \App\Models\Learning\LearningItemModel();
        $this->rosterRiskService = new RosterRiskService();
        $this->gradebookRecordModel = new GradebookRecordModel();
    }

    /**
     * Returns every widget the user should see, each already
     * carrying its own data: ['profile-card' => [...], 'key-metrics' => [...]]
     */
    public function getDashboardWidgets(User $user): array
    {
        $slugs = [];

        foreach ($this->widgetsByRole as $role => $roleWidgets) {
            if ($user->inGroup($role)) {
                $slugs = array_merge($slugs, $roleWidgets);
            }
        }

        $slugs = array_unique($slugs);

        $widgets = [];

        foreach ($slugs as $slug) {
            $widgets[$slug] = $this->resolveWidgetData($slug, $user);
        }

        return $widgets;
    }

    /**
     * Maps a widget slug to its data source.
     * Add a new `case` here whenever a new widget slug is introduced.
     */
    protected function resolveWidgetData(string $slug, User $user): array
    {
        return match ($slug) {
            'profile-card'     => $this->getProfileCardData($user),
            'key-metrics'      => $this->getKeyMetricsData($user),
            'upcoming-tasks'   => $this->getUpcomingTasksData($user),
            'my-courses'       => $this->getMyCoursesData($user),
            'announcements'    => $this->getAnnouncementsData($user),
            'reminders'        => $this->getRemindersData($user),
            'quick-access'     => $this->getQuickAccessData($user),
            'grading-queue'    => $this->getGradingQueueData($user),
            'at-risk-students' => $this->getAtRiskStudentsData($user),
            'teaching-load'    => $this->getTeachingLoadData($user),
            'class-progress'   => $this->getClassProgressData($user),
            'content-status'   => $this->getContentStatusData($user),
            default            => [],
        };
    }

    protected function getProfileCardData(User $user): array
    {
        return $this->userProfileModel->retrieveUserProfile($user) ?? [];
    }

    /** Student-only widget; instructors show Teaching Load in that slot. */
    protected function getKeyMetricsData(User $user): array
    {
        $assessments = $this->studentAssessments($user);

        // "actionable" is AssessmentOverviewService's own definition of work
        // still on the student: in progress, due soon, overdue-with-attempts-
        // left, or not yet started — not merely "not graded yet".
        $pending = count(array_filter(
            $assessments,
            static fn (array $row) => !empty($row['student']['actionable'])
        ));

        $percentages = [];

        foreach ($this->enrollmentModel->retrieveStudentCourseOfferings($user->id) as $offering) {

            $scheme = (new GradingSchemeService())->forOffering((int) $offering['offering_id']);

            if ($scheme['status'] !== 'Published') {
                continue;
            }

            $record = $this->gradebookRecordModel->findForStudent((int) $offering['offering_id'], (int) $user->id);

            $effective = $record ? GradebookRecordModel::effectivePercentage($record) : null;

            if ($effective !== null) {
                $percentages[] = $effective;
            }

        }

        return [
            'courses_enrolled' => $this->enrollmentModel->retrieveTotalCoursesEnrolled($user->id),
            'pending_tasks'    => $pending,
            'average_grade'    => $percentages !== [] ? array_sum($percentages) / count($percentages) : null,
        ];
    }

    /**
     * Every assessment across every offering the student is enrolled in,
     * each already carrying its own 'student' state/label/score — resolved
     * once and reused by Key Metrics and Upcoming Tasks so they never
     * disagree on what "pending" means.
     */
    protected function studentAssessments(User $user): array
    {
        $rows = [];

        foreach ($this->enrollmentModel->retrieveStudentCourseOfferings($user->id) as $offering) {

            $overview = (new AssessmentOverviewService())->build(
                $user,
                (int) $offering['offering_id'],
                (int) $offering['course_id']
            );

            foreach ($overview['assessments'] as $assessment) {
                $assessment['course_code'] = $offering['course_code'];
                $assessment['offering_id'] = (int) $offering['offering_id'];
                $assessment['slug'] = url_title($offering['course_title'], '-', true);
                $rows[] = $assessment;
            }

        }

        return $rows;
    }

    protected function getUpcomingTasksData(User $user): array
    {
        $overviewService = new AssessmentOverviewService();

        $now = time();

        $tasks = [];

        foreach ($this->studentAssessments($user) as $row) {

            if ($row['due_ts'] === null) {
                continue;
            }

            $isGraded = ($row['student']['result']['counted'] ?? null) !== null;
            $isSubmitted = $isGraded || !empty($row['student']['submitted']);

            $urgency = match (true) {
                $row['due_ts'] < $now && !$isSubmitted => 'overdue',
                $row['due_ts'] <= strtotime('today 23:59:59', $now) => 'today',
                $row['due_ts'] <= $now + AssessmentOverviewService::SOON_DAYS * 86400 => 'soon',
                default => 'later',
            };

            $tasks[] = [
                'id' => (int) $row['id'],
                'offering_id' => $row['offering_id'],
                'slug' => $row['slug'],
                'code' => $row['course_code'],
                'title' => $row['title'],
                'due' => $overviewService->dueLabel($row['due_ts'], $now),
                'urgency' => $urgency,
                'status' => $isGraded ? 'graded' : ($isSubmitted ? 'submitted' : null),
                'due_ts' => $row['due_ts'],
            ];

        }

        usort($tasks, static fn (array $a, array $b) => $a['due_ts'] <=> $b['due_ts']);

        return ['tasks' => array_slice($tasks, 0, 8)];
    }

    protected function getMyCoursesData(User $user): array
    {
        $offerings = $this->enrollmentModel->retrieveStudentCourseOfferings($user->id);

        if ($offerings === []) {
            return ['courses' => []];
        }

        $offeringIds = array_column($offerings, 'offering_id');

        $completed = [];

        foreach ($this->enrollmentModel->retrieveRosterProgress($offeringIds) as $row) {
            if ((int) $row['student_user_id'] === (int) $user->id) {
                $completed[(int) $row['offering_id']] = (int) $row['completed_count'];
            }
        }

        $itemTotals = [];

        foreach ($this->courseModuleModel->retrieveModuleProgress($offeringIds) as $module) {
            $offeringId = (int) $module['offering_id'];
            $itemTotals[$offeringId] = ($itemTotals[$offeringId] ?? 0) + (int) $module['item_count'];
        }

        $courses = [];

        foreach ($offerings as $offering) {

            $offeringId = (int) $offering['offering_id'];
            $total = $itemTotals[$offeringId] ?? 0;

            $courses[] = [
                'offering_id' => $offeringId,
                'slug' => url_title($offering['course_title'], '-', true),
                'course_code' => $offering['course_code'],
                'section_code' => $offering['section_code'],
                'course_title' => $offering['course_title'],
                'progress' => $total > 0 ? (int) round(($completed[$offeringId] ?? 0) / $total * 100) : 0,
            ];

        }

        return ['courses' => $courses];
    }

    protected function getAnnouncementsData(User $user): array
    {
        $offerings = $this->enrollmentModel->retrieveStudentCourseOfferings($user->id);

        if ($offerings === []) {
            return ['announcements' => []];
        }

        $byOffering = [];

        foreach ($offerings as $offering) {
            $byOffering[(int) $offering['offering_id']] = $offering;
        }

        $rows = $this->announcementModel->retrieveForOfferings(array_keys($byOffering), 8);

        $announcements = [];

        foreach ($rows as $row) {

            $offering = $byOffering[(int) $row['offering_id']] ?? null;

            if ($offering === null) {
                continue;
            }

            $announcements[] = [
                'id' => (int) $row['id'],
                'offering_id' => (int) $row['offering_id'],
                'slug' => url_title($offering['course_title'], '-', true),
                'code' => $offering['course_code'] . ' - ' . $offering['section_code'],
                'title' => $row['subject'],
                'author' => trim((string) $row['author_name']) !== '' ? $row['author_name'] : 'Instructor',
                'posted' => $this->describeEditedAt($row['created_at']),
            ];

        }

        return ['announcements' => $announcements];
    }

    protected function getRemindersData(User $user): array
    {
        return [
            'reminders' => array_map(function (array $row): array {

                $at = strtotime($row['remind_at']);

                return [
                    'id' => (int) $row['id'],
                    'title' => $row['title'],
                    'due' => (date('Y-m-d', $at) === date('Y-m-d') ? 'Today' : date('M j', $at)) . ' · ' . date('g:i A', $at),
                ];

            }, (new ReminderModel())->retrieveUpcomingForUser((int) $user->id)),
        ];
    }

    protected function getQuickAccessData(User $user): array
    {
        return [];
    }

    /**
     * Every assessment across the instructor's own sections with ungraded
     * work waiting on it — reuses AssessmentOverviewService's per-attempt
     * "toGrade"/"waitingSince" computation (same one behind the Assessments
     * tab's "To Grade" metric) so this never disagrees with that tab, and
     * stays offering-scoped rather than aggregating a whole course.
     */
    protected function getGradingQueueData(User $user): array
    {
        $sections = $this->instructorSections($user);

        if ($sections === []) {
            return ['queue' => []];
        }

        $overviewService = new AssessmentOverviewService();

        $queue = [];

        foreach ($sections as $section) {

            $offeringId = (int) $section['offering_id'];

            $overview = $overviewService->build($user, $offeringId, (int) $section['course_id']);

            foreach ($overview['assessments'] as $assessment) {

                $toGrade = (int) ($assessment['section']['toGrade'] ?? 0);

                if ($toGrade <= 0 || ($assessment['status'] ?? null) === 'Archived') {
                    continue;
                }

                $waitingSince = $assessment['section']['waitingSince'] ?? null;

                $queue[] = [
                    'assessment_id' => (int) $assessment['id'],
                    'offering_id' => $offeringId,
                    'slug' => url_title($section['course_title'], '-', true),
                    'course_code' => $section['course_code'],
                    'section_code' => $section['section_code'],
                    'title' => $assessment['title'],
                    'type' => strtolower((string) ($assessment['assessment_type'] ?? '')),
                    'ungraded' => $toGrade,
                    'submitted' => (int) ($assessment['section']['submitted'] ?? 0),
                    'waiting_days' => $waitingSince !== null ? max(0, (int) floor((time() - $waitingSince) / 86400)) : 0,
                ];

            }

        }

        usort($queue, static fn (array $a, array $b) => $b['waiting_days'] <=> $a['waiting_days']);

        return ['queue' => $queue];
    }

    public function getAcademicTerm(User $user): array
    {
        $institutionId = $this->institutionUserModel
            ->retrieveInstitutionId($user->id);

        if ($institutionId === null) {
            return [];
        }

        return $this->academicTermModel->retrieveActiveAcademicTerm($institutionId);
    }

    /** The instructor's active sections, resolved once and reused. */
    protected function instructorSections(User $user): array
    {
        return $this->instructorSections ??= $this->instructorAssignmentModel
            ->retrieveInstructorSections($user->id);
    }

    protected function getAtRiskStudentsData(User $user): array
    {
        $sections = $this->instructorSections($user);

        if ($sections === []) {
            return ['students' => []];
        }

        $labels = [];
        $slugs = [];

        foreach ($sections as $section) {
            $offeringId = (int) $section['offering_id'];
            $labels[$offeringId] = $section['course_code'] . ' - ' . $section['section_code'];
            $slugs[$offeringId] = url_title($section['course_title'], '-', true);
        }

        // Who counts as at risk is decided by RosterRiskService, shared with
        // the course Overview; this only shapes it for the widget.
        $students = $this->rosterRiskService->atRisk(array_keys($labels));

        return [
            'students' => array_map(fn (array $student) => [
                'name'        => $student['name'],
                'student_id'  => $student['student_id'],
                'offering_id' => $student['offering_id'],
                'slug'        => $slugs[$student['offering_id']] ?? '',
                'section'     => $labels[$student['offering_id']] ?? '',
                'progress'    => $student['progress'],
                'last_active' => $this->describeLastActive($student['days_since']),
                'reason'      => $student['reason'],
            ], $students),
        ];
    }

    protected function getTeachingLoadData(User $user): array
    {
        $sections = $this->instructorSections($user);

        if ($sections === []) {
            return ['sections' => []];
        }

        $blocks = $this->courseScheduleModel->groupByOffering(
            $this->courseScheduleModel->retrieveForOfferings(
                array_column($sections, 'offering_id')
            )
        );

        $shaped = [];

        foreach ($sections as $section) {
            $offeringId = (int) $section['offering_id'];
            $block = $blocks[$offeringId] ?? null;

            $shaped[] = [
                'offering_id' => $offeringId,
                'slug' => url_title($section['course_title'], '-', true),
                'course_code' => $section['course_code'],
                'section_code' => $section['section_code'],
                'course_title' => $section['course_title'],
                'enrolled' => (int) $section['enrolled'],
                'capacity' => (int) $section['capacity'],
                'schedule' => $block['schedule'] ?? 'No schedule set',
                'room' => $block['room'] ?? '--',
            ];
        }

        return ['sections' => $shaped];
    }

    protected function getClassProgressData(User $user): array
    {
        $sections = $this->instructorSections($user);

        if ($sections === []) {
            return ['progress' => []];
        }

        $offeringIds = array_column($sections, 'offering_id');

        $rosters = [];

        foreach ($sections as $section) {
            $rosters[(int) $section['offering_id']] = (int) $section['enrolled'];
        }

        $byOffering = [];

        foreach ($this->courseModuleModel->retrieveModuleProgress($offeringIds) as $module) {
            $offeringId = (int) $module['offering_id'];

            $roster = $rosters[$offeringId] ?? 0;
            $items = (int) $module['item_count'];

            // Every enrolled student must clear every published item for 100%.
            $denominator = $roster * $items;

            $byOffering[$offeringId][] = [
                'title' => $module['title'],
                'completion' => $denominator > 0
                    ? (int) round((int) $module['completed_count'] / $denominator * 100)
                    : 0,
            ];
        }

        $progress = [];

        foreach ($sections as $section) {
            $offeringId = (int) $section['offering_id'];

            // An empty roster would render every module at 0%, which reads as
            // a failing cohort rather than an unenrolled section.
            if (empty($byOffering[$offeringId]) || ($rosters[$offeringId] ?? 0) === 0) {
                continue;
            }

            $progress[] = [
                'course_code' => $section['course_code'],
                'section_code' => $section['section_code'],
                'modules' => $byOffering[$offeringId],
            ];
        }

        return ['progress' => $progress];
    }

    protected function getContentStatusData(User $user): array
    {
        $sections = $this->instructorSections($user);

        if ($sections === []) {
            return ['drafts' => []];
        }

        $offeringIds = array_column($sections, 'offering_id');

        $codes = [];
        $slugs = [];

        foreach ($sections as $section) {
            $offeringId = (int) $section['offering_id'];
            $codes[$offeringId] = $section['course_code'] . (!empty($section['section_code']) ? ' - ' . $section['section_code'] : '');
            $slugs[$offeringId] = url_title($section['course_title'], '-', true);
        }

        $drafts = [];

        $sources = [
            'Module' => $this->courseModuleModel->retrieveDrafts($offeringIds),
            'Unit' => $this->learningUnitModel->retrieveDrafts($offeringIds),
            'Item' => $this->learningItemModel->retrieveDrafts($offeringIds),
        ];

        foreach ($sources as $type => $rows) {
            foreach ($rows as $row) {

                $offeringId = (int) $row['offering_id'];

                $drafts[] = [
                    'id' => (int) $row['content_id'],
                    'module_id' => (int) ($row['module_id'] ?? $row['content_id']),
                    'offering_id' => $offeringId,
                    'slug' => $slugs[$offeringId] ?? '',
                    'course_code' => $codes[$offeringId] ?? '',
                    'title' => $row['title'],
                    'type' => $type,
                    'items' => (int) ($row['child_count'] ?? 0),
                    'detail' => $type === 'Item' ? ucfirst((string) $row['item_type']) : null,
                    'updated' => $row['updated_at'] ? date('M d', strtotime((string) $row['updated_at'])) : '',
                    'sort_key' => strtotime((string) $row['updated_at']),
                ];
            }
        }

        usort($drafts, static fn ($a, $b) => $b['sort_key'] <=> $a['sort_key']);

        return [
            'drafts' => array_map(
                static function (array $draft): array {
                    unset($draft['sort_key']);

                    return $draft;
                },
                $drafts
            ),
        ];
    }

    protected function describeLastActive(?int $days): string
    {
        if ($days === null) {
            return 'never';
        }

        if ($days <= 0) {
            return 'today';
        }

        if ($days === 1) {
            return 'yesterday';
        }

        return $days . ' days ago';
    }

    protected function describeEditedAt(?string $timestamp): string
    {
        if ($timestamp === null) {
            return 'unknown';
        }

        $days = (int) ((time() - strtotime($timestamp)) / 86400);

        if ($days <= 0) {
            return 'today';
        }

        if ($days === 1) {
            return 'Yesterday';
        }

        if ($days < 7) {
            return $days . ' days ago';
        }

        return date('M d', strtotime($timestamp));
    }
}