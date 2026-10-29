<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Models\Assignments\DeanAssignmentModel;
use App\Services\Courses\CourseRoleConflicts;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Sis\SisOwnership;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use RuntimeException;

class DeanAssignmentService
{
    public const SIS_LOCK = 'This assignment is managed by your student information system. Change it there; the next sync brings it here.';

    private DeanAssignmentModel $assignments;

    public function __construct(?DeanAssignmentModel $assignments = null)
    {
        $this->assignments = $assignments ?? new DeanAssignmentModel();
    }

    /**
     * @param bool $ignoreSisLock true for the SIS sync itself, which is the owner the lock protects
     *
     * @throws StructureException
     */
    public function assign(int $institutionId, int $departmentId, int $userId, ?User $actor = null, bool $ignoreSisLock = false): int
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

        // Not the dean of courses they are a student of (9.5).
        if (($clash = (new CourseRoleConflicts())->forDean($userId, $departmentId)) !== null) {
            throw StructureException::field('user_id', CourseRoleConflicts::sentence($userId, $clash));
        }

        // A dean can also teach, but cannot also be a chairperson: the one who oversees a department
        // does not lead one of its sub-departments.
        if (
            $db->table('auth_groups_users')->where(['user_id' => $userId, 'group' => 'chairperson'])->countAllResults() > 0
            || $db->table('chairperson_assignments')->where(['chairperson_user_id' => $userId, 'status' => 'active'])->countAllResults() > 0
        ) {
            throw StructureException::field('user_id', 'That person is a chairperson. A dean cannot also be a chairperson: remove the chairperson role and assignments first.');
        }

        // A dean the SIS supplies is replaced by the SIS, not by hand, while its connection is enabled.
        if (! $ignoreSisLock) {
            $sis = new SisOwnership();

            foreach (array_filter([$this->assignments->currentDeanOfDepartment($departmentId), $this->assignments->departmentOfDean($userId)]) as $held) {
                if ($sis->managesAssignment('dean', (int) $held['id'])) {
                    throw new StructureException(self::SIS_LOCK);
                }
            }
        }

        // The institution's first dean brings sign-off with it: grades already finalized now wait for one (2.3).
        $firstDean = ! \App\Services\Courses\Grades\GradeAuthority::usesApproval($institutionId);

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

        // Requests waiting for the department's dean now wait for this one (or, if it is their own, for the administrator).
        (new GradeOverrideRequestService())->retargetDepartment($departmentId);

        if ($firstDean) {
            (new \App\Services\Courses\Grades\GradeSignoffService())->announceNowNeeded($institutionId);
        }

        service('auditLogger')->log('structure.dean_assigned', $this->actorOrNull($actor), [
            'target_type' => 'dean_assignment', 'target_id' => $id, 'institution_id' => $institutionId,
            'new' => ['department_id' => $departmentId, 'dean_user_id' => $userId],
        ]);

        return $id;
    }

    /**
     * @param bool $ignoreSisLock true for the SIS sync, and for what follows from the account itself changing
     *
     * @throws StructureException when the SIS owns this assignment and the connection is enabled
     */
    public function end(int $institutionId, int $assignmentId, ?User $actor = null, bool $ignoreSisLock = false): void
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

        if (! $ignoreSisLock && (new SisOwnership())->managesAssignment('dean', $assignmentId)) {
            throw new StructureException(self::SIS_LOCK);
        }

        $now = date('Y-m-d H:i:s');
        $db->table('dean_assignments')->where('id', $assignmentId)->update([
            'status' => 'ended', 'active_marker' => null, 'ended_at' => $now, 'updated_at' => $now,
        ]);

        // Requests waiting for this dean move up one level: nobody is left to decide them.
        (new GradeOverrideRequestService())->retargetDepartment((int) $row['department_id']);

        service('auditLogger')->log('structure.dean_ended', $this->actorOrNull($actor), [
            'target_type' => 'dean_assignment', 'target_id' => $assignmentId, 'institution_id' => $institutionId,
            'old' => ['department_id' => (int) $row['department_id'], 'dean_user_id' => (int) $row['dean_user_id']],
        ]);
    }

    /**
     * Ends the person's deanship, if they have one: when their dean role is taken away or their account
     * is deactivated. Their other roles are not touched, and their history stays.
     */
    public function endForPerson(int $userId, ?User $actor = null): bool
    {
        $row = $this->assignments->departmentOfDean($userId);

        if ($row === null) {
            return false;
        }

        // Taking the role away or deactivating the person ends the deanship whoever supplied it.
        $this->end((int) $row['institution_id'], (int) $row['id'], $actor, true);

        return true;
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