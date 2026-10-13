<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Institutions\InstitutionSecurity;
use CodeIgniter\Shield\Entities\User;

/**
 * Whether a password may be set (SA8): the minimum length in force (the
 * platform's, or the person's institution's if it asks for more), then
 * Shield's checks (not a common password, not built from the person's name or
 * email). Used wherever a person chooses a password: accepting an invitation
 * and a password reset.
 */
class PasswordPolicy
{
    /** The reason the password is not acceptable, worded for the person, or null when it is fine. */
    public function problem(string $password, User $user, ?int $institutionId = null): ?string
    {
        // Someone being invited has no account yet: the invitation says which institution it is for.
        $security = new InstitutionSecurity();
        $rules    = $institutionId !== null ? $security->forInstitution($institutionId)['effective'] : $security->forUser($user);
        $minimum  = (int) $rules['passwordMinLength'];

        if (mb_strlen($password) < $minimum) {
            return "The password must be at least {$minimum} characters.";
        }

        $check = service('passwords')->check($password, $user);

        return $check->isOK() ? null : $check->reason();
    }
}
