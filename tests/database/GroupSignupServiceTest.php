<?php

declare(strict_types=1);

use App\Services\Groups\ActivityGroupMembershipService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupSetService;
use App\Services\Groups\GroupSignupService;
use App\Services\Institutions\StructureException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Students joining, leaving and switching groups of a self-signup activity.
 *
 * @internal
 */
final class GroupSignupServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GroupSignupService $svc;

    private int $offering;

    private int $teacher;

    private int $assignment;

    private int $config;

    private int $a;

    private int $b;

    /** @var list<int> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = new GroupSignupService();
        $this->offering = CourseFactory::offering();
        $module         = CourseFactory::module($this->offering);
        $instructor     = CourseFactory::user('instructor');
        $this->teacher  = (int) $instructor->id;

        for ($i = 0; $i < 5; $i++) {
            $student   = CourseFactory::user();
            $this->s[] = (int) $student->id;
            CourseFactory::enroll($this->offering, $student);
        }

        $sets             = new GroupSetService();
        $set              = $sets->create($this->offering, ['name' => 'Set', 'formation_mode' => 'self_signup', 'min_size' => 1, 'max_size' => 2], $this->teacher);
        $sets->addGroup($set, 'A', $this->teacher);
        $sets->addGroup($set, 'B', $this->teacher);
        $this->assignment = CourseFactory::assessment($this->offering, $instructor, $module, null, 'Assignment');
        $this->config     = (new ActivityGroupService())->attachAssessment($this->assignment, $set, [], $this->teacher);
        $this->a          = $this->groupId('A');
        $this->b          = $this->groupId('B');
    }

    private function groupId(string $name): int
    {
        return (int) db_connect()->table('student_groups')->where('config_id', $this->config)->where('name', $name)->get()->getRowArray()['id'];
    }

    private function groupOf(int $student): ?int
    {
        $row = db_connect()->table('student_group_members')->where('config_id', $this->config)->where('student_user_id', $student)->get()->getRowArray();

        return $row === null ? null : (int) $row['group_id'];
    }

    private function config(array $changes): void
    {
        db_connect()->table('activity_group_configs')->where('id', $this->config)->update($changes);
    }

    private function seat(int $group, int $student): void
    {
        db_connect()->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $this->config, 'student_user_id' => $student, 'joined_at' => date('Y-m-d H:i:s')]);
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

    private function audits(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->get()->getResultArray();
    }

    /** Committed rows belong to the test that made them: empty everything, then reopen the isolation transaction. */
    private function cleanCommitted(): void
    {
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($db->listTables() as $table) {
            if ($table !== 'migrations') {
                $db->query('TRUNCATE TABLE `' . $table . '`');
            }
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->transBegin(); // tearDown rolls this back
    }

    private function otherConnection(): mysqli
    {
        $c = config('Database')->tests;

        return new mysqli((string) $c['hostname'], (string) $c['username'], (string) $c['password'], (string) $c['database'], (int) $c['port']);
    }

    public function testJoinPutsStudentInGroup(): void
    {
        $this->svc->join($this->config, $this->a, $this->s[0]);

        $this->assertSame($this->a, $this->groupOf($this->s[0]));
    }

    public function testJoinOutsideWindowRefused(): void
    {
        $this->config(['signup_opens_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->assertSame('Signup has not opened yet', $this->refused(fn () => $this->svc->join($this->config, $this->a, $this->s[0]))->getMessage());

        $this->config(['signup_opens_at' => date('Y-m-d H:i:s', time() - 7200), 'signup_closes_at' => date('Y-m-d H:i:s', time() - 3600)]);
        $this->assertSame('Signup is closed', $this->refused(fn () => $this->svc->join($this->config, $this->a, $this->s[0]))->getMessage());
        $this->assertNull($this->groupOf($this->s[0]));

        $this->config(['signup_opens_at' => date('Y-m-d H:i:s', time() - 7200), 'signup_closes_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->svc->join($this->config, $this->a, $this->s[0]);
        $this->assertSame($this->a, $this->groupOf($this->s[0]));
    }

    public function testJoinOnlyInSelfSignupMode(): void
    {
        foreach (['manual', 'auto', 'proposed'] as $mode) {
            $this->config(['formation_mode' => $mode]);
            $this->refused(fn () => $this->svc->join($this->config, $this->a, $this->s[0]));
        }

        $this->assertNull($this->groupOf($this->s[0]));
    }

    public function testFullGroupRefusesJoin(): void
    {
        $this->seat($this->a, $this->s[0]);
        $this->seat($this->a, $this->s[1]);

        $this->assertSame('Group is full', $this->refused(fn () => $this->svc->join($this->config, $this->a, $this->s[2]))->getMessage());
        $this->assertNull($this->groupOf($this->s[2]));
    }

    public function testUnassignedStudentCannotJoin(): void
    {
        db_connect()->table('assessment_settings')->insert(['assessment_id' => $this->assignment, 'assign_to_specific_students' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('assessment_assignees')->insert(['assessment_id' => $this->assignment, 'student_user_id' => $this->s[0], 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('You are not part of this activity', $this->refused(fn () => $this->svc->join($this->config, $this->a, $this->s[1]))->getMessage());
        $this->svc->join($this->config, $this->a, $this->s[0]);
        $this->assertSame($this->a, $this->groupOf($this->s[0]));
    }

    public function testDroppedOrNotEnrolledStudentCannotJoin(): void
    {
        $dropped = CourseFactory::user();
        CourseFactory::enroll($this->offering, $dropped, 'dropped');
        $stranger = CourseFactory::user();

        foreach ([(int) $dropped->id, (int) $stranger->id] as $id) {
            $this->assertSame('You are not part of this activity', $this->refused(fn () => $this->svc->join($this->config, $this->a, $id))->getMessage());
            $this->assertNull($this->groupOf($id));
        }
    }

    public function testStudentCannotJoinGroupOfAnotherActivityOrOffering(): void
    {
        $otherOffering = CourseFactory::offering('B');
        $otherModule   = CourseFactory::module($otherOffering);
        $sets          = new GroupSetService();
        $set           = $sets->create($otherOffering, ['name' => 'Other', 'formation_mode' => 'self_signup', 'min_size' => 1, 'max_size' => 2], $this->teacher);
        $sets->addGroup($set, 'Z', $this->teacher);
        $otherConfig = (new ActivityGroupService())->attachAssessment(CourseFactory::assessment($otherOffering, CourseFactory::user('instructor'), $otherModule, null, 'Assignment'), $set, [], $this->teacher);
        $otherGroup  = (int) db_connect()->table('student_groups')->where('config_id', $otherConfig)->get()->getRowArray()['id'];

        $this->refused(fn () => $this->svc->join($this->config, $otherGroup, $this->s[0]));
        $this->refused(fn () => $this->svc->join($otherConfig, $this->a, $this->s[0]));
        $this->assertSame(0, db_connect()->table('student_group_members')->where('student_user_id', $this->s[0])->countAllResults());
    }

    public function testAlreadyInAGroupRefused(): void
    {
        $this->svc->join($this->config, $this->a, $this->s[0]);

        $this->refused(fn () => $this->svc->join($this->config, $this->b, $this->s[0]));
        $this->assertSame($this->a, $this->groupOf($this->s[0]));
    }

    public function testFrozenGroupRefusesJoin(): void
    {
        (new ActivityGroupMembershipService())->freezeGroup($this->a);

        $this->refused(fn () => $this->svc->join($this->config, $this->a, $this->s[0]));
        $this->assertNull($this->groupOf($this->s[0]));
    }

    public function testLeaveRefusedWhenFrozen(): void
    {
        $this->seat($this->a, $this->s[0]);
        (new ActivityGroupMembershipService())->freezeGroup($this->a);

        $this->assertSame('Your group has submitted; ask your instructor', $this->refused(fn () => $this->svc->leave($this->a, $this->s[0]))->getMessage());
        $this->assertSame($this->a, $this->groupOf($this->s[0]));
    }

    public function testLeaveRefusedWhenToggleOffAfterLeaderFinalized(): void
    {
        $this->seat($this->a, $this->s[0]);
        $this->seat($this->a, $this->s[1]);
        db_connect()->table('student_groups')->where('id', $this->a)->update(['leader_user_id' => $this->s[0], 'leader_finalized_at' => date('Y-m-d H:i:s')]);
        $this->config(['nonleader_can_leave' => 0]);

        $this->refused(fn () => $this->svc->leave($this->a, $this->s[1]));
        $this->assertSame($this->a, $this->groupOf($this->s[1]));

        // the leader themself is not held by the toggle
        $this->svc->leave($this->a, $this->s[0]);
        $this->assertNull($this->groupOf($this->s[0]));
    }

    public function testLeaveAllowedWhenToggleOn(): void
    {
        $this->seat($this->a, $this->s[0]);
        $this->seat($this->a, $this->s[1]);
        db_connect()->table('student_groups')->where('id', $this->a)->update(['leader_user_id' => $this->s[0], 'leader_finalized_at' => date('Y-m-d H:i:s')]);
        $this->config(['nonleader_can_leave' => 1]);
        $this->assertSame(1, (int) db_connect()->table('activity_group_configs')->where('id', $this->config)->get()->getRowArray()['nonleader_can_leave']);

        $this->svc->leave($this->a, $this->s[1]);

        $this->assertNull($this->groupOf($this->s[1]));
    }

    public function testLeaveAllowedInManualMode(): void
    {
        $this->config(['formation_mode' => 'manual']);
        $this->seat($this->a, $this->s[0]);

        $this->svc->leave($this->a, $this->s[0]);

        $this->assertNull($this->groupOf($this->s[0]));
    }

    public function testLeaveByNonMemberRefused(): void
    {
        $this->seat($this->b, $this->s[0]);

        $this->refused(fn () => $this->svc->leave($this->a, $this->s[0]));
        $this->assertSame($this->b, $this->groupOf($this->s[0]));
    }

    public function testSwitchMovesStudent(): void
    {
        $this->seat($this->a, $this->s[0]);

        $this->svc->switchTo($this->config, $this->b, $this->s[0]);

        $this->assertSame($this->b, $this->groupOf($this->s[0]));
        $this->assertCount(1, $this->audits('groups.member_switched'));
    }

    public function testSwitchIsAtomicWhenTargetFull(): void
    {
        $this->seat($this->a, $this->s[0]);
        $this->seat($this->b, $this->s[1]);
        $this->seat($this->b, $this->s[2]);

        $this->assertSame('Group is full', $this->refused(fn () => $this->svc->switchTo($this->config, $this->b, $this->s[0]))->getMessage());
        $this->assertSame($this->a, $this->groupOf($this->s[0]));
    }

    public function testSwitchRefusedFromFrozenGroupAndWithoutAGroup(): void
    {
        $this->refused(fn () => $this->svc->switchTo($this->config, $this->b, $this->s[0]));

        $this->seat($this->a, $this->s[0]);
        (new ActivityGroupMembershipService())->freezeGroup($this->a);
        $this->refused(fn () => $this->svc->switchTo($this->config, $this->b, $this->s[0]));
        $this->assertSame($this->a, $this->groupOf($this->s[0]));
    }

    public function testFinalizedOfferingRefuses(): void
    {
        $this->seat($this->a, $this->s[0]);
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $this->refused(fn () => $this->svc->join($this->config, $this->b, $this->s[1]));
        $this->refused(fn () => $this->svc->leave($this->a, $this->s[0]));
        $this->refused(fn () => $this->svc->switchTo($this->config, $this->b, $this->s[0]));
        $this->assertSame($this->a, $this->groupOf($this->s[0]));
        $this->assertNull($this->groupOf($this->s[1]));
    }

    public function testJoinAndLeaveWriteAudit(): void
    {
        $this->svc->join($this->config, $this->a, $this->s[0]);
        $joined = $this->audits('groups.member_joined');
        $this->assertCount(1, $joined);
        $this->assertSame((string) $this->s[0], (string) $joined[0]['actor_user_id']);

        $this->svc->leave($this->a, $this->s[0]);
        $this->assertCount(1, $this->audits('groups.member_left'));
    }

    public function testCandidateGroupsListsOnlyOpenGroups(): void
    {
        $this->seat($this->a, $this->s[1]);
        $this->seat($this->a, $this->s[2]);

        $groups = $this->svc->candidateGroups($this->config, $this->s[0]);
        $byName = array_column($groups, null, 'name');

        $this->assertCount(2, $groups);
        $this->assertTrue($byName['A']['full']);
        $this->assertSame(2, $byName['A']['members_count']);
        $this->assertSame(2, $byName['A']['max']);
        $this->assertCount(2, $byName['A']['member_names']);
        $this->assertFalse($byName['B']['full']);

        (new ActivityGroupMembershipService())->freezeGroup($this->b);
        $this->assertSame(['A'], array_column($this->svc->candidateGroups($this->config, $this->s[0]), 'name'));

        $this->config(['signup_closes_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertSame([], $this->svc->candidateGroups($this->config, $this->s[0]));
        $this->config(['signup_closes_at' => null, 'formation_mode' => 'manual']);
        $this->assertSame([], $this->svc->candidateGroups($this->config, $this->s[0]));
    }

    /**
     * A second PHP process opens its own connection, locks the group row and takes the last seat while this
     * process's join is already waiting on that row. The service must wait for the lock, recount, and refuse.
     */
    public function testTwoConcurrentJoinsForLastSeatOnlyOneWins(): void
    {
        $this->seat($this->a, $this->s[0]); // max 2: one seat left
        db_connect()->transCommit();        // the fixtures must be visible to the other connection
        $file = tempnam(sys_get_temp_dir(), 'gs');

        try {
            $c = config('Database')->tests;
            file_put_contents($file, <<<'PHP'
                <?php
                [$h, $u, $p, $d, $port, $group, $student, $config] = array_slice($argv, 1);
                $m = new mysqli($h, $u, $p, $d, (int) $port);
                $m->begin_transaction();
                $m->query("SELECT id FROM student_groups WHERE id = $group FOR UPDATE");
                echo "locked\n";
                flush();
                usleep(1500000);
                $m->query("INSERT INTO student_group_members (group_id, config_id, student_user_id, joined_at) VALUES ($group, $config, $student, NOW())");
                $m->commit();
                echo "done\n";
                PHP);
            $proc = proc_open([PHP_BINARY, $file, (string) $c['hostname'], (string) $c['username'], (string) $c['password'], (string) $c['database'], (string) $c['port'], (string) $this->a, (string) $this->s[1], (string) $this->config], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($proc);
            $this->assertSame("locked\n", fgets($pipes[1]), 'the other connection holds the group row lock');

            $start  = microtime(true);
            $error  = $this->refused(fn () => $this->svc->join($this->config, $this->a, $this->s[2]));
            $waited = microtime(true) - $start;

            $this->assertSame('Group is full', $error->getMessage());
            $this->assertGreaterThan(0.5, $waited, 'the join waited for the row lock instead of counting a stale total');
            $this->assertSame("done\n", fgets($pipes[1]));
            proc_close($proc);

            $this->assertSame(2, db_connect()->table('student_group_members')->where('group_id', $this->a)->countAllResults());
            $this->assertSame($this->a, $this->groupOf($this->s[1]));
            $this->assertNull($this->groupOf($this->s[2]));
        } finally {
            @unlink($file);
            $this->cleanCommitted();
        }
    }

    public function testJoinTakesTheGroupRowLock(): void
    {
        db_connect()->transCommit();
        $other = null;

        try {
            $other = $this->otherConnection();
            $other->begin_transaction();
            $other->query('SELECT id FROM student_groups WHERE id = ' . $this->a . ' FOR UPDATE');
            db_connect()->query('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                $this->svc->join($this->config, $this->a, $this->s[0]);
                $this->fail('The join should have waited for the group row lock.');
            } catch (StructureException $e) {
                $this->assertNull($this->groupOf($this->s[0]), 'a join that could not lock leaves no member row');
            }
        } finally {
            db_connect()->query('SET SESSION innodb_lock_wait_timeout = 50');
            $other?->rollback();
            $other?->close();
            $this->cleanCommitted();
        }
    }
}
