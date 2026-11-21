<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Models\Groups\ActivityGroupConfigModel;
use App\Services\Institutions\StructureException;

/**
 * Mode D: students propose a group and invite classmates; invitees must accept; the instructor approves or declines.
 * A proposed group's members count as grouped (the unique key on config_id + student), but the group is not joinable
 * (GroupSignupService only joins 'active' groups). Every write refuses on a finalized offering and leaves an audit row.
 */
class GroupProposalService
{
    /** @param list<int> $inviteeIds */
    public function propose(int $configId, int $studentId, array $inviteeIds, string $name): int
    {
        $config = $this->config($configId);
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);
        $this->assertOpen($config);
        $name    = GroupSetService::groupName($name);
        $pool    = (new ActivityGroupService())->pool($configId);
        $invited = array_values(array_unique(array_diff(array_map('intval', $inviteeIds), [$studentId])));

        if (!in_array($studentId, $pool, true)) {
            throw new StructureException('You are not part of this activity');
        }

        if (array_diff($invited, $pool) !== []) {
            throw new StructureException('Everyone you invite must be part of this activity.');
        }

        if (1 + count($invited) > $this->limit($config)) {
            throw new StructureException('A group can have at most ' . $this->limit($config) . ' members.');
        }

        if (db_connect()->table('student_groups')->where('config_id', $configId)->where('status !=', 'declined')->countAllResults() >= GroupLimits::MAX_GROUPS_PER_ACTIVITY) {
            throw new StructureException('An activity can have at most ' . GroupLimits::MAX_GROUPS_PER_ACTIVITY . ' groups.');
        }

        $grouped = array_map('intval', array_column(db_connect()->table('student_group_members')->select('student_user_id')->where('config_id', $configId)
            ->whereIn('student_user_id', [$studentId, ...$invited])->get()->getResultArray(), 'student_user_id'));

        if (in_array($studentId, $grouped, true)) {
            throw new StructureException('You are already in a group for this activity.');
        }

        if ($grouped !== []) {
            throw new StructureException('Someone you invited is already in a group.');
        }

        $groupId = 0;

        $this->atomic(function () use ($configId, $studentId, $invited, $name, &$groupId): void {
            $now = date('Y-m-d H:i:s');
            $db  = db_connect();
            $db->table('student_groups')->insert(['set_id' => null, 'config_id' => $configId, 'name' => $name, 'status' => 'proposed', 'proposed_by' => $studentId, 'created_at' => $now, 'updated_at' => $now]);
            $groupId = (int) $db->insertID();
            $db->table('student_group_members')->insert(['group_id' => $groupId, 'set_id' => null, 'config_id' => $configId, 'student_user_id' => $studentId, 'joined_at' => $now]);

            foreach ($invited as $id) {
                $db->table('student_group_invitations')->insert(['group_id' => $groupId, 'student_user_id' => $id, 'status' => 'pending', 'invited_at' => $now]);
            }
        }, 'Your proposal could not be saved. Try again.');

        GroupSetService::audit('groups.proposed', $studentId, $groupId, 'student_group', ['new' => ['config_id' => $configId, 'name' => $name, 'invitee_ids' => $invited]]);
        GroupNotifier::hook(static fn (GroupNotifier $n) => $n->proposalReady($groupId, $studentId));

