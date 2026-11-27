<?php

declare(strict_types=1);

namespace App\Controllers\Profiles;

use App\Controllers\BaseController;
use App\Filters\AccountStateFilter;
use App\Filters\MessagesFilter;
use App\Services\Auth\RolePermissions;
use App\Services\Concerns\InTransaction;
use App\Services\Institutions\InstitutionSecurity;
use App\Services\Messaging\MessagePreferences;
use App\Services\Parents\ParentAccessService;
use App\Services\Parents\ParentPortal;
use App\Services\Parents\ParentPreferences;
use App\Services\Profiles\PasswordChangeService;
use App\Services\Profiles\ProfileEditException;
use App\Services\Profiles\SecurityActivity;
use App\Services\Profiles\SecurityNotice;
use App\Services\Profiles\TwoFactorPreference;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Users\AccountControl;
use App\Services\Users\PasswordResetService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Entities\User;
use InvalidArgumentException;

/**
 * /settings, for every role (D21): one page of section cards about the signed-in person's own account. Its writes
 * stay open in a read-only institution (D29-C3), so these routes carry no `readonly` filter.
 */
class AccountSettingsController extends BaseController
{
    use InTransaction;

    private const PAGE = 'settings';

    /** Reset links a person may ask for per 15 minutes. */
    private const LINKS = 3;

    public function index(): string
    {
        $user          = auth()->user();
        $notifications = $this->notifications($user);

        // Each section is [id, jump-list label, partial]; the jump list is drawn from what is here.
        return view('pages/settings/index', [
            'title'       => 'Settings',
            'page'        => 'settings',
            'flashToasts' => true,
            'user'        => $user,
            'minLength'   => (int) (new InstitutionSecurity())->forUser($user)['passwordMinLength'],
            'hasSchool'   => (new PlanLimits())->institutionOf((int) $user->id) !== null,
            'sections'    => [
                ['id' => 'password', 'label' => 'Password', 'view' => 'pages/settings/partials/password'],
                ['id' => 'two-step', 'label' => 'Two-step sign-in', 'view' => 'pages/settings/partials/two-step'],
                ['id' => 'devices', 'label' => 'Signed-in devices', 'view' => 'pages/settings/partials/devices'],
                // Nothing to choose (Messages closed to them and not a parent): no card.
                ...($notifications['messages'] === null && $notifications['parent'] === null ? [] : [
                    ['id' => 'notifications', 'label' => 'Notifications', 'view' => 'pages/settings/partials/notifications'],
                ]),
                ['id' => 'activity', 'label' => 'Recent activity', 'view' => 'pages/settings/partials/activity'],
            ],
            'twoFactor'     => (new TwoFactorPreference())->status($user),
            'notifications' => $notifications,
            'activity'      => (new SecurityActivity())->recent((int) $user->id),
        ]);
    }

    /**
     * Saves the Notifications card (D15): the Messages email switch and quiet hours, and a parent's email choice
     * for each child, with the same rules as before. A choice left as it was is not written again (AC8).
     */
    public function saveNotifications(): RedirectResponse
    {
        $user  = auth()->user();
        $parts = $this->notifications($user);
        $post  = fn (string $key): string => (string) $this->request->getPost($key);

        if ($parts['messages'] !== null) {
            // A form that sends no choice leaves the stored one; an unknown value is refused.
            $mode = $this->request->getPost('quiet_mode') === null ? $parts['messages']['quiet_mode'] : $post('quiet_mode');

            if (! in_array($mode, MessagePreferences::QUIET_MODES, true)) {
                return redirect()->to(site_url(self::PAGE) . '#notifications')->withInput()->with('errors', ['quiet_mode' => 'Choose when you want quiet hours.']);
            }

            // Times only count for `own`; one not posted keeps what is stored.
            // The stored times are theirs only when the stored mode is `own` (else they are the school's or none).
            $keep  = $parts['messages']['quiet_mode'] === 'own';
            $start = $this->request->getPost('quiet_start') === null ? ($keep ? $parts['messages']['quiet_start'] : null) : $post('quiet_start');
            $end   = $this->request->getPost('quiet_end') === null ? ($keep ? $parts['messages']['quiet_end'] : null) : $post('quiet_end');

            // A form that sends no choice leaves the stored one; an unknown value is refused.
            $scope = $this->request->getPost('email_scope') === null ? $parts['messages']['email_scope'] : $post('email_scope');

            if (! in_array($scope, MessagePreferences::SCOPES, true)) {
                return redirect()->to(site_url(self::PAGE) . '#notifications')->withInput()->with('errors', ['email_scope' => 'Choose who may email you about new messages.']);
            }

            if (! (new MessagePreferences())->save($user, $start, $end, $scope, $mode)) {
                return redirect()->to(site_url(self::PAGE) . '#notifications')->withInput()->with('errors', ['quiet_start' => 'Enter both times, as hours and minutes.']);
            }
        }

        if ($parts['parent'] !== null && $parts['parent']['portal_on']) {
            $ticked  = array_map('intval', (array) $this->request->getPost('email'));
            $changed = [];

            foreach ($parts['parent']['children'] as $child) {
                $want = in_array($child['student_id'], $ticked, true);

                if ($want !== $child['email']) {
                    $changed[$child['student_id']] = $want;
                }
            }

            (new ParentPreferences())->save((int) $user->id, $changed);
        }

        return redirect()->to(site_url(self::PAGE))->with('message', 'Notifications saved');
    }

