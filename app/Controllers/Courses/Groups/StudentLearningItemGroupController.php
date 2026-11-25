<?php

declare(strict_types=1);

namespace App\Controllers\Courses\Groups;

use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupSignupService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * JSON for the student's group card on an ungraded learning item (screen 3D). Same rules as an assignment's card
 * (own group only; other groups by name only when groups_visible), plus the completion status: each member's own
 * status under "each", or the group's one status and who marked it under "any". Items never freeze, have no
 * leader and never block an ungrouped student. Join, leave and switch are the parent's actions.
 */
class StudentLearningItemGroupController extends StudentGroupController
{
    private const NAME = "COALESCE(NULLIF(TRIM(CONCAT(p.first_name, ' ', p.last_name)), ''), u.username)";

    public function show(int $offeringId, int $itemId): ResponseInterface
    {
        $config = $this->findConfig($offeringId, $itemId);

        if ($config === null) {
            return $this->response->setJSON(['success' => true, 'isGroupActivity' => false]);
        }

        $configId  = (int) $config['id'];
        $me        = $this->actor();
        $finalized = (new GradeFinalizationService())->lockRefusal($offeringId) !== null;
        $groups    = array_values(array_filter($this->groups($configId), static fn (array $g) => $g['status'] === 'active'));
        $mine      = null;

        foreach ($groups as $g) {
            if (in_array($me, array_column($g['members'], 'id'), true)) {
                $mine = $g;
            }
        }

        $opens  = $config['signup_opens_at'];
        $closes = $config['signup_closes_at'];
        $isOpen = $config['formation_mode'] === 'self_signup' && ($opens === null || strtotime((string) $opens) <= time()) && ($closes === null || strtotime((string) $closes) > time());
        $inPool = in_array($me, (new ActivityGroupService())->pool($configId), true);

        $out = [
            'success'            => true,
            'isGroupActivity'    => true,
            'completionMode'     => $config['completion_mode'],
            'inPool'             => $inPool,
            'blocked'            => false, // an ungrouped student still completes the item alone
            'signup'             => ['mode' => $config['formation_mode'], 'opensAt' => $opens, 'closesAt' => $closes, 'isOpen' => $isOpen, 'minSize' => (int) $config['min_size'], 'maxSize' => (int) $config['max_size']],
            'myGroup'            => $mine === null ? null : $this->mine($mine, $config, $finalized),
            'candidates'         => $inPool ? (new GroupSignupService())->candidateGroups($configId, $me) : [],
            'offeringFinalized'  => $finalized,
            'canPropose'         => false,
            'invitable'          => [],
            'pendingInvitations' => [],
        ];

        if ((int) $config['groups_visible'] === 1) {
            $out['otherGroups'] = array_values(array_map(
                // Other groups stay names only: no avatar is drawn for them.
                static fn (array $g) => ['id' => $g['id'], 'name' => $g['name'], 'members' => array_map(static fn (array $m): array => ['id' => $m['id'], 'name' => $m['name']], $g['members'])],
                array_filter($groups, static fn (array $g) => $mine === null || $g['id'] !== $mine['id']),
            ));
        }

        return $this->response->setJSON($out);
    }

    protected function findConfig(int $offeringId, int $itemId): ?array
    {
        LearningItemGroupsController::assertItemOf($offeringId, $itemId);

        return (new ActivityGroupService())->findByLearningItem($itemId);
    }

    /**
     * @param array<string, mixed> $g
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function mine(array $g, array $config, bool $finalized): array
    {
        $me     = $this->actor();
        $itemId = (int) $config['learning_item_id'];
        $done   = array_map('intval', array_column(db_connect()->table('learning_completions')->select('student_user_id')
            ->where('learning_item_id', $itemId)->where('status', 'completed')->whereIn('student_user_id', array_column($g['members'], 'id') ?: [0])->get()->getResultArray(), 'student_user_id'));
        $each   = $config['completion_mode'] !== 'any';

        $members = array_map(static fn (array $m) => ['id' => $m['id'], 'name' => $m['name'], 'avatar' => $m['avatar'], 'isMe' => $m['id'] === $me] + ($each ? ['completed' => in_array($m['id'], $done, true)] : []), $g['members']);

        $out = [
            'id'             => $g['id'],
            'name'           => $g['name'],
            'status'         => $g['status'],
            'members'        => $members,
            'frozen'         => false,
            'canLeave'       => !$finalized,
            'canLeaveReason' => $finalized ? 'The grades of this course are final, so groups cannot change.' : null,
            'canRename'      => false,
            'canVote'        => false,
            'leader'         => null,
            'extension'      => null,
        ];

        if (!$each) {
            $marker = db_connect()->table('audit_logs a')->select(self::NAME . ' AS name', false)
                ->join('users u', 'u.id = a.actor_user_id')->join('user_profiles p', 'p.user_id = u.id', 'left')
                ->where('a.action', 'groups.completed_for_group')->where('a.target_type', 'student_group')->where('a.target_id', (string) $g['id'])
                ->orderBy('a.id', 'DESC')->limit(1)->get()->getRowArray();

            $out['completed']   = $done !== [];
            $out['completedBy'] = $done === [] ? null : ($marker['name'] ?? null);
        }

        return $out;
    }
}
