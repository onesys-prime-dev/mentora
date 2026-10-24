<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Admin\PermissionMatrix;
use App\Services\Subscriptions\PlanLimits;
use CodeIgniter\Shield\Authorization\PermissionMatcher;
use CodeIgniter\Shield\Entities\User;
use Config\AuthGroups;

/**
 * May this person do this? Their roles, in their institution's permission
 * settings applied on top of the platform's (audit decisions U1a, U1b).
 *
 * The platform matrix is the system administrator's (PermissionMatrix); an
 * institution's rows in role_permissions change only the boxes it set, so a
 * box it never touched follows the platform, later changes included. Someone
 * in no institution (a system administrator) gets the platform matrix. A
 * permission granted to the account itself (Shield's per-user permissions)
 * counts as well.
 *
 * Every permission check of a route goes through here (the `permission`
 * filter). Worked out once per institution and per person within a request;
 * flush() forgets it after a change.
 */
final class RolePermissions
{
    /** @var array<int, array<string, list<string>>> institution id => matrix */
    private static array $matrices = [];

    /** @var array<int, int> user id => institution id (0 for none) */
    private static array $institutions = [];

    public static function flush(): void
    {
        self::$matrices     = [];
        self::$institutions = [];
    }

    /** @return array<string, list<string>> role => permissions, for the people of this institution (0: the platform) */
    public function matrixFor(int $institutionId): array
    {
        $platform = config(AuthGroups::class)->matrix;

        if ($institutionId === 0) {
            return $platform;
        }

        // No refill: a role the institution cut down, which a later platform change then empties, stays
        // empty rather than getting back everything the institution took away.
        return self::$matrices[$institutionId] ??= PermissionMatrix::apply(
            $platform,
            PermissionMatrix::storedOverrides($institutionId),
            array_keys(config(AuthGroups::class)->permissions),
            null,
            false,
        );
    }

    /**
     * @param string|null $asRole the one role to judge by (the role a course route acts as), or null for any of
     *                            the person's roles; a role they do not hold counts for nothing
     */
    public function allows(User $user, string $permission, ?string $asRole = null): bool
    {
        $permission = strtolower($permission);

        if (PermissionMatcher::matches($permission, $user->getPermissions())) {
            return true;
        }

        $institution = self::$institutions[(int) $user->id] ??= ((new PlanLimits())->institutionOf((int) $user->id) ?? 0);
        $matrix      = $this->matrixFor($institution);
        $groups      = $user->getGroups() ?? [];

        if ($asRole !== null) {
            $groups = in_array($asRole, $groups, true) ? [$asRole] : [];
        }

        foreach ($groups as $group) {
            if (isset($matrix[$group]) && PermissionMatcher::matches($permission, $matrix[$group])) {
                return true;
            }
        }

        return false;
    }
}
