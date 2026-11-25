<?php

declare(strict_types=1);

namespace App\Controllers\Courses\Groups;

use App\Controllers\BaseController;
use App\Models\Assessments\AssessmentModel;
use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupExtensionService;
use App\Services\Groups\GroupLeaderService;
use App\Services\Groups\GroupProposalService;
use App\Services\Groups\GroupSignupService;
use App\Services\Institutions\StructureException;
use App\Services\Profiles\AvatarData;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * JSON for a student's "Group activity" card (screens 3A, 3C): their own group, the groups they can still join,
 * and join, leave and switch. A student only ever receives their own group, the open groups while they can
 * join, and other groups only when the instructor allows it (groups_visible). A member is { id, name } and
 * nothing else. An assessment not of the offering or a group not of this activity is "not found"; a broken
 * rule is a 422 with { message, errors }.
 */
class StudentGroupController extends BaseController
{
    private const NAME = "COALESCE(NULLIF(TRIM(CONCAT(p.first_name, ' ', p.last_name)), ''), u.username)";

    public function show(int $offeringId, int $assessmentId): ResponseInterface
    {
        $this->assessment($offeringId, $assessmentId);
        $config = (new ActivityGroupService())->findByAssessment($assessmentId);

        if ($config === null) {
            return $this->response->setJSON(['success' => true, 'isGroupActivity' => false]);
        }

        $configId = (int) $config['id'];
        $me       = $this->actor();
        $inPool   = in_array($me, (new ActivityGroupService())->pool($configId), true);
        $finalized = (new GradeFinalizationService())->lockRefusal($offeringId) !== null;
        $groups   = $this->groups($configId);
        $mine     = null;

        foreach ($groups as $g) {
            if (in_array($me, array_column($g['members'], 'id'), true)) {
                $mine = $g;
            }
        }

        $opens    = $config['signup_opens_at'];
        $closes   = $config['signup_closes_at'];
        $inWindow = ($opens === null || strtotime((string) $opens) <= time()) && ($closes === null || strtotime((string) $closes) > time());
        $isOpen   = $config['formation_mode'] === 'self_signup' && $inWindow;
        // Mode D: an ungrouped student of the activity may propose a group while the window is open.
        $canPropose = $config['formation_mode'] === 'proposed' && $inWindow && $inPool && $mine === null && !$finalized;

        $out = [
            'success'            => true,
            'isGroupActivity'    => true,
            'inPool'             => $inPool,
            'ungroupedPolicy'    => $config['ungrouped_policy'],
            'submissionStyle'    => $config['submission_style'],
            'signup'             => ['mode' => $config['formation_mode'], 'opensAt' => $opens, 'closesAt' => $closes, 'isOpen' => $isOpen,
                'minSize' => (int) $config['min_size'], 'maxSize' => (int) $config['max_size']],
            'myGroup'            => $mine === null ? null : $this->mine($mine, $config, $finalized),
            'candidates'         => $inPool ? (new GroupSignupService())->candidateGroups($configId, $me) : [],
            'blocked'            => $inPool && $mine === null && $config['ungrouped_policy'] === 'block',
            'offeringFinalized'  => $finalized,
            'canPropose'         => $canPropose,
            'invitable'          => $canPropose ? $this->invitable($configId, $me) : [],
            'pendingInvitations' => $mine === null ? array_map(static fn (array $i) => ['groupId' => $i['id'], 'name' => $i['name'], 'proposer' => $i['proposer'], 'members' => $i['member_names']],
                (new GroupProposalService())->pendingInvitationsFor($configId, $me)) : [],
        ];

        if ((int) $config['groups_visible'] === 1) {
            $out['otherGroups'] = array_values(array_map(
                // Other groups stay names only: no avatar is drawn for them.
                static fn (array $g) => ['id' => $g['id'], 'name' => $g['name'], 'members' => array_map(static fn (array $m): array => ['id' => $m['id'], 'name' => $m['name']], $g['members'])],
                array_filter($groups, static fn (array $g) => $g['status'] === 'active' && ($mine === null || $g['id'] !== $mine['id'])),
            ));
        }

        return $this->response->setJSON($out);
    }

    public function join(int $offeringId, int $assessmentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);
        $groupId  = $this->groupId($configId);

