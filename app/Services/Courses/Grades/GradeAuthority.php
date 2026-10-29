<?php

declare(strict_types=1);

namespace App\Services\Courses\Grades;

use App\Models\Assignments\DeanAssignmentModel;
use App\Services\Auth\RolePermissions;
use App\Services\Users\AccountControl;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Who decides a grade-override request or a sign-off (audit F3, 7.2, 7.3, U1e).
 *
 * The department's dean decides, when they hold the duty's permission
 * (grades.approve for overrides, grades.signoff for sign-off); a dean without
 * it counts as no dean. Otherwise the institution's administrators who hold
 * it do. Whether an institution uses approval and sign-off at all is
 * whether it has any dean (decision 2.2), whatever their permissions.
 *
 * The one answer for the override chain, sign-off and the SIS export, so they
 * never disagree.
 */
final class GradeAuthority
{
    /** The department's active dean, when they hold the permission (as dean); else null. */
    public static function deanFor(int $departmentId, string $permission): ?int
    {
        $row = (new DeanAssignmentModel())->currentDeanOfDepartment($departmentId);

        if ($row === null) {
            return null;
        }

        $dean = model(UserModel::class)->findById((int) $row['dean_user_id']);

        return $dean !== null && (new RolePermissions())->allows($dean, $permission, 'dean') ? (int) $dean->id : null;
    }

    /**
     * The institution's active administrators who hold the permission (as institutional administrator).
     *
     * @param list<int> $except people to leave out (the requester)
     *
     * @return list<int>
     */
    public static function institutionAdmins(int $institutionId, string $permission, array $except = []): array
    {
        $ids = array_map('intval', array_column(db_connect()->table('institution_users iu')
            ->select('u.id')
            ->join('users u', 'u.id = iu.user_id AND u.deleted_at IS NULL AND u.active = 1', 'inner', false)
            ->join('auth_groups_users g', "g.user_id = u.id AND g.`group` = 'institutional_admin'", 'inner', false)
            ->where('iu.institution_id', $institutionId)
            ->get()->getResultArray(), 'id'));

        $accounts = new AccountControl();
        $users    = model(UserModel::class);
        $holders  = [];

        foreach (array_values(array_diff(array_unique($ids), $except)) as $id) {
            $admin = $users->findById($id);

            if ($admin !== null && ! $accounts->isDeactivated($id) && (new RolePermissions())->allows($admin, $permission, 'institutional_admin')) {
                $holders[] = $id;
            }
        }

        return $holders;
    }

    /**
     * Where nobody may decide (7.3, U1f): each department of an institution that uses approval with no dean
     * holding the permission while no administrator holds it either, per duty. Shown as a warning on both
     * Roles pages; requests and sign-offs there wait.
     *
     * @return list<array{department: string, permission: string}>
     */
    public static function gaps(int $institutionId): array
    {
        if (! self::usesApproval($institutionId)) {
            return [];
        }

        $departments = db_connect()->table('departments')->select('id, name')->where('institution_id', $institutionId)->orderBy('name')->get()->getResultArray();
        $gaps        = [];

        foreach (['grades.approve', 'grades.signoff'] as $permission) {
            if (self::institutionAdmins($institutionId, $permission) !== []) {
                continue;
            }

            foreach ($departments as $department) {
                if (self::deanFor((int) $department['id'], $permission) === null) {
                    $gaps[] = ['department' => (string) $department['name'], 'permission' => $permission];
                }
            }
        }

        return $gaps;
    }

    /** Does this institution use approval and sign-off? Only where it has a dean (decision 2.2). */
    public static function usesApproval(int $institutionId): bool
    {
        return db_connect()->table('dean_assignments da')
            ->join('departments d', 'd.id = da.department_id')
            ->where('d.institution_id', $institutionId)
            ->where(['da.status' => 'active', 'da.active_marker' => 1])
            ->countAllResults() > 0;
    }
}
