<?php

use App\Models\Users\UserProfileModel;
use CodeIgniter\Shield\Entities\User;

/*
|--------------------------------------------------------------------------
| Global navigation
|--------------------------------------------------------------------------
|
| The header's app menu and the dashboard's Quick Access draw from the same
| list, so the two never drift apart. Only pages outside a single course
| belong here — course pages (modules, assessments, ...) live in the course
| sidebar instead.
|
*/

if (! function_exists('role_home')) {

    /**
     * The first address segment of a role's own area (`student` -> "student",
     * `institutional_admin` -> "institution"): where its dashboard lives.
     */
    function role_home(string $role): string
    {
        return $role === 'institutional_admin' ? 'institution' : $role;
    }
}

if (! function_exists('messages_unread_count')) {

    /**
     * Messages waiting for the signed-in person: the badge on the Messages tile.
     * Zero when nobody is signed in, and never an error: a page must still draw if Messages cannot count.
     */
    function messages_unread_count(): int
    {
        $user = auth()->user();

        if ($user === null) {
            return 0;
        }

        try {
            return (new \App\Services\Messaging\ConversationService())->unreadCount($user);
        } catch (\Throwable) {
            return 0;
        }
    }
}

if (! function_exists('messages_tile')) {

    /**
     * The Messages tile for a role, or null while the `messages` flag is off, for the system
     * administrator, and when the signed-in person's role lacks `messages.use`.
     *
     * @return array{key: string, label: string, icon: string, url: string, badge: int}|null
     */
    function messages_tile(string $role): ?array
    {
        helper('feature');

        $user = auth()->user();

        // An unknown flag throws in feature_enabled(); a menu must not take the page down, so it counts as off.
        try {
            $on = feature_enabled('messages');
        } catch (\InvalidArgumentException) {
            $on = false;
        }

        if ($role === 'admin' || $user === null || ! $on
            || ! (new \App\Services\Auth\RolePermissions())->allows($user, 'messages.use', $role)) {
            return null;
        }

        return ['key' => 'messages', 'label' => 'Messages', 'icon' => 'inbox', 'url' => site_url('messages'), 'badge' => messages_unread_count()];
    }
}