    /** The old addresses of these forms (D15, D23): sent to their card here, without acting. */
    public function moved(string $section): RedirectResponse
    {
        return redirect()->to(site_url(self::PAGE) . '#' . $section)->setStatusCode($this->request->getMethod() === 'GET' ? 301 : 303);
    }

    public function changePassword(): RedirectResponse
    {
        $user = auth()->user();
        $post = fn (string $key): string => (string) $this->request->getPost($key);

        try {
            (new PasswordChangeService())->change($user, $post('current_password'), $post('password'), $post('password_confirm'));
        } catch (ProfileEditException $e) {
            return redirect()->to(site_url(self::PAGE) . '#password')->with('errors', [$e->field => $e->getMessage()]);
        }

        // The change ended every session; this one began "after" it, so it stays (Review Focus 5), under a new id.
        $this->renewSession();

        return redirect()->to(site_url(self::PAGE))->with('message', 'Password changed');
    }

    /** Emails the signed-in person a link to choose a new password, for when they forgot the current one. */
    public function sendPasswordLink(): RedirectResponse
    {
        $user = auth()->user();
        $back = redirect()->to(site_url(self::PAGE) . '#password');

        if (! service('throttler')->check('password-link-' . $user->id, self::LINKS, PasswordChangeService::WINDOW)) {
            return $back->with('error', PasswordChangeService::TOO_MANY);
        }

        try {
            $result = (new PasswordResetService())->send((int) $user->id, $user);
        } catch (InvalidArgumentException $e) {
            return $back->with('error', $e->getMessage());
        }

        if ($result['email_error'] !== null) {
            return $back->with('error', 'The email could not be sent. Try again in a few minutes.');
        }

        return $back->with('message', 'We emailed you a link to set a new password.');
    }

    /** Turns emailed two-step sign-in on (action=on) or off (action=off, with the password) (D14). */
    public function twoFactor(): RedirectResponse
    {
        $user    = auth()->user();
        $service = new TwoFactorPreference();

        $on      = $this->request->getPost('action') === 'on';

        try {
            $on ? $service->optIn($user) : $service->optOut($user, (string) $this->request->getPost('password'));
        } catch (ProfileEditException $e) {
            return redirect()->to(site_url(self::PAGE) . '#two-step')->with('errors', [$e->field => $e->getMessage()]);
        }

        return redirect()->to(site_url(self::PAGE))->with('message', $on ? 'Two-step sign-in turned on' : 'Two-step sign-in turned off');
    }

    /** Ends every other session the account has; this one began "after" it, so it stays (D14). */
    public function signOutOthers(): RedirectResponse
    {
        $user = auth()->user();

        // Each one emails the owner, so a stolen session cannot flood their inbox.
        if (! service('throttler')->check('sign-out-others-' . $user->id, PasswordChangeService::ATTEMPTS, PasswordChangeService::WINDOW)) {
            return redirect()->to(site_url(self::PAGE) . '#devices')->with('error', PasswordChangeService::TOO_MANY);
        }

        // The sign-out and its audit entry happen together or not at all.
        $this->inTransaction(static function () use ($user): void {
            (new AccountControl())->signOutEverywhere((int) $user->id);
            service('auditLogger')->log('profile.signed_out_others', $user, ['target_type' => 'user', 'target_id' => (int) $user->id]);
        });
        $this->renewSession();

        SecurityNotice::send(
            $user,
            'Your Mentora account was signed out on your other devices',
            "Every other device signed in to your Mentora account ({$user->email}) was signed out on " . date('M j, Y \a\t g:i A') . ".\n\n" . SecurityNotice::ifNotYou(),
        );

        return redirect()->to(site_url(self::PAGE))->with('message', 'Signed out of all other devices');
    }

    /**
     * After every other session was ended: this one counts as begun after it, and gets a new id (the old id may be
     * what someone else holds). The data, its start time included, moves to the new id.
     */
    private function renewSession(): void
    {
        session()->set(AccountStateFilter::SIGNED_IN_AT, time() + 1);
        // Only the id changes: the CSRF hash in the data is kept on purpose, so the page's other open forms still post.
        session()->regenerate(true);
    }

    /**
     * What the Notifications card offers this person: the Messages part while Messages is open to them, and the
     * parent part for a parent (their children only while the portal is on, as before).
     *
     * @return array{
     *     messages: array{quiet_mode: string, quiet_start: ?string, quiet_end: ?string, email_alerts: bool, email_scope: string, school_default: array{0: ?string, 1: ?string}}|null,
     *     parent: array{portal_on: bool, children: list<array{student_id: int, name: string, email: bool}>}|null,
     * }
     */
    private function notifications(User $user): array
    {
        $messages = null;

        if (! MessagesFilter::closed() && (new RolePermissions())->allows($user, 'messages.use')) {
            $prefs    = new MessagePreferences();
            $messages = $prefs->get((int) $user->id) + ['school_default' => $prefs->schoolDefault((int) $user->id)];
        }

        $parent = null;

        if ($user->inGroup('parent')) {
            $portalOn = (new ParentPortal())->enabled((new PlanLimits())->institutionOf((int) $user->id));
            $prefs    = new ParentPreferences();
            $children = [];

            foreach ($portalOn ? (new ParentAccessService())->children($user) : [] as $child) {
                $children[] = $child + ['email' => $prefs->emailEnabled((int) $user->id, $child['student_id'])];
            }

            $parent = ['portal_on' => $portalOn, 'children' => $children];
        }

        return ['messages' => $messages, 'parent' => $parent];
    }
}
