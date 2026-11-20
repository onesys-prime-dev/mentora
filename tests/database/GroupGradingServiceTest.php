<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Groups\ActivityGroupMembershipService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupGradingService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Grading a group once and fanning the result out to every member: rubric scopes, the single-score modes, average,
 * adjustments with reasons, one transaction, late adds, drops and reopening.
 *
 * @internal
 */
final class GroupGradingServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GroupGradingService $service;

    private int $offering;

    private int $module;

    private User $instructor;

    private int $teacher;

    private int $assignment;

    private int $config;

    /** @var list<User> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->build();
    }

    private function build(): void
    {
        $this->service    = new GroupGradingService();
        $this->offering   = CourseFactory::offering();
        $this->module     = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        $this->teacher    = (int) $this->instructor->id;
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        $this->u = [];

        for ($i = 0; $i < 4; $i++) {
            $this->u[] = $student = CourseFactory::user('student');
            CourseFactory::enroll($this->offering, $student);
        }

        $this->assignment = CourseFactory::assessment($this->offering, $this->instructor, $this->module, null, 'Assignment');
        db_connect()->table('assessments')->where('id', $this->assignment)->update(['total_points' => 20]);
        $this->config = (new ActivityGroupService())->attachAssessment($this->assignment, null, ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 5], $this->teacher);
    }

    private function configure(array $changes): void
    {
        db_connect()->table('activity_group_configs')->where('id', $this->config)->update($changes);
    }

    /** @param list<int> $members indexes into $this->u */
    private function group(array $members, ?int $config = null, string $status = 'active'): int
    {
        $db = db_connect();
        $db->table('student_groups')->insert(['set_id' => null, 'config_id' => $config ?? $this->config, 'name' => 'Team ' . count($members), 'status' => $status]);
        $group = (int) $db->insertID();

        foreach ($members as $m) {
            $db->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $config ?? $this->config, 'student_user_id' => $this->u[$m]->id, 'joined_at' => date('Y-m-d H:i:s')]);
        }

        return $group;
    }

    /** The group's shared hand-in, by member $by. */
    private function handIn(int $group, int $by = 0): int
    {
        return AssessmentFactory::attempt($this->assignment, $this->u[$by], 'Submitted', 0.0, ['group_id' => $group, 'submitted_by_user_id' => $this->u[$by]->id]);
    }

    /** A per-member hand-in. */
    private function ownHandIn(int $member): int
    {
        return AssessmentFactory::attempt($this->assignment, $this->u[$member], 'Submitted');
    }

    /** @return array{0: int, 1: int} [group criterion, individual criterion], 10 points each */
    private function rubric(): array
    {
        $ids = AssessmentFactory::rubric($this->assignment, $this->instructor, [10, 10])['criteria'];
        db_connect()->table('assessment_rubric_criteria')->where('id', $ids[0])->update(['scope' => 'group']);
        db_connect()->table('assessment_rubric_criteria')->where('id', $ids[1])->update(['scope' => 'individual']);

        return $ids;
    }

    private function grade(int $group, array $input): void
    {
        $this->service->gradeGroup($this->assignment, $group, $input, $this->teacher);
    }

    /** @return array<string, mixed>|null */
    private function score(int $submission, int $member): ?array
    {
        return db_connect()->table('group_member_scores')->where('submission_id', $submission)->where('student_user_id', $this->u[$member]->id)->get()->getRowArray();
    }

    /** @return array<string, mixed> */
    private function submission(int $id): array
    {
        return db_connect()->table('assessment_submissions')->where('id', $id)->get()->getRowArray();
    }

    private function rows(): int
    {
        return db_connect()->table('group_member_scores')->countAllResults();
    }

    /** @return list<float|null> each member's final score, in member order */
    private function finals(int $submission, int $count = 4): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $row   = $this->score($submission, $i);
            $out[] = $row === null || $row['final_score'] === null ? null : (float) $row['final_score'];
        }

        return $out;
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

    private function audited(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    private function finalize(): void
    {
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
    }

    private function member(int $i): int
    {
        return (int) $this->u[$i]->id;
    }

    public function testAllMembersGetGroupPlusOwnIndividualScore(): void
    {
        [$g, $ind] = $this->rubric();
        $group     = $this->group([0, 1, 2, 3]);
        $sub       = $this->handIn($group);

        $this->grade($group, ['group_scores' => [$g => 8], 'members' => [
            $this->member(0) => ['individual' => [$ind => 2]],
            $this->member(1) => ['individual' => [$ind => 4]],
            $this->member(2) => ['individual' => [$ind => 6]],
            $this->member(3) => ['individual' => [$ind => 8]],
        ]]);

        $this->assertSame([10.0, 12.0, 14.0, 16.0], $this->finals($sub));
        $this->assertSame(70.0, (float) $this->score($sub, 2)['final_percentage']);
        $this->assertSame(8.0, (float) $this->score($sub, 2)['group_points']);
        $this->assertSame(6.0, (float) $this->score($sub, 2)['individual_points']);
        $this->assertEquals([$ind => 6], json_decode($this->score($sub, 2)['individual_rubric_scores'], true));
        $this->assertSame($group, (int) $this->score($sub, 2)['group_id']);

        $row = $this->submission($sub);
        $this->assertSame('Graded', $row['status']);
        $this->assertSame(1, (int) $row['is_graded']);
        $this->assertSame('Rubric', $row['grading_mode']);
        $this->assertSame(8.0, (float) $row['score'], 'the shared row holds the group part');
        $this->assertEquals([$g => 8], json_decode($row['rubric_scores'], true));
        $this->assertSame($this->teacher, (int) $row['graded_by']);
    }

    public function testCollectiveSingleScoreSameForAll(): void
    {
        $group = $this->group([0, 1, 2, 3]);
        $sub   = $this->handIn($group, 2);

        $this->grade($group, ['group_score' => 15]);

        $this->assertSame([15.0, 15.0, 15.0, 15.0], $this->finals($sub));
        $this->assertSame(75.0, (float) $this->score($sub, 0)['final_percentage']);
        $this->assertSame('Default', $this->submission($sub)['grading_mode']);
        $this->assertSame(15.0, (float) $this->submission($sub)['score']);
    }

    public function testCollectiveAdjustAppliesAdjustment(): void
    {
        $this->configure(['grading_mode' => 'collective_adjust']);
        $group = $this->group([0, 1, 2, 3]);
        $sub   = $this->handIn($group);

        $this->grade($group, ['group_score' => 15, 'members' => [
            $this->member(1) => ['adjustment' => -3, 'reason' => 'Missed the meetings'],
            $this->member(2) => ['note' => 'Carried the slides'],
        ]]);

        $this->assertSame([15.0, 12.0, 15.0, 15.0], $this->finals($sub));
        $this->assertSame(-3.0, (float) $this->score($sub, 1)['adjustment']);
        $this->assertSame('Missed the meetings', $this->score($sub, 1)['adjustment_reason']);
        $this->assertSame('Carried the slides', $this->score($sub, 2)['private_note']);
    }

    public function testAdjustmentRequiresReason(): void
    {
        $this->configure(['grading_mode' => 'collective_adjust']);
        $group = $this->group([0, 1]);
        $sub   = $this->handIn($group);

        $this->assertArrayHasKey('reason', $this->refused(fn () => $this->grade($group, ['group_score' => 15, 'members' => [$this->member(1) => ['adjustment' => 2, 'reason' => '  ']]]))->errors());
        $this->refused(fn () => $this->grade($group, ['group_score' => 15, 'members' => [$this->member(1) => ['adjustment' => 2, 'reason' => str_repeat('x', 501)]]]));

        $this->assertSame(0, $this->rows());
        $this->assertSame('Submitted', $this->submission($sub)['status']);
    }

    public function testAdjustmentRefusedWhenModeCollective(): void
    {
        $group = $this->group([0, 1]);
        $sub   = $this->handIn($group);

        $this->refused(fn () => $this->grade($group, ['group_score' => 15, 'members' => [$this->member(1) => ['adjustment' => 2, 'reason' => 'Extra work']]]));

        $this->assertSame(0, $this->rows());
        $this->assertSame('Submitted', $this->submission($sub)['status']);

        // A zero adjustment is no adjustment.
        $this->grade($group, ['group_score' => 15, 'members' => [$this->member(1) => ['adjustment' => 0, 'reason' => null]]]);
        $this->assertSame([15.0, 15.0], $this->finals($sub, 2));
    }

    public function testFinalClampedToZeroAndTotal(): void
    {
        $this->configure(['grading_mode' => 'collective_adjust']);
        $group = $this->group([0, 1, 2]);
        $sub   = $this->handIn($group);

        $this->grade($group, ['group_score' => 18, 'members' => [
            $this->member(0) => ['adjustment' => 5, 'reason' => 'Bonus'],
            $this->member(1) => ['adjustment' => -25, 'reason' => 'Did nothing'],
        ]]);

        $this->assertSame([20.0, 0.0, 18.0], $this->finals($sub, 3));
        $this->assertSame(100.0, (float) $this->score($sub, 0)['final_percentage']);

        $this->grade($group, ['group_score' => 30]);
        $this->assertSame(20.0, (float) $this->submission($sub)['score'], 'the group score itself is clamped to the total');
    }

    public function testIndividualModeUsesOwnScores(): void
    {
        $this->configure(['grading_mode' => 'individual']);
        $group = $this->group([0, 1, 2, 3]);
        $sub   = $this->handIn($group);

        $this->grade($group, ['members' => [
            $this->member(0) => ['score' => 5],
            $this->member(1) => ['score' => 10],
            $this->member(2) => ['score' => 15],
            $this->member(3) => ['score' => 25],
        ]]);

        $this->assertSame([5.0, 10.0, 15.0, 20.0], $this->finals($sub));
        $this->assertNull($this->score($sub, 1)['group_points']);
        $this->assertSame(10.0, (float) $this->score($sub, 1)['individual_points']);
    }

    public function testPerMemberStyleWritesEachMembersOwnSubmissionWithMergedRubricScores(): void
    {
        $this->configure(['submission_style' => 'per_member']);
        [$g, $ind] = $this->rubric();
        $group     = $this->group([0, 1, 2, 3]);
        $own       = [$this->ownHandIn(0), $this->ownHandIn(1), $this->ownHandIn(2)];

        $this->grade($group, ['group_scores' => [$g => 7], 'feedback' => 'Good teamwork', 'members' => [
            $this->member(0) => ['individual' => [$ind => 1]],
            $this->member(1) => ['individual' => [$ind => 2]],
            $this->member(2) => ['individual' => [$ind => 3]],
            $this->member(3) => ['individual' => [$ind => 4]],
        ]]);

        foreach ($own as $i => $id) {
            $row = $this->submission($id);
            $this->assertSame('Graded', $row['status']);
            $this->assertSame(7.0 + $i + 1, (float) $row['score']);
            $this->assertEquals([$g => 7, $ind => $i + 1], json_decode($row['rubric_scores'], true));
            $this->assertSame(7.0 + $i + 1, (float) $this->score($id, $i)['final_score']);
            $this->assertSame(1, db_connect()->table('assessment_feedbacks')->where('submission_id', $id)->countAllResults());
        }

        $this->assertSame(3, $this->rows(), 'the member without a hand-in is skipped');
        $this->assertSame(0, db_connect()->table('group_member_scores')->where('student_user_id', $this->member(3))->countAllResults());

        $audit = db_connect()->table('audit_logs')->where('action', 'groups.graded')->get()->getRowArray();
        $this->assertSame([$this->member(3)], json_decode($audit['new_values'], true)['skipped']);
    }

    public function testAverageModeGivesEveryoneTheAverageAndExcludesNonSubmitters(): void
    {
        $this->configure(['submission_style' => 'per_member', 'use_average' => 1]);
        $group = $this->group([0, 1, 2, 3]);
        $own   = [$this->ownHandIn(0), $this->ownHandIn(1), $this->ownHandIn(2)];

        $this->grade($group, ['members' => [
            $this->member(0) => ['score' => 10],
            $this->member(1) => ['score' => 14],
            $this->member(2) => ['score' => 18],
            $this->member(3) => ['score' => 20],
        ]]);

        foreach ($own as $i => $id) {
            $this->assertSame(14.0, (float) $this->score($id, $i)['final_score']);
            $this->assertSame(14.0, (float) $this->submission($id)['score']);
        }

        $this->assertSame(18.0, (float) $this->score($own[2], 2)['individual_points'], 'each member keeps their own input');
        $this->assertSame(3, $this->rows());
    }

    public function testFanOutRollsBackAllOnOneFailure(): void
    {
        // Real transactions are needed to see a rollback: leave the one the isolation opened and build again.
        db_connect()->transRollback();
        $lastAudit = (int) (db_connect()->table('audit_logs')->selectMax('id')->get()->getRowArray()['id'] ?? 0);
        $this->build();

        try {
            $this->configure(['submission_style' => 'per_member']);
            $group = $this->group([0, 1, 2, 3]);
            $own   = [$this->ownHandIn(0), $this->ownHandIn(1), $this->ownHandIn(2), $this->ownHandIn(3)];
            $this->grade($group, ['group_score' => 10]);

            $snapshot = static fn (): array => [
                db_connect()->table('assessment_submissions')->whereIn('id', $own)->orderBy('id')->get()->getResultArray(),
                db_connect()->table('group_member_scores')->whereIn('submission_id', $own)->orderBy('id')->get()->getResultArray(),
            ];
            $before = $snapshot();

            $failing = new class () extends GroupGradingService {
                private int $calls = 0;

                protected function saveMemberScore(array $row): void
                {
                    if (++$this->calls === 3) {
                        throw new RuntimeException('disk full');
                    }

                    parent::saveMemberScore($row);
                }
            };

            $this->refused(fn () => $failing->gradeGroup($this->assignment, $group, ['group_score' => 16], $this->teacher));

            $this->assertSame($before, $snapshot());
            $this->assertSame(1, $this->audited('groups.graded'));
        } finally {
            // Committed rows must not reach the next tests.
            db_connect()->table('audit_logs')->where('id >', $lastAudit)->delete();
            db_connect()->table('assessments')->where('id', $this->assignment)->delete();
            db_connect()->table('course_offerings')->where('id', $this->offering)->delete();
            db_connect()->transBegin();
        }
    }

    public function testFinalizedOfferingRefuses(): void
    {
        $group = $this->group([0, 1]);
        $sub   = $this->handIn($group);
        $this->grade($group, ['group_score' => 10]);
        $this->finalize();

        foreach ([
            fn () => $this->grade($group, ['group_score' => 12]),
            fn () => $this->service->reopenGroup($this->assignment, $group, $this->teacher),
            fn () => $this->service->resolveDropped($group, $this->assignment, $this->member(1), 'excuse', 'Withdrew', $this->teacher),
            fn () => $this->service->resolveLateAdd($group, $this->assignment, $this->member(1), 'inherit', 'Joined', $this->teacher),
        ] as $write) {
            $this->assertSame(GradeFinalizationService::LOCKED_MESSAGE, $this->refused($write)->getMessage());
        }

        $this->assertSame([10.0, 10.0], $this->finals($sub, 2));
        $this->assertSame(0, (int) $this->score($sub, 1)['excused']);
    }

    public function testFeedbackAppendedOnceToSharedSubmission(): void
    {
        $group = $this->group([0, 1, 2]);
        $sub   = $this->handIn($group);

        $this->grade($group, ['group_score' => 15, 'feedback' => 'Nice report']);
        $this->grade($group, ['group_score' => 16, 'feedback' => 'Nice report']);

        $rows = db_connect()->table('assessment_feedbacks')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame($sub, (int) $rows[0]['submission_id']);
        $this->assertSame('instructor', $rows[0]['author_role']);
    }

    public function testRegradingUpdatesWithoutDuplicates(): void
    {
        $group = $this->group([0, 1, 2, 3]);
        $sub   = $this->handIn($group);

        $this->grade($group, ['group_score' => 10]);
        $this->grade($group, ['group_score' => 16]);

        $this->assertSame(4, $this->rows());
        $this->assertSame([16.0, 16.0, 16.0, 16.0], $this->finals($sub));
        $this->assertSame(16.0, (float) $this->submission($sub)['score']);
    }

    public function testLateAddPendingThenInheritOrSeparate(): void
    {
        $group   = $this->group([0, 1]);
        $sub     = $this->handIn($group);
        $members = new ActivityGroupMembershipService();
        $this->grade($group, ['group_score' => 12]);

        $members->addLateEnrollee($group, $this->member(2), 'Enrolled late', $this->teacher);
        $pending = $this->score($sub, 2);
        $this->assertSame(1, (int) $pending['pending']);
        $this->assertNull($pending['final_score']);

        $this->refused(fn () => $this->service->resolveLateAdd($group, $this->assignment, $this->member(2), 'inherit', ' ', $this->teacher));
        $this->refused(fn () => $this->service->resolveLateAdd($group, $this->assignment, $this->member(2), 'steal', 'x', $this->teacher));

        $this->service->resolveLateAdd($group, $this->assignment, $this->member(2), 'inherit', 'Did the final part', $this->teacher);
        $inherited = $this->score($sub, 2);
        $this->assertSame(0, (int) $inherited['pending']);
        $this->assertSame(12.0, (float) $inherited['final_score']);
        $this->assertSame(12.0, (float) $inherited['group_points']);
        $this->assertNull($inherited['individual_points']);

        $members->addLateEnrollee($group, $this->member(3), 'Enrolled late', $this->teacher);
        $this->service->resolveLateAdd($group, $this->assignment, $this->member(3), 'separate', 'Will grade on its own', $this->teacher);
        $this->assertSame(1, (int) $this->score($sub, 3)['pending']);
        $this->assertNull($this->score($sub, 3)['final_score']);

        $this->grade($group, ['group_score' => 14]);
        $this->assertSame(0, (int) $this->score($sub, 3)['pending']);
        $this->assertSame([14.0, 14.0, 14.0, 14.0], $this->finals($sub));
        $this->assertSame(2, $this->audited('groups.late_add_resolved'));
    }

    public function testDroppedKeepDefaultAndExcuse(): void
    {
        $group = $this->group([0, 1, 2]);
        $sub   = $this->handIn($group);
        $this->grade($group, ['group_score' => 12]);

        (new ActivityGroupMembershipService())->onStudentDropped($this->offering, $this->member(1));
        $this->assertSame(0, db_connect()->table('student_group_members')->where('group_id', $group)->where('student_user_id', $this->member(1))->countAllResults());
        $kept = $this->score($sub, 1);
        $this->assertSame(0, (int) $kept['excused'], 'keep is the default');
        $this->assertSame(12.0, (float) $kept['final_score']);

        $this->refused(fn () => $this->service->resolveDropped($group, $this->assignment, $this->member(1), 'excuse', '', $this->teacher));

        $this->service->resolveDropped($group, $this->assignment, $this->member(1), 'excuse', 'Withdrew for medical reasons', $this->teacher);
        $this->assertSame(1, (int) $this->score($sub, 1)['excused']);
        $this->assertSame(12.0, (float) $this->score($sub, 1)['final_score'], 'the history stays');

        $this->service->resolveDropped($group, $this->assignment, $this->member(1), 'keep', 'Changed my mind', $this->teacher);
        $this->assertSame(0, (int) $this->score($sub, 1)['excused']);

        // Also for a member still in the group.
        $this->service->resolveDropped($group, $this->assignment, $this->member(2), 'excuse', 'Dropping next week', $this->teacher);
        $this->assertSame(1, (int) $this->score($sub, 2)['excused']);

        // Regrading keeps the decision.
        $this->grade($group, ['group_score' => 13]);
        $this->assertSame(1, (int) $this->score($sub, 2)['excused']);
        $this->assertSame(3, $this->audited('groups.dropped_resolved'));
    }

    public function testReopenClearsFinalsAndAllowsRegrade(): void
    {
        $group = $this->group([0, 1]);
        $sub   = $this->handIn($group);

        $this->refused(fn () => $this->service->reopenGroup($this->assignment, $group, $this->teacher));

        $this->grade($group, ['group_score' => 12, 'members' => [$this->member(0) => ['note' => 'Quiet but solid']]]);
        $this->service->reopenGroup($this->assignment, $group, $this->teacher);

        $row = $this->submission($sub);
        $this->assertSame('Submitted', $row['status']);
        $this->assertSame(0, (int) $row['is_graded']);
        $this->assertSame([null, null], $this->finals($sub, 2));
        $this->assertNull($this->score($sub, 0)['final_percentage']);
        $this->assertSame('Quiet but solid', $this->score($sub, 0)['private_note']);
        $this->assertSame(1, $this->audited('groups.grading_reopened'));

        $this->grade($group, ['group_score' => 15]);
        $this->assertSame([15.0, 15.0], $this->finals($sub, 2));
        $this->assertSame('Graded', $this->submission($sub)['status']);
    }

    public function testCriterionOfWrongScopeRefused(): void
    {
        [$g, $ind] = $this->rubric();
        $group     = $this->group([0, 1]);
        $sub       = $this->handIn($group);

        $this->refused(fn () => $this->grade($group, ['group_scores' => [$ind => 5]]));
        $this->refused(fn () => $this->grade($group, ['group_scores' => [$g => 5], 'members' => [$this->member(0) => ['individual' => [$g => 5]]]]));
        $this->refused(fn () => $this->grade($group, ['group_scores' => [$g => 5, 999999 => 1]]));

        $this->assertSame(0, $this->rows());
        $this->assertSame('Submitted', $this->submission($sub)['status']);

        // Rubric points are clamped to the criterion's maximum, as single grading does.
        $this->grade($group, ['group_scores' => [$g => 50], 'members' => [$this->member(0) => ['individual' => [$ind => -4]]]]);
        $this->assertSame([10.0, 10.0], $this->finals($sub, 2));
    }

    public function testGroupOfAnotherActivityRefused(): void
    {
        $other       = CourseFactory::assessment($this->offering, $this->instructor, $this->module, null, 'Assignment');
        $otherConfig = (new ActivityGroupService())->attachAssessment($other, null, ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 5], $this->teacher);
        $foreign     = $this->group([0, 1], $otherConfig);
        AssessmentFactory::attempt($other, $this->u[0], 'Submitted', 0.0, ['group_id' => $foreign]);

        $this->refused(fn () => $this->grade($foreign, ['group_score' => 10]));

        $proposed = $this->group([2], null, 'proposed');
        $this->handIn($proposed, 2);
        $this->refused(fn () => $this->grade($proposed, ['group_score' => 10]));

        $notYet = $this->group([3]);
        $this->assertStringContainsString('not submitted', $this->refused(fn () => $this->grade($notYet, ['group_score' => 10]))->getMessage());

        $mine = $this->group([0]);
        $this->handIn($mine);
        $this->refused(fn () => $this->grade($mine, ['group_score' => 10, 'members' => [$this->member(3) => ['note' => 'not in this group']]]));

        $this->assertSame(0, $this->rows());
    }

    public function testAuditRows(): void
    {
        $this->configure(['grading_mode' => 'collective_adjust']);
        $group = $this->group([0, 1, 2, 3]);
        $this->handIn($group);

        $this->grade($group, ['group_score' => 15, 'members' => [$this->member(1) => ['adjustment' => -2, 'reason' => 'Late drafts']]]);

        $graded = db_connect()->table('audit_logs')->where('action', 'groups.graded')->get()->getResultArray();
        $this->assertCount(1, $graded);
        $this->assertSame((string) $group, $graded[0]['target_id']);
        $new = json_decode($graded[0]['new_values'], true);
        $this->assertSame('collective_adjust', $new['mode']);
        $this->assertSame(4, $new['graded']);

        $adjusted = db_connect()->table('audit_logs')->where('action', 'groups.adjusted')->get()->getResultArray();
        $this->assertCount(1, $adjusted);
        $this->assertEquals(['student_user_id' => $this->member(1), 'adjustment' => -2.0, 'reason' => 'Late drafts'], json_decode($adjusted[0]['new_values'], true));
        $this->assertEquals(['adjustment' => 0.0, 'reason' => null], json_decode($adjusted[0]['old_values'], true));

        $this->grade($group, ['group_score' => 15, 'members' => [$this->member(1) => ['adjustment' => -3, 'reason' => 'Later drafts']]]);
        $this->assertSame(2, $this->audited('groups.graded'));
        $last = db_connect()->table('audit_logs')->where('action', 'groups.adjusted')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertEquals(['adjustment' => -2.0, 'reason' => 'Late drafts'], json_decode($last['old_values'], true));
    }
}
