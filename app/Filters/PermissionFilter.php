<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Auth\RolePermissions;
use CodeIgniter\Shield\Filters\PermissionFilter as ShieldPermissionFilter;

/**
 * `permission:<p>[,<p>…]`, as Shield's filter (the same sign-in redirect and
 * refusal), but answered with the person's institution's settings on top of
 * the platform's (RolePermissions, audit decision U1b).
 */
class PermissionFilter extends ShieldPermissionFilter
{
    protected function isAuthorized(array $arguments): bool
    {
        $user        = auth()->user();
        $permissions = new RolePermissions();

        foreach ($arguments as $permission) {
            if ($permissions->allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }
}
