<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Services\Courses\CourseRoleConflicts;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Courses\Grades\OverrideRequestChain;
use App\Services\Notifications\NotificationService;
use App\Services\Sis\SisOwnership;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;

/**
 * Who chairs which sub-department, as the institutional administrator
 * assigns it. A chairperson's reach (every course in their sub-departments)
 * is derived from these rows live (ChairpersonAccessService), so assigning
 * and revoking take effect on the person's very next request.
 *
 * Rules:
 *  - A sub-department has exactly one chairperson (decision 16.0); a person can
 *    chair more than one sub-department. Assigning another to a sub-department
 *    that has one is refused unless the administrator confirms the replacement:
 *    the old assignment then ends (history kept) and the old chair is told;
 *    requests waiting for a chairperson's endorsement pass to the new chair,
 *    who is told (16.0b, 16.0c).
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

    public const SIS_LOCK = 'This assignment is managed by your student information system. Change it there; the next sync brings it here.';

    /**
     * @return int the assignment row: a new one, or the revoked one made active again
     *
     * @param bool $replace       the administrator confirmed replacing the sub-department's current chairperson
     * @param bool $ignoreSisLock the SIS sync itself replaces a chairperson it manages
     *
     * @throws StructureException
     * @throws PageNotFoundException
     */
    public function assign(int $institutionId, int $subDepartmentId, int $userId, ?User $actor = null, bool $replace = false, bool $ignoreSisLock = false): int
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

        // Not the chairperson of courses they are a student of (9.4).
        if (($clash = (new CourseRoleConflicts())->forChairing($userId, $subDepartmentId)) !== null) {
            throw StructureException::field('user_id', "{$candidate['name']} {$clash}.");
        }

        $existing = $db->table('chairperson_assignments')->where(['sub_department_id' => $subDepartmentId, 'chairperson_user_id' => $userId])->get()->getRowArray();
        $now      = date('Y-m-d H:i:s');

        if ($existing !== null && $existing['status'] === 'active') {
            throw StructureException::field('user_id', "{$candidate['name']} already chairs {$sub['name']}.");
        }

        $current = $db->table('chairperson_assignments')->where(['sub_department_id' => $subDepartmentId, 'status' => 'active'])->get()->getRowArray();

        // A chairperson the SIS manages is replaced there, not by hand: said before asking to confirm a replacement.
        if ($current !== null && ! $ignoreSisLock && (new SisOwnership())->managesAssignment('chairperson', (int) $current['id'])) {
            throw new StructureException(self::SIS_LOCK);
        }

        if ($current !== null && ! $replace) {
            throw StructureException::field('user_id', $this->nameOf((int) $current['chairperson_user_id']) . " chairs {$sub['name']}. Assign {$candidate['name']} instead? Confirm to replace them.");
        }

        // Two administrators assigning at once: the unique key lets one in, and the other is told.
        $raced = StructureException::field('user_id', "Someone else just changed the chairperson of {$sub['name']}. Reload the page to see who chairs it now.");

        try {
            $db->transStart();

            if ($current !== null) {
                $db->table('chairperson_assignments')->where('id', $current['id'])->update(['status' => 'revoked', 'active_marker' => null, 'updated_at' => $now]);
            }

            if ($existing !== null) {
                $db->table('chairperson_assignments')->where('id', $existing['id'])->update(['status' => 'active', 'active_marker' => 1, 'assigned_at' => $now, 'updated_at' => $now]);
                $id = (int) $existing['id'];
            } else {
                $db->table('chairperson_assignments')->insert(['sub_department_id' => $subDepartmentId, 'chairperson_user_id' => $userId, 'status' => 'active', 'active_marker' => 1, 'assigned_at' => $now, 'updated_at' => $now]);
                $id = (int) $db->insertID();
            }

            $db->transComplete();
        } catch (DatabaseException) {
            throw $raced;
        }

        if (! $db->transStatus()) {
            throw $raced;
        }

        if ($current !== null) {
            $this->audit('structure.chairperson_revoked', $actor, $institutionId, (int) $current['id'], ['sub_department_id' => $subDepartmentId, 'chairperson_user_id' => (int) $current['chairperson_user_id']], ['status' => 'revoked', 'replaced_by' => $userId]);

            (new NotificationService())->send((int) $current['chairperson_user_id'], 'chairperson', 'Chairperson assignment ended', "{$candidate['name']} now chairs {$sub['name']}, so your assignment there has ended.", false, "chair-replaced:{$current['id']}:" . date('YmdHis'), $institutionId, site_url('chairperson/dashboard'));
        }

        $this->audit('structure.chairperson_assigned', $actor, $institutionId, $id, null, ['sub_department_id' => $subDepartmentId, 'chairperson_user_id' => $userId]);

        // Requests waiting for a chairperson's endorsement are now theirs (16.0c, U5); their own move on.
        (new GradeOverrideRequestService())->retargetChairStep($subDepartmentId);

        $waiting = $db->table('grade_override_requests r')
            ->join('course_offerings co', 'co.id = r.offering_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->where(['p.sub_department_id' => $subDepartmentId, 'r.status' => OverrideRequestChain::AWAITING_CHAIR])
            ->where('r.requested_by !=', $userId)
            ->countAllResults();

        if ($waiting > 0) {
            (new NotificationService())->send($userId, 'grade_override', 'Grade overrides to endorse', "{$sub['name']}: " . $waiting . ($waiting === 1 ? ' grade-override request waits' : ' grade-override requests wait') . ' for your endorsement.', false, "chair-takeover:{$id}:" . date('YmdHis'), $institutionId, site_url('chairperson/override-requests'));
        }

        return $id;
    }

    /**
     * @param bool $ignoreSisLock true for the SIS sync itself, which is the owner the lock protects
     *
     * @throws PageNotFoundException when the assignment is not this institution's
     * @throws StructureException    when the SIS owns this assignment and the connection is enabled
     */
    public function revoke(int $institutionId, int $assignmentId, ?User $actor = null, bool $ignoreSisLock = false): void
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

        if (! $ignoreSisLock && (new SisOwnership())->managesAssignment('chairperson', $assignmentId)) {
            throw new StructureException(self::SIS_LOCK);
        }

        $db->table('chairperson_assignments')->where('id', $assignmentId)->update(['status' => 'revoked', 'active_marker' => null, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->audit('structure.chairperson_revoked', $actor, $institutionId, $assignmentId, ['sub_department_id' => (int) $row['sub_department_id'], 'chairperson_user_id' => (int) $row['chairperson_user_id']], ['status' => 'revoked']);

        // With no chairperson left to endorse them, waiting requests move on instead of waiting for nobody.
        (new GradeOverrideRequestService())->retargetChairStep((int) $row['sub_department_id']);
    }

    private function nameOf(int $userId): string
    {
        $profile = db_connect()->table('user_profiles')->select('first_name, last_name')->where('user_id', $userId)->get()->getRowArray();

        return $profile === null ? 'Someone' : trim($profile['first_name'] . ' ' . $profile['last_name']);
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
