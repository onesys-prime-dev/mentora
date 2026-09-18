<?php

namespace App\Services\Dashboard;

use App\Models\Users\UserProfileModel;
use App\Models\Courses\EnrollmentModel;
use App\Models\Courses\CourseScheduleModel;
use App\Models\Assignments\InstructorAssignmentModel;
use App\Models\Learning\CourseModuleModel;
use App\Models\Learning\LearningUnitModel;
use CodeIgniter\Shield\Entities\User;

class DashboardService
{
    protected UserProfileModel $userProfileModel;
    protected EnrollmentModel $enrollmentModel;
    protected CourseScheduleModel $courseScheduleModel;
    protected InstructorAssignmentModel $instructorAssignmentModel;
    protected CourseModuleModel $courseModuleModel;
    protected LearningUnitModel $learningUnitModel;

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
        $this->courseScheduleModel = new CourseScheduleModel();
        $this->instructorAssignmentModel = new InstructorAssignmentModel();
        $this->courseModuleModel = new CourseModuleModel();
        $this->learningUnitModel = new LearningUnitModel();
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
        return [
            'courses_enrolled' => $this->enrollmentModel->retrieveTotalCoursesEnrolled($user->id),
            //'pending_tasks' => $this->assessmentModel->retrieveTotalPendingTasks($user),
            //'current_gpa'   => $this->gradebookModel->retrieveCurrentGpa($user),
        ];
    }

    protected function getUpcomingTasksData(User $user): array
    {
        return [];
    }

    protected function getMyCoursesData(User $user): array
    {
        return [];
    }

    protected function getAnnouncementsData(User $user): array
    {
        return [];
    }

    protected function getRemindersData(User $user): array
    {
        return [];
    }

    protected function getQuickAccessData(User $user): array
    {
        return [];
    }

    /**
     * Blocked on the assessments + submissions tables; returns nothing until
     * those exist, which renders the widget's empty state.
     */
    protected function getGradingQueueData(User $user): array
    {
        return [];
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

        $offeringIds = array_column($sections, 'offering_id');

        // Published items per offering — the denominator for "how far along".
        $itemTotals = [];

        foreach ($this->courseModuleModel->retrieveModuleProgress($offeringIds) as $module) {
            $offeringId = (int) $module['offering_id'];

            $itemTotals[$offeringId] = ($itemTotals[$offeringId] ?? 0)
                + (int) $module['item_count'];
        }

        $labels = [];

        foreach ($sections as $section) {
            $labels[(int) $section['offering_id']] =
                $section['course_code'] . ' - ' . $section['section_code'];
        }

        $students = [];

        foreach ($this->enrollmentModel->retrieveRosterProgress($offeringIds) as $row) {
            $offeringId = (int) $row['offering_id'];
            $total = $itemTotals[$offeringId] ?? 0;

            // Nothing published yet means nobody can be behind.
            if ($total === 0) {
                continue;
            }

            $progress = (int) round((int) $row['completed_count'] / $total * 100);

            $daysSince = $row['last_active'] !== null
                ? (int) ((time() - strtotime($row['last_active'])) / 86400)
                : null;

            // Silence for two weeks outranks a merely low percentage: a
            // student still working is not the one who needs chasing.
            if ($daysSince === null || $daysSince >= 14) {
                $reason = 'inactive';
            } elseif ($progress < 50) {
                $reason = 'behind';
            } else {
                continue;
            }

            $students[] = [
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'section' => $labels[$offeringId] ?? '',
                'progress' => $progress,
                'last_active' => $this->describeLastActive($daysSince),
                'reason' => $reason,
                'sort_key' => $daysSince ?? PHP_INT_MAX,
            ];
        }

        // Longest silent first — that is the order an instructor should work in.
        usort($students, static fn ($a, $b) => $b['sort_key'] <=> $a['sort_key']);

        return [
            'students' => array_map(
                static function (array $student): array {
                    unset($student['sort_key']);

                    return $student;
                },
                $students
            ),
        ];
    }

    protected function getTeachingLoadData(User $user): array
    {
        $sections = $this->instructorSections($user);

        if ($sections === []) {
            return ['sections' => []];
        }

        $blocks = $this->groupSchedules(
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

        foreach ($sections as $section) {
            $codes[(int) $section['offering_id']] = $section['course_code'];
        }

        $drafts = [];

        $sources = [
            'Module' => $this->courseModuleModel->retrieveDrafts($offeringIds),
            'Unit' => $this->learningUnitModel->retrieveDrafts($offeringIds),
        ];

        foreach ($sources as $type => $rows) {
            foreach ($rows as $row) {
                $drafts[] = [
                    'course_code' => $codes[(int) $row['offering_id']] ?? '',
                    'title' => $row['title'],
                    'type' => $type,
                    'items' => (int) $row['child_count'],
                    'updated' => $this->describeEditedAt($row['updated_at']),
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

    /**
     * Folds meeting blocks into one line per offering, collapsing days that
     * share a start and end time into a single "MWF 8:00-9:30".
     */
    protected function groupSchedules(array $rows): array
    {
        $letters = [
            'mon' => 'M',
            'tue' => 'T',
            'wed' => 'W',
            'thu' => 'Th',
            'fri' => 'F',
            'sat' => 'Sa',
            'sun' => 'Su',
        ];

        $byOffering = [];

        foreach ($rows as $row) {
            $offeringId = (int) $row['offering_id'];

            $slot = substr((string) $row['start_time'], 0, 5)
                . '-'
                . substr((string) $row['end_time'], 0, 5);

            $byOffering[$offeringId][$slot]['days'][] =
                $letters[$row['day_of_week']] ?? '';

            $byOffering[$offeringId][$slot]['room'] = $row['room'];
        }

        $grouped = [];

        foreach ($byOffering as $offeringId => $slots) {
            $parts = [];
            $room = null;

            foreach ($slots as $slot => $detail) {
                $parts[] = implode('', $detail['days']) . ' ' . $slot;

                $room ??= $detail['room'];
            }

            $grouped[$offeringId] = [
                'schedule' => implode(' / ', $parts),
                'room' => $room ?: '--',
            ];
        }

        return $grouped;
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