<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Services\Institutions\StructureException;

/**
 * Who is in which group of a set. A student is in at most one group per set (also a database rule).
 */
class GroupMembershipService
{
    /** @return list<int> user ids of the students enrolled or completed in the offering (not dropped or withdrawn) */
    public function poolForOffering(int $offeringId): array
    {
        $rows = db_connect()->table('enrollments')->select('student_user_id')->where('offering_id', $offeringId)
            ->whereIn('status', ['enrolled', 'completed'])->get()->getResultArray();

        return array_map('intval', array_column($rows, 'student_user_id'));
    }

    public function addToSet(int $groupId, int $studentId, int $actorId): void
    {
        $set = $this->setOf($groupId);
        GroupSetService::refuseIfFinalized((int) $set['offering_id']);

        db_connect()->transStart();

        try {
            $this->lockGroup($groupId);

            if (!in_array($studentId, $this->poolForOffering((int) $set['offering_id']), true)) {
                throw new StructureException('That student is not enrolled in this course.');
            }

            if ($this->currentGroup($studentId, (int) $set['id']) !== null) {
                throw new StructureException('That student is already in a group of this set. Move them instead.');
            }

            $this->assertRoom($groupId, $set);
            $this->insert($groupId, (int) $set['id'], $studentId);
        } catch (StructureException $e) {
            db_connect()->transComplete();

            throw $e;
        }

        db_connect()->transComplete();

        if (!db_connect()->transStatus()) {
            throw new StructureException('The student could not be added. Try again.');
        }

        GroupSetService::audit('groups.member_added', $actorId, $groupId, 'student_group', ['new' => ['student_user_id' => $studentId, 'group_id' => $groupId, 'set_id' => (int) $set['id']]]);
    }

    public function moveInSet(int $studentId, int $toGroupId, int $actorId): void
    {
        $set = $this->setOf($toGroupId);
        GroupSetService::refuseIfFinalized((int) $set['offering_id']);

        $from = $this->currentGroup($studentId, (int) $set['id']);

        if ($from === null) {
            throw new StructureException('That student is not in a group of this set yet.');
        }

        if ($from === $toGroupId) {
            return;
        }

        db_connect()->transStart();

        try {
            $this->lockGroup($toGroupId);
            $this->assertRoom($toGroupId, $set);
            db_connect()->table('student_group_members')->where('student_user_id', $studentId)->where('set_id', (int) $set['id'])->delete();
            $this->insert($toGroupId, (int) $set['id'], $studentId);
        } catch (StructureException $e) {
            db_connect()->transComplete();

            throw $e;
        }

        db_connect()->transComplete();

        if (!db_connect()->transStatus()) {
            throw new StructureException('The student could not be moved. They stay in their group.');
        }

        GroupSetService::audit('groups.member_moved', $actorId, $toGroupId, 'student_group', ['old' => ['group_id' => $from], 'new' => ['group_id' => $toGroupId, 'student_user_id' => $studentId, 'set_id' => (int) $set['id']]]);
    }

    public function removeFromSet(int $studentId, int $setId, int $actorId): void
    {
        $set = (new GroupSetService())->find($setId);
        GroupSetService::refuseIfFinalized((int) $set['offering_id']);

        $from = $this->currentGroup($studentId, $setId);

        if ($from === null) {
            return;
        }

        db_connect()->table('student_group_members')->where('student_user_id', $studentId)->where('set_id', $setId)->delete();
        GroupSetService::audit('groups.member_removed', $actorId, $from, 'student_group', ['old' => ['group_id' => $from, 'student_user_id' => $studentId, 'set_id' => $setId]]);
    }

    /** @return array<string, mixed> the set a set-group belongs to */
    private function setOf(int $groupId): array
    {
        $group = db_connect()->table('student_groups')->where('id', $groupId)->get()->getRowArray();

        if ($group === null || $group['set_id'] === null) {
            throw new StructureException('That group does not exist.');
        }

        return (new GroupSetService())->find((int) $group['set_id']);
    }

    private function currentGroup(int $studentId, int $setId): ?int
    {
        $row = db_connect()->table('student_group_members')->select('group_id')->where('student_user_id', $studentId)->where('set_id', $setId)->get()->getRowArray();

        return $row === null ? null : (int) $row['group_id'];
    }

    /** Serializes joins on one group so two people cannot take the last seat. */
    private function lockGroup(int $groupId): void
    {
        db_connect()->query('SELECT id FROM student_groups WHERE id = ? FOR UPDATE', [$groupId]);
    }

    private function assertRoom(int $groupId, array $set): void
    {
        $limit = min((int) $set['max_size'], GroupLimits::MAX_MEMBERS);

        if (db_connect()->table('student_group_members')->where('group_id', $groupId)->countAllResults() >= $limit) {
            throw new StructureException('Group is full.');
        }
    }

    private function insert(int $groupId, int $setId, int $studentId): void
    {
        db_connect()->table('student_group_members')->insert(['group_id' => $groupId, 'set_id' => $setId, 'config_id' => null, 'student_user_id' => $studentId, 'joined_at' => date('Y-m-d H:i:s')]);
    }
}
