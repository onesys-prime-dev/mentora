<?php

declare(strict_types=1);

namespace App\Auth\Actions;

use CodeIgniter\Shield\Authentication\Actions\ConditionalActionInterface;
use CodeIgniter\Shield\Authentication\Actions\Email2FA;
use CodeIgniter\Shield\Entities\User;

/**
 * Shield's email two-factor step, but only for system administrators.
 *
 * Shield applies a login action to everyone, and the other roles must keep
 * signing in with a password alone. Until the emailed code is entered the
 * administrator is only "pending": loggedIn() stays false, so the group:admin
 * filter keeps them out of the admin area.
 */
class AdminEmail2FA extends Email2FA implements ConditionalActionInterface
{
    public function appliesTo(User $user): bool
    {
        return $user->inGroup('admin');
    }
}
