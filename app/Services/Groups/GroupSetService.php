<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Models\Groups\GroupSetModel;
use App\Models\Groups\StudentGroupModel;
use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Institutions\StructureException;

/**
 * Group sets and the groups inside them. Every write refuses on a finalized offering and leaves an audit row.
 * Rule violations throw StructureException (message for the person, field => message for the form).
 */
class GroupSetService
{
    private const MODES = ['manual', 'auto', 'self_signup', 'proposed'];

    /** Refuses with the finalization message when the offering's grades are final. Shared with GroupMembershipService. */
    public static function refuseIfFinalized(int $offeringId): void
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            throw new StructureException($locked['message']);
        }
    }

    /** @param array<string, mixed> $details old/new values */
    public static function audit(string $action, int $userId, int $targetId, string $targetType, array $details = []): void
    {
        service('auditLogger')->log($action, auth()->getProvider()->findById($userId), ['target_type' => $targetType, 'target_id' => $targetId] + $details);
    }

    /** @return array<string, mixed> */
    public function find(int $setId): array
    {
        return (new GroupSetModel())->find($setId) ?? throw new StructureException('That group set does not exist.');
    }

    public function create(int $offeringId, array $data, int $userId): int
    {
        self::refuseIfFinalized($offeringId);

        $row = $this->validated($data) + ['offering_id' => $offeringId, 'status' => 'active', 'created_by' => $userId];

        $active = (new GroupSetModel())->where('offering_id', $offeringId)->where('status', 'active')->countAllResults();

        if ($active >= GroupLimits::MAX_SETS_PER_OFFERING) {
            throw new StructureException('A course can have at most ' . GroupLimits::MAX_SETS_PER_OFFERING . ' group sets. Archive one first.');
        }

        $id = (int) (new GroupSetModel())->insert($row, true);
        self::audit('groups.set_created', $userId, $id, 'group_set', ['new' => $row]);

        return $id;
    }

    public function update(int $setId, array $data, int $userId): void
    {
        $old = $this->find($setId);
        self::refuseIfFinalized((int) $old['offering_id']);

        $new = $this->validated($data + $old);
        (new GroupSetModel())->update($setId, $new);
        self::audit('groups.set_updated', $userId, $setId, 'group_set', ['old' => array_intersect_key($old, $new), 'new' => $new]);
    }

    public function archive(int $setId, int $userId): void
    {
        $old = $this->find($setId);
        self::refuseIfFinalized((int) $old['offering_id']);

        (new GroupSetModel())->update($setId, ['status' => 'archived']);
        self::audit('groups.set_archived', $userId, $setId, 'group_set', ['old' => ['status' => $old['status']], 'new' => ['status' => 'archived']]);
    }

    public function addGroup(int $setId, string $name, int $userId): int
    {
        $set = $this->find($setId);
        self::refuseIfFinalized((int) $set['offering_id']);

        $name = $this->groupName($name);

        if ((new StudentGroupModel())->where('set_id', $setId)->countAllResults() >= GroupLimits::MAX_GROUPS_PER_ACTIVITY) {
            throw new StructureException('A set can have at most ' . GroupLimits::MAX_GROUPS_PER_ACTIVITY . ' groups.');
        }

        $id = (int) (new StudentGroupModel())->insert(['set_id' => $setId, 'name' => $name, 'status' => 'active'], true);
        self::audit('groups.group_added', $userId, $id, 'student_group', ['new' => ['set_id' => $setId, 'name' => $name]]);

        return $id;
    }

    /**
     * Stores a (possibly edited) GroupGeneratorService preview as "Group 1".."Group N" in one transaction.
     *
     * @param array{groups?: list<list<int>>} $preview
     */
    public function saveGenerated(int $setId, array $preview, bool $replace, int $userId): void
    {
        $set        = $this->find($setId);
        $offeringId = (int) $set['offering_id'];
        self::refuseIfFinalized($offeringId);

        $old = (new StudentGroupModel())->where('set_id', $setId)->findAll();

        if ($old !== [] && !$replace) {
            throw new StructureException('This set already has groups. Confirm to replace them.');
        }

        $groups = array_values(array_map(static fn ($g) => array_map('intval', (array) $g), $preview['groups'] ?? []));
        $all    = array_merge([], ...$groups);
        $limit  = min((int) $set['max_size'], GroupLimits::MAX_MEMBERS);

        if ($groups === [] || count($groups) > GroupLimits::MAX_GROUPS_PER_ACTIVITY) {
            throw new StructureException('Choose between 1 and ' . GroupLimits::MAX_GROUPS_PER_ACTIVITY . ' groups to save.');
        }

        if (array_diff($all, (new GroupMembershipService())->poolForOffering($offeringId)) !== [] || count($all) !== count(array_unique($all))) {
            throw new StructureException('Every student must be enrolled in this course and in only one group.');
        }

        foreach ($groups as $g) {
            if ($g === [] || count($g) > $limit) {
                throw new StructureException("A group needs between 1 and {$limit} students.");
            }
        }

        $db = db_connect();
        $db->transStart();

        try {
            if ($old !== []) {
                $db->table('student_group_members')->where('set_id', $setId)->delete();
                $db->table('student_groups')->where('set_id', $setId)->delete();
            }

            $now = date('Y-m-d H:i:s');

            foreach ($groups as $i => $members) {
                $groupId = (int) (new StudentGroupModel())->insert(['set_id' => $setId, 'name' => 'Group ' . ($i + 1), 'status' => 'active'], true);

                foreach ($members as $studentId) {
                    $db->table('student_group_members')->insert(['group_id' => $groupId, 'set_id' => $setId, 'config_id' => null, 'student_user_id' => $studentId, 'joined_at' => $now]);
                }
            }
        } catch (\Throwable $e) {
            $db->transRollback();

            throw new StructureException('The groups could not be saved. Nothing was changed.');
        }

        $db->transComplete();

        if (!$db->transStatus()) {
            throw new StructureException('The groups could not be saved. Nothing was changed.');
        }

        self::audit('groups.generated', $userId, $setId, 'group_set', ['new' => ['groups' => count($groups), 'students' => count($all)], 'replaced' => $old !== [], 'old_group_count' => count($old)]);
    }

    public function renameGroup(int $groupId, string $name, int $userId): void
    {
        $group = $this->group($groupId);
        self::refuseIfFinalized($this->offeringOfGroup($group));

        $name = $this->groupName($name);
        (new StudentGroupModel())->update($groupId, ['name' => $name]);
        self::audit('groups.group_renamed', $userId, $groupId, 'student_group', ['old' => ['name' => $group['name']], 'new' => ['name' => $name]]);
    }

    public function deleteGroup(int $groupId, int $userId): void
    {
        $group = $this->group($groupId);
        self::refuseIfFinalized($this->offeringOfGroup($group));

        $members = db_connect()->table('student_group_members')->select('student_user_id')->where('group_id', $groupId)->get()->getResultArray();

        db_connect()->transStart();
        db_connect()->table('student_group_members')->where('group_id', $groupId)->delete();
        (new StudentGroupModel())->delete($groupId);
        db_connect()->transComplete();

        self::audit('groups.group_deleted', $userId, $groupId, 'student_group', ['old' => ['set_id' => $group['set_id'], 'name' => $group['name'], 'members' => array_map('intval', array_column($members, 'student_user_id'))]]);
    }

    /** @return array<string, mixed> */
    private function group(int $groupId): array
    {
        $group = (new StudentGroupModel())->find($groupId);

        if ($group === null || $group['set_id'] === null) {
            throw new StructureException('That group does not exist.');
        }

        return $group;
    }

    private function offeringOfGroup(array $group): int
    {
        return (int) $this->find((int) $group['set_id'])['offering_id'];
    }

    /** Shared with ActivityGroupService. */
    public static function groupName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 150) {
            throw StructureException::field('name', 'Give the group a name of up to 150 characters.');
        }

        return $name;
    }

    /** @return array<string, mixed> the columns to store */
    private function validated(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $mode = (string) ($data['formation_mode'] ?? 'manual');
        $min  = (int) ($data['min_size'] ?? 1);
        $max  = (int) ($data['max_size'] ?? GroupLimits::MAX_MEMBERS);
        $open = ($data['signup_opens_at'] ?? null) ?: null;
        $shut = ($data['signup_closes_at'] ?? null) ?: null;

        $errors = [];

        if ($name === '' || mb_strlen($name) > 150) {
            $errors['name'] = 'Give the set a name of up to 150 characters.';
        }

        if (!in_array($mode, self::MODES, true)) {
            $errors['formation_mode'] = 'Choose how the groups are formed.';
        }

        if ($min < 1) {
            $errors['min_size'] = 'A group needs at least 1 member.';
        }

        if ($max < $min || $max > GroupLimits::MAX_MEMBERS) {
            $errors['max_size'] = 'The largest size must be between the smallest size and ' . GroupLimits::MAX_MEMBERS . '.';
        }

        if ($open !== null && $shut !== null && strtotime((string) $shut) <= strtotime((string) $open)) {
            $errors['signup_closes_at'] = 'Sign-up must close after it opens.';
        }

        if ($errors !== []) {
            throw new StructureException(reset($errors), $errors);
        }

        return ['name' => $name, 'formation_mode' => $mode, 'min_size' => $min, 'max_size' => $max, 'signup_opens_at' => $open, 'signup_closes_at' => $shut];
    }
}
