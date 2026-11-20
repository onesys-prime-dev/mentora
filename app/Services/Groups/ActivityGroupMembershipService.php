<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Models\Groups\ActivityGroupConfigModel;
use App\Services\Institutions\StructureException;

/**
 * Members, freezing and settings of an activity's groups. A group freezes at its first submission and then
 * refuses member changes until an instructor unlocks it with a reason (D5). Every write refuses on a finalized
 * offering and leaves an audit row.
 */
class ActivityGroupMembershipService
{
    private const ENUMS = [
        'ungrouped_policy' => ['block', 'solo'],
        'submission_style' => ['shared', 'leader_only', 'per_member'],
        'grading_mode'     => ['collective', 'collective_adjust', 'individual'],
        'completion_mode'  => ['any', 'each'],
        'formation_mode'   => ['manual', 'auto', 'self_signup', 'proposed'],
    ];

    private const FLAGS = ['use_average', 'nonleader_can_rename', 'nonleader_can_vote', 'nonleader_can_leave', 'groups_visible'];

    private const OTHER = ['min_size', 'max_size', 'signup_opens_at', 'signup_closes_at'];

    /** Called by the enrolment code after a status change; a groups failure must never undo the enrolment change. */
    public static function enrolmentChanged(int $offeringId, int $studentId, string $status): void
    {
        if (!in_array($status, ['dropped', 'withdrawn'], true)) {
            return;
        }

        try {
            (new self())->onStudentDropped($offeringId, $studentId);
        } catch (\Throwable $e) {
            log_message('error', 'Groups were not updated for a dropped student: ' . $e->getMessage());
        }
    }

    public function addMember(int $groupId, int $studentId, int $actorId): void
    {
        $this->add($groupId, $studentId, $actorId, false, null);
    }

    public function addLateEnrollee(int $groupId, int $studentId, string $reason, int $userId): void
    {
        $this->add($groupId, $studentId, $userId, true, $this->reason($reason));
    }

    public function moveMember(int $studentId, int $toGroupId, int $actorId): void
    {
        $to = $this->group($toGroupId);
        GroupSetService::refuseIfFinalized((int) $to['offering_id']);
        $from = $this->currentGroup($studentId, (int) $to['config_id']);

        if ($from === null) {
            throw new StructureException('That student is not in a group of this activity yet.');
        }

        if ($from === $toGroupId) {
            return;
        }

        $this->atomic(function () use ($studentId, $to, $from, $toGroupId): void {
            $ids = [$from, $toGroupId];
            sort($ids);

            foreach ($ids as $id) {
                $locked = $this->lock($id);
                $this->refuseIfFrozen($locked);
            }

            $this->assertRoom($toGroupId, (int) $to['config_id']);
            db_connect()->table('student_group_members')->where('config_id', $to['config_id'])->where('student_user_id', $studentId)->delete();
            $this->insert($toGroupId, (int) $to['config_id'], $studentId);
        }, 'The student could not be moved. They stay in their group.');

        GroupSetService::audit('groups.member_moved', $actorId, $toGroupId, 'student_group', ['old' => ['group_id' => $from], 'new' => ['group_id' => $toGroupId, 'student_user_id' => $studentId, 'config_id' => (int) $to['config_id']]]);
    }

    public function removeMember(int $studentId, int $configId, int $actorId): void
    {
        $config = $this->config($configId);
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);
        $from = $this->currentGroup($studentId, $configId);

        if ($from === null) {
            return;
        }

        $this->atomic(function () use ($studentId, $configId, $from): void {
            $this->refuseIfFrozen($this->lock($from));
            db_connect()->table('student_group_members')->where('config_id', $configId)->where('student_user_id', $studentId)->delete();
        }, 'The student could not be removed. Try again.');

