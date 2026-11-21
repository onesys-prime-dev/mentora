<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Models\Groups\ActivityGroupConfigModel;
use App\Services\Institutions\StructureException;

/**
 * Students acting for themselves: joining, leaving and switching groups of an activity. The group row is locked
 * (SELECT ... FOR UPDATE) before anything is read in the transaction, so two students taking the last seat are
 * serialized and the second one recounts after the first has committed. Every write refuses on a finalized
 * offering and leaves an audit row (actor = the student).
 */
class GroupSignupService
{
    public function join(int $configId, int $groupId, int $studentId): void
    {
        $config = $this->config($configId);
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);
        $this->assertOpen($config);
        $this->assertInPool($configId, $studentId);

        $this->atomic(function () use ($config, $configId, $groupId, $studentId): void {
            if ($this->lock($groupId, $configId)['frozen_at'] !== null) {
                throw new StructureException('That group has already submitted.');
            }

            if ($this->currentGroup($studentId, $configId) !== null) {
                throw new StructureException('You are already in a group for this activity.');
            }

            $this->assertRoom($groupId, $config);
            $this->insert($groupId, $configId, $studentId);
        }, 'You could not join the group. Try again.');

        (new \App\Services\Courses\LearningProgressService())->onGroupMemberAdded($configId, $studentId);
        GroupSetService::audit('groups.member_joined', $studentId, $groupId, 'student_group', ['new' => ['group_id' => $groupId, 'student_user_id' => $studentId, 'config_id' => $configId]]);
        GroupNotifier::hook(static fn (GroupNotifier $n) => $n->memberChanged($groupId, $studentId, 'joined', $studentId));
    }

    public function leave(int $groupId, int $studentId): void
    {
        $row = db_connect()->table('student_groups g')->select('g.config_id, c.offering_id')->join('activity_group_configs c', 'c.id = g.config_id')->where('g.id', $groupId)->get()->getRowArray()
            ?? throw new StructureException('That group does not exist.');
        $config = $this->config((int) $row['config_id']);
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);

        $this->atomic(function () use ($config, $groupId, $studentId): void {
            $group = $this->lock($groupId, (int) $config['id']);

            if ($group['frozen_at'] !== null) {
                throw new StructureException('Your group has submitted; ask your instructor');
            }

            if ($this->currentGroup($studentId, (int) $config['id']) !== $groupId) {
                throw new StructureException('You are not in that group.');
            }

            if ($group['leader_finalized_at'] !== null && (int) $group['leader_user_id'] !== $studentId && (int) $config['nonleader_can_leave'] !== 1) {
                throw new StructureException('Your group has chosen its leader, so members cannot leave. Ask your instructor.');
            }

            db_connect()->table('student_group_members')->where('config_id', $config['id'])->where('student_user_id', $studentId)->delete();
        }, 'You could not leave the group. Try again.');

        (new GroupLeaderService())->membershipChanged($groupId);
        GroupSetService::audit('groups.member_left', $studentId, $groupId, 'student_group', ['old' => ['group_id' => $groupId, 'student_user_id' => $studentId, 'config_id' => (int) $config['id']]]);
        GroupNotifier::hook(static fn (GroupNotifier $n) => $n->memberChanged($groupId, $studentId, 'left', $studentId));
    }

    public function switchTo(int $configId, int $toGroupId, int $studentId): void
    {
        $config = $this->config($configId);
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);
        $this->assertOpen($config);
        $this->assertInPool($configId, $studentId);
        $from = $this->currentGroup($studentId, $configId);

        if ($from === null) {
            throw new StructureException('You are not in a group yet. Join one instead.');
        }

        if ($from === $toGroupId) {
            return;
        }

        $this->atomic(function () use ($config, $configId, $from, $toGroupId, $studentId): void {
            $ids = [$from, $toGroupId];
            sort($ids); // one lock order for everyone, so two switches cannot deadlock

            foreach ($ids as $id) {
                if ($this->lock($id, $configId)['frozen_at'] !== null) {
                    throw new StructureException($id === $from ? 'Your group has submitted; ask your instructor' : 'That group has already submitted.');
                }
            }

            if ($this->currentGroup($studentId, $configId) !== $from) {
                throw new StructureException('Your group changed. Try again.');
            }

            $this->assertRoom($toGroupId, $config);
            db_connect()->table('student_group_members')->where('config_id', $configId)->where('student_user_id', $studentId)->delete();
            $this->insert($toGroupId, $configId, $studentId);
        }, 'You could not switch groups. You stay in your group.');

        (new GroupLeaderService())->membershipChanged($from);
        (new \App\Services\Courses\LearningProgressService())->onGroupMemberAdded($configId, $studentId);
        GroupSetService::audit('groups.member_switched', $studentId, $toGroupId, 'student_group', ['old' => ['group_id' => $from], 'new' => ['group_id' => $toGroupId, 'student_user_id' => $studentId, 'config_id' => $configId]]);
        GroupNotifier::hook(static fn (GroupNotifier $n) => $n->moved($from, $toGroupId, $studentId, $studentId));
    }

    /** @return list<array{id:int,name:string,members_count:int,max:int,member_names:list<string>,full:bool}> */
    public function candidateGroups(int $configId, int $studentId): array
    {
        $config = $this->config($configId);

        try {
            $this->assertOpen($config);
            $this->assertInPool($configId, $studentId);
            GroupSetService::refuseIfFinalized((int) $config['offering_id']);
        } catch (StructureException) {
            return [];
        }

        $mine = $this->currentGroup($studentId, $configId);
        $max  = $this->limit($config);
        $out  = [];

        foreach (db_connect()->table('student_groups')->where('config_id', $configId)->where('status', 'active')->where('frozen_at IS NULL', null, false)->orderBy('name')->orderBy('id')->get()->getResultArray() as $g) {
            if ((int) $g['id'] === $mine) {
                continue;
            }

            $names = [];

            foreach (db_connect()->table('student_group_members m')->select('u.username, up.first_name, up.last_name')->join('users u', 'u.id = m.student_user_id')
                ->join('user_profiles up', 'up.user_id = m.student_user_id', 'left')->where('m.group_id', $g['id'])->orderBy('m.joined_at')->orderBy('m.id')->get()->getResultArray() as $m) {
                $names[] = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: (string) $m['username'];
            }

            $out[] = ['id' => (int) $g['id'], 'name' => (string) $g['name'], 'members_count' => count($names), 'max' => $max, 'member_names' => $names, 'full' => count($names) >= $max];
        }

        return $out;
    }

    /** @param array<string, mixed> $config */
    private function assertOpen(array $config): void
    {
        if ($config['formation_mode'] !== 'self_signup') {
            throw new StructureException('This activity does not use self-signup.');
        }

        $now = time();

        if ($config['signup_opens_at'] !== null && strtotime((string) $config['signup_opens_at']) > $now) {
            throw new StructureException('Signup has not opened yet');
        }

        if ($config['signup_closes_at'] !== null && strtotime((string) $config['signup_closes_at']) <= $now) {
            throw new StructureException('Signup is closed');
        }
    }

    private function assertInPool(int $configId, int $studentId): void
    {
        if (!in_array($studentId, (new ActivityGroupService())->pool($configId), true)) {
            throw new StructureException('You are not part of this activity');
        }
    }

    /** Runs the writes in one transaction; rule refusals pass through, anything else (a lock timeout, a duplicate key) becomes the plain message. */
    private function atomic(callable $writes, string $failure): void
    {
        $db = db_connect();
        $db->transStart();

        try {
            $writes();
        } catch (StructureException $e) {
            $db->transComplete();

            throw $e;
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Group signup failed: ' . $e->getMessage());

            throw new StructureException($failure);
        }

        $db->transComplete();

        if (!$db->transStatus()) {
            throw new StructureException($failure);
        }
    }

    /**
     * Locks the group row first thing in the transaction, so every count after it sees what earlier joiners committed.
     *
     * @return array<string, mixed>
     */
    private function lock(int $groupId, int $configId): array
    {
        $group = db_connect()->query('SELECT id, config_id, status, frozen_at, leader_user_id, leader_finalized_at FROM student_groups WHERE id = ? FOR UPDATE', [$groupId])->getRowArray();

        if ($group === null || (int) $group['config_id'] !== $configId || $group['status'] !== 'active') {
            throw new StructureException('That group is not part of this activity.');
        }

        return $group;
    }

    /** @param array<string, mixed> $config */
    private function limit(array $config): int
    {
        return min((int) $config['max_size'], GroupLimits::MAX_MEMBERS);
    }

    /** @param array<string, mixed> $config */
    private function assertRoom(int $groupId, array $config): void
    {
        if (db_connect()->table('student_group_members')->where('group_id', $groupId)->countAllResults() >= $this->limit($config)) {
            throw new StructureException('Group is full');
        }
    }

    private function currentGroup(int $studentId, int $configId): ?int
    {
        $row = db_connect()->table('student_group_members')->select('group_id')->where('config_id', $configId)->where('student_user_id', $studentId)->get()->getRowArray();

        return $row === null ? null : (int) $row['group_id'];
    }

    private function insert(int $groupId, int $configId, int $studentId): void
    {
        db_connect()->table('student_group_members')->insert(['group_id' => $groupId, 'set_id' => null, 'config_id' => $configId, 'student_user_id' => $studentId, 'joined_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed> the activity's settings plus its offering */
    private function config(int $configId): array
    {
        return (new ActivityGroupConfigModel())->find($configId) ?? throw new StructureException('That group activity does not exist.');
    }
}
