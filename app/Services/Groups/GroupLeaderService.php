<?php

declare(strict_types=1);

namespace App\Services\Groups;

use App\Services\Institutions\StructureException;

/**
 * Leader of an activity group: members vote (a nomination is a vote) and a candidate with strictly more than half of the
 * current members' votes becomes the final leader (a tie or no majority means none). The instructor can always set one.
 * When members leave, their votes are discarded and the tally is recounted; a leaving final leader passes the role to
 * the next-joined member (D15). Every write refuses on a finalized offering and leaves an audit row.
 */
class GroupLeaderService
{
    public function nominate(int $groupId, int $voterId, int $candidateId): void
    {
        $this->vote($groupId, $voterId, $candidateId);
    }

    public function vote(int $groupId, int $voterId, int $candidateId): void
    {
        $audits = [];
        $old    = null;

        $this->atomic(function () use ($groupId, $voterId, $candidateId, &$audits, &$old): void {
            $group = $this->lock($groupId);
            GroupSetService::refuseIfFinalized((int) $group['offering_id']);

            if ($group['status'] !== 'active') {
                throw new StructureException('That group is not open for a leader vote.');
            }

            if ($group['frozen_at'] !== null) {
                throw new StructureException('This group has already submitted, so its leader is locked.');
            }

            $members = $this->members($groupId);

            if (!in_array($voterId, $members, true) || !in_array($candidateId, $members, true)) {
                throw new StructureException('Only members of the group can vote, and only for a member.');
            }

            if ($group['leader_finalized_at'] !== null && (int) $group['nonleader_can_vote'] !== 1) {
                throw new StructureException('Your group has already chosen its leader.');
            }

            $db  = db_connect();
            $old = $db->table('student_group_leader_votes')->select('candidate_user_id')->where('group_id', $groupId)->where('voter_user_id', $voterId)->get()->getRowArray();
            $db->table('student_group_leader_votes')->where('group_id', $groupId)->where('voter_user_id', $voterId)->delete();
            $db->table('student_group_leader_votes')->insert(['group_id' => $groupId, 'voter_user_id' => $voterId, 'candidate_user_id' => $candidateId, 'voted_at' => date('Y-m-d H:i:s')]);
            $audits = $this->settle($group, $members, false);
        }, 'Your vote could not be saved. Try again.');

        GroupSetService::audit('groups.leader_voted', $voterId, $groupId, 'student_group', ['old' => ['candidate_user_id' => $old === null ? null : (int) $old['candidate_user_id']], 'new' => ['candidate_user_id' => $candidateId]]);
        GroupNotifier::hook(static fn (GroupNotifier $n) => $n->leaderVote($groupId, $voterId));
        $this->emit($audits, $voterId, $groupId);
    }

