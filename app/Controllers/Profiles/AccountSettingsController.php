<?php

declare(strict_types=1);

namespace App\Controllers\Profiles;

use App\Controllers\BaseController;
use App\Filters\AccountStateFilter;
use App\Services\Institutions\InstitutionSecurity;
use App\Services\Profiles\PasswordChangeService;
use App\Services\Profiles\ProfileEditException;
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
            ],
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
}
