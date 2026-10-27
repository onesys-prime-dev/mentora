<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Models\Users\RolePermissionModel;
use App\Services\Admin\PermissionMatrix;
use App\Services\Auth\RolePermissions;
use CodeIgniter\Shield\Entities\User;
use InvalidArgumentException;
use Throwable;

/**
 * An institution's own settings for the academic permissions of its roles
 * (audit decisions U1a–U1d), on top of the platform's.
 *
 * Only the five roles that work inside an institution, and only academic
 * permissions, each where it does something for the role. A row is stored
 * only where the institution differs from the platform's current value, so a
 * box it never touched follows later platform changes; setting a box back to
 * the platform's value removes the row. The same safeguards as the system
 * administrator's page: every role keeps at least one permission, the change
 * is audited (with the institution), and undone if the audit entry fails.
 */
final class InstitutionRoles
{
    public const ROLES = ['dean', 'chairperson', 'instructor', 'student', 'parent'];

    /** The permissions an institution sets: never platform or institution-administration ones (U1d). */
    public const ACADEMIC = [
        'courses.view', 'courses.manage', 'grading.manage', 'announcements.manage', 'discussions.manage',
        'program.monitor', 'department.monitor', 'grades.approve', 'grades.signoff',
        'children.view', 'followups.manage', 'department.announce',
    ];

    /**
     * @return bool false when the role already had that answer (nothing done)
     *
     * @throws InvalidArgumentException when the change is not allowed
     * @throws Throwable                whatever stopped the audit entry, after the change was undone
     */
    public function set(int $institutionId, string $role, string $permission, bool $granted, User $actor): bool
    {
        $permission = strtolower($permission);

        if (! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('That role is set by the platform.');
        }

        if (! in_array($permission, self::ACADEMIC, true)) {
            throw new InvalidArgumentException('That permission is not one an institution sets.');
        }

        if (! in_array($permission, PermissionMatrix::applicable($role), true)) {
            throw new InvalidArgumentException('That permission does nothing for that role.');
        }

        $permissions = new RolePermissions();
        $current     = $permissions->matrixFor($institutionId)[$role] ?? [];
        $has         = in_array($permission, $current, true);

        if ($has === $granted) {
            return false;
        }

        if (! $granted && count($current) === 1) {
            throw new InvalidArgumentException('A role must keep at least one permission.');
        }

        $model    = new RolePermissionModel();
        $previous = $model->where(['institution_id' => $institutionId, 'role' => $role, 'permission' => $permission])->first();

        // Only a difference from the platform's current value is worth a row.
        $matchesPlatform = in_array($permission, $permissions->matrixFor(0)[$role] ?? [], true) === $granted;

        $this->replaceRow($institutionId, $role, $permission, $matchesPlatform ? null : $granted);

        try {
            service('auditLogger')->log('roles.permission_changed', $actor, [
                'target_type'    => 'role',
                'target_id'      => $role,
                'institution_id' => $institutionId,
                'old'            => [$permission => $has],
                'new'            => [$permission => $granted],
            ]);
        } catch (Throwable $e) {
            $this->replaceRow($institutionId, $role, $permission, $previous === null ? null : (bool) (int) $previous['granted']);

            throw $e;
        }

        // Who may approve overrides changed: the waiting requests go to whoever may decide them now (7.2).
        if ($permission === 'grades.approve' && in_array($role, ['dean', 'institutional_admin'], true)) {
            (new \App\Services\Courses\Grades\GradeOverrideRequestService())->retargetInstitution($institutionId);
        }

        return true;
    }

    /** @param bool|null $granted null for no row */
    private function replaceRow(int $institutionId, string $role, string $permission, ?bool $granted): void
    {
        $model = new RolePermissionModel();
        $model->where(['institution_id' => $institutionId, 'role' => $role, 'permission' => $permission])->delete();

        if ($granted !== null) {
            $model->insert(['institution_id' => $institutionId, 'role' => $role, 'permission' => $permission, 'granted' => $granted ? 1 : 0]);
        }

        RolePermissions::flush();
    }
}