    /** Instructor or chairperson override: allowed at any time, even on a frozen group. */
    public function setLeader(int $groupId, int $studentId, int $userId): void
    {
        $old = null;

        $this->atomic(function () use ($groupId, $studentId, &$old): void {
            $group = $this->lock($groupId);
            GroupSetService::refuseIfFinalized((int) $group['offering_id']);

            if (!in_array($studentId, $this->members($groupId), true)) {
                throw new StructureException('The leader must be a member of the group.');
            }

            $old = $group['leader_user_id'] === null ? null : (int) $group['leader_user_id'];
            db_connect()->table('student_groups')->where('id', $groupId)->update(['leader_user_id' => $studentId, 'leader_finalized_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        }, 'The leader could not be set. Try again.');

        GroupSetService::audit('groups.leader_set', $userId, $groupId, 'student_group', ['old' => ['leader_user_id' => $old], 'new' => ['leader_user_id' => $studentId]]);
        GroupNotifier::hook(static fn (GroupNotifier $n) => $n->memberChanged($groupId, $studentId, 'leader', $userId));
    }

    public function rename(int $groupId, string $name, int $byUserId, bool $asInstructor = false): void
    {
        $name = GroupSetService::groupName($name);
        $old  = '';

        $this->atomic(function () use ($groupId, $name, $byUserId, $asInstructor, &$old): void {
            $group = $this->lock($groupId);
            GroupSetService::refuseIfFinalized((int) $group['offering_id']);

            if (!$asInstructor) {
                if ($group['frozen_at'] !== null) {
                    throw new StructureException('This group has already submitted, so it cannot be renamed.');
                }

                if (!in_array($byUserId, $this->members($groupId), true)) {
                    throw new StructureException('Only members of the group can rename it.');
                }

                if ($group['leader_finalized_at'] !== null && (int) $group['leader_user_id'] !== $byUserId && (int) $group['nonleader_can_rename'] !== 1) {
                    throw new StructureException('Only the group leader can rename the group.');
                }
            }

            $old = (string) $group['name'];
            db_connect()->table('student_groups')->where('id', $groupId)->update(['name' => $name, 'updated_at' => date('Y-m-d H:i:s')]);
        }, 'The group could not be renamed. Try again.');

        GroupSetService::audit('groups.group_renamed', $byUserId, $groupId, 'student_group', ['old' => ['name' => $old], 'new' => ['name' => $name]]);
    }

    /**
     * Call after any member left, was removed or moved out: drops votes of non-members, recounts, and passes a lost
     * leadership to the next-joined member. A frozen group only loses members through a drop, which also passes it on.
     */
    public function membershipChanged(int $groupId): void
    {
        $audits = [];

        $this->atomic(function () use ($groupId, &$audits): void {
            try {
                $group = $this->lock($groupId);
            } catch (StructureException) {
                return; // not an activity group
            }

            $audits = $this->settle($group, $this->members($groupId), true);
        }, 'The leader could not be updated.');

        $this->emit($audits, 0, $groupId);
    }

    /** @return array{leaderId:?int,final:bool,votes:array<int,int>,needed:int,members:int} */
    public function leaderState(int $groupId): array
    {
        $group   = db_connect()->table('student_groups')->select('leader_user_id, leader_finalized_at')->where('id', $groupId)->get()->getRowArray() ?? throw new StructureException('That group does not exist.');
        $members = $this->members($groupId);

        return ['leaderId' => $group['leader_user_id'] === null ? null : (int) $group['leader_user_id'], 'final' => $group['leader_finalized_at'] !== null,
            'votes' => $this->tally($groupId, $members), 'needed' => intdiv(count($members), 2) + 1, 'members' => count($members)];
    }

    /** @return array{leaderId:?int,final:bool,votes:array<int,int>,needed:int,members:int,myVote:?int} */
    public function leaderStateFor(int $groupId, int $studentId): array
    {
        $mine = db_connect()->table('student_group_leader_votes')->select('candidate_user_id')->where('group_id', $groupId)->where('voter_user_id', $studentId)->get()->getRowArray();

        return $this->leaderState($groupId) + ['myVote' => $mine === null ? null : (int) $mine['candidate_user_id']];
    }

    /**
     * Discards votes of non-members, then makes the leader match the votes. With $passOn a leader who is no longer a member
     * is replaced by the next-joined member when nobody has a majority. Runs inside the caller's transaction.
     *
     * @param array<string, mixed> $group locked row
     * @param list<int>            $members
     *
     * @return list<array{0:string,1:array<string, mixed>}> audits to write once the transaction has committed
     */
    private function settle(array $group, array $members, bool $passOn): array
    {
        $db      = db_connect();
        $groupId = (int) $group['id'];
        $now     = date('Y-m-d H:i:s');
        $audits  = [];

        if ($members === []) {
            $db->table('student_group_leader_votes')->where('group_id', $groupId)->delete();
        } else {
            $db->table('student_group_leader_votes')->where('group_id', $groupId)->groupStart()->whereNotIn('voter_user_id', $members)->orWhereNotIn('candidate_user_id', $members)->groupEnd()->delete();
        }

        $leader = $group['leader_user_id'] === null ? null : (int) $group['leader_user_id'];
        $votes  = $this->tally($groupId, $members);
        $needed = intdiv(count($members), 2) + 1;
        $winner = null;

        foreach ($votes as $candidate => $count) {
            if ($count >= $needed) {
                $winner = $candidate;
            }
        }

        $new   = null;
        $event = 'groups.leader_finalized';

        if ($winner !== null) {
            $new = $winner;
        } elseif ($leader !== null && in_array($leader, $members, true)) {
            // An instructor-set leader without any votes stays; one whose votes no longer make a majority is unfinalized.
            $new = ($votes[$leader] ?? 0) > 0 ? null : $leader;
        } elseif ($leader !== null && $passOn && $members !== []) {
            $new   = $members[0];
            $event = 'groups.leader_passed';
        }

        if ($new === $leader && ($new === null || $group['leader_finalized_at'] !== null)) {
            return [];
        }

        $db->table('student_groups')->where('id', $groupId)->update(['leader_user_id' => $new, 'leader_finalized_at' => $new === null ? null : $now, 'updated_at' => $now]);

        return [[$new === null ? 'groups.leader_finalized' : $event, ['old' => ['leader_user_id' => $leader], 'new' => ['leader_user_id' => $new]]]];
    }

    /** @param list<array{0:string,1:array<string, mixed>}> $audits */
    private function emit(array $audits, int $userId, int $groupId): void
    {
        foreach ($audits as [$action, $details]) {
            GroupSetService::audit($action, $userId, $groupId, 'student_group', $details);
            $details['new']['leader_user_id'] === null || GroupNotifier::hook(static fn (GroupNotifier $n) => $n->memberChanged($groupId, (int) $details['new']['leader_user_id'], 'leader', $userId));
        }
    }

    /**
     * @param list<int> $members
     *
     * @return array<int, int> candidate id => votes cast by current members for current members
     */
    private function tally(int $groupId, array $members): array
    {
        if ($members === []) {
            return [];
        }

        $rows = db_connect()->table('student_group_leader_votes')->select('candidate_user_id, COUNT(*) AS n')->where('group_id', $groupId)
            ->whereIn('voter_user_id', $members)->whereIn('candidate_user_id', $members)->groupBy('candidate_user_id')->orderBy('candidate_user_id')->get()->getResultArray();
        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['candidate_user_id']] = (int) $row['n'];
        }

        return $out;
    }

    /** @return list<int> current members, earliest joined first */
    private function members(int $groupId): array
    {
        $rows = db_connect()->table('student_group_members')->select('student_user_id')->where('group_id', $groupId)->orderBy('joined_at')->orderBy('id')->get()->getResultArray();

        return array_map('intval', array_column($rows, 'student_user_id'));
    }

    /** @return array<string, mixed> the activity group with its offering and the activity's leader settings, locked */
    private function lock(int $groupId): array
    {
        $row = db_connect()->query(
            'SELECT g.*, c.offering_id, c.nonleader_can_rename, c.nonleader_can_vote FROM student_groups g JOIN activity_group_configs c ON c.id = g.config_id WHERE g.id = ? FOR UPDATE',
            [$groupId],
        )->getRowArray();

        return $row ?? throw new StructureException('That group does not exist.');
    }

    /** Same shape as GroupSignupService: rule refusals pass through, anything else rolls back to a plain message. */
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
            log_message('error', 'Group leader change failed: ' . $e->getMessage());

            throw new StructureException($failure);
        }

        $db->transComplete();

        if (!$db->transStatus()) {
            throw new StructureException($failure);
        }
    }
}
