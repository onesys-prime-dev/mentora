<?php

declare(strict_types=1);

use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupSubmissionResolver;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Which group a student submits for, whether they may, and which submission row they see.
 *
 * @internal
 */
final class GroupSubmissionResolverTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GroupSubmissionResolver $svc;

    private int $offering;

    private int $teacher;

    private int $assignment;

    private int $config;

    /** @var list<int> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = new GroupSubmissionResolver();
        $this->offering = CourseFactory::offering();
        $module         = CourseFactory::module($this->offering);
        $instructor     = CourseFactory::user('instructor');
        $this->teacher  = (int) $instructor->id;

        for ($i = 0; $i < 4; $i++) {
            $student   = CourseFactory::user();
            $this->s[] = (int) $student->id;
            CourseFactory::enroll($this->offering, $student);
        }

        $this->assignment = CourseFactory::assessment($this->offering, $instructor, $module, null, 'Assignment');
        $this->config     = (new ActivityGroupService())->attachAssessment($this->assignment, null, ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 4], $this->teacher);
    }

    private function config(array $changes): void
    {
        db_connect()->table('activity_group_configs')->where('id', $this->config)->update($changes);
    }

    /** @param list<int> $members */
    private function group(array $members, string $status = 'active', ?int $leader = null): int
    {
        db_connect()->table('student_groups')->insert(['set_id' => null, 'config_id' => $this->config, 'name' => 'Team', 'status' => $status,
            'leader_user_id' => $leader, 'leader_finalized_at' => $leader === null ? null : date('Y-m-d H:i:s')]);
        $group = (int) db_connect()->insertID();

        foreach ($members as $id) {
            db_connect()->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $this->config, 'student_user_id' => $id, 'joined_at' => date('Y-m-d H:i:s')]);
        }

        return $group;
    }

    private function submit(int $student, int $attempt, ?int $group = null): int
    {
        db_connect()->table('assessment_submissions')->insert(['assessment_id' => $this->assignment, 'student_id' => $student, 'group_id' => $group,
            'attempt_number' => $attempt, 'started_at' => date('Y-m-d H:i:s'), 'submitted_at' => date('Y-m-d H:i:s'), 'status' => 'Submitted']);

        return (int) db_connect()->insertID();
    }

    public function testNonGroupAssignmentAlwaysAllowed(): void
    {
        $other = CourseFactory::assessment($this->offering, CourseFactory::user('instructor'), null, null, 'Assignment');
        $r     = $this->svc->canSubmit($other, $this->s[0]);

        $this->assertTrue($r['allowed']);
        $this->assertSame('none', $r['code']);
        $this->assertNull($this->svc->groupFor($other, $this->s[0]));
    }

    public function testUngroupedBlockedByDefault(): void
    {
        $r = $this->svc->canSubmit($this->assignment, $this->s[0]);

        $this->assertFalse($r['allowed']);
        $this->assertSame('needs_group', $r['code']);
        $this->assertSame('Join a group before you submit.', $r['reason']);
    }

    public function testUngroupedSoloAllowedWhenPolicySolo(): void
    {
        $this->config(['ungrouped_policy' => 'solo']);
        $r = $this->svc->canSubmit($this->assignment, $this->s[0]);

        $this->assertTrue($r['allowed']);
        $this->assertSame('solo', $r['code']);
    }

    public function testProposedGroupMembersCannotSubmit(): void
    {
        $this->group([$this->s[0]], 'proposed');
        $r = $this->svc->canSubmit($this->assignment, $this->s[0]);

        $this->assertFalse($r['allowed']);
        $this->assertSame('needs_group', $r['code']);
        $this->assertNull($this->svc->groupFor($this->assignment, $this->s[0]));
    }

    public function testLeaderOnlyBlocksNonLeaderAfterFinalization(): void
    {
        $this->config(['submission_style' => 'leader_only']);
        $this->group([$this->s[0], $this->s[1]], 'active', $this->s[0]);

        $this->assertTrue($this->svc->canSubmit($this->assignment, $this->s[0])['allowed']);
        $r = $this->svc->canSubmit($this->assignment, $this->s[1]);
        $this->assertFalse($r['allowed']);
        $this->assertSame('leader_only', $r['code']);
    }

    public function testLeaderOnlyAllowsAnyoneBeforeLeaderFinal(): void
    {
        $this->config(['submission_style' => 'leader_only']);
        $this->group([$this->s[0], $this->s[1]]);

        $this->assertTrue($this->svc->canSubmit($this->assignment, $this->s[1])['allowed']);
    }

    public function testSharedStyleAnyMemberCanSubmit(): void
    {
        $this->group([$this->s[0], $this->s[1]], 'active', $this->s[0]);

        $this->assertTrue($this->svc->canSubmit($this->assignment, $this->s[1])['allowed']);
    }

    public function testStudentOutsidePoolRefused(): void
    {
        $stranger = CourseFactory::user();
        $r        = $this->svc->canSubmit($this->assignment, (int) $stranger->id);

        $this->assertFalse($r['allowed']);
        $this->assertSame('not_in_pool', $r['code']);
    }

    public function testSubmissionForSharedReturnsGroupRowToEveryMember(): void
    {
        $group = $this->group([$this->s[0], $this->s[1]]);
        $this->submit($this->s[0], 1, $group);
        $latest = $this->submit($this->s[1], 2, $group);

        $this->assertSame($latest, (int) $this->svc->submissionFor($this->assignment, $this->s[0])['id']);
        $this->assertSame($latest, (int) $this->svc->submissionFor($this->assignment, $this->s[1])['id']);
        $this->assertNull($this->svc->submissionFor($this->assignment, $this->s[2]));
    }

    public function testSubmissionForPerMemberReturnsOwnRow(): void
    {
        $this->config(['submission_style' => 'per_member']);
        $this->group([$this->s[0], $this->s[1]]);
        $mine = $this->submit($this->s[0], 1);

        $this->assertSame($mine, (int) $this->svc->submissionFor($this->assignment, $this->s[0])['id']);
        $this->assertNull($this->svc->submissionFor($this->assignment, $this->s[1]));
    }

    public function testAttemptNumberCountedPerGroupForShared(): void
    {
        $group = $this->group([$this->s[0], $this->s[1]]);
        $this->submit($this->s[0], 1, $group);
        $this->submit($this->s[1], 2, $group);

        $this->assertSame(3, $this->svc->attemptNumberFor($this->assignment, $this->s[0]));
        $this->assertSame(3, $this->svc->attemptNumberFor($this->assignment, $this->s[1]));
    }

    public function testAttemptNumberCountedPerStudentForPerMember(): void
    {
        $this->config(['submission_style' => 'per_member']);
        $this->group([$this->s[0], $this->s[1]]);
        $this->submit($this->s[0], 1);

        $this->assertSame(2, $this->svc->attemptNumberFor($this->assignment, $this->s[0]));
        $this->assertSame(1, $this->svc->attemptNumberFor($this->assignment, $this->s[1]));
    }

    public function testEffectiveDueUsesExtension(): void
    {
        $group = $this->group([$this->s[0]]);
        $due   = db_connect()->table('assessments')->where('id', $this->assignment)->get()->getRowArray()['due_date'];

        $this->assertSame($due, $this->svc->effectiveDueDate($this->assignment, $this->s[0]));

        db_connect()->table('group_extensions')->insert(['group_id' => $group, 'assessment_id' => $this->assignment, 'until_at' => '2026-12-20 10:00:00', 'reason' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame('2026-12-20 10:00:00', $this->svc->effectiveDueDate($this->assignment, $this->s[0]));
        $this->assertSame($due, $this->svc->effectiveDueDate($this->assignment, $this->s[1]));
    }

    public function testMigrationAllowsTwoNullGroupSubmissionsSameAttempt(): void
    {
        $this->submit($this->s[0], 1);
        $this->submit($this->s[1], 1);

        $this->assertSame(2, db_connect()->table('assessment_submissions')->where('assessment_id', $this->assignment)->countAllResults());
    }

    public function testDuplicateGroupAttemptRefusedByIndex(): void
    {
        $group = $this->group([$this->s[0], $this->s[1]]);
        $this->submit($this->s[0], 1, $group);

        try {
            $this->submit($this->s[1], 1, $group);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException) {
            // thrown or swallowed depending on db_debug: either way the row must not exist
        }

        $this->assertSame(1, db_connect()->table('assessment_submissions')->where('group_id', $group)->countAllResults());
    }
}
