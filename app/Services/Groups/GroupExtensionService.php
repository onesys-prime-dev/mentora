<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Services\Institutions\StructureException;

/** More time for one group on one assignment. Refuses on a finalized offering and leaves an audit row. */
class GroupExtensionService
{
    public function extend(int $groupId, int $assessmentId, string $until, string $reason, int $userId): void
    {
        $reason = trim($reason);
        $config = (new ActivityGroupService())->findByAssessment($assessmentId);
        $group  = db_connect()->table('student_groups')->where('id', $groupId)->get()->getRowArray();

        if ($config === null || $group === null || (int) $group['config_id'] !== (int) $config['id']) {
            throw new StructureException('That group does not belong to this assignment.');
        }

        GroupSetService::refuseIfFinalized((int) $config['offering_id']);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw StructureException::field('reason', 'Give a reason of up to 500 characters.');
        }

        $untilTs = strtotime($until);

        if ($untilTs === false) {
            throw StructureException::field('until', 'Enter a valid date and time.');
        }

        $old     = $this->extensionFor($groupId, $assessmentId);
        $due     = db_connect()->table('assessments')->select('due_date')->where('id', $assessmentId)->get()->getRowArray()['due_date'] ?? null;
        $current = max(array_filter([$due === null ? null : strtotime($due), $old === null ? null : strtotime($old['until_at'])])) ?: time();

        if ($untilTs <= $current) {
            throw StructureException::field('until', 'The new deadline must be later than the current one.');
        }

        $until = date('Y-m-d H:i:s', $untilTs);
        $db    = db_connect();

        if ($old === null) {
            $db->table('group_extensions')->insert(['group_id' => $groupId, 'assessment_id' => $assessmentId, 'until_at' => $until, 'reason' => $reason, 'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s')]);
        } else {
            $db->table('group_extensions')->where('id', $old['id'])->update(['until_at' => $until, 'reason' => $reason, 'created_by' => $userId]);
        }

        GroupSetService::audit('groups.deadline_extended', $userId, $groupId, 'student_group', [
            'old' => ['until_at' => $old['until_at'] ?? null, 'reason' => $old['reason'] ?? null],
            'new' => ['until_at' => $until, 'reason' => $reason],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function extensionFor(int $groupId, int $assessmentId): ?array
    {
        return db_connect()->table('group_extensions')->where('group_id', $groupId)->where('assessment_id', $assessmentId)->get()->getRowArray();
    }
}
