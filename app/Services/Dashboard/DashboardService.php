<?php

namespace App\Services\Dashboard;

use App\Models\Users\UserProfileModel;
use App\Models\Courses\EnrollmentModel;
use CodeIgniter\Shield\Entities\User;

class DashboardService
{
    protected UserProfileModel $userProfileModel;
    protected EnrollmentModel $enrollmentModel;

    /**
     * Which widget slugs are visible to each role.
     * Add a new widget for a role by adding its slug here —
     * nothing else in the controller/view needs to change.
     */
    protected array $widgetsByRole = [
        'student' => [
            'profile-card',
            'key-metrics',
            'upcoming-tasks',
            'announcements',
            'reminders',
            'quick-access',
        ],
        'instructor' => [
            'teaching-load',
            'grading-queue',
        ],
    ];

    public function __construct()
    {
        $this->userProfileModel = new UserProfileModel();
        $this->enrollmentModel = new EnrollmentModel();
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
            'profile-card'    => $this->getProfileCardData($user),
            'key-metrics'     => $this->getKeyMetricsData($user),
            'upcoming-tasks'  => $this->getUpcomingTasksData($user),
            'announcements'   => $this->getAnnouncementsData($user),
            'reminders'       => $this->getRemindersData($user),
            'quick-access'    => $this->getQuickAccessData($user),
            'teaching-load'   => $this->getTeachingLoadData($user),
            'grading-queue'   => $this->getGradingQueueData($user),
            default           => [],
        };
    }

    protected function getProfileCardData(User $user): array
    {
        return $this->userProfileModel->retrieveUserProfile($user) ?? [];
    }

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

    protected function getTeachingLoadData(User $user): array
    {
        return [];
    }

    protected function getGradingQueueData(User $user): array
    {
        return [];
    }
}