        return $groupId;
    }

    public function respondToInvitation(int $groupId, int $studentId, bool $accept): void
    {
        $config = $this->configOfGroup($groupId);
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);

        if ($accept) {
            $this->assertOpen($config);

            if (!in_array($studentId, (new ActivityGroupService())->pool((int) $config['id']), true)) {
                throw new StructureException('You are not part of this activity');
            }
        }

        $this->atomic(function () use ($config, $groupId, $studentId, $accept): void {
            $db    = db_connect();
            $group = $this->lock($groupId);

            if ($group['status'] !== 'proposed' || $group['frozen_at'] !== null) {
                throw new StructureException('That group is no longer open for invitations.');
            }

            $invite = $db->query('SELECT id, status FROM student_group_invitations WHERE group_id = ? AND student_user_id = ? FOR UPDATE', [$groupId, $studentId])->getRowArray();

            if ($invite === null || $invite['status'] !== 'pending') {
                throw new StructureException('You have no pending invitation for this group.');
            }

            if ($accept) {
                if ($db->table('student_group_members')->where('config_id', $config['id'])->where('student_user_id', $studentId)->countAllResults() > 0) {
                    throw new StructureException('You are already in a group for this activity.');
                }

                if ($db->table('student_group_members')->where('group_id', $groupId)->countAllResults() >= $this->limit($config)) {
                    throw new StructureException('Group is full');
                }

                $db->table('student_group_members')->insert(['group_id' => $groupId, 'set_id' => null, 'config_id' => $config['id'], 'student_user_id' => $studentId, 'joined_at' => date('Y-m-d H:i:s')]);
            }

            $db->table('student_group_invitations')->where('id', $invite['id'])->update(['status' => $accept ? 'accepted' : 'declined', 'responded_at' => date('Y-m-d H:i:s')]);
        }, 'Your answer could not be saved. Try again.');

        if ($accept) {
            (new \App\Services\Courses\LearningProgressService())->onGroupMemberAdded((int) $config['id'], $studentId);
        }

        GroupSetService::audit($accept ? 'groups.invite_accepted' : 'groups.invite_declined', $studentId, $groupId, 'student_group', ['new' => ['group_id' => $groupId, 'student_user_id' => $studentId]]);
        $accept && GroupNotifier::hook(static fn (GroupNotifier $n) => $n->accepted($groupId, $studentId));
    }

    public function approve(int $groupId, int $userId): void
    {
        $config = $this->configOfGroup($groupId);
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);

        $this->atomic(function () use ($config, $groupId): void {
            $db = db_connect();

            if ($this->lock($groupId)['status'] !== 'proposed') {
                throw new StructureException('That proposal is no longer pending.');
            }

            if ($db->table('student_group_members')->where('group_id', $groupId)->countAllResults() < (int) $config['min_size']) {
                throw new StructureException('Needs at least ' . (int) $config['min_size'] . ' members');
            }

            $db->table('student_groups')->where('id', $groupId)->update(['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')]);
            $this->closeInvitations($groupId);
        }, 'The proposal could not be approved. Try again.');

        GroupSetService::audit('groups.proposal_approved', $userId, $groupId, 'student_group', ['old' => ['status' => 'proposed'], 'new' => ['status' => 'active']]);
        GroupNotifier::hook(static fn (GroupNotifier $n) => $n->groupApproved($groupId, $userId));
    }

    public function decline(int $groupId, int $userId, string $reason): void
    {
        $reason = trim($reason);
        $config = $this->configOfGroup($groupId);
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);

        if ($reason === '') {
            throw StructureException::field('reason', 'Give a reason for declining.');
        }

        $this->atomic(function () use ($groupId): void {
            $db = db_connect();

            if ($this->lock($groupId)['status'] !== 'proposed') {
                throw new StructureException('That proposal is no longer pending.');
            }

            $db->table('student_group_members')->where('group_id', $groupId)->delete();
            $db->table('student_groups')->where('id', $groupId)->update(['status' => 'declined', 'updated_at' => date('Y-m-d H:i:s')]);
            $this->closeInvitations($groupId);
        }, 'The proposal could not be declined. Try again.');

        GroupSetService::audit('groups.proposal_declined', $userId, $groupId, 'student_group', ['old' => ['status' => 'proposed'], 'new' => ['status' => 'declined', 'reason' => $reason]]);
    }

    /** @return list<array{id:int,name:string,proposer:string,member_names:list<string>}> invitations waiting for this student */
    public function pendingInvitationsFor(int $configId, int $studentId): array
    {
        $rows = db_connect()->table('student_group_invitations i')->select('g.id, g.name, g.proposed_by')->join('student_groups g', 'g.id = i.group_id')
            ->where('g.config_id', $configId)->where('g.status', 'proposed')->where('i.student_user_id', $studentId)->where('i.status', 'pending')->orderBy('i.id')->get()->getResultArray();

        return array_map(fn (array $g) => ['id' => (int) $g['id'], 'name' => (string) $g['name'], 'proposer' => $this->displayName((int) $g['proposed_by']), 'member_names' => $this->memberNames((int) $g['id'])], $rows);
    }

    /** @return list<array{id:int,name:string,proposer:string,member_names:list<string>,pending_names:list<string>,meetsMinimum:bool}> the instructor's queue */
    public function proposalsForConfig(int $configId): array
    {
        $min = (int) $this->config($configId)['min_size'];
        $out = [];

        foreach (db_connect()->table('student_groups')->where('config_id', $configId)->where('status', 'proposed')->orderBy('id')->get()->getResultArray() as $g) {
            $members = $this->memberNames((int) $g['id']);
            $pending = array_column(db_connect()->table('student_group_invitations')->select('student_user_id')->where('group_id', $g['id'])->where('status', 'pending')->orderBy('id')->get()->getResultArray(), 'student_user_id');
            $out[]   = ['id' => (int) $g['id'], 'name' => (string) $g['name'], 'proposer' => $this->displayName((int) $g['proposed_by']), 'member_names' => $members,
                'pending_names' => array_map(fn ($id) => $this->displayName((int) $id), $pending), 'meetsMinimum' => count($members) >= $min];
        }

        return $out;
    }

    /** @return array{belowMinimum:list<int>,ungrouped:list<int>} what the instructor must sort out once signup has closed */
    public function windowSummary(int $configId): array
    {
        $min   = (int) $this->config($configId)['min_size'];
        $below = [];

        foreach (db_connect()->query(
            "SELECT g.id FROM student_groups g LEFT JOIN student_group_members m ON m.group_id = g.id WHERE g.config_id = ? AND g.status IN ('active', 'proposed') GROUP BY g.id HAVING COUNT(m.id) < ? ORDER BY g.id",
            [$configId, $min],
        )->getResultArray() as $row) {
            $below[] = (int) $row['id'];
        }

        $grouped = array_map('intval', array_column(db_connect()->table('student_group_members')->select('student_user_id')->where('config_id', $configId)->get()->getResultArray(), 'student_user_id'));

        return ['belowMinimum' => $below, 'ungrouped' => array_values(array_diff((new ActivityGroupService())->pool($configId), $grouped))];
    }

    /** @param array<string, mixed> $config */
    private function assertOpen(array $config): void
    {
        if ($config['formation_mode'] !== 'proposed') {
            throw new StructureException('This activity does not use student proposals.');
        }

        $now = time();

        if ($config['signup_opens_at'] !== null && strtotime((string) $config['signup_opens_at']) > $now) {
            throw new StructureException('Signup has not opened yet');
        }

        if ($config['signup_closes_at'] !== null && strtotime((string) $config['signup_closes_at']) <= $now) {
            throw new StructureException('Signup is closed');
        }
    }

    /** @param array<string, mixed> $config */
    private function limit(array $config): int
    {
        return min((int) $config['max_size'], GroupLimits::MAX_MEMBERS);
    }

    /** Pending invitations of a group that is now decided can no longer be answered. */
    private function closeInvitations(int $groupId): void
    {
        db_connect()->table('student_group_invitations')->where('group_id', $groupId)->where('status', 'pending')->update(['status' => 'declined', 'responded_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed> locked first thing in the transaction so concurrent answers and decisions are serialized */
    private function lock(int $groupId): array
    {
        return db_connect()->query('SELECT id, config_id, status, frozen_at FROM student_groups WHERE id = ? FOR UPDATE', [$groupId])->getRowArray()
            ?? throw new StructureException('That group does not exist.');
    }

    /** Same shape as GroupSignupService: rule refusals pass through, anything else rolls back to a plain message. */
    private function atomic(callable $writes, string $failure): void
    {
        $db = db_connect();
        $db->transStart();

        try {
            $writes();
        } catch (StructureException $e) {
            $db->transRollback();

            throw $e;
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Group proposal failed: ' . $e->getMessage());

            throw new StructureException($failure);
        }

        $db->transComplete();

        if (!$db->transStatus()) {
            throw new StructureException($failure);
        }
    }

    /** @return list<string> */
    private function memberNames(int $groupId): array
    {
        $ids = array_column(db_connect()->table('student_group_members')->select('student_user_id')->where('group_id', $groupId)->orderBy('joined_at')->orderBy('id')->get()->getResultArray(), 'student_user_id');

        return array_map(fn ($id) => $this->displayName((int) $id), $ids);
    }

    private function displayName(int $userId): string
    {
        $row = db_connect()->table('users u')->select('u.username, up.first_name, up.last_name')->join('user_profiles up', 'up.user_id = u.id', 'left')->where('u.id', $userId)->get()->getRowArray();

        return $row === null ? '' : (trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: (string) $row['username']);
    }

    /** @return array<string, mixed> */
    private function config(int $configId): array
    {
        return (new ActivityGroupConfigModel())->find($configId) ?? throw new StructureException('That group activity does not exist.');
    }

    /** @return array<string, mixed> */
    private function configOfGroup(int $groupId): array
    {
        $row = db_connect()->table('student_groups')->select('config_id')->where('id', $groupId)->get()->getRowArray();

        if ($row === null || $row['config_id'] === null) {
            throw new StructureException('That group does not exist.');
        }

        return $this->config((int) $row['config_id']);
    }
}
