<?php

declare(strict_types=1);

namespace App\Controllers\Parents;

use App\Controllers\BaseController;
use App\Services\Parents\ParentAccessService;
use App\Services\Parents\ParentPortal;
use App\Services\Parents\ParentPreferences;
use App\Services\Subscriptions\PlanLimits;
use App\Services\Users\PasswordResetService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;

/**
 * The parent's own page: their account, a way to change their password, and
 * whether Mentora emails them about each child. These two forms are the only
 * things a parent can change, and both are about their own account: nothing
 * here touches a child's data. The password is changed through an emailed,
 * one-time link (the same one an administrator can send), so there is no
 * password form to get wrong; the link goes only to the signed-in parent's
 * own address.
 */
class ParentProfileController extends BaseController
{
    private const PAGE = 'parent/profile';

    public function index(): string
    {
        $user     = auth()->user();
        $portalOn = (new ParentPortal())->enabled((new PlanLimits())->institutionOf((int) $user->id));
        $prefs    = new ParentPreferences();
        $profile  = db_connect()->table('user_profiles')->select('first_name, last_name')->where('user_id', (int) $user->id)->get()->getRowArray() ?? [];

        $children = [];

        // The children are shown only while the portal is on; the account always is.
        if ($portalOn) {
            foreach ((new ParentAccessService())->children($user) as $child) {
                $children[] = $child + ['email' => $prefs->emailEnabled((int) $user->id, $child['student_id'])];
            }
        }

        return view('pages/parent/profile', [
            'title'        => 'Profile',
            'page'         => 'profile',
            'user'         => $user,
            'flashToasts'  => true,
            'accountName'  => trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: (string) $user->username,
            'accountEmail' => (string) $user->email,
            'portalOn'     => $portalOn,
            'children'     => $children,
        ]);
    }

    /** Saves which children the parent wants email about: the ticked ones on, every other child of theirs off. */
    public function saveNotifications(): RedirectResponse
    {
        $user   = auth()->user();
        $ticked = array_map('intval', (array) $this->request->getPost('email'));
        $choice = [];

        foreach ((new ParentAccessService())->children($user) as $child) {
            $choice[$child['student_id']] = in_array($child['student_id'], $ticked, true);
        }

        (new ParentPreferences())->save((int) $user->id, $choice);

        return redirect()->to(site_url(self::PAGE))->with('message', 'Your email choices were saved.');
    }

    /** Emails the signed-in parent a link to choose a new password. */
    public function sendPasswordLink(): RedirectResponse
    {
        $user = auth()->user();

        try {
            $result = (new PasswordResetService())->send((int) $user->id, $user);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url(self::PAGE))->with('error', $e->getMessage());
        }

        if ($result['email_error'] !== null) {
            return redirect()->to(site_url(self::PAGE))->with('error', PasswordResetService::SEND_FAILED);
        }

        return redirect()->to(site_url(self::PAGE))->with('message', 'We emailed a link to ' . $result['email'] . '. It works once and expires soon.');
    }
}
