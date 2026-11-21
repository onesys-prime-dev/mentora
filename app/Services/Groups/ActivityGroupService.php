<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Models\Assessments\AssessmentModel;
use App\Models\Groups\ActivityGroupConfigModel;
use App\Models\Groups\StudentGroupModel;
use App\Services\Institutions\StructureException;

/**
 * Turns an assignment into a group activity. The activity gets its own COPY of a set's groups (D2), so editing
 * the set later never changes a running activity. Every write refuses on a finalized offering and leaves an audit row.
 */
class ActivityGroupService
{
    private const ENUMS = [
        'formation_mode'   => ['manual', 'auto', 'self_signup', 'proposed'],
        'ungrouped_policy' => ['block', 'solo'],
        'submission_style' => ['shared', 'leader_only', 'per_member'],
        'grading_mode'     => ['collective', 'collective_adjust', 'individual'],
        'completion_mode'  => ['any', 'each'],
    ];

    private const FLAGS = ['use_average', 'nonleader_can_rename', 'nonleader_can_vote', 'nonleader_can_leave', 'groups_visible'];

    /** @param array<string, mixed> $settings optional keys, see the activity_group_configs columns */
    public function attachAssessment(int $assessmentId, ?int $setId, array $settings, int $userId): int
    {
        $assessment = db_connect()->table('assessments')->select('assessments.id, assessment_types.name AS type_name')
            ->join('assessment_types', 'assessment_types.id = assessments.assessment_type_id', 'left')
            ->where('assessments.id', $assessmentId)->get()->getRowArray();
        $offeringId = (new AssessmentModel())->offeringIdOf($assessmentId);

        if ($assessment === null || $offeringId === null) {
            throw new StructureException('That assignment does not exist.');
        }

        GroupSetService::refuseIfFinalized($offeringId);

        if (strtolower((string) $assessment['type_name']) === 'quiz') {
            throw new StructureException('Groups can be used on assignments only.');
        }

        if ($this->findByAssessment($assessmentId) !== null) {
            throw new StructureException('This assignment already uses groups.');
        }

        return $this->attach($offeringId, 'assessment_id', $assessmentId, $setId, $settings, $userId);
    }

    /** What an item's groups can be set to: no leader, grading, submission or ungrouped rules (D12). */
    public const ITEM_SETTINGS = ['formation_mode', 'min_size', 'max_size', 'signup_opens_at', 'signup_closes_at', 'completion_mode', 'groups_visible'];

    /**
     * Groups on an ungraded item are grouping only (D12) and never freeze (D26); $completionMode is 'any' or 'each' (D13).
     * Students proposing groups is offered on assignments only, so 'proposed' is refused here.
     *
     * @param array<string, mixed> $settings optional ITEM_SETTINGS
     */
    public function attachLearningItem(int $itemId, ?int $setId, string $completionMode, int $userId, array $settings = []): int
    {
        $item = db_connect()->table('learning_items')->select('learning_items.item_type, course_modules.offering_id')
            ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')->join('course_modules', 'course_modules.id = learning_units.module_id')
            ->where('learning_items.id', $itemId)->get()->getRowArray();

        if ($item === null) {
            throw new StructureException('That learning item does not exist.');
        }

        $offeringId = (int) $item['offering_id'];
        GroupSetService::refuseIfFinalized($offeringId);

        if (in_array($item['item_type'], ['assignment', 'quiz'], true)) {
            throw new StructureException("Use the assignment's own group settings. On a learning item, groups can be used on a reading, video, PDF, link or discussion only.");
        }

        if (!in_array($item['item_type'], ['reading', 'video', 'pdf', 'link', 'discussion'], true) || db_connect()->table('assessments')->where('learning_item_id', $itemId)->countAllResults() > 0) {
            throw new StructureException('Groups can be used on a reading, video, PDF, link or discussion item only.');
        }

        if ($this->findByLearningItem($itemId) !== null) {
            throw new StructureException('This item already uses groups.');
        }

        if (!in_array($completionMode, self::ENUMS['completion_mode'], true)) {
            throw StructureException::field('completion_mode', 'Choose how the item is completed.');
        }

        $set = $setId === null ? null : (new GroupSetService())->find($setId);

        if (($settings['formation_mode'] ?? null) === 'proposed' || ($set['formation_mode'] ?? null) === 'proposed') {
            throw StructureException::field('formation_mode', 'Students cannot propose groups on a learning item. Choose another way to form groups, or another set.');
        }

        $settings = ['completion_mode' => $completionMode] + array_intersect_key($settings, array_flip(self::ITEM_SETTINGS));

        return $this->attach($offeringId, 'learning_item_id', $itemId, $setId, $settings, $userId);
    }

