<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Models\Assignments\DeanAssignmentModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use RuntimeException;

class DeanAssignmentService
{
    private DeanAssignmentModel $assignments;

    public function __construct(?DeanAssignmentModel $assignments = null)
    {
        $this->assignments = $assignments ?? new DeanAssignmentModel();
    }

    public function assign(int $institutionId, int $departmentId, int $userId, ?User $actor = null): int
    {
        $db = db_connect();
        $department = $db->table('departments')->where([
            'id' => $departmentId, 'institution_id' => $institutionId,
        ])->get()->getRowArray();

        if ($department === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $member = $db->table('institution_users')
            ->where(['institution_id' => $institutionId, 'user_id' => $userId])
            ->countAllResults() > 0;
        if (! $member || $db->table('users')->where('id', $userId)->countAllResults() === 0) {
            throw StructureException::field('user_id', 'Choose a person who belongs to this institution.');
        }

        $now = date('Y-m-d H:i:s');
        $db->transStart();

        if ($db->table('auth_groups_users')->where(['user_id' => $userId, 'group' => 'dean'])->countAllResults() === 0) {
            $db->table('auth_groups_users')->insert([
                'user_id' => $userId,
                'group' => 'dean',
                'created_at' => $now,
            ]);
        }
        $currentDepartment = $this->assignments->currentDeanOfDepartment($departmentId);
        $currentPerson = $this->assignments->where([
            'dean_user_id' => $userId, 'status' => 'active', 'active_marker' => 1,
        ])->findAll();

        foreach (array_merge($currentDepartment === null ? [] : [$currentDepartment], $currentPerson) as $old) {
            $db->table('dean_assignments')->where('id', $old['id'])->update([
                'status' => 'ended', 'active_marker' => null, 'ended_at' => $now, 'updated_at' => $now,
            ]);
        }

        $db->table('dean_assignments')->insert([
            'department_id' => $departmentId, 'dean_user_id' => $userId, 'status' => 'active',
            'active_marker' => 1, 'assigned_at' => $now, 'assigned_by' => $actor?->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $db->insertID();
        $db->transComplete();

        if ($db->transStatus() === false) {
            throw new RuntimeException('The dean assignment could not be saved.');
        }

        service('auditLogger')->log('structure.dean_assigned', $this->actorOrNull($actor), [
            'target_type' => 'dean_assignment', 'target_id' => $id, 'institution_id' => $institutionId,
            'new' => ['department_id' => $departmentId, 'dean_user_id' => $userId],
        ]);

        return $id;
    }

    public function end(int $institutionId, int $assignmentId, ?User $actor = null): void
    {
        $db = db_connect();
        $row = $db->table('dean_assignments da')
            ->select('da.*')
            ->join('departments d', 'd.id = da.department_id')
            ->where(['da.id' => $assignmentId, 'd.institution_id' => $institutionId])
            ->get()->getRowArray();

        if ($row === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        if ($row['status'] !== 'active') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $db->table('dean_assignments')->where('id', $assignmentId)->update([
            'status' => 'ended', 'active_marker' => null, 'ended_at' => $now, 'updated_at' => $now,
        ]);

        service('auditLogger')->log('structure.dean_ended', $this->actorOrNull($actor), [
            'target_type' => 'dean_assignment', 'target_id' => $assignmentId, 'institution_id' => $institutionId,
            'old' => ['department_id' => (int) $row['department_id'], 'dean_user_id' => (int) $row['dean_user_id']],
        ]);
    }

    public function currentDeanOfDepartment(int $departmentId): ?array
    {
        return $this->assignments->currentDeanOfDepartment($departmentId);
    }

    public function departmentOfDean(int $userId): ?array
    {
        return $this->assignments->departmentOfDean($userId);
    }

    private function actorOrNull(?User $actor): ?User
    {
        return $actor ?? (is_cli() ? null : auth()->user());
    }
}