        GroupSetService::audit('groups.member_removed', $actorId, $from, 'student_group', ['old' => ['group_id' => $from, 'student_user_id' => $studentId, 'config_id' => $configId]]);
    }

    /** Idempotent. Called by the submission code (Phase 6) at a group's first submission. */
    public function freezeGroup(int $groupId): void
    {
        db_connect()->table('student_groups')->where('id', $groupId)->where('config_id IS NOT NULL', null, false)->where('frozen_at IS NULL', null, false)->update(['frozen_at' => date('Y-m-d H:i:s')]);

        if (db_connect()->affectedRows() > 0) {
            GroupSetService::audit('groups.frozen', 0, $groupId, 'student_group', ['new' => ['frozen' => true]]);
        }
    }

    public function unlock(int $groupId, string $reason, int $userId): void
    {
        $group = $this->group($groupId);
        GroupSetService::refuseIfFinalized((int) $group['offering_id']);
        $reason = $this->reason($reason);

        if ($group['frozen_at'] === null) {
            return;
        }

        db_connect()->table('student_groups')->where('id', $groupId)->update(['frozen_at' => null]);
        GroupSetService::audit('groups.unlocked', $userId, $groupId, 'student_group', ['old' => ['frozen_at' => $group['frozen_at']], 'new' => ['reason' => $reason]]);
    }

    /** @param array<string, mixed> $changes */
    public function changeSettings(int $configId, array $changes, bool $confirmed, int $userId): void
    {
        $config = $this->config($configId);
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);

        $allowed = array_merge(array_keys(self::ENUMS), self::FLAGS, self::OTHER);
        $new     = [];
        $old     = [];

        if ($unknown = array_diff(array_keys($changes), $allowed)) {
            throw StructureException::field((string) reset($unknown), 'That setting cannot be changed here.');
        }

        foreach ($changes as $key => $value) {
            $value = $this->normal($key, $value);

            if ($value !== $this->normal($key, $config[$key])) {
                $old[$key] = $this->normal($key, $config[$key]);
                $new[$key] = $value;
            }
        }

        foreach (array_intersect_key(self::ENUMS, $new) as $key => $options) {
            if ($new[$key] === null ? $key !== 'completion_mode' : !in_array($new[$key], $options, true)) {
                throw StructureException::field($key, 'That option is not valid.');
            }
        }

        $min = (int) ($new['min_size'] ?? $config['min_size']);
        $max = (int) ($new['max_size'] ?? $config['max_size']);

        if ((isset($new['min_size']) || isset($new['max_size'])) && ($min < 1 || $max < $min || $max > GroupLimits::MAX_MEMBERS)) {
            throw StructureException::field('max_size', 'Group sizes must be between 1 and ' . GroupLimits::MAX_MEMBERS . ', smallest first.');
        }

        foreach (['signup_opens_at', 'signup_closes_at'] as $key) {
            if (isset($new[$key]) && strtotime((string) $new[$key]) === false) {
                throw StructureException::field($key, 'That is not a valid date and time.');
            }
        }

        $opens  = $new['signup_opens_at'] ?? $this->normal('signup_opens_at', $config['signup_opens_at']);
        $closes = $new['signup_closes_at'] ?? $this->normal('signup_closes_at', $config['signup_closes_at']);

        if ((isset($new['signup_opens_at']) || isset($new['signup_closes_at'])) && $opens !== null && $closes !== null && strtotime((string) $closes) <= strtotime((string) $opens)) {
            throw StructureException::field('signup_closes_at', 'Sign-up must close after it opens.');
        }

        if ($new === []) {
            return;
        }

        $frozen = db_connect()->table('student_groups')->where('config_id', $configId)->where('frozen_at IS NOT NULL', null, false)->countAllResults() > 0;

        if ($frozen && isset($new['submission_style'])) {
            throw StructureException::field('submission_style', 'A group has already submitted. Unlock the group first to change how work is submitted.');
        }

        if ($frozen && isset($new['formation_mode'])) {
            throw StructureException::field('formation_mode', 'A group has already submitted. Unlock the group first to change how groups are formed.');
        }

        if ($frozen && isset($new['ungrouped_policy']) && !$confirmed) {
            throw StructureException::field('confirm_required', 'Groups have already submitted. Changing what happens to ungrouped students affects work in progress. Confirm to continue.');
        }

        (new ActivityGroupConfigModel())->update($configId, $new);
        GroupSetService::audit('groups.settings_changed', $userId, $configId, 'activity_group_config', ['old' => $old, 'new' => $new]);
    }

    /** Removes the student from every group of the offering, activity or set, frozen or not (D9). */
    public function onStudentDropped(int $offeringId, int $studentId): void
    {
        GroupSetService::refuseIfFinalized($offeringId);
        // Phase 7 adds the keep-or-excuse decision for graded groups here; until grading exists a drop always removes.

        $rows = db_connect()->table('student_group_members m')->select('m.id, m.group_id, m.set_id, m.config_id')
            ->join('group_sets gs', 'gs.id = m.set_id', 'left')->join('activity_group_configs c', 'c.id = m.config_id', 'left')
            ->where('m.student_user_id', $studentId)->groupStart()->where('gs.offering_id', $offeringId)->orWhere('c.offering_id', $offeringId)->groupEnd()
            ->get()->getResultArray();

        foreach ($rows as $row) {
            db_connect()->table('student_group_members')->where('id', $row['id'])->delete();
            GroupSetService::audit('groups.member_removed', 0, (int) $row['group_id'], 'student_group', [
                'old' => ['group_id' => (int) $row['group_id'], 'student_user_id' => $studentId, 'set_id' => $row['set_id'], 'config_id' => $row['config_id']],
                'new' => ['reason' => 'dropped'],
            ]);
        }
    }

    private function add(int $groupId, int $studentId, int $actorId, bool $late, ?string $reason): void
    {
        $group = $this->group($groupId);
        GroupSetService::refuseIfFinalized((int) $group['offering_id']);
        $configId = (int) $group['config_id'];

        $this->atomic(function () use ($groupId, $studentId, $configId, $late): void {
            $locked = $this->lock($groupId);

            if (!$late) {
                $this->refuseIfFrozen($locked);
            }

            if (!in_array($studentId, (new ActivityGroupService())->pool($configId), true)) {
                throw new StructureException('That student is not one of the students who can do this activity.');
            }

            if ($this->currentGroup($studentId, $configId) !== null) {
                throw new StructureException('That student is already in a group of this activity. Move them instead.');
            }

            $this->assertRoom($groupId, $configId);
            $this->insert($groupId, $configId, $studentId);
        }, 'The student could not be added. Try again.');

        GroupSetService::audit('groups.member_added', $actorId, $groupId, 'student_group', ['new' => ['student_user_id' => $studentId, 'group_id' => $groupId, 'config_id' => $configId] + ($late ? ['late_enrollee' => true, 'reason' => $reason] : [])]);
    }

    /** Runs the writes in one transaction; rule refusals pass through, anything else becomes the plain message. */
    private function atomic(callable $writes, string $failure): void
    {
        $db = db_connect();
        $db->transStart();

        try {
            $writes();
        } catch (StructureException $e) {
            $db->transComplete();

            throw $e;
        }

        $db->transComplete();

        if (!$db->transStatus()) {
            throw new StructureException($failure);
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw StructureException::field('reason', 'Give a reason of up to 500 characters.');
        }

        return $reason;
    }

    private function normal(string $key, mixed $value): int|string|null
    {
        if (in_array($key, self::FLAGS, true)) {
            return (int) (bool) $value;
        }

        if ($key === 'min_size' || $key === 'max_size') {
            return (int) $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        // A datetime-local value and the stored value compare equal, so a re-save is no change.
        if (($key === 'signup_opens_at' || $key === 'signup_closes_at') && ($time = strtotime((string) $value)) !== false) {
            return date('Y-m-d H:i:s', $time);
        }

        return (string) $value;
    }

    /** @return array<string, mixed> an activity group plus its activity's offering */
    private function group(int $groupId): array
    {
        $row = db_connect()->table('student_groups g')->select('g.*, c.offering_id')->join('activity_group_configs c', 'c.id = g.config_id')->where('g.id', $groupId)->get()->getRowArray();

        return $row ?? throw new StructureException('That group does not exist.');
    }

    /** @return array<string, mixed> */
    private function config(int $configId): array
    {
        return (new ActivityGroupConfigModel())->find($configId) ?? throw new StructureException('That group activity does not exist.');
    }

    private function currentGroup(int $studentId, int $configId): ?int
    {
        $row = db_connect()->table('student_group_members')->select('group_id')->where('config_id', $configId)->where('student_user_id', $studentId)->get()->getRowArray();

        return $row === null ? null : (int) $row['group_id'];
    }

    /** Serializes changes to one group so two people cannot take the last seat. @return array<string, mixed> */
    private function lock(int $groupId): array
    {
        return db_connect()->query('SELECT id, frozen_at FROM student_groups WHERE id = ? FOR UPDATE', [$groupId])->getRowArray() ?? throw new StructureException('That group does not exist.');
    }

    private function refuseIfFrozen(array $group): void
    {
        if ($group['frozen_at'] !== null) {
            throw new StructureException('This group has already submitted, so its members are locked. Unlock the group first to change them.');
        }
    }

    private function assertRoom(int $groupId, int $configId): void
    {
        $limit = min((int) $this->config($configId)['max_size'], GroupLimits::MAX_MEMBERS);

        if (db_connect()->table('student_group_members')->where('group_id', $groupId)->countAllResults() >= $limit) {
            throw new StructureException('Group is full.');
        }
    }

    private function insert(int $groupId, int $configId, int $studentId): void
    {
        db_connect()->table('student_group_members')->insert(['group_id' => $groupId, 'set_id' => null, 'config_id' => $configId, 'student_user_id' => $studentId, 'joined_at' => date('Y-m-d H:i:s')]);
    }
}
