<?php

declare(strict_types=1);

namespace App\Auth;

use App\Filters\AccountStateFilter;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;

/**
 * Shield's session authenticator, which also records when every session began. A sign-in with a password does
 * that through the `login` event (AuditAuthListener), but a remember-me sign-in fires no event, so without this
 * its session would have no start time and AccountStateFilter would end it after any "sign out everywhere".
 */
class SessionAuthenticator extends Session
{
    public function startLogin(User $user): void
    {
        parent::startLogin($user);

        session()->set(AccountStateFilter::SIGNED_IN_AT, time());
    }
}