    /** The shared tail of both attach methods: one independent copy of the set, atomic, audited. */
    private function attach(int $offeringId, string $targetColumn, int $targetId, ?int $setId, array $settings, int $userId): int
    {
        $set = $setId === null ? null : (new GroupSetService())->find($setId);

        if ($set !== null) {
            if ((int) $set['offering_id'] !== $offeringId) {
                throw new StructureException('That group set belongs to another course.');
            }

            if ((new \App\Models\Groups\StudentGroupModel())->where('set_id', $setId)->countAllResults() > GroupLimits::MAX_GROUPS_PER_ACTIVITY) {
                throw new StructureException('An activity can have at most ' . GroupLimits::MAX_GROUPS_PER_ACTIVITY . ' groups.');
            }

            $settings = array_intersect_key($set, array_flip(['formation_mode', 'min_size', 'max_size', 'signup_opens_at', 'signup_closes_at'])) + $settings;
        }

        $row = $this->validated($settings) + ['offering_id' => $offeringId, $targetColumn => $targetId, 'source_set_id' => $setId, 'created_by' => $userId];

        $db = db_connect();
        $db->transStart();

        try {
            $configId = (int) (new ActivityGroupConfigModel())->insert($row, true);

            if ($setId !== null) {
                $this->copyGroups($configId, 'set_id', $setId);
            }
        } catch (\Throwable $e) {
            $db->transRollback();

            throw new StructureException('The groups could not be attached. Nothing was changed.');
        }

        $db->transComplete();

        if (!$db->transStatus()) {
            throw new StructureException('The groups could not be attached. Nothing was changed.');
        }

        GroupSetService::audit('groups.activity_attached', $userId, $configId, 'activity_group_config', ['new' => $row]);

        return $configId;
    }

    public function copyFromActivity(int $configId, int $fromConfigId, int $userId): void
    {
        $config = $this->find($configId);
        $from   = $this->find($fromConfigId);

        if ($configId === $fromConfigId || (int) $from['offering_id'] !== (int) $config['offering_id']) {
            throw new StructureException('Choose another activity of this course to copy from.');
        }

        $this->replace($configId, $config, 'config_id', $fromConfigId, 'groups.activity_copied', $userId, ['from_config_id' => $fromConfigId]);
    }

    public function recopy(int $configId, int $userId): void
    {
        $config = $this->find($configId);

        if ($config['source_set_id'] === null) {
            throw new StructureException('This activity was not made from a group set.');
        }

        $this->replace($configId, $config, 'set_id', (int) $config['source_set_id'], 'groups.activity_recopied', $userId, ['from_set_id' => (int) $config['source_set_id']]);
    }

    public function detach(int $configId, int $userId): void
    {
        $config = $this->find($configId);
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);
        $this->refuseIfFrozen($configId);

