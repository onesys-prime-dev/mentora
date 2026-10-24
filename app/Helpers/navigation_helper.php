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

if (! function_exists('global_apps')) {

    /**
     * Global apps, same set for every role. `url` is null while a page isn't
     * built yet — those render as inert tiles (see components/app-tile.php).
     *
     * @return list<array{key: string, label: string, icon: string, url: ?string}>
     */
    function global_apps(string $role): array
    {
        // The institutional administrator has no courses, calendar or schedule of their own:
        // their tools are the institution's. Tiles without a url are built in later branches.
        if ($role === 'institutional_admin') {
            return [
                ['key' => 'dashboard',   'label' => 'Home',        'icon' => 'apps',     'url' => site_url('institution/dashboard')],
                ['key' => 'academics',   'label' => 'Academics',   'icon' => 'hat',      'url' => site_url('institution/structure')],
                ['key' => 'terms',       'label' => 'Terms',       'icon' => 'calendar', 'url' => site_url('institution/terms')],
                ['key' => 'offerings',   'label' => 'Offerings',   'icon' => 'modules',  'url' => site_url('institution/offerings')],
                ['key' => 'people',      'label' => 'People',      'icon' => 'user',     'url' => site_url('institution/people')],
                ['key' => 'roles',       'label' => 'Roles and permissions', 'icon' => 'lock', 'url' => site_url('institution/roles')],
                ['key' => 'reports',     'label' => 'Reports',     'icon' => 'score',    'url' => site_url('institution/reports')],
                ['key' => 'settings',    'label' => 'Settings',    'icon' => 'settings', 'url' => site_url('institution/settings')],
                ['key' => 'subscription', 'label' => 'Subscription', 'icon' => 'info',   'url' => site_url('institution/subscription')],
                ['key' => 'integrations', 'label' => 'Integrations', 'icon' => 'link',   'url' => site_url('institution/integrations')],
                ['key' => 'audit',       'label' => 'Audit log',   'icon' => 'lock',     'url' => site_url('institution/audit')],
                ['key' => 'institution', 'label' => 'Data export', 'icon' => 'folder',   'url' => site_url('institution/export')],
            ];
        }

        // The dean's tools arrive one branch at a time; until then only the landing page exists.
        if ($role === 'dean') {
            $dean = [
                ['key' => 'dashboard', 'label' => 'Home', 'icon' => 'apps', 'url' => site_url('dean/dashboard')],
                ['key' => 'courses', 'label' => 'Courses', 'icon' => 'hat', 'url' => site_url('dean/courses')],
                ['key' => 'approvals', 'label' => 'Approvals', 'icon' => 'tasks', 'url' => site_url('dean/approvals')],
                ['key' => 'signoff', 'label' => 'Sign-off', 'icon' => 'check', 'url' => site_url('dean/signoff')],
                ['key' => 'follow-ups', 'label' => 'Follow-ups', 'icon' => 'inbox', 'url' => site_url('dean/follow-ups')],
                ['key' => 'announcements', 'label' => 'Announcements', 'icon' => 'announcement', 'url' => site_url('dean/announcements')],
                ['key' => 'teaching-load', 'label' => 'Teaching load', 'icon' => 'modules', 'url' => site_url('dean/teaching-load')],
                ['key' => 'chairperson-review', 'label' => 'Chairperson review', 'icon' => 'tasks', 'url' => site_url('dean/chairperson-review')],
                ['key' => 'monitor-department', 'label' => 'Monitor Department', 'icon' => 'score', 'url' => site_url('dean/monitor-department')],
            ];

            // Only when their institution lets them (department.monitor).
            if (($user = auth()->user()) !== null && ! (new \App\Services\Auth\RolePermissions())->allows($user, 'department.monitor')) {
                $dean = array_values(array_filter($dean, static fn (array $app): bool => $app['key'] !== 'monitor-department'));
            }

            return $dean;
        }

        // A parent's tools: their children's dashboard and calendar, the shared notifications list,
        // and their own profile.
        if ($role === 'parent') {
            return [
                ['key' => 'dashboard', 'label' => 'Home', 'icon' => 'apps', 'url' => site_url('parent/dashboard')],
                ['key' => 'calendar', 'label' => 'Calendar', 'icon' => 'calendar', 'url' => site_url('parent/calendar')],
                ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'bell', 'url' => site_url('notifications')],
                ['key' => 'profile', 'label' => 'Profile', 'icon' => 'user', 'url' => site_url('parent/profile')],
            ];
        }

        // The system administrator's tools. The page also has its own grouped navigation bar;
        // this is the same set as tiles, so the Apps menu is the same everywhere.
        if ($role === 'admin') {
            return [
                ['key' => 'dashboard',    'label' => 'Home',          'icon' => 'apps',      'url' => site_url('admin/dashboard')],
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
            ['key' => 'grades',        'label' => 'Grades',        'icon' => 'grades',       'url' => null],
            ['key' => 'announcements', 'label' => 'Announcements', 'icon' => 'announcement', 'url' => in_array($role, ['student', 'instructor', 'chairperson'], true) ? site_url($role . '/announcements') : null],
            ['key' => 'inbox',         'label' => 'Inbox',         'icon' => 'inbox',        'url' => null],
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

        // What the dean or a chairperson asked of an instructor.
        if ($role === 'instructor') {
            $apps[] = ['key' => 'follow-ups', 'label' => 'Requests', 'icon' => 'inbox', 'url' => site_url('instructor/follow-ups')];
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