if (! function_exists('global_apps')) {

    /**
     * Global apps, same set for every role. `url` is null while a page isn't
     * built yet — those render as inert tiles (see components/app-tile.php).
     *
     * The Grades tile may carry a `badge` count and its `badge_label` (grades_badge_on());
     * the Messages tile carries its unread count as `badge` (messages_tile()).
     *
     * @return list<array{key: string, label: string, icon: string, url: ?string, badge?: int, badge_label?: string}>
     */
    function global_apps(string $role): array
    {
        // The institutional administrator has no courses, calendar or schedule of their own:
        // their tools are the institution's. Tiles without a url are built in later branches.
        if ($role === 'institutional_admin') {
            return [
                ['key' => 'dashboard',   'label' => 'Home',        'icon' => 'apps',     'url' => site_url('institution/dashboard')],
                ['key' => 'academics',   'label' => 'Structure',   'icon' => 'hat',      'url' => site_url('institution/structure')],
                ['key' => 'courses',     'label' => 'Courses',     'icon' => 'resources', 'url' => site_url('institution/courses')],
                ['key' => 'terms',       'label' => 'Terms',       'icon' => 'calendar', 'url' => site_url('institution/terms')],
                ['key' => 'offerings',   'label' => 'Offerings',   'icon' => 'modules',  'url' => site_url('institution/offerings')],
                ['key' => 'people',      'label' => 'People',      'icon' => 'user',     'url' => site_url('institution/people')],
                ['key' => 'roles',       'label' => 'Roles and permissions', 'icon' => 'lock', 'url' => site_url('institution/roles')],
                ['key' => 'approvals',   'label' => 'Approvals',   'icon' => 'tasks',    'url' => site_url('institution/approvals')],
                ['key' => 'signoff',     'label' => 'Sign-off',    'icon' => 'check',    'url' => site_url('institution/signoff')],
                ['key' => 'reports',     'label' => 'Reports',     'icon' => 'score',    'url' => site_url('institution/reports')],
                ['key' => 'settings',    'label' => 'Settings',    'icon' => 'settings', 'url' => site_url('institution/settings')],
                ['key' => 'subscription', 'label' => 'Subscription', 'icon' => 'info',   'url' => site_url('institution/subscription')],
                ['key' => 'integrations', 'label' => 'Integrations', 'icon' => 'link',   'url' => site_url('institution/integrations')],
                ['key' => 'audit',       'label' => 'Audit log',   'icon' => 'lock',     'url' => site_url('institution/audit')],
                ['key' => 'institution', 'label' => 'Data export', 'icon' => 'folder',   'url' => site_url('institution/export')],
                ...array_filter([messages_tile($role)]),
            ];
        }

        // The dean's tools arrive one branch at a time; until then only the landing page exists.
        if ($role === 'dean') {
            $dean = [
                ['key' => 'dashboard', 'label' => 'Home', 'icon' => 'apps', 'url' => site_url('dean/dashboard')],
                ['key' => 'courses', 'label' => 'Courses', 'icon' => 'hat', 'url' => site_url('dean/courses')],
                ['key' => 'grades', 'label' => 'Grades', 'icon' => 'grades', 'url' => site_url('dean/grades')],
                ['key' => 'approvals', 'label' => 'Approvals', 'icon' => 'tasks', 'url' => site_url('dean/approvals')],
                ['key' => 'signoff', 'label' => 'Sign-off', 'icon' => 'check', 'url' => site_url('dean/signoff')],
                ['key' => 'follow-ups', 'label' => 'Follow-ups', 'icon' => 'inbox', 'url' => site_url('dean/follow-ups')],
                ['key' => 'announcements', 'label' => 'Announcements', 'icon' => 'announcement', 'url' => site_url('dean/announcements')],
                ['key' => 'teaching-load', 'label' => 'Teaching load', 'icon' => 'modules', 'url' => site_url('dean/teaching-load')],
                ['key' => 'chairperson-review', 'label' => 'Chairperson review', 'icon' => 'tasks', 'url' => site_url('dean/chairperson-review')],
                ['key' => 'monitor-department', 'label' => 'Monitor Department', 'icon' => 'score', 'url' => site_url('dean/monitor-department')],
            ];

            // Only what their institution lets them open (QA AMB-AUTH-08): each tile's page refuses without its
            // permission. Follow-ups stays: what was received can be answered without followups.manage.
            if (($user = auth()->user()) !== null) {
                $permissions = new \App\Services\Auth\RolePermissions();
                $gates       = [
                    'courses'            => 'courses.view',
                    'grades'             => 'department.monitor',
                    'approvals'          => 'grades.approve',
                    'signoff'            => 'grades.signoff',
                    'announcements'      => 'department.announce',
                    'monitor-department' => 'department.monitor',
                ];
                // Asked of the dean role only: what another role of theirs has does not open the dean's pages.
                $hidden      = array_keys(array_filter($gates, static fn (string $p): bool => ! $permissions->allows($user, $p, 'dean')));
                $dean        = array_values(array_filter($dean, static fn (array $app): bool => ! in_array($app['key'], $hidden, true)));
            }

            return grades_badge_on([...$dean, ...array_filter([messages_tile($role)])], $role);
        }

        // A parent's tools: their children's dashboard, calendar and grades, the shared notifications
        // list, and their own profile.
        if ($role === 'parent') {
            return grades_badge_on([
                ['key' => 'dashboard', 'label' => 'Home', 'icon' => 'apps', 'url' => site_url('parent/dashboard')],
                ['key' => 'calendar', 'label' => 'Calendar', 'icon' => 'calendar', 'url' => site_url('parent/calendar')],
                ['key' => 'grades', 'label' => 'Grades', 'icon' => 'grades', 'url' => site_url('parent/grades')],
                ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'bell', 'url' => site_url('notifications')],
                ['key' => 'profile', 'label' => 'Profile', 'icon' => 'user', 'url' => site_url('parent/profile')],
                ...array_filter([messages_tile($role)]),
            ], $role);
        }

        // The system administrator's tools. The page also has its own grouped navigation bar;
        // this is the same set as tiles, so the Apps menu is the same everywhere.
        if ($role === 'admin') {
            return [
                ['key' => 'dashboard',    'label' => 'Home',          'icon' => 'home',      'url' => site_url('admin/dashboard')],
                ['key' => 'institutions', 'label' => 'Institutions',  'icon' => 'hat',       'url' => site_url('admin/institutions')],
                ['key' => 'plans',        'label' => 'Plans',         'icon' => 'points',    'url' => site_url('admin/plans')],
                ['key' => 'users',        'label' => 'Users',         'icon' => 'user',      'url' => site_url('admin/users')],
                ['key' => 'flags',        'label' => 'Feature flags', 'icon' => 'pin',       'url' => site_url('admin/flags')],
                ['key' => 'roles',        'label' => 'Roles',         'icon' => 'lock',      'url' => site_url('admin/roles')],
                ['key' => 'monitoring',   'label' => 'System status', 'icon' => 'score',     'url' => site_url('admin/monitoring')],
                ['key' => 'backups',      'label' => 'Backups',       'icon' => 'folder',    'url' => site_url('admin/backups')],
                ['key' => 'audit',        'label' => 'Audit log',     'icon' => 'tasks',     'url' => site_url('admin/audit-log')],
                ['key' => 'archive',      'label' => 'Archive',       'icon' => 'drafts',    'url' => site_url('admin/archive')],
                ['key' => 'general',      'label' => 'General',       'icon' => 'settings',  'url' => site_url('admin/settings/general')],
                ['key' => 'security',     'label' => 'Security',      'icon' => 'attention', 'url' => site_url('admin/settings/security')],
            ];
        }

        $apps = [
            ['key' => 'courses',       'label' => 'Courses',       'icon' => 'hat',          'url' => site_url($role . '/courses')],
            ['key' => 'calendar',      'label' => 'Calendar',      'icon' => 'calendar',     'url' => site_url($role . '/calendar')],
            ['key' => 'schedule',      'label' => 'Schedule',      'icon' => 'clock',        'url' => site_url($role . '/schedule')],
            // The dean's and the parent's Grades tiles are in their own lists above.
            ['key' => 'grades',        'label' => 'Grades',        'icon' => 'grades',       'url' => match ($role) {
                'instructor'  => site_url('instructor/grades'),
                'student'     => site_url('student/grades'),
                'chairperson' => site_url('chairperson/grades'),
                default      => null,
            }],
            ['key' => 'announcements', 'label' => 'Announcements', 'icon' => 'announcement', 'url' => in_array($role, ['student', 'instructor', 'chairperson'], true) ? site_url($role . '/announcements') : null],
        ];

        // Two of the chairperson's own pages. No other role is shown these tiles.
        if ($role === 'chairperson') {
            // Only when their institution lets them (program.monitor).
            if (($user = auth()->user()) === null || (new \App\Services\Auth\RolePermissions())->allows($user, 'program.monitor')) {
                $apps[] = ['key' => 'monitor-program', 'label' => 'Monitor Program', 'icon' => 'score', 'url' => site_url($role . '/monitor-program')];
            }

            $apps[] = ['key' => 'override-requests', 'label' => 'Override requests', 'icon' => 'tasks', 'url' => site_url('chairperson/override-requests')];
            $apps[] = ['key' => 'follow-ups', 'label' => 'Follow-ups', 'icon' => 'inbox', 'url' => site_url('chairperson/follow-ups')];
        }

        // The instructor's, the student's and the chairperson's Grades pages need courses.view (in that role):
        // without it there is no page, so no tile.
        if (in_array($role, ['instructor', 'student', 'chairperson'], true) && ($user = auth()->user()) !== null && ! (new \App\Services\Auth\RolePermissions())->allows($user, 'courses.view', $role)) {
            $apps = array_values(array_filter($apps, static fn (array $app): bool => $app['key'] !== 'grades'));
        }

        // What the dean or a chairperson asked of an instructor.
        if ($role === 'instructor') {
            $apps[] = ['key' => 'follow-ups', 'label' => 'Requests', 'icon' => 'inbox', 'url' => site_url('instructor/follow-ups')];
        }

        if (($messages = messages_tile($role)) !== null) {
            $apps[] = $messages;
        }

        return grades_badge_on($apps, $role);
    }
}

