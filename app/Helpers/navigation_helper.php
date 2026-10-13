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
                ['key' => 'reports',     'label' => 'Reports',     'icon' => 'score',    'url' => site_url('institution/reports')],
                ['key' => 'settings',    'label' => 'Settings',    'icon' => 'settings', 'url' => site_url('institution/settings')],
                ['key' => 'subscription', 'label' => 'Subscription', 'icon' => 'info',   'url' => site_url('institution/subscription')],
                ['key' => 'integrations', 'label' => 'Integrations', 'icon' => 'link',   'url' => site_url('institution/integrations')],
                ['key' => 'institution', 'label' => 'Data export', 'icon' => 'folder',   'url' => site_url('institution/export')],
            ];
        }

        return [
            ['key' => 'courses',       'label' => 'Courses',       'icon' => 'hat',          'url' => site_url($role . '/courses')],
            ['key' => 'calendar',      'label' => 'Calendar',      'icon' => 'calendar',     'url' => site_url($role . '/calendar')],
            ['key' => 'schedule',      'label' => 'Schedule',      'icon' => 'clock',        'url' => site_url($role . '/schedule')],
            ['key' => 'grades',        'label' => 'Grades',        'icon' => 'grades',       'url' => null],
            ['key' => 'announcements', 'label' => 'Announcements', 'icon' => 'announcement', 'url' => null],
            ['key' => 'inbox',         'label' => 'Inbox',         'icon' => 'inbox',        'url' => null],
            // Chairperson-only; an inert tile everywhere else, same as grades/announcements/inbox above.
            ['key' => 'monitor-program', 'label' => 'Monitor Program', 'icon' => 'score', 'url' => $role === 'chairperson' ? site_url($role . '/monitor-program') : null],
        ];
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
