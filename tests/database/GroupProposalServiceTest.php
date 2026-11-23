<?php

declare(strict_types=1);

use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupProposalService;
use App\Services\Groups\GroupSignupService;
use App\Services\Institutions\StructureException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Students propose groups and invite classmates; instructors approve or decline.
 *
 * @internal
 */
final class GroupProposalServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GroupProposalService $svc;

    private int $offering;

    private int $teacher;

    private int $config;

    /** @var list<int> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = new GroupProposalService();
        $this->offering = CourseFactory::offering();
        $module         = CourseFactory::module($this->offering);
        $instructor     = CourseFactory::user('instructor');
        $this->teacher  = (int) $instructor->id;

        for ($i = 0; $i < 6; $i++) {
            $student   = CourseFactory::user();
            $this->s[] = (int) $student->id;
            CourseFactory::enroll($this->offering, $student);
        }

        $assignment   = CourseFactory::assessment($this->offering, $instructor, $module, null, 'Assignment');
        $this->config = (new ActivityGroupService())->attachAssessment($assignment, null, ['formation_mode' => 'proposed', 'min_size' => 2, 'max_size' => 3], $this->teacher);
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

    /** @return list<int> */
    private function members(int $group): array
    {
        $rows = db_connect()->table('student_group_members')->where('group_id', $group)->orderBy('id')->get()->getResultArray();

        return array_map('intval', array_column($rows, 'student_user_id'));
    }

    private function groupStatus(int $group): string
    {
        return (string) db_connect()->table('student_groups')->where('id', $group)->get()->getRowArray()['status'];
    }

    private function invite(int $group, int $student): ?string
    {
        $row = db_connect()->table('student_group_invitations')->where('group_id', $group)->where('student_user_id', $student)->get()->getRowArray();

        return $row === null ? null : (string) $row['status'];
    }

    private function audits(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->get()->getResultArray();
    }

    private function rawGroup(string $name, string $status = 'active'): int
    {
        db_connect()->table('student_groups')->insert(['set_id' => null, 'config_id' => $this->config, 'name' => $name, 'status' => $status]);

        return (int) db_connect()->insertID();
    }

    private function seatElsewhere(int $student): int
    {
        $group = $this->rawGroup('Other' . $student);
        db_connect()->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $this->config, 'student_user_id' => $student, 'joined_at' => date('Y-m-d H:i:s')]);

        return $group;
    }

    public function testProposalOnlyInProposedMode(): void
    {
        $this->config(['formation_mode' => 'self_signup']);
        $this->refused(fn () => $this->svc->propose($this->config, $this->s[0], [$this->s[1]], 'Team'));
        $this->assertSame(0, db_connect()->table('student_groups')->where('config_id', $this->config)->countAllResults());
    }

    public function testProposalOutsideWindowRefused(): void
    {
        $this->config(['signup_opens_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->assertSame('Signup has not opened yet', $this->refused(fn () => $this->svc->propose($this->config, $this->s[0], [$this->s[1]], 'Team'))->getMessage());

        $this->config(['signup_opens_at' => date('Y-m-d H:i:s', time() - 7200), 'signup_closes_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $this->assertSame('Signup is closed', $this->refused(fn () => $this->svc->propose($this->config, $this->s[0], [$this->s[1]], 'Team'))->getMessage());
    }

    public function testProposerAndInviteesMustBeInPoolAndUngrouped(): void
    {
        $outsider = (int) CourseFactory::user()->id;
        $this->refused(fn () => $this->svc->propose($this->config, $outsider, [$this->s[1]], 'Team'));
        $this->refused(fn () => $this->svc->propose($this->config, $this->s[0], [$outsider], 'Team'));

        $this->seatElsewhere($this->s[1]);
        $this->refused(fn () => $this->svc->propose($this->config, $this->s[0], [$this->s[1]], 'Team'));
        $this->refused(fn () => $this->svc->propose($this->config, $this->s[1], [$this->s[2]], 'Team'));

        $this->refused(fn () => $this->svc->propose($this->config, $this->s[0], [$this->s[2]], '  '));
        $this->refused(fn () => $this->svc->propose($this->config, $this->s[0], [$this->s[2]], str_repeat('x', 151)));

        $id = $this->svc->propose($this->config, $this->s[0], [$this->s[2], $this->s[2], $this->s[0]], 'Team');
        $this->assertSame('proposed', $this->groupStatus($id));
        $this->assertSame([$this->s[0]], $this->members($id));
        $this->assertSame('pending', $this->invite($id, $this->s[2]));
        $this->assertNull($this->invite($id, $this->s[0]));
        // a proposer is grouped, so cannot propose again
        $this->refused(fn () => $this->svc->propose($this->config, $this->s[0], [$this->s[3]], 'Again'));
    }

    public function testOverMaxSizeRefused(): void
    {
        $this->refused(fn () => $this->svc->propose($this->config, $this->s[0], [$this->s[1], $this->s[2], $this->s[3]], 'Big'));
        $this->assertSame(0, db_connect()->table('student_groups')->where('config_id', $this->config)->countAllResults());
    }

    public function testInviteeAcceptJoinsAndDeclineDoesNot(): void
    {
        $id = $this->svc->propose($this->config, $this->s[0], [$this->s[1], $this->s[2]], 'Team');
        $this->svc->respondToInvitation($id, $this->s[1], true);
        $this->svc->respondToInvitation($id, $this->s[2], false);

        $this->assertSame([$this->s[0], $this->s[1]], $this->members($id));
        $this->assertSame('accepted', $this->invite($id, $this->s[1]));
        $this->assertSame('declined', $this->invite($id, $this->s[2]));
        $this->refused(fn () => $this->svc->respondToInvitation($id, $this->s[1], true)); // no longer pending
    }

    public function testAcceptRefusedWhenAlreadyGroupedOrWindowClosedOrFull(): void
    {
        $id = $this->svc->propose($this->config, $this->s[0], [$this->s[1], $this->s[2]], 'Team');
        $this->seatElsewhere($this->s[1]);
        $this->refused(fn () => $this->svc->respondToInvitation($id, $this->s[1], true));
        $this->assertSame([$this->s[0]], $this->members($id));

        $this->config(['signup_closes_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertSame('Signup is closed', $this->refused(fn () => $this->svc->respondToInvitation($id, $this->s[2], true))->getMessage());
        $this->config(['signup_closes_at' => null, 'max_size' => 1]);
        $this->assertSame('Group is full', $this->refused(fn () => $this->svc->respondToInvitation($id, $this->s[2], true))->getMessage());
        $this->assertSame('pending', $this->invite($id, $this->s[2]));
    }

    public function testOnlyTheInvitedStudentCanRespond(): void
    {
        $id = $this->svc->propose($this->config, $this->s[0], [$this->s[1]], 'Team');
        $this->refused(fn () => $this->svc->respondToInvitation($id, $this->s[3], true));
        $this->refused(fn () => $this->svc->respondToInvitation($id, $this->s[0], true));
        $this->assertSame([$this->s[0]], $this->members($id));
    }

    public function testApproveBelowMinRefused(): void
    {
        $id = $this->svc->propose($this->config, $this->s[0], [$this->s[1]], 'Team');
        $this->assertSame('Needs at least 2 members', $this->refused(fn () => $this->svc->approve($id, $this->teacher))->getMessage());
        $this->assertSame('proposed', $this->groupStatus($id));
    }

    public function testApproveMakesGroupActiveAndCancelsPendingInvites(): void
    {
        $id = $this->svc->propose($this->config, $this->s[0], [$this->s[1], $this->s[2]], 'Team');
        $this->svc->respondToInvitation($id, $this->s[1], true);
        $this->svc->approve($id, $this->teacher);

        $this->assertSame('active', $this->groupStatus($id));
        $this->assertSame('declined', $this->invite($id, $this->s[2]));
        $this->assertSame('accepted', $this->invite($id, $this->s[1]));
        $this->refused(fn () => $this->svc->approve($id, $this->teacher)); // not proposed any more
    }

    public function testDeclineFreesMembersAndNeedsReason(): void
    {
        $id = $this->svc->propose($this->config, $this->s[0], [$this->s[1], $this->s[2]], 'Team');
        $this->svc->respondToInvitation($id, $this->s[1], true);
        $this->refused(fn () => $this->svc->decline($id, $this->teacher, ' '));
        $this->assertSame('proposed', $this->groupStatus($id));

        $this->svc->decline($id, $this->teacher, 'Mix the teams');
        $this->assertSame('declined', $this->groupStatus($id));
        $this->assertSame([], $this->members($id));
        $this->assertSame('declined', $this->invite($id, $this->s[2]));

        $again = $this->svc->propose($this->config, $this->s[0], [$this->s[1]], 'Again'); // freed
        $this->assertSame([$this->s[0]], $this->members($again));
    }

    public function testProposedGroupIsNotJoinableByOthers(): void
    {
        $this->config(['formation_mode' => 'self_signup']);
        $proposed = $this->rawGroup('P', 'proposed');
        $declined = $this->rawGroup('D', 'declined');
        $signup   = new GroupSignupService();

        $this->refused(fn () => $signup->join($this->config, $proposed, $this->s[3]));
        $this->refused(fn () => $signup->join($this->config, $declined, $this->s[3]));
        $this->assertSame([], $this->members($proposed));
        $this->assertSame([], $this->members($declined));
    }

    public function testProposalIsAtomicOnFailure(): void
    {
        $before = db_connect()->table('student_groups')->countAllResults();

        // An unknown invitee id is outside the pool: refused, nothing written.
        $this->refused(fn () => $this->svc->propose($this->config, $this->s[0], [$this->s[1], 999999999], 'Team'));
        $this->assertSame($before, db_connect()->table('student_groups')->countAllResults());

        // An invitee in another group refuses the whole proposal, no group row or member left behind.
        $other = $this->seatElsewhere($this->s[2]);
        $this->refused(fn () => $this->svc->propose($this->config, $this->s[0], [$this->s[1], $this->s[2]], 'Team'));
        $this->assertSame($before + 1, db_connect()->table('student_groups')->countAllResults());
        $this->assertSame([$this->s[2]], $this->members($other));
        $this->assertSame(0, db_connect()->table('student_group_members')->where('student_user_id', $this->s[0])->countAllResults());
        $this->assertSame(0, db_connect()->table('student_group_invitations')->countAllResults());
    }

    public function testFinalizedOfferingRefuses(): void
    {
        $id = $this->svc->propose($this->config, $this->s[0], [$this->s[1]], 'Team');
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $this->refused(fn () => $this->svc->propose($this->config, $this->s[2], [$this->s[3]], 'Two'));
        $this->refused(fn () => $this->svc->respondToInvitation($id, $this->s[1], true));
        $this->refused(fn () => $this->svc->approve($id, $this->teacher));
        $this->refused(fn () => $this->svc->decline($id, $this->teacher, 'No'));
        $this->assertSame('proposed', $this->groupStatus($id));
    }

    public function testAuditRowsWritten(): void
    {
        $id = $this->svc->propose($this->config, $this->s[0], [$this->s[1], $this->s[2]], 'Team');
        $this->svc->respondToInvitation($id, $this->s[1], true);
        $this->svc->respondToInvitation($id, $this->s[2], false);
        $this->svc->approve($id, $this->teacher);

        $id2 = $this->svc->propose($this->config, $this->s[3], [$this->s[4]], 'Second');
        $this->svc->decline($id2, $this->teacher, 'Too small');

        foreach (['groups.proposed' => 2, 'groups.invite_accepted' => 1, 'groups.invite_declined' => 1, 'groups.proposal_approved' => 1, 'groups.proposal_declined' => 1] as $action => $n) {
            $this->assertCount($n, $this->audits($action), $action);
        }

        $this->assertStringContainsString('Too small', json_encode($this->audits('groups.proposal_declined')[0]));
    }

    public function testWindowSummaryListsBelowMinAndUngrouped(): void
    {
        $small = $this->svc->propose($this->config, $this->s[0], [$this->s[1]], 'Small');
        $ok    = $this->svc->propose($this->config, $this->s[2], [$this->s[3]], 'Ok');
        $this->svc->respondToInvitation($ok, $this->s[3], true);

        $summary = $this->svc->windowSummary($this->config);
        $this->assertSame([$small], $summary['belowMinimum']);
        $this->assertEqualsCanonicalizing([$this->s[1], $this->s[4], $this->s[5]], $summary['ungrouped']);

        $queue = $this->svc->proposalsForConfig($this->config);
        $this->assertCount(2, $queue);
        $row = array_values(array_filter($queue, static fn ($q) => $q['id'] === $ok))[0];
        $this->assertTrue($row['meetsMinimum']);

        $pending = $this->svc->pendingInvitationsFor($this->config, $this->s[1]);
        $this->assertSame($small, $pending[0]['id']);
        $this->assertSame('Small', $pending[0]['name']);
    }
}
