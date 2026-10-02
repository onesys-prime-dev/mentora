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

if (! function_exists('global_apps')) {

    /**
     * Global apps, same set for every role. `url` is null while a page isn't
     * built yet — those render as inert tiles (see components/app-tile.php).
     *
     * @return list<array{key: string, label: string, icon: string, url: ?string}>
     */
    function global_apps(string $role): array
    {
        return [
            ['key' => 'courses',       'label' => 'Courses',       'icon' => 'hat',          'url' => site_url($role . '/courses')],
            ['key' => 'schedule',      'label' => 'Schedule',      'icon' => 'clock',        'url' => site_url($role . '/schedule')],
            ['key' => 'calendar',      'label' => 'Calendar',      'icon' => 'calendar',     'url' => null],
            ['key' => 'grades',        'label' => 'Grades',        'icon' => 'grades',       'url' => null],
            ['key' => 'announcements', 'label' => 'Announcements', 'icon' => 'announcement', 'url' => null],
            ['key' => 'inbox',         'label' => 'Inbox',         'icon' => 'inbox',        'url' => null],
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
