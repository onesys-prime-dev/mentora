<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Users\RolePermissionModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Shield\Authorization\PermissionMatcher;
use CodeIgniter\Shield\Entities\User;
use Config\AuthGroups;
use InvalidArgumentException;
use Throwable;

/**
 * Which role has which permission: the shipped defaults with the system
 * administrator's changes applied on top (SA6).
 *
 * Changes are rows in role_permissions. They are applied when Config\AuthGroups
 * is built, which is what Shield's `can()` and `permission:` filter read, so a
 * change takes effect on the next request with no other plumbing.
 *
 * set() is the only way the roles page changes it, and it applies the same
 * rules up front: a refused change throws and stores nothing.
 *
 * Three rules protect the matrix even if the table is edited by hand:
 *  - the `admin` role is locked: its rows in the table are ignored;
 *  - a row naming an unknown role or permission is ignored;
 *  - a customisation that would leave a role with no permission at all is
 *    ignored for that role, which then keeps its defaults.
 */
class PermissionMatrix
{
    /** The system administrator role. It always has exactly its default permissions. */
    public const LOCKED_ROLE = 'admin';

    /**
     * The defaults with $overrides applied. Pure: no database, no config
     * lookups beyond the two constants it is given.
     *
     * @param array<string, list<string>>                                          $defaults
     * @param list<array{role: string, permission: string, granted: int|string|bool}> $overrides
     * @param list<string>|null                                                    $known     the permissions that exist; those in $defaults when omitted
     *
     * @return array<string, list<string>>
     */
    public static function apply(array $defaults, array $overrides, ?array $known = null): array
    {
        $known ??= self::knownPermissions($defaults);

        $matrix = $defaults;

        foreach ($overrides as $override) {
            $role       = (string) $override['role'];
            $permission = strtolower((string) $override['permission']);

            if ($role === self::LOCKED_ROLE || ! isset($defaults[$role]) || ! in_array($permission, $known, true)) {
                continue;
            }

            if ((bool) (int) $override['granted']) {
                $matrix[$role][] = $permission;
            } else {
                $matrix[$role] = array_values(array_filter($matrix[$role], static fn (string $granted): bool => $granted !== $permission));
            }
        }

        foreach ($matrix as $role => $permissions) {
            $matrix[$role] = array_values(array_unique($permissions));

            if ($matrix[$role] === []) {
                $matrix[$role] = $defaults[$role];
            }
        }

        return $matrix;
    }

    /**
     * Every row in role_permissions. Empty (rather than an error) when the
     * table does not exist yet, as during the first migration.
     *
     * @return list<array{role: string, permission: string, granted: int|string|bool}>
     */
    public static function storedOverrides(): array
    {
        try {
            $db = db_connect();

            if (! $db->tableExists('role_permissions')) {
                return [];
            }

            return $db->table('role_permissions')->select('role, permission, granted')->orderBy('id')->get()->getResultArray();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Forget the matrix built earlier in this request, so the next permission
     * check rebuilds it from the table. Call after changing role_permissions.
     */
    public static function flush(): void
    {
        Factories::reset('config');
    }

    /**
     * The permissions as listed in the config, each with its description.
     *
     * @return array<string, string>
     */
    public function permissions(): array
    {
        return config(AuthGroups::class)->permissions;
    }

    /**
     * The roles as listed in the config.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return array_keys(config(AuthGroups::class)->groups);
    }

    /**
     * What each role has before any customisation.
     *
     * @return array<string, list<string>>
     */
    public function defaults(): array
    {
        return AuthGroups::DEFAULT_MATRIX;
    }

    /**
     * What each role has now: the defaults with the changes applied.
     *
     * @return array<string, list<string>>
     */
    public function effective(): array
    {
        return config(AuthGroups::class)->matrix;
    }

    /**
     * Grants or takes away one permission for one role.
     *
     * Stores only the difference from the shipped defaults (setting a
     * permission back to its default removes the row), records the change in
     * the audit log, and rebuilds the matrix so the next permission check sees
     * it. If the audit entry cannot be written the change is undone: a
     * permission never changes without a trace.
     *
     * @return bool false when the role already had that answer (nothing done)
     *
     * @throws InvalidArgumentException when the change is not allowed
     * @throws Throwable                whatever stopped the audit entry from being written, after the change was undone
     */
    public function set(string $role, string $permission, bool $granted, ?User $actor = null): bool
    {
        $permission = strtolower($permission);

        if (! in_array($role, $this->roles(), true)) {
            throw new InvalidArgumentException("Unknown role {$role}.");
        }

        if (! array_key_exists($permission, $this->permissions())) {
            throw new InvalidArgumentException("Unknown permission {$permission}.");
        }

        if ($role === self::LOCKED_ROLE) {
            throw new InvalidArgumentException('The system administrator role is locked; its permissions cannot be changed.');
        }

        $current = $this->effective()[$role];
        $has     = in_array($permission, $current, true);

        if ($has === $granted) {
            return false;
        }

        if (! $granted && count($current) === 1) {
            throw new InvalidArgumentException('A role must keep at least one permission.');
        }

        $model    = new RolePermissionModel();
        $previous = $model->where('role', $role)->where('permission', $permission)->first();

        // Only a difference from the defaults is worth a row.
        $matchesDefault = in_array($permission, $this->defaults()[$role], true) === $granted;

        $this->replaceRow($model, $role, $permission, $matchesDefault ? null : $granted);

        try {
            service('auditLogger')->log('roles.permission_changed', $actor ?? auth()->user(), [
                'target_type' => 'role',
                'target_id'   => $role,
                'old'         => [$permission => $has],
                'new'         => [$permission => $granted],
            ]);
        } catch (Throwable $e) {
            $this->replaceRow($model, $role, $permission, $previous === null ? null : (bool) (int) $previous['granted']);

            throw $e;
        }

        return true;
    }

    /**
     * Makes the table hold exactly one row for the pair, or none, then rebuilds the matrix.
     *
     * @param bool|null $granted null for no row
     */
    private function replaceRow(RolePermissionModel $model, string $role, string $permission, ?bool $granted): void
    {
        $model->where('role', $role)->where('permission', $permission)->delete();

        if ($granted !== null) {
            $model->insert(['role' => $role, 'permission' => $permission, 'granted' => $granted ? 1 : 0]);
        }

        self::flush();
    }

    /** True when the role has the permission now. */
    public function has(string $role, string $permission): bool
    {
        return PermissionMatcher::matches(strtolower($permission), $this->effective()[$role] ?? []);
    }

    /**
     * @param array<string, list<string>> $matrix
     *
     * @return list<string>
     */
    private static function knownPermissions(array $matrix): array
    {
        return array_values(array_unique(array_merge(...array_values($matrix))));
    }
}
