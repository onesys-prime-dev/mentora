<?php

declare(strict_types=1);

use App\Services\Groups\ActivityGroupMembershipService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupLeaderService;
use App\Services\Institutions\StructureException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Leader votes (strict majority of current members), instructor override, renaming and leader hand-over.
 *
 * @internal
 */
final class GroupLeaderServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GroupLeaderService $svc;

    private int $offering;

    private int $teacher;

    private int $config;

    /** @var list<int> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = new GroupLeaderService();
        $this->offering = CourseFactory::offering();
        $module         = CourseFactory::module($this->offering);
        $instructor     = CourseFactory::user('instructor');
        $this->teacher  = (int) $instructor->id;

        for ($i = 0; $i < 5; $i++) {
            $student   = CourseFactory::user();
            $this->s[] = (int) $student->id;
            CourseFactory::enroll($this->offering, $student);
        }

        $assignment   = CourseFactory::assessment($this->offering, $instructor, $module, null, 'Assignment');
        $this->config = (new ActivityGroupService())->attachAssessment($assignment, null, ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 5], $this->teacher);
        $this->config(['nonleader_can_rename' => 0, 'nonleader_can_vote' => 0]);
    }

    private function config(array $changes): void
    {
        db_connect()->table('activity_group_configs')->where('id', $this->config)->update($changes);
    }

    private function refused(callable $fn): StructureException
    {
        try {
            $fn();
        } catch (StructureException $e) {
            return $e;
        }

        $this->fail('Expected a StructureException.');
    }

    /** A group whose members joined one second apart in the order given. @param list<int> $members */
    private function group(array $members, string $name = 'Team'): int
    {
        db_connect()->table('student_groups')->insert(['set_id' => null, 'config_id' => $this->config, 'name' => $name, 'status' => 'active']);
        $group = (int) db_connect()->insertID();

        foreach ($members as $i => $id) {
            db_connect()->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $this->config, 'student_user_id' => $id, 'joined_at' => date('Y-m-d H:i:s', 1_700_000_000 + $i)]);
        }

        return $group;
    }

    private function row(int $group): array
    {
        return db_connect()->table('student_groups')->where('id', $group)->get()->getRowArray();
    }

    private function leader(int $group): ?int
    {
        $l = $this->row($group)['leader_user_id'];

        return $l === null ? null : (int) $l;
    }

    private function freeze(int $group): void
    {
        db_connect()->table('student_groups')->where('id', $group)->update(['frozen_at' => date('Y-m-d H:i:s')]);
    }

    private function audits(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->get()->getResultArray();
    }

    public function testMajorityFinalizesLeader(): void
    {
        $g = $this->group([$this->s[0], $this->s[1], $this->s[2]]);
        $this->svc->vote($g, $this->s[0], $this->s[1]);
        $this->assertNull($this->leader($g));
        $this->svc->vote($g, $this->s[2], $this->s[1]);
        $this->assertSame($this->s[1], $this->leader($g));
        $this->assertNotNull($this->row($g)['leader_finalized_at']);
    }

    public function testTwoMemberGroupNeedsBothVotes(): void
    {
        $g = $this->group([$this->s[0], $this->s[1]]);
        $this->svc->vote($g, $this->s[0], $this->s[0]);
        $this->assertNull($this->leader($g));
        $this->svc->vote($g, $this->s[1], $this->s[0]);
        $this->assertSame($this->s[0], $this->leader($g));
    }

    public function testSingleMemberGroupOneVote(): void
    {
        $g = $this->group([$this->s[0]]);
        $this->svc->nominate($g, $this->s[0], $this->s[0]);
        $this->assertSame($this->s[0], $this->leader($g));
        $state = $this->svc->leaderStateFor($g, $this->s[0]);
        $this->assertSame(1, $state['needed']);
        $this->assertTrue($state['final']);
        $this->assertSame($this->s[0], $state['myVote']);
    }

    public function testTieGivesNoLeader(): void
    {
        $g = $this->group([$this->s[0], $this->s[1], $this->s[2], $this->s[3]]);
        $this->svc->vote($g, $this->s[0], $this->s[0]);
        $this->svc->vote($g, $this->s[1], $this->s[0]);
        $this->svc->vote($g, $this->s[2], $this->s[1]);
        $this->svc->vote($g, $this->s[3], $this->s[1]);
        $this->assertNull($this->leader($g));
        $this->assertNull($this->row($g)['leader_finalized_at']);
        $state = $this->svc->leaderState($g);
        $this->assertSame(3, $state['needed']);
        $this->assertSame(4, $state['members']);
        $this->assertSame([$this->s[0] => 2, $this->s[1] => 2], $state['votes']);
    }

    public function testNonMemberCannotVoteOrBeCandidate(): void
    {
        $g = $this->group([$this->s[0], $this->s[1]]);
        $this->refused(fn () => $this->svc->vote($g, $this->s[4], $this->s[0]));
        $this->refused(fn () => $this->svc->vote($g, $this->s[0], $this->s[4]));
        $this->assertSame(0, db_connect()->table('student_group_leader_votes')->countAllResults());
    }

    public function testFrozenGroupRefusesVotes(): void
    {
        $g = $this->group([$this->s[0]]);
        $this->freeze($g);
        $this->refused(fn () => $this->svc->vote($g, $this->s[0], $this->s[0]));
        $this->assertNull($this->leader($g));
    }

    public function testFinalLeaderNotChangeableByVotesUnlessToggleOn(): void
    {
        $g = $this->group([$this->s[0], $this->s[1], $this->s[2]]);
        $this->svc->vote($g, $this->s[0], $this->s[0]);
        $this->svc->vote($g, $this->s[1], $this->s[0]);
        $this->assertSame($this->s[0], $this->leader($g));

        $this->refused(fn () => $this->svc->vote($g, $this->s[1], $this->s[2]));
        $this->assertSame($this->s[0], $this->leader($g));
    }

    public function testToggleOnLetsMajorityReplaceLeader(): void
    {
        $this->config(['nonleader_can_vote' => 1]);
        $g = $this->group([$this->s[0], $this->s[1], $this->s[2]]);
        $this->svc->vote($g, $this->s[0], $this->s[0]);
        $this->svc->vote($g, $this->s[1], $this->s[0]);
        $this->assertSame($this->s[0], $this->leader($g));

        $this->svc->vote($g, $this->s[1], $this->s[2]);
        $this->assertNull($this->leader($g), 'the old leader lost the majority and nobody has one');
        $this->svc->vote($g, $this->s[0], $this->s[2]);
        $this->assertSame($this->s[2], $this->leader($g));
    }

    public function testInstructorSetLeaderAnytimeAudited(): void
    {
        $g = $this->group([$this->s[0], $this->s[1]]);
        $this->freeze($g);
        $this->svc->setLeader($g, $this->s[1], $this->teacher);
        $this->assertSame($this->s[1], $this->leader($g));
        $this->assertNotNull($this->row($g)['leader_finalized_at']);
        $this->refused(fn () => $this->svc->setLeader($g, $this->s[4], $this->teacher));

        $rows = $this->audits('groups.leader_set');
        $this->assertCount(1, $rows);
        $this->assertSame((string) $this->teacher, (string) $rows[0]['actor_user_id']);
    }

    public function testRenameFreeBeforeFinalization(): void
    {
        $g = $this->group([$this->s[0], $this->s[1], $this->s[2]]);
        $this->svc->rename($g, 'Alpha', $this->s[2]);
        $this->assertSame('Alpha', $this->row($g)['name']);
        $this->refused(fn () => $this->svc->rename($g, '  ', $this->s[2]));
        $this->refused(fn () => $this->svc->rename($g, str_repeat('x', 151), $this->s[2]));
        $this->refused(fn () => $this->svc->rename($g, 'Nope', $this->s[4]), 'a non-member cannot rename');
    }

    public function testRenameOnlyLeaderAfterFinalizationUnlessToggle(): void
    {
        $g = $this->group([$this->s[0], $this->s[1], $this->s[2]]);
        $this->svc->setLeader($g, $this->s[0], $this->teacher);

        $this->refused(fn () => $this->svc->rename($g, 'Beta', $this->s[1]));
        $this->svc->rename($g, 'Beta', $this->s[0]);
        $this->assertSame('Beta', $this->row($g)['name']);

        $this->config(['nonleader_can_rename' => 1]);
        $this->svc->rename($g, 'Gamma', $this->s[1]);
        $this->assertSame('Gamma', $this->row($g)['name']);
    }

    public function testRenameRefusedWhenFrozenForStudents(): void
    {
        $g = $this->group([$this->s[0]]);
        $this->freeze($g);
        $this->refused(fn () => $this->svc->rename($g, 'Late', $this->s[0]));
        $this->assertSame('Team', $this->row($g)['name']);
    }

    public function testInstructorRenameAlways(): void
    {
        $g = $this->group([$this->s[0], $this->s[1]]);
        $this->svc->setLeader($g, $this->s[0], $this->teacher);
        $this->freeze($g);
        $this->svc->rename($g, 'Boss', $this->teacher, true);
        $this->assertSame('Boss', $this->row($g)['name']);
    }

    public function testLeaderLeavingPassesToNextJoined(): void
    {
        $g = $this->group([$this->s[0], $this->s[1], $this->s[2]]);
        $this->svc->setLeader($g, $this->s[1], $this->teacher);

        (new ActivityGroupMembershipService())->removeMember($this->s[1], $this->config, $this->teacher);
        $this->assertSame($this->s[0], $this->leader($g), 'earliest joined remaining member');
        $this->assertNotNull($this->row($g)['leader_finalized_at']);

        // A dropped leader passes leadership on even when the group is frozen.
        $this->freeze($g);
        (new ActivityGroupMembershipService())->onStudentDropped($this->offering, $this->s[0]);
        $this->assertSame($this->s[2], $this->leader($g));
        $this->assertNotEmpty($this->audits('groups.leader_passed'));
    }

    public function testFrozenGroupKeepsLeaderWhenOtherMemberDrops(): void
    {
        $g = $this->group([$this->s[0], $this->s[1], $this->s[2]]);
        $this->svc->setLeader($g, $this->s[0], $this->teacher);
        $this->freeze($g);
        (new ActivityGroupMembershipService())->onStudentDropped($this->offering, $this->s[2]);
        $this->assertSame($this->s[0], $this->leader($g));
    }

    public function testVotesRecountedWhenMemberLeaves(): void
    {
        // 4 members, s0 has 2 votes (no majority); a non-voting member leaves -> 3 members, 2 votes win.
        $g = $this->group([$this->s[0], $this->s[1], $this->s[2], $this->s[3]]);
        $this->svc->vote($g, $this->s[0], $this->s[0]);
        $this->svc->vote($g, $this->s[1], $this->s[0]);
        $this->assertNull($this->leader($g));
        (new ActivityGroupMembershipService())->removeMember($this->s[3], $this->config, $this->teacher);
        $this->assertSame($this->s[0], $this->leader($g));

    }

    public function testVoterLeavingCanUnfinalize(): void
    {
        // 3 members, s0 final with 2 votes; a voter leaves -> 2 members, 1 vote, no majority.
        $g2 = $this->group([$this->s[0], $this->s[1], $this->s[2]], 'Other');
        $this->svc->vote($g2, $this->s[0], $this->s[0]);
        $this->svc->vote($g2, $this->s[1], $this->s[0]);
        $this->assertSame($this->s[0], $this->leader($g2));
        db_connect()->table('student_group_members')->where('group_id', $g2)->where('student_user_id', $this->s[1])->delete();
        $this->svc->membershipChanged($g2);
        $this->assertNull($this->leader($g2));
        $this->assertNull($this->row($g2)['leader_finalized_at']);
        $this->assertSame(0, db_connect()->table('student_group_leader_votes')->where('group_id', $g2)->where('voter_user_id', $this->s[1])->countAllResults());
    }

    public function testLastMemberLeavingClearsLeader(): void
    {
        $g = $this->group([$this->s[0]]);
        $this->svc->vote($g, $this->s[0], $this->s[0]);
        (new ActivityGroupMembershipService())->removeMember($this->s[0], $this->config, $this->teacher);
        $this->assertNull($this->leader($g));
        $this->assertNull($this->row($g)['leader_finalized_at']);
    }

    public function testFinalizedOfferingRefuses(): void
    {
        $g = $this->group([$this->s[0]]);
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
        $this->refused(fn () => $this->svc->vote($g, $this->s[0], $this->s[0]));
        $this->refused(fn () => $this->svc->setLeader($g, $this->s[0], $this->teacher));
        $this->refused(fn () => $this->svc->rename($g, 'X', $this->s[0]));
        $this->assertNull($this->leader($g));
    }

    public function testAuditRows(): void
    {
        $g = $this->group([$this->s[0], $this->s[1]]);
        $this->svc->vote($g, $this->s[0], $this->s[1]);
        $this->svc->vote($g, $this->s[1], $this->s[1]);
        $this->svc->rename($g, 'Named', $this->s[1]);

        $this->assertCount(2, $this->audits('groups.leader_voted'));
        $this->assertCount(1, $this->audits('groups.leader_finalized'));
        $this->assertCount(1, $this->audits('groups.group_renamed'));
    }
}