        return $this->run(fn () => (new GroupSignupService())->join($configId, $groupId, $this->actor()));
    }

    public function switch(int $offeringId, int $assessmentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);
        $groupId  = $this->groupId($configId);

        return $this->run(fn () => (new GroupSignupService())->switchTo($configId, $groupId, $this->actor()));
    }

    public function leave(int $offeringId, int $assessmentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);
        $row      = db_connect()->table('student_group_members')->select('group_id')->where('config_id', $configId)->where('student_user_id', $this->actor())->get()->getRowArray();

        return $this->run(function () use ($row): void {
            if ($row === null) {
                throw new StructureException('You are not in a group.');
            }

            (new GroupSignupService())->leave((int) $row['group_id'], $this->actor());
        });
    }

    public function vote(int $offeringId, int $assessmentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);
        $body     = $this->request->getJSON(true) ?? [];

        return $this->run(fn () => (new GroupLeaderService())->vote($this->myGroupId($configId), $this->actor(), (int) ($body['candidate_id'] ?? 0)));
    }

    public function rename(int $offeringId, int $assessmentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);
        $body     = $this->request->getJSON(true) ?? [];

        return $this->run(fn () => (new GroupLeaderService())->rename($this->myGroupId($configId), (string) ($body['name'] ?? ''), $this->actor()));
    }

    public function propose(int $offeringId, int $assessmentId): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);
        $body     = $this->request->getJSON(true) ?? [];
        $invitees = array_map('intval', array_filter((array) ($body['invitee_ids'] ?? []), 'is_numeric'));

        return $this->run(fn () => ['id' => (new GroupProposalService())->propose($configId, $this->actor(), $invitees, (string) ($body['name'] ?? ''))]);
    }

    public function accept(int $offeringId, int $assessmentId, int $groupId): ResponseInterface
    {
        return $this->answer($offeringId, $assessmentId, $groupId, true);
    }

    public function decline(int $offeringId, int $assessmentId, int $groupId): ResponseInterface
    {
        return $this->answer($offeringId, $assessmentId, $groupId, false);
    }

    private function answer(int $offeringId, int $assessmentId, int $groupId, bool $accept): ResponseInterface
    {
        $configId = $this->configId($offeringId, $assessmentId);

        // Only a group of this activity that invited this student exists for them.
        if (db_connect()->table('student_group_invitations i')->join('student_groups g', 'g.id = i.group_id')
            ->where('g.id', $groupId)->where('g.config_id', $configId)->where('i.student_user_id', $this->actor())->countAllResults() === 0) {
            throw PageNotFoundException::forPageNotFound('Invitation not found.');
        }

        return $this->run(fn () => (new GroupProposalService())->respondToInvitation($groupId, $this->actor(), $accept));
    }

    private function myGroupId(int $configId): int
    {
        $row = db_connect()->table('student_group_members')->select('group_id')->where('config_id', $configId)->where('student_user_id', $this->actor())->get()->getRowArray();

        return $row === null ? throw new StructureException('You are not in a group.') : (int) $row['group_id'];
    }

    /** @return array{until:string,reason:string}|null */
    private function extensionOf(int $groupId, int $assessmentId): ?array
    {
        $x = (new GroupExtensionService())->extensionFor($groupId, $assessmentId);

        return $x === null ? null : ['until' => $x['until_at'], 'reason' => $x['reason']];
    }

    /** @return list<array{id:int,name:string}> students of the activity who are in no group (or proposal) yet, except me */
    private function invitable(int $configId, int $me): array
    {
        $grouped = array_map('intval', array_column(db_connect()->table('student_group_members')->select('student_user_id')->where('config_id', $configId)->get()->getResultArray(), 'student_user_id'));
        $free    = array_values(array_diff((new ActivityGroupService())->pool($configId), $grouped, [$me]));

        if ($free === []) {
            return [];
        }

        $rows = db_connect()->table('users u')->select('u.id, ' . self::NAME . ' AS name', false)->join('user_profiles p', 'p.user_id = u.id', 'left')
            ->whereIn('u.id', $free)->orderBy('name')->get()->getResultArray();

        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    /** @return list<array{id:int,name:string,status:string,frozen:bool,leader_user_id:?int,leader_finalized_at:?string,members:list<array{id:int,name:string,avatar:?array<string, mixed>}>}> */
    protected function groups(int $configId): array
    {
        $members = db_connect()->table('student_group_members m')
            ->select('m.group_id, m.student_user_id, ' . self::NAME . ' AS name', false)
            ->join('users u', 'u.id = m.student_user_id')->join('user_profiles p', 'p.user_id = u.id', 'left')
            ->where('m.config_id', $configId)->orderBy('m.joined_at')->orderBy('m.id')->get()->getResultArray();

        $byGroup = [];
        // One lookup for the activity: every member's avatar as this student may see them (FR2).
        $avatars = (new AvatarData())->forViewerJson(auth()->user(), array_map('intval', array_column($members, 'student_user_id')));

        foreach ($members as $m) {
            $byGroup[(int) $m['group_id']][] = ['id' => (int) $m['student_user_id'], 'name' => $avatars[(int) $m['student_user_id']]['name'] ?? (string) $m['name'], 'avatar' => $avatars[(int) $m['student_user_id']] ?? null];
        }

        $rows = db_connect()->table('student_groups')->where('config_id', $configId)->whereIn('status', ['active', 'proposed'])->orderBy('name')->orderBy('id')->get()->getResultArray();

        return array_map(static fn (array $g) => [
            'id'                  => (int) $g['id'],
            'name'                => (string) $g['name'],
            'status'              => (string) $g['status'],
            'frozen'              => $g['frozen_at'] !== null,
            'leader_user_id'      => $g['leader_user_id'] === null ? null : (int) $g['leader_user_id'],
            'leader_finalized_at' => $g['leader_finalized_at'],
            'members'             => $byGroup[(int) $g['id']] ?? [],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $g
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed> id, name, status, members ({id, name, avatar} only), frozen, leave/rename/vote rights, leader and, while proposed, the proposal
     */
    private function mine(array $g, array $config, bool $finalized): array
    {
        $me       = $this->actor();
        $isLeader = $g['leader_finalized_at'] !== null && $g['leader_user_id'] === $me;
        $final    = $g['leader_finalized_at'] !== null;
        $open     = !$finalized && !$g['frozen'];
        $reason   = match (true) {
            $finalized => 'The grades of this course are final, so groups cannot change.',
            $g['frozen'] => 'Your group has submitted. Ask your instructor if someone must change.',
            $final && !$isLeader && (int) $config['nonleader_can_leave'] !== 1 => 'Your group has chosen its leader, so members cannot leave. Ask your instructor.',
            default => null,
        };

        $state = (new GroupLeaderService())->leaderStateFor($g['id'], $me);
        $votes = [];

        foreach ($state['votes'] as $candidate => $count) {
            $votes[] = ['candidateId' => $candidate, 'count' => $count];
        }

        $out = [
            'id'             => $g['id'],
            'name'           => $g['name'],
            'status'         => $g['status'],
            'members'        => $g['members'],
            'frozen'         => $g['frozen'],
            'canLeave'       => $reason === null,
            'canLeaveReason' => $reason,
            // D14: anyone renames until the leader is final, then only the leader unless the instructor allows it.
            'canRename'      => $open && (!$final || $isLeader || (int) $config['nonleader_can_rename'] === 1),
            'canVote'        => $open && $g['status'] === 'active' && (!$final || (int) $config['nonleader_can_vote'] === 1),
            'extension'      => $this->extensionOf($g['id'], (int) $config['assessment_id']),
            'leader'         => ['id' => $state['leaderId'], 'final' => $state['final'], 'votes' => $votes, 'needed' => $state['needed'], 'myVote' => $state['myVote']],
        ];

        if ($g['status'] === 'proposed') {
            $pending = db_connect()->table('student_group_invitations i')->select(self::NAME . ' AS name', false)
                ->join('users u', 'u.id = i.student_user_id')->join('user_profiles p', 'p.user_id = u.id', 'left')
                ->where('i.group_id', $g['id'])->where('i.status', 'pending')->orderBy('i.id')->get()->getResultArray();
            $out['proposal'] = ['pending' => array_map(static fn (array $r) => (string) $r['name'], $pending)];
        }

        return $out;
    }

    private function assessment(int $offeringId, int $assessmentId): void
    {
        if ((new AssessmentModel())->offeringIdOf($assessmentId) !== $offeringId) {
            throw PageNotFoundException::forPageNotFound('Assessment not found.');
        }
    }

    /** @return array<string, mixed>|null the activity's group settings; 404 when the activity is not of this offering (the item subclass overrides it) */
    protected function findConfig(int $offeringId, int $assessmentId): ?array
    {
        $this->assessment($offeringId, $assessmentId);

        return (new ActivityGroupService())->findByAssessment($assessmentId);
    }

    protected function configId(int $offeringId, int $assessmentId): int
    {
        $config = $this->findConfig($offeringId, $assessmentId);

        if ($config === null) {
            throw PageNotFoundException::forPageNotFound('This assignment does not use groups.');
        }

        return (int) $config['id'];
    }

    protected function groupId(int $configId): int
    {
        $id = (int) (($this->request->getJSON(true) ?? [])['group_id'] ?? 0);

        if (db_connect()->table('student_groups')->where('id', $id)->where('config_id', $configId)->countAllResults() === 0) {
            throw PageNotFoundException::forPageNotFound('Group not found.');
        }

        return $id;
    }

    /** Runs a change: 200 {success}, or 422 {message, errors} for a broken rule. */
    protected function run(callable $change): ResponseInterface
    {
        try {
            $result = $change();
        } catch (StructureException $e) {
            return $this->response->setStatusCode(422)->setJSON(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors()]);
        }

        return $this->response->setJSON(['success' => true] + (is_array($result) ? $result : []));
    }

    protected function actor(): int
    {
        return (int) auth()->id();
    }
}