if (! function_exists('grades_badge_on')) {

    /**
     * The Grades tile with what needs the signed-in person there (GradesBadge: cached a minute, worked out once
     * per request), as `badge` and its spoken `badge_label`; nothing when the count is 0, when no one is signed
     * in, or when the list has no Grades page to open.
     *
     * @param list<array<string, mixed>> $apps
     *
     * @return list<array<string, mixed>>
     */
    function grades_badge_on(array $apps, string $role): array
    {
        if (($user = auth()->user()) === null) {
            return $apps;
        }

        foreach ($apps as $i => $app) {
            if ($app['key'] !== 'grades' || $app['url'] === null) {
                continue;
            }

            $count = \App\Services\Grades\GradesBadge::for($user, $role);

            if ($count > 0) {
                $apps[$i]['badge']       = $count;
                $apps[$i]['badge_label'] = \App\Services\Grades\GradesBadge::label($role, $count);
            }
        }

        return $apps;
    }
}

if (! function_exists('current_user_profile')) {

    /**
     * Name, student number, program and email for the header's account
     * menu. Cached per request — the header renders on every page.
     */
    function current_user_profile(User $user): array
    {
        static $profiles = [];

        return $profiles[$user->id] ??= (new UserProfileModel())->retrieveUserProfile($user) ?? [];
    }
}
