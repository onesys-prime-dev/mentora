<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Auth\RolePermissions;
use CodeIgniter\Shield\Filters\PermissionFilter as ShieldPermissionFilter;

/**
 * `permission:<p>[,<p>…]`, as Shield's filter (the same sign-in redirect and
 * refusal), but answered with the person's institution's settings on top of
 * the platform's (RolePermissions, audit decision U1b).
 *
 * `<p>@<role>` asks only that role: a dean's page asks the dean's permission,
 * so a dean who also teaches is not let in by what their instructor role has.
 */
class PermissionFilter extends ShieldPermissionFilter
{
    protected function isAuthorized(array $arguments): bool
    {
        $user        = auth()->user();
        $permissions = new RolePermissions();

        foreach ($arguments as $argument) {
            [$permission, $role] = array_pad(explode('@', $argument, 2), 2, null);

            if ($permissions->allows($user, $permission, $role)) {
                return true;
            }
        }

        return false;
    }
}
