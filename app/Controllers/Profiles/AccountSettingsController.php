<?php

declare(strict_types=1);

namespace App\Controllers\Profiles;

use App\Controllers\BaseController;
use App\Filters\AccountStateFilter;
use App\Services\Institutions\InstitutionSecurity;
use App\Services\Profiles\PasswordChangeService;
use App\Services\Profiles\ProfileEditException;
use App\Services\Profiles\SecurityNotice;
use App\Services\Profiles\TwoFactorPreference;
use App\Services\Users\AccountControl;
use App\Services\Users\PasswordResetService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * /settings, for every role (D21): one page of section cards about the signed-in person's own account. Its writes
 * stay open in a read-only institution (D29-C3), so these routes carry no `readonly` filter.
 */
class AccountSettingsController extends BaseController
{
    private const PAGE = 'settings';

    /** Reset links a person may ask for per 15 minutes. */
    private const LINKS = 3;

    public function index(): string
    {
        $user = auth()->user();

        // Each section is [id, jump-list label, partial]; the jump list is drawn from what is here.
        return view('pages/settings/index', [
            'title'       => 'Settings',
            'page'        => 'settings',
            'flashToasts' => true,
            'user'        => $user,
            'minLength'   => (int) (new InstitutionSecurity())->forUser($user)['passwordMinLength'],
            'sections'    => [
                ['id' => 'password', 'label' => 'Password', 'view' => 'pages/settings/partials/password'],
                ['id' => 'two-step', 'label' => 'Two-step sign-in', 'view' => 'pages/settings/partials/two-step'],
                ['id' => 'devices', 'label' => 'Signed-in devices', 'view' => 'pages/settings/partials/devices'],
            ],
            'twoFactor'   => (new TwoFactorPreference())->status($user),
        ]);
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

        // The change ended every session; this one began "after" it, so it stays (Review Focus 5).
        session()->set(AccountStateFilter::SIGNED_IN_AT, time() + 1);

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

        if ($this->request->getPost('action') === 'on') {
            $service->optIn($user);

            return redirect()->to(site_url(self::PAGE))->with('message', 'Two-step sign-in turned on');
        }

        try {
            $service->optOut($user, (string) $this->request->getPost('password'));
        } catch (ProfileEditException $e) {
            return redirect()->to(site_url(self::PAGE) . '#two-step')->with('errors', [$e->field => $e->getMessage()]);
        }

        return redirect()->to(site_url(self::PAGE))->with('message', 'Two-step sign-in turned off');
    }

    /** Ends every other session the account has; this one began "after" it, so it stays (D14). */
    public function signOutOthers(): RedirectResponse
    {
        $user = auth()->user();

        (new AccountControl())->signOutEverywhere((int) $user->id);
        session()->set(AccountStateFilter::SIGNED_IN_AT, time() + 1);

        service('auditLogger')->log('profile.signed_out_others', $user, ['target_type' => 'user', 'target_id' => (int) $user->id]);
        SecurityNotice::send(
            $user,
            'Your Mentora account was signed out on your other devices',
            "Every other device signed in to your Mentora account ({$user->email}) was signed out on " . date('M j, Y \a\t g:i A') . ".\n\n" . SecurityNotice::ifNotYou(),
        );

        return redirect()->to(site_url(self::PAGE))->with('message', 'Signed out of all other devices');
    }
}
