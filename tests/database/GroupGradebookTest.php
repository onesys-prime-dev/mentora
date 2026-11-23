<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssessmentResultService;
use App\Services\Courses\Assessments\AssessmentService;
use App\Services\Courses\Assessments\AssignmentSubmissionService;
use App\Services\Courses\Grades\GradebookSyncService;
use App\Services\Courses\LearningProgressService;
use App\Services\Groups\ActivityGroupMembershipService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupGradingService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\GradeFactory;

/**
 * A group member's own final (group_member_scores) is what the gradebook, the student's result and min-score
 * prerequisites read (Task 7.3). Non-group work reads its own rows exactly as before.
 *
 * @internal
 */
final class GroupGradebookTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GroupGradingService $grading;

    private int $offering;

    private int $module;

    private User $instructor;

    private int $teacher;

    private int $assignment;

    private int $config;

    private int $scheme;

    private int $item;

    /** @var list<User> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->grading    = new GroupGradingService();
        $this->offering   = CourseFactory::offering();
        $this->module     = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        $this->teacher    = (int) $this->instructor->id;
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        for ($i = 0; $i < 4; $i++) {
            $this->u[] = $student = CourseFactory::user('student');
            CourseFactory::enroll($this->offering, $student);
        }

        $this->assignment = CourseFactory::assessment($this->offering, $this->instructor, $this->module, null, 'Assignment');
        AssessmentFactory::open($this->assignment, ['total_points' => 20]);
        $this->config = (new ActivityGroupService())->attachAssessment($this->assignment, null, ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 5], $this->teacher);

        $this->scheme = GradeFactory::scheme($this->offering);
        $this->item   = GradeFactory::item($this->scheme, GradeFactory::category($this->scheme, 'Work', 100), 'Project', 20, 1, $this->assignment);
    }

    private function configure(array $changes): void
    {
        db_connect()->table('activity_group_configs')->where('id', $this->config)->update($changes);
    }

    /** @param list<int> $members indexes into $this->u */
    private function group(array $members): int
    {
        $db = db_connect();
        $db->table('student_groups')->insert(['set_id' => null, 'config_id' => $this->config, 'name' => 'Team Rocket', 'status' => 'active']);
        $group = (int) $db->insertID();

        foreach ($members as $m) {
            $db->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $this->config, 'student_user_id' => $this->u[$m]->id, 'joined_at' => date('Y-m-d H:i:s')]);
        }

        return $group;
    }

    private function handIn(int $group, int $by = 0): int
    {
        return AssessmentFactory::attempt($this->assignment, $this->u[$by], 'Submitted', 0.0, ['group_id' => $group, 'submitted_by_user_id' => $this->u[$by]->id]);
    }

    private function member(int $i): int
    {
        return (int) $this->u[$i]->id;
    }

    private function grade(int $group, array $input): void
    {
        $this->grading->gradeGroup($this->assignment, $group, $input, $this->teacher);
    }

    /** @return array<string, mixed>|null */
    private function cell(int $member, ?int $item = null): ?array
    {
        return db_connect()->table('gradebook_item_scores')->where('grade_item_id', $item ?? $this->item)->where('student_id', $this->member($member))->get()->getRowArray();
    }

    /** @return list<float|null> */
    private function cells(int $count = 4): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $cell  = $this->cell($i);
            $out[] = $cell === null || $cell['raw_score'] === null ? null : (float) $cell['raw_score'];
        }

        return $out;
    }

    private function publish(): void
    {
        db_connect()->table('grading_schemes')->where('id', $this->scheme)->update(['status' => 'Published', 'needs_recalculation' => 0]);
    }

    /** @return array<string, mixed> the result that counts for one member */
    private function resultOf(int $member): array
    {
        $results = new AssessmentResultService();
        $own     = db_connect()->table('assessment_submissions')->where('assessment_id', $this->assignment)->where('student_id', $this->member($member))->get()->getResultArray();

        return $results->resultFor(false, $results->memberAttempts($this->assignment, $this->member($member), $own));
    }

    public function testMemberWithoutOwnSubmissionGetsGroupFinalInGradebook(): void
    {
        $this->configure(['grading_mode' => 'collective_adjust']);
        $group = $this->group([0, 1, 2, 3]);
        $sub   = $this->handIn($group);

        $this->grade($group, ['group_score' => 15, 'members' => [$this->member(2) => ['adjustment' => -3, 'reason' => 'Missed meetings']]]);

        $this->assertSame([15.0, 15.0, 12.0, 15.0], $this->cells());

        foreach ([0, 1, 2, 3] as $m) {
            $this->assertSame($sub, (int) $this->cell($m)['assessment_submission_id']);
            $this->assertSame(0, (int) $this->cell($m)['is_missing']);
        }

        $this->assertSame(60.0, (float) $this->cell(2)['raw_percentage']);
    }

    public function testExcusedMemberBecomesMissingInGradebook(): void
    {
        $group = $this->group([0, 1, 2]);
        $sub   = $this->handIn($group);
        $this->grade($group, ['group_score' => 12]);

        $this->grading->resolveDropped($group, $this->assignment, $this->member(1), 'excuse', 'Withdrew for medical reasons', $this->teacher);

        $cell = $this->cell(1);
        $this->assertNotNull($cell);
        $this->assertSame(1, (int) $cell['is_missing']);
        $this->assertNull($cell['raw_score']);
        $this->assertSame($sub, (int) $cell['assessment_submission_id']);
        $this->assertSame([12.0, null, 12.0], $this->cells(3));

        $this->grading->resolveDropped($group, $this->assignment, $this->member(1), 'keep', 'Came back', $this->teacher);
        $this->assertSame(0, (int) $this->cell(1)['is_missing']);
        $this->assertSame(12.0, (float) $this->cell(1)['raw_score']);
    }

    public function testPendingLateAddHasNoScoreNotZero(): void
    {
        $group = $this->group([0, 1]);
        $this->handIn($group);
        $this->grade($group, ['group_score' => 12]);

        (new ActivityGroupMembershipService())->addLateEnrollee($group, $this->member(2), 'Enrolled late', $this->teacher);
        (new GradebookSyncService())->syncItemScore($this->assignment, $this->member(2));

        $this->assertNull($this->cell(2), 'no gradebook score, not a zero');

        $result = $this->resultOf(2);
        $this->assertNull($result['counted']);
        $this->assertNull($result['score']);
        $this->assertNotEmpty($result['pending'], 'the late add is pending');

        $this->grading->resolveLateAdd($group, $this->assignment, $this->member(2), 'inherit', 'Did the final part', $this->teacher);
        $this->assertSame(12.0, (float) $this->cell(2)['raw_score']);
    }

    public function testUngradedGroupBehavesAsUngraded(): void
    {
        $group = $this->group([0, 1]);
        $this->handIn($group);

        $result = $this->resultOf(1);
        $this->assertNull($result['counted']);
        $this->assertCount(1, $result['finished'], 'the teammate sees the group hand-in');
        $this->assertNotEmpty($result['pending']);
    }

    public function testPerMemberStyleUsesOwnRowFinal(): void
    {
        $this->configure(['submission_style' => 'per_member', 'grading_mode' => 'collective_adjust']);
        $group = $this->group([0, 1, 2, 3]);
        $own   = [];

        foreach ([0, 1, 2] as $m) {
            $own[$m] = AssessmentFactory::attempt($this->assignment, $this->u[$m], 'Submitted');
        }

        $this->grade($group, ['group_score' => 14, 'members' => [$this->member(1) => ['adjustment' => 2, 'reason' => 'Extra chapter']]]);

        $this->assertSame([14.0, 16.0, 14.0, null], $this->cells());
        $this->assertSame($own[1], (int) $this->cell(1)['assessment_submission_id']);
        $this->assertNull($this->cell(3), 'no hand-in, no grade');

        $this->grading->resolveDropped($group, $this->assignment, $this->member(2), 'excuse', 'Withdrew', $this->teacher);
        $this->assertSame(1, (int) $this->cell(2)['is_missing']);
    }

    public function testUngroupedSoloStudentUsesOwnSubmission(): void
    {
        $this->configure(['ungrouped_policy' => 'solo']);
        $this->group([0, 1]);
        AssessmentFactory::attempt($this->assignment, $this->u[3], 'Graded', 17.0);

        (new GradebookSyncService())->syncItemScore($this->assignment, $this->member(3));

        $this->assertSame(17.0, (float) $this->cell(3)['raw_score']);
    }

    public function testNonGroupGradebookUnchanged(): void
    {
        $plain = CourseFactory::assessment($this->offering, $this->instructor, $this->module, null, 'Assignment');
        $item  = GradeFactory::item($this->scheme, GradeFactory::category($this->scheme, 'Solo', 100), 'Essay', 20, 2, $plain);

        AssessmentFactory::attempt($plain, $this->u[0], 'Graded', 18.0);
        $latest = AssessmentFactory::attempt($plain, $this->u[0], 'Graded', 11.0);
        AssessmentFactory::attempt($plain, $this->u[1], 'Submitted');

        $sync = new GradebookSyncService();
        $sync->syncItemScore($plain, $this->member(0));
        $sync->syncItemScore($plain, $this->member(1));

        $cell = $this->cell(0, $item);
        $this->assertSame(11.0, (float) $cell['raw_score']);
        $this->assertSame(55.0, (float) $cell['raw_percentage']);
        $this->assertSame($latest, (int) $cell['assessment_submission_id']);
        $this->assertSame(0, (int) $cell['is_missing']);
        $this->assertNull($this->cell(1, $item));

        $own = db_connect()->table('assessment_submissions')->where('assessment_id', $plain)->where('student_id', $this->member(0))->get()->getResultArray();
        $this->assertSame($own, (new AssessmentResultService())->memberAttempts($plain, $this->member(0), $own), 'non-group attempts pass through untouched');
    }

    public function testPublishedSchemeRecalculatesEachMemberOnce(): void
    {
        $this->publish();
        $group = $this->group([0, 1, 2, 3]);
        $this->handIn($group, 3);

        $this->grade($group, ['group_score' => 18]);

        foreach ([0, 1, 2, 3] as $m) {
            $records = db_connect()->table('gradebook_records')->where('student_id', $this->member($m))->where('course_offering_id', $this->offering)->get()->getResultArray();
            $this->assertCount(1, $records, "member {$m} has exactly one final grade row");
        }

        $this->assertSame(4, db_connect()->table('gradebook_item_scores')->where('grade_item_id', $this->item)->countAllResults());
    }

    public function testRegradeUpdatesGradebook(): void
    {
        $group = $this->group([0, 1, 2, 3]);
        $this->handIn($group);

        $this->grade($group, ['group_score' => 10]);
        $this->grade($group, ['group_score' => 16]);

        $this->assertSame([16.0, 16.0, 16.0, 16.0], $this->cells());
        $this->assertSame(4, db_connect()->table('gradebook_item_scores')->where('grade_item_id', $this->item)->countAllResults());
    }

    public function testReopenedGroupClearsGradebookScores(): void
    {
        $group = $this->group([0, 1, 2]);
        $this->handIn($group);
        $this->grade($group, ['group_score' => 12]);
        $this->assertSame([12.0, 12.0, 12.0], $this->cells(3));

        $this->grading->reopenGroup($this->assignment, $group, $this->teacher);

        $this->assertSame([null, null, null], $this->cells(3));
        $this->assertSame(0, db_connect()->table('gradebook_item_scores')->where('grade_item_id', $this->item)->countAllResults());
    }

    public function testSyncAssessmentScoresReachesMembersWithoutOwnSubmission(): void
    {
        $group = $this->group([0, 1, 2]);
        $this->handIn($group);
        $this->grade($group, ['group_score' => 12]);
        db_connect()->table('gradebook_item_scores')->where('grade_item_id', $this->item)->delete();

        $this->assertSame(3, (new GradebookSyncService())->syncAssessmentScores($this->assignment));
        $this->assertSame([12.0, 12.0, 12.0], $this->cells(3));
    }

    public function testMinScorePrerequisiteUsesMemberFinal(): void
    {
        $progress = new LearningProgressService();
        $unit     = CourseFactory::unit($this->module);
        [$workItem, $waiting] = CourseFactory::items($unit, [['title' => 'Group project', 'type' => 'assignment'], ['title' => 'Next', 'type' => 'reading']]);

        $project = CourseFactory::assessment($this->offering, $this->instructor, $this->module, $workItem, 'Assignment');
        AssessmentFactory::open($project, ['total_points' => 20]);
        $config = (new ActivityGroupService())->attachAssessment($project, null, ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 5], $this->teacher);
        db_connect()->table('activity_group_configs')->where('id', $config)->update(['grading_mode' => 'collective_adjust']);

        $this->assertTrue($progress->saveDependencies($this->offering, $waiting, 'previous', [], ['mode' => 'custom', 'percent' => 70])['success']);

        $db = db_connect();
        $db->table('student_groups')->insert(['set_id' => null, 'config_id' => $config, 'name' => 'Team', 'status' => 'active']);
        $group = (int) $db->insertID();

        foreach ([0, 1, 2] as $m) {
            $db->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $config, 'student_user_id' => $this->u[$m]->id, 'joined_at' => date('Y-m-d H:i:s')]);
            CourseFactory::progress($workItem, $this->u[$m]);
        }

        AssessmentFactory::attempt($project, $this->u[0], 'Submitted', 0.0, ['group_id' => $group, 'submitted_by_user_id' => $this->u[0]->id]);

        // 15/20 = 75% for the group; member 1 is adjusted down to 10/20 = 50%.
        $this->grading->gradeGroup($project, $group, ['group_score' => 15, 'members' => [$this->member(1) => ['adjustment' => -5, 'reason' => 'Did little']]], $this->teacher);

        $this->assertNull($progress->lockFor($this->u[2], $waiting), 'a teammate who did not submit reaches 75%');
        $this->assertNull($progress->lockFor($this->u[0], $waiting), 'the submitter reaches 75%, not their 15-point row alone');
        $this->assertNotNull($progress->lockFor($this->u[1], $waiting), 'the adjusted member has 50%');
    }

    /** Grades the group with a private adjustment and note for member 1 and a note for member 2. */
    private function gradedWithSecrets(): int
    {
        $this->configure(['grading_mode' => 'collective_adjust']);
        $group = $this->group([0, 1, 2, 3]);
        $this->handIn($group);

        $this->grade($group, ['group_score' => 15, 'feedback' => 'Strong report overall', 'members' => [
            $this->member(1) => ['adjustment' => -4, 'reason' => 'SECRET-REASON-ONE', 'note' => 'SECRET-NOTE-ONE'],
            $this->member(2) => ['adjustment' => 2, 'reason' => 'Own reason for two', 'note' => 'Own note for two'],
        ]]);

        return $group;
    }

    public function testStudentResultNeverContainsOtherMembersAdjustment(): void
    {
        $this->gradedWithSecrets();

        foreach ([2, 3] as $viewer) {
            $html  = (new AssessmentService())->viewAssessment($this->u[$viewer], $this->assignment)['html'];
            $state = (new AssignmentSubmissionService())->getState($this->u[$viewer], $this->assignment);
            $blob  = $html . json_encode($state) . json_encode($this->resultOf($viewer));

            $this->assertStringNotContainsString('SECRET-REASON-ONE', $blob);
            $this->assertStringNotContainsString('SECRET-NOTE-ONE', $blob);
            $this->assertStringNotContainsString('-4.00', $blob, 'no other member\'s adjustment value');
            $this->assertStringNotContainsString('11.00', $blob, 'no other member\'s final');
            $this->assertStringContainsString('Strong report overall', $html, 'the group feedback thread');
        }

        $this->assertStringNotContainsString('Own reason for two', (new AssessmentService())->viewAssessment($this->u[3], $this->assignment)['html']);
    }

    public function testOverviewAndFeedShowTheMembersOwnFinal(): void
    {
        $this->gradedWithSecrets();
        $course   = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRowArray()['course_id'];
        $overview = (new AssessmentService());

        foreach ([2 => 17.0, 1 => 11.0, 0 => 15.0] as $m => $final) {
            $built = (new \App\Services\Courses\Assessments\AssessmentOverviewService($overview))->build($this->u[$m], $this->offering, $course);
            $row   = array_values(array_filter($built['assessments'], fn (array $a) => (int) $a['id'] === $this->assignment))[0];

            $this->assertSame('graded', $row['student']['key'], "member {$m} sees the group work as graded");
            $this->assertSame($final, $row['student']['score']);
            $this->assertSame([$final], array_column($built['feedback'], 'score'));

            $feed = (new \App\Services\Courses\ActivityFeedService())->forStudent($this->member($m), $this->offering, $built['assessments']);
            $this->assertStringContainsString(rtrim(rtrim(number_format($final, 2), '0'), '.') . '/20', implode(' ', array_column(array_filter($feed, static fn (array $e) => $e['kind'] === 'grade'), 'detail')));
        }
    }

    public function testStudentSeesOwnAdjustmentReasonAndNote(): void
    {
        $this->gradedWithSecrets();

        $html = (new AssessmentService())->viewAssessment($this->u[2], $this->assignment)['html'];

        $this->assertStringContainsString('17/20', $html, 'their own final');
        $this->assertStringContainsString('Own reason for two', $html);
        $this->assertStringContainsString('Own note for two', $html);
        $this->assertStringContainsString('Strong report overall', $html);
        $this->assertStringContainsString('data-group-result', $html);

        $counted = $this->resultOf(2)['counted'];
        $this->assertSame(17.0, (float) $counted['score']);
        $this->assertSame(15.0, (float) $counted['member_score']['group_points']);
        $this->assertSame(2.0, (float) $counted['member_score']['adjustment']);

        // The non-submitter's learning-item state reads the same.
        $state = (new AssignmentSubmissionService())->getState($this->u[2], $this->assignment);
        $this->assertSame(17.0, (float) $state['counted']['score']);
    }
}