        (new ActivityGroupConfigModel())->delete($configId); // the groups and members go with it (foreign keys)
        GroupSetService::audit('groups.activity_detached', $userId, $configId, 'activity_group_config', ['old' => $config]);
    }

    public function addGroup(int $configId, string $name, int $userId): int
    {
        $config = $this->find($configId);
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);
        $name = GroupSetService::groupName($name);

        if ((new StudentGroupModel())->where('config_id', $configId)->countAllResults() >= GroupLimits::MAX_GROUPS_PER_ACTIVITY) {
            throw new StructureException('An activity can have at most ' . GroupLimits::MAX_GROUPS_PER_ACTIVITY . ' groups.');
        }

        $id = (int) (new StudentGroupModel())->insert(['config_id' => $configId, 'name' => $name, 'status' => 'active'], true);
        GroupSetService::audit('groups.group_added', $userId, $id, 'student_group', ['new' => ['config_id' => $configId, 'name' => $name]]);

        return $id;
    }

    /** Staff rename: the same locked, audited rename students use (GroupLeaderService), without the student rules. */
    public function renameGroup(int $groupId, string $name, int $userId): void
    {
        $this->group($groupId);
        (new GroupLeaderService())->rename($groupId, $name, $userId, true);
    }

    /** A group that has submitted keeps its work, so it cannot be deleted. */
    public function deleteGroup(int $groupId, int $userId): void
    {
        $group = $this->group($groupId);

        if ($group['frozen_at'] !== null) {
            throw new StructureException('This group has already submitted, so it cannot be deleted.');
        }

        $members = db_connect()->table('student_group_members')->select('student_user_id')->where('group_id', $groupId)->get()->getResultArray();
        (new StudentGroupModel())->delete($groupId); // its members go with it (foreign key)
        GroupSetService::audit('groups.group_deleted', $userId, $groupId, 'student_group', ['old' => ['config_id' => (int) $group['config_id'], 'name' => $group['name'], 'members' => array_map('intval', array_column($members, 'student_user_id'))]]);
    }

    /** @return array<string, mixed> an activity group; refuses on a finalized offering */
    private function group(int $groupId): array
    {
        $group = (new StudentGroupModel())->find($groupId);

        if ($group === null || $group['config_id'] === null) {
            throw new StructureException('That group does not exist.');
        }

        GroupSetService::refuseIfFinalized((int) $this->find((int) $group['config_id'])['offering_id']);

        return $group;
    }

    /** @return list<int> students who may be in a group of this activity (D23) */
    public function pool(int $configId): array
    {
        $config = $this->find($configId);
        $pool   = (new GroupMembershipService())->poolForOffering((int) $config['offering_id']);

        if ($config['assessment_id'] === null || db_connect()->table('assessment_settings')->where('assessment_id', $config['assessment_id'])->where('assign_to_specific_students', 1)->countAllResults() === 0) {
            return $pool;
        }

        $rows = db_connect()->table('assessment_assignees')->select('student_user_id')->where('assessment_id', $config['assessment_id'])->get()->getResultArray();

        return array_values(array_intersect($pool, array_map('intval', array_column($rows, 'student_user_id'))));
    }

    /** @return array<string, mixed>|null */
    public function findByAssessment(int $assessmentId): ?array
    {
        return (new ActivityGroupConfigModel())->where('assessment_id', $assessmentId)->first();
    }

    /** @return array<string, mixed>|null */
    public function findByLearningItem(int $itemId): ?array
    {
        return (new ActivityGroupConfigModel())->where('learning_item_id', $itemId)->first();
    }

    /** @return array<string, mixed> */
    private function find(int $configId): array
    {
        return (new ActivityGroupConfigModel())->find($configId) ?? throw new StructureException('That group activity does not exist.');
    }

    private function refuseIfFrozen(int $configId): void
    {
        if (db_connect()->table('student_groups')->where('config_id', $configId)->where('frozen_at IS NOT NULL', null, false)->countAllResults() > 0) {
            throw new StructureException('A group has already submitted, so the groups are locked.');
        }
    }

    /** Swaps this activity's groups for a fresh copy in one transaction. */
    private function replace(int $configId, array $config, string $column, int $sourceId, string $action, int $userId, array $details): void
    {
        GroupSetService::refuseIfFinalized((int) $config['offering_id']);
        $this->refuseIfFrozen($configId);

        $db = db_connect();
        $db->transStart();

        try {
            $db->table('student_group_members')->where('config_id', $configId)->delete();
            $db->table('student_groups')->where('config_id', $configId)->delete();
            $this->copyGroups($configId, $column, $sourceId);
        } catch (\Throwable $e) {
            $db->transRollback();

            throw new StructureException('The groups could not be copied. Nothing was changed.');
        }

        $db->transComplete();

        if (!$db->transStatus()) {
            throw new StructureException('The groups could not be copied. Nothing was changed.');
        }

        GroupSetService::audit($action, $userId, $configId, 'activity_group_config', ['new' => $details]);
    }

    /** Copies the groups of a set or of another activity, keeping only students still eligible. Overridable so a test can fail it. */
    protected function copyGroups(int $configId, string $column, int $sourceId): void
    {
        $db   = db_connect();
        $pool = $this->pool($configId);
        $now  = date('Y-m-d H:i:s');

        foreach ($db->table('student_groups')->where($column, $sourceId)->orderBy('id')->get()->getResultArray() as $group) {
            $groupId = (int) (new \App\Models\Groups\StudentGroupModel())->insert(['config_id' => $configId, 'name' => $group['name'], 'status' => 'active'], true);

            $members = $db->table('student_group_members')->select('student_user_id')->where('group_id', $group['id'])->get()->getResultArray();

            foreach (array_intersect(array_map('intval', array_column($members, 'student_user_id')), $pool) as $studentId) {
                $db->table('student_group_members')->insert(['group_id' => $groupId, 'set_id' => null, 'config_id' => $configId, 'student_user_id' => $studentId, 'joined_at' => $now]);
            }
        }
    }

    /** @return array<string, mixed> the columns to store, defaults filled in */
    private function validated(array $s): array
    {
        $row = ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => GroupLimits::MAX_MEMBERS, 'signup_opens_at' => null, 'signup_closes_at' => null,
            'ungrouped_policy' => 'block', 'submission_style' => 'shared', 'grading_mode' => 'collective', 'completion_mode' => null,
            'use_average' => 0, 'nonleader_can_rename' => 0, 'nonleader_can_vote' => 0, 'nonleader_can_leave' => 1, 'groups_visible' => 0];

        foreach (array_intersect_key($s, $row) as $key => $value) {
            $row[$key] = in_array($key, self::FLAGS, true) ? (int) (bool) $value : (($value === '' ? null : $value));
        }

        foreach (self::ENUMS as $key => $allowed) {
            if ($row[$key] !== null && !in_array($row[$key], $allowed, true)) {
                throw StructureException::field($key, 'That option is not valid.');
            }
        }

        $row['min_size'] = (int) $row['min_size'];
        $row['max_size'] = (int) $row['max_size'];

        if ($row['min_size'] < 1 || $row['max_size'] < $row['min_size'] || $row['max_size'] > GroupLimits::MAX_MEMBERS) {
            throw StructureException::field('max_size', 'Group sizes must be between 1 and ' . GroupLimits::MAX_MEMBERS . ', smallest first.');
        }

        return $row;
    }
}
