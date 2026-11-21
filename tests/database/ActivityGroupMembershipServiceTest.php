<?php

declare(strict_types=1);

use App\Services\Groups\ActivityGroupMembershipService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupMembershipService;
use App\Services\Groups\GroupSetService;
use App\Services\Institutions\StructureException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Members, freezing, settings and dropped students of an activity's groups.
 *
 * @internal
 */
final class ActivityGroupMembershipServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ActivityGroupMembershipService $svc;

    private int $offering;

    private int $teacher;

    /** @var list<int> */
    private array $s = [];

    private int $set;

    private int $config;

    private int $a;

    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = new ActivityGroupMembershipService();
        $this->offering = CourseFactory::offering();
        $module         = CourseFactory::module($this->offering);
        $instructor     = CourseFactory::user('instructor');
        $this->teacher  = (int) $instructor->id;

        for ($i = 0; $i < 5; $i++) {
            $student   = CourseFactory::user();
            $this->s[] = (int) $student->id;
            CourseFactory::enroll($this->offering, $student);
        }

        $sets      = new GroupSetService();
        $this->set = $sets->create($this->offering, ['name' => 'Set', 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 3], $this->teacher);
        $sa        = $sets->addGroup($this->set, 'A', $this->teacher);
        $sb        = $sets->addGroup($this->set, 'B', $this->teacher);
        $members   = new GroupMembershipService();
        $members->addToSet($sa, $this->s[0], $this->teacher);
        $members->addToSet($sa, $this->s[1], $this->teacher);
        $members->addToSet($sb, $this->s[2], $this->teacher);

        $this->config = (new ActivityGroupService())->attachAssessment(CourseFactory::assessment($this->offering, $instructor, $module, null, 'Assignment'), $this->set, [], $this->teacher);
        $this->a      = $this->groupId('A');
        $this->b      = $this->groupId('B');
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

    private function frozen(int $groupId): bool
    {
        return db_connect()->table('student_groups')->where('id', $groupId)->get()->getRowArray()['frozen_at'] !== null;
    }

    public function testFrozenGroupRefusesAddMoveRemove(): void
    {
        $this->svc->freezeGroup($this->a);

        $this->assertStringContainsString('unlock', strtolower($this->refused(fn () => $this->svc->addMember($this->a, $this->s[3], $this->teacher))->getMessage()));
        $this->refused(fn () => $this->svc->moveMember($this->s[0], $this->b, $this->teacher));
        $this->refused(fn () => $this->svc->moveMember($this->s[2], $this->a, $this->teacher));
        $this->refused(fn () => $this->svc->removeMember($this->s[0], $this->config, $this->teacher));
        $this->assertSame($this->a, $this->groupOf($this->s[0]));
        $this->assertNull($this->groupOf($this->s[3]));
    }

    public function testUnlockRequiresReasonAndAudits(): void
    {
        $this->svc->freezeGroup($this->a);

        $this->refused(fn () => $this->svc->unlock($this->a, '   ', $this->teacher));
        $this->refused(fn () => $this->svc->unlock($this->a, str_repeat('x', 501), $this->teacher));
        $this->assertTrue($this->frozen($this->a));

        $this->svc->unlock($this->a, 'Wrong file submitted', $this->teacher);

        $this->assertFalse($this->frozen($this->a));
        $rows = $this->audits('groups.unlocked');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Wrong file submitted', json_encode($rows[0]));
    }

    public function testUnlockedGroupAcceptsChangesAgain(): void
    {
        $this->svc->freezeGroup($this->a);
        $this->svc->unlock($this->a, 'Reopen', $this->teacher);

        $this->svc->addMember($this->a, $this->s[3], $this->teacher);
        $this->svc->moveMember($this->s[3], $this->b, $this->teacher);
        $this->svc->removeMember($this->s[3], $this->config, $this->teacher);

        $this->assertNull($this->groupOf($this->s[3]));
    }

    public function testFreezeIsIdempotent(): void
    {
        $this->svc->freezeGroup($this->a);
        $first = db_connect()->table('student_groups')->where('id', $this->a)->get()->getRowArray()['frozen_at'];
        $this->svc->freezeGroup($this->a);

        $this->assertSame($first, db_connect()->table('student_groups')->where('id', $this->a)->get()->getRowArray()['frozen_at']);
        $this->assertCount(1, $this->audits('groups.frozen'));
    }

    public function testAddMemberPoolOnlyAndOneGroupPerActivity(): void
    {
        $outsider = CourseFactory::user();
        $this->assertStringContainsString('not', $this->refused(fn () => $this->svc->addMember($this->a, (int) $outsider->id, $this->teacher))->getMessage());

        $this->assertStringContainsString('already in a group', $this->refused(fn () => $this->svc->addMember($this->b, $this->s[0], $this->teacher))->getMessage());

        $before = count($this->audits('groups.member_added'));
        $this->svc->addMember($this->a, $this->s[3], $this->teacher);
        $this->assertSame($this->a, $this->groupOf($this->s[3]));

        $this->assertSame('Group is full.', $this->refused(fn () => $this->svc->addMember($this->a, $this->s[4], $this->teacher))->getMessage());
        $this->assertCount($before + 1, $this->audits('groups.member_added'));
    }

    public function testMoveAndRemoveAudit(): void
    {
        $this->svc->moveMember($this->s[0], $this->b, $this->teacher);
        $this->assertSame($this->b, $this->groupOf($this->s[0]));
        $this->svc->removeMember($this->s[0], $this->config, $this->teacher);
        $this->assertNull($this->groupOf($this->s[0]));
        $this->assertCount(1, $this->audits('groups.member_moved'));
        $this->assertCount(1, $this->audits('groups.member_removed'));
    }

    public function testDropRemovesMemberFromUnfrozenGroup(): void
    {
        $this->svc->onStudentDropped($this->offering, $this->s[0]);

        $this->assertNull($this->groupOf($this->s[0]));
        $this->assertSame($this->a, $this->groupOf($this->s[1]));
        $rows = $this->audits('groups.member_removed');
        $this->assertNotEmpty($rows);
        $this->assertStringContainsString('dropped', json_encode($rows[0]));
    }

    public function testDropRemovesMemberFromFrozenGroupToo(): void
    {
        $this->svc->freezeGroup($this->a);
        $this->svc->onStudentDropped($this->offering, $this->s[0]);

        $this->assertNull($this->groupOf($this->s[0]));
        $this->assertTrue($this->frozen($this->a));
        $this->assertSame($this->a, $this->groupOf($this->s[1]));
    }

    public function testDropRemovesFromSetGroupsAndActivityGroups(): void
    {
        $this->svc->onStudentDropped($this->offering, $this->s[0]);

        $this->assertSame(0, db_connect()->table('student_group_members')->where('student_user_id', $this->s[0])->countAllResults());
        $this->assertSame(1, db_connect()->table('student_group_members')->where('student_user_id', $this->s[1])->where('set_id', $this->set)->countAllResults());
    }

    public function testLateEnrolleeCanBeAddedToFrozenGroup(): void
    {
        $this->svc->freezeGroup($this->a);
        $before = count($this->audits('groups.member_added'));

        $this->refused(fn () => $this->svc->addLateEnrollee($this->a, $this->s[3], ' ', $this->teacher));
        $this->refused(fn () => $this->svc->addLateEnrollee($this->a, (int) CourseFactory::user()->id, 'Joined late', $this->teacher));

        $this->svc->addLateEnrollee($this->a, $this->s[3], 'Joined late', $this->teacher);

        $this->assertSame($this->a, $this->groupOf($this->s[3]));
        $this->assertTrue($this->frozen($this->a));
        $rows = $this->audits('groups.member_added');
        $this->assertCount($before + 1, $rows);
        $this->assertStringContainsString('late_enrollee', json_encode(end($rows)));

        $this->refused(fn () => $this->svc->addLateEnrollee($this->a, $this->s[4], 'Joined late', $this->teacher)); // full
    }

    public function testStyleChangeRefusedWhileFrozen(): void
    {
        $this->svc->changeSettings($this->config, ['submission_style' => 'leader_only'], false, $this->teacher);
        $this->svc->freezeGroup($this->a);

        $this->assertStringContainsString('Unlock the group first', $this->refused(fn () => $this->svc->changeSettings($this->config, ['submission_style' => 'per_member'], true, $this->teacher))->getMessage());
        $this->svc->unlock($this->a, 'Change style', $this->teacher);
        $this->svc->changeSettings($this->config, ['submission_style' => 'per_member'], false, $this->teacher);
        $this->assertSame('per_member', db_connect()->table('activity_group_configs')->where('id', $this->config)->get()->getRowArray()['submission_style']);
    }

    public function testUngroupedPolicyChangeAfterFreezeNeedsConfirmation(): void
    {
        $this->svc->freezeGroup($this->a);

        $e = $this->refused(fn () => $this->svc->changeSettings($this->config, ['ungrouped_policy' => 'solo'], false, $this->teacher));
        $this->assertArrayHasKey('confirm_required', $e->errors());
        $this->assertSame('block', db_connect()->table('activity_group_configs')->where('id', $this->config)->get()->getRowArray()['ungrouped_policy']);

        $this->svc->changeSettings($this->config, ['ungrouped_policy' => 'solo'], true, $this->teacher);
        $this->assertSame('solo', db_connect()->table('activity_group_configs')->where('id', $this->config)->get()->getRowArray()['ungrouped_policy']);
    }

    public function testSettingsChangeAuditsOldAndNew(): void
    {
        $this->svc->changeSettings($this->config, ['grading_mode' => 'individual', 'use_average' => 1, 'max_size' => '3'], false, $this->teacher);

        $rows = $this->audits('groups.settings_changed');
        $this->assertCount(1, $rows);
        $json = json_encode($rows[0]);
        $this->assertStringContainsString('individual', $json);
        $this->assertStringContainsString('collective', $json);
        $this->assertStringNotContainsString('max_size', $json);
    }

    public function testNoAuditWhenNothingChanged(): void
    {
        $this->svc->changeSettings($this->config, ['grading_mode' => 'collective', 'use_average' => 0], false, $this->teacher);

        $this->assertCount(0, $this->audits('groups.settings_changed'));
    }

    public function testInvalidEnumRefused(): void
    {
        foreach ([['grading_mode' => 'nonsense'], ['min_size' => 5, 'max_size' => 2], ['max_size' => 99], ['formation_mode' => 'nonsense'], ['formation_mode' => '']] as $bad) {
            $this->assertNotSame('', $this->refused(fn () => $this->svc->changeSettings($this->config, $bad, false, $this->teacher))->getMessage());
        }
    }

    public function testFormationModeAndSignupWindowSaveAndAudit(): void
    {
        $this->svc->changeSettings($this->config, ['formation_mode' => 'self_signup', 'signup_opens_at' => '2030-01-01T08:00', 'signup_closes_at' => '2030-01-02T08:00'], false, $this->teacher);

        $row = db_connect()->table('activity_group_configs')->where('id', $this->config)->get()->getRowArray();
        $this->assertSame('self_signup', $row['formation_mode']);
        $this->assertSame('2030-01-01 08:00:00', $row['signup_opens_at']);
        $this->assertStringContainsString('self_signup', json_encode($this->audits('groups.settings_changed')[0]));

        // The same window in the stored format is no change.
        $this->svc->changeSettings($this->config, ['signup_opens_at' => '2030-01-01 08:00:00'], false, $this->teacher);
        $this->assertCount(1, $this->audits('groups.settings_changed'));
    }

    public function testSignupWindowMustCloseAfterItOpens(): void
    {
        $e = $this->refused(fn () => $this->svc->changeSettings($this->config, ['signup_opens_at' => '2030-01-02T08:00', 'signup_closes_at' => '2030-01-01T08:00'], false, $this->teacher));
        $this->assertArrayHasKey('signup_closes_at', $e->errors());
    }

    public function testModeChangeRefusedWhileFrozen(): void
    {
        $this->svc->freezeGroup($this->a);

        $e = $this->refused(fn () => $this->svc->changeSettings($this->config, ['formation_mode' => 'self_signup'], true, $this->teacher));
        $this->assertArrayHasKey('formation_mode', $e->errors());
        $this->assertStringContainsString('Unlock the group first', $e->getMessage());
    }

    public function testFinalizedOfferingRefusesAll(): void
    {
        $this->svc->freezeGroup($this->a);
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $this->refused(fn () => $this->svc->addMember($this->b, $this->s[3], $this->teacher));
        $this->refused(fn () => $this->svc->moveMember($this->s[2], $this->a, $this->teacher));
        $this->refused(fn () => $this->svc->removeMember($this->s[2], $this->config, $this->teacher));
        $this->refused(fn () => $this->svc->unlock($this->a, 'Reason', $this->teacher));
        $this->refused(fn () => $this->svc->changeSettings($this->config, ['use_average' => 1], false, $this->teacher));
        $this->refused(fn () => $this->svc->addLateEnrollee($this->b, $this->s[3], 'Late', $this->teacher));
        $this->refused(fn () => $this->svc->onStudentDropped($this->offering, $this->s[2]));
        $this->assertSame($this->b, $this->groupOf($this->s[2]));
    }
}
