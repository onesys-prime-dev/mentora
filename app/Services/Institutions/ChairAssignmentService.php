<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;

/**
 * Who chairs which sub-department, as the institutional administrator
 * assigns it. A chairperson's reach (every course in their sub-departments)
 * is derived from these rows live (ChairpersonAccessService), so assigning
 * and revoking take effect on the person's very next request.
 *
 * Rules:
 *  - A sub-department can have more than one chairperson, and a person can
 *    chair more than one sub-department.
 *  - Only a member of this institution who holds the chairperson role, and
 *    only into an active sub-department of this institution.
 *  - A dean cannot also be a chairperson (they may still teach).
 *  - Revoking keeps the row (status "revoked"); assigning again reuses it.
 */
class ChairAssignmentService
{
    /**
     * Active chairpersons, grouped by sub-department.
     *
     * @return array<int, list<array{id: int, user_id: int, name: string, email: string}>>
     */
    public function bySubDepartment(int $institutionId): array
    {
        $rows = db_connect()->table('chairperson_assignments ca')
            ->select('ca.id, ca.sub_department_id, ca.chairperson_user_id, up.first_name, up.last_name, ai.secret AS email')
            ->join('sub_departments sd', 'sd.id = ca.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->join('users u', 'u.id = ca.chairperson_user_id')
            ->join('user_profiles up', 'up.user_id = u.id', 'left')
            ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left', false)
            ->where('d.institution_id', $institutionId)
            ->where('ca.status', 'active')
            ->orderBy('up.last_name')
            ->orderBy('up.first_name')
            ->get()->getResultArray();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['sub_department_id']][] = [
                'id'      => (int) $row['id'],
                'user_id' => (int) $row['chairperson_user_id'],
                'name'    => trim($row['first_name'] . ' ' . $row['last_name']) ?: (string) $row['email'],
                'email'   => (string) $row['email'],
            ];
        }

        return $grouped;
    }

    /**
     * People of the institution who can be assigned (they hold the
     * chairperson role and the account is in good standing).
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    public function candidates(int $institutionId): array
    {
        $rows = db_connect()->table('institution_users iu')
            ->select('u.id, up.first_name, up.last_name, ai.secret AS email')
            ->join('users u', 'u.id = iu.user_id AND u.deleted_at IS NULL AND u.active = 1', 'inner', false)
            ->join('auth_groups_users g', "g.user_id = u.id AND g.`group` = 'chairperson'", 'inner', false)
            ->join('user_profiles up', 'up.user_id = u.id', 'left')
            ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left', false)
            ->where('iu.institution_id', $institutionId)
            // A dean can also teach, but cannot also be a chairperson.
            ->where("NOT EXISTS (SELECT 1 FROM auth_groups_users dean_group WHERE dean_group.user_id = u.id AND dean_group.`group` = 'dean')", null, false)
            ->orderBy('up.last_name')
            ->orderBy('up.first_name')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id'    => (int) $row['id'],
            'name'  => trim($row['first_name'] . ' ' . $row['last_name']) ?: (string) $row['email'],
            'email' => (string) $row['email'],
        ], $rows);
    }

    /**
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function assign(int $institutionId, int $subDepartmentId, int $userId, ?User $actor = null): void
    {
        $scope = InstitutionScope::forInstitution($institutionId);
        $scope->require('subDepartment', $subDepartmentId);

        $db  = db_connect();
        $sub = $db->table('sub_departments')->where('id', $subDepartmentId)->get()->getRowArray();

        if ((int) $sub['is_active'] !== 1) {
            throw new StructureException("{$sub['name']} is inactive. Reactivate it before assigning a chairperson.");
        }

        if ($db->table('auth_groups_users')->where(['user_id' => $userId, 'group' => 'dean'])->countAllResults() > 0) {
            throw StructureException::field('user_id', 'That person is a dean. A dean cannot also be a chairperson.');
        }

        $candidate = null;

        foreach ($this->candidates($institutionId) as $person) {
            if ($person['id'] === $userId) {
                $candidate = $person;
            }
        }

        if ($candidate === null) {
            throw StructureException::field('user_id', 'Choose one of the people who hold the chairperson role in your institution.');
        }

        $existing = $db->table('chairperson_assignments')->where(['sub_department_id' => $subDepartmentId, 'chairperson_user_id' => $userId])->get()->getRowArray();
        $now      = date('Y-m-d H:i:s');

        if ($existing !== null && $existing['status'] === 'active') {
            throw StructureException::field('user_id', "{$candidate['name']} already chairs {$sub['name']}.");
        }

        if ($existing !== null) {
            $db->table('chairperson_assignments')->where('id', $existing['id'])->update(['status' => 'active', 'assigned_at' => $now, 'updated_at' => $now]);
            $id = (int) $existing['id'];
        } else {
            $db->table('chairperson_assignments')->insert(['sub_department_id' => $subDepartmentId, 'chairperson_user_id' => $userId, 'status' => 'active', 'assigned_at' => $now, 'updated_at' => $now]);
            $id = (int) $db->insertID();
        }

        $this->audit('structure.chairperson_assigned', $actor, $institutionId, $id, null, ['sub_department_id' => $subDepartmentId, 'chairperson_user_id' => $userId]);
    }

    /**
     * @throws PageNotFoundException when the assignment is not this institution's
     */
    public function revoke(int $institutionId, int $assignmentId, ?User $actor = null): void
    {
        $db  = db_connect();
        $row = $db->table('chairperson_assignments ca')
            ->select('ca.*')
            ->join('sub_departments sd', 'sd.id = ca.sub_department_id')
            ->join('departments d', 'd.id = sd.department_id')
            ->where('ca.id', $assignmentId)
            ->where('d.institution_id', $institutionId)
            ->get()->getRowArray();

        if ($row === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($row['status'] !== 'active') {
            return;
        }

        $db->table('chairperson_assignments')->where('id', $assignmentId)->update(['status' => 'revoked', 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit('structure.chairperson_revoked', $actor, $institutionId, $assignmentId, ['sub_department_id' => (int) $row['sub_department_id'], 'chairperson_user_id' => (int) $row['chairperson_user_id']], ['status' => 'revoked']);
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function audit(string $action, ?User $actor, int $institutionId, int $id, ?array $old, ?array $new): void
    {
        service('auditLogger')->log($action, $actor ?? auth()->user(), [
            'target_type'    => 'chairperson_assignment',
            'target_id'      => $id,
            'institution_id' => $institutionId,
            'old'            => $old,
            'new'            => $new,
        ]);
    }
}
