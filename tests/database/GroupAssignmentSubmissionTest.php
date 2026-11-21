<?php

declare(strict_types=1);

use App\Services\Courses\Assessments\AssignmentSubmissionService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupSubmissionResolver;
use App\Services\Storage\FileAccessService;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Handing in a group activity through the normal assignment flow: who may submit, one shared row per group (or one
 * per member), group deadlines and attempts, freezing, module completion, and what each member sees.
 *
 * @internal
 */
final class GroupAssignmentSubmissionTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private AssignmentSubmissionService $service;

    private int $offering;

    private int $module;

    private User $instructor;

    private int $assignment;

    private int $config;

    /** @var list<User> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service    = new AssignmentSubmissionService();
        $this->offering   = CourseFactory::offering();
        $this->module     = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);

        for ($i = 0; $i < 4; $i++) {
            $this->u[] = $student = CourseFactory::user('student');
            CourseFactory::enroll($this->offering, $student);
        }

        $this->assignment = $this->assignment();
        $this->config     = (new ActivityGroupService())->attachAssessment($this->assignment, null, ['formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 4], (int) $this->instructor->id);
    }

    /** An open text-entry assignment, one submission. */
    private function assignment(array $assessment = [], array $settings = []): int
    {
        $id = CourseFactory::assessment($this->offering, $this->instructor, $this->module, null, 'Assignment');
        AssessmentFactory::open($id, $assessment + ['attempt_limit' => 1]);
        db_connect()->table('assessment_settings')->insert($settings + ['assessment_id' => $id, 'allow_file_upload' => 0, 'allow_text_entry' => 1, 'allow_link_entry' => 0, 'created_at' => date('Y-m-d H:i:s')]);

        return $id;
    }

    private function update(array $assessment = [], array $settings = [], array $config = []): void
    {
        $db = db_connect();
        $assessment === [] || $db->table('assessments')->where('id', $this->assignment)->update($assessment);
        $settings === [] || $db->table('assessment_settings')->where('assessment_id', $this->assignment)->update($settings);
        $config === [] || $db->table('activity_group_configs')->where('id', $this->config)->update($config);
    }

    /** @param list<int> $members indexes into $this->u */
    private function group(array $members, ?int $leader = null): int
    {
        $db = db_connect();
        $db->table('student_groups')->insert(['set_id' => null, 'config_id' => $this->config, 'name' => 'Team ' . count($members), 'status' => 'active',
            'leader_user_id' => $leader === null ? null : $this->u[$leader]->id, 'leader_finalized_at' => $leader === null ? null : date('Y-m-d H:i:s')]);
        $group = (int) $db->insertID();

        foreach ($members as $m) {
            $db->table('student_group_members')->insert(['group_id' => $group, 'set_id' => null, 'config_id' => $this->config, 'student_user_id' => $this->u[$m]->id, 'joined_at' => date('Y-m-d H:i:s')]);
        }

        return $group;
    }

    private function submit(int $student, string $text = 'Our work', ?int $assignment = null): array
    {
        return $this->service->submit($this->u[$student], $assignment ?? $this->assignment, ['submission_text' => $text]);
    }

    private function rows(?int $assignment = null): array
    {
        return db_connect()->table('assessment_submissions')->where('assessment_id', $assignment ?? $this->assignment)->orderBy('id')->get()->getResultArray();
    }

    private function frozen(int $group): bool
    {
        return db_connect()->table('student_groups')->where('id', $group)->get()->getRowArray()['frozen_at'] !== null;
    }

    public function testUngroupedBlockedByDefaultSoloAllowed(): void
    {
        $this->assertSame(['success' => false, 'message' => 'Join a group before you submit.'], $this->submit(0));
        $this->assertSame([], $this->rows());
        $this->assertSame('needs_group', $this->service->getState($this->u[0], $this->assignment)['group']['blockedCode']);

        $this->update([], [], ['ungrouped_policy' => 'solo']);

        $this->assertTrue($this->submit(0)['success']);
        $row = $this->rows()[0];
        $this->assertNull($row['group_id']);
        $this->assertSame((int) $this->u[0]->id, (int) $row['student_id']);
    }

    public function testSharedSubmissionStoredOnceWithGroupAndSubmitter(): void
    {
        $group = $this->group([0, 1]);

        $this->assertSame(['success' => true, 'message' => 'Your work was submitted.'], $this->submit(1));

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame($group, (int) $rows[0]['group_id']);
        $this->assertSame((int) $this->u[1]->id, (int) $rows[0]['student_id']);
        $this->assertSame((int) $this->u[1]->id, (int) $rows[0]['submitted_by_user_id']);
        $this->assertSame(1, (int) $rows[0]['attempt_number']);
    }

    public function testAttemptLimitCountedPerGroup(): void
    {
        $this->update(['attempt_limit' => 2]);
        $this->group([0, 1]);

        $this->assertTrue($this->submit(0, 'One')['success']);
        $this->assertTrue($this->submit(1, 'Two')['success']);
        $third = $this->submit(0, 'Three');

        $this->assertSame("You've used all 2 submissions.", $third['message']);
        $this->assertSame([1, 2], array_map('intval', array_column($this->rows(), 'attempt_number')));
        $this->assertSame(2, $this->service->getState($this->u[1], $this->assignment)['attemptsUsed']);
    }

    public function testSecondMemberSeesGroupSubmissionAndCannotDoubleSubmit(): void
    {
        $this->group([0, 1]);
        $this->submit(0, 'From Ada');

        $state = $this->service->getState($this->u[1], $this->assignment);

        $this->assertSame(1, $state['attemptsUsed']);
        $this->assertSame('From Ada', $state['latest']['submission_text']);
        $this->assertSame($this->u[0]->username, $state['latest']['submitted_by']);
        $this->assertFalse($state['canSubmit']);
        $this->assertSame("You've already submitted this assignment.", $state['reason']);
        $this->assertSame('Team 2', $state['group']['name']);
        $this->assertSame($this->u[0]->username, $state['group']['submittedBy']);
        $this->assertTrue($state['group']['canSubmit']);
        $this->assertNull($state['group']['extensionUntil']);

        $this->assertSame("You've already submitted this assignment.", $this->submit(1, 'Again')['message']);
        $this->assertCount(1, $this->rows());
        $this->assertSame(0, $this->service->getState($this->u[2], $this->assignment)['attemptsUsed'], 'another student sees nothing');
    }

    public function testLeaderOnlyRefusesNonLeaderAfterFinalization(): void
    {
        $this->update([], [], ['submission_style' => 'leader_only']);
        $this->group([0, 1], 0);

        $refused = $this->submit(1);
        $this->assertFalse($refused['success']);
        $this->assertStringStartsWith('Only your group leader', $refused['message']);
        $this->assertSame('leader_only', $this->service->getState($this->u[1], $this->assignment)['group']['blockedCode']);
        $this->assertSame([], $this->rows());

        $this->assertTrue($this->submit(0)['success']);
    }

    public function testExtensionMakesLateSubmissionOnTime(): void
    {
        $this->update(['due_date' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        $group = $this->group([0, 1]);
        $until = date('Y-m-d H:i:s', strtotime('+1 day'));
        db_connect()->table('group_extensions')->insert(['group_id' => $group, 'assessment_id' => $this->assignment, 'until_at' => $until, 'reason' => 'Sick', 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertSame($until, $this->service->getState($this->u[1], $this->assignment)['group']['extensionUntil']);
        $this->assertTrue($this->submit(1)['success']);
        $this->assertSame(0, (int) $this->rows()[0]['is_late_submission']);
    }

    public function testLateWithoutExtensionStillFlaggedOrRefusedPerExistingRules(): void
    {
        $this->update(['due_date' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        $this->group([0, 1]);

        $this->assertSame("The due date has passed and late submissions aren't allowed.", $this->submit(0)['message']);

        $this->update([], ['allow_late_submission' => 1]);

        $this->assertTrue($this->submit(0)['success']);
        $this->assertSame(1, (int) $this->rows()[0]['is_late_submission']);
    }

    public function testPerMemberStyleUsesOwnRowOwnDeadlineAndOwnAttempts(): void
    {
        $this->update(['due_date' => date('Y-m-d H:i:s', strtotime('-1 hour'))], ['allow_late_submission' => 1], ['submission_style' => 'per_member']);
        $group = $this->group([0, 1]);
        db_connect()->table('group_extensions')->insert(['group_id' => $group, 'assessment_id' => $this->assignment, 'until_at' => date('Y-m-d H:i:s', strtotime('+1 day')), 'reason' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertTrue($this->submit(0, 'Mine')['success']);
        $this->assertSame(0, $this->service->getState($this->u[1], $this->assignment)['attemptsUsed'], 'teammate has their own attempts');
        $this->assertTrue($this->submit(1, 'Mine too')['success']);

        $rows = $this->rows();
        $this->assertCount(2, $rows);

        foreach ($rows as $i => $row) {
            $this->assertNull($row['group_id']);
            $this->assertNull($row['submitted_by_user_id']);
            $this->assertSame((int) $this->u[$i]->id, (int) $row['student_id']);
            $this->assertSame(1, (int) $row['attempt_number']);
            $this->assertSame(1, (int) $row['is_late_submission'], 'per-member style ignores the group extension');
        }

        $this->assertArrayNotHasKey('submitted_by', $this->service->getState($this->u[0], $this->assignment)['latest']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function styles(): iterable
    {
        yield 'shared' => ['shared'];
        yield 'per member' => ['per_member'];
    }

    #[DataProvider('styles')]
    public function testFirstSubmissionFreezesGroup(string $style): void
    {
        $this->update([], [], ['submission_style' => $style]);
        $mine  = $this->group([0, 1]);
        $other = $this->group([2]);

        $this->assertFalse($this->frozen($mine));
        $this->assertTrue($this->submit(0)['success']);

        $this->assertTrue($this->frozen($mine));
        $this->assertFalse($this->frozen($other));
    }

    /** @return iterable<string, array{0: string, 1: list<int>}> */
    public static function completionStyles(): iterable
    {
        yield 'shared completes every member' => ['shared', [0, 1, 2]];
        yield 'per member completes only the submitter' => ['per_member', [0]];
    }

    /** @param list<int> $completed */
    #[DataProvider('completionStyles')]
    public function testLearningCompletionForEveryMemberOnSharedSubmission(string $style, array $completed): void
    {
        [$item] = CourseFactory::items(CourseFactory::unit($this->module), [['type' => 'assignment']]);
        $this->update(['learning_item_id' => $item], [], ['submission_style' => $style]);
        $this->group([0, 1, 2]);

        $this->assertTrue($this->submit(0)['success']);

        $done = array_map('intval', array_column(db_connect()->table('learning_completions')->where('learning_item_id', $item)->where('status', 'completed')->get()->getResultArray(), 'student_user_id'));
        sort($done);
        $this->assertSame(array_map(fn ($i) => (int) $this->u[$i]->id, $completed), $done);
    }

    public function testNonGroupAssignmentBehaviourUnchanged(): void
    {
        $plain = $this->assignment(['attempt_limit' => 2]);

        $this->assertSame(['success' => true, 'message' => 'Your work was submitted.'], $this->submit(0, 'One', $plain));
        $this->assertTrue($this->submit(0, 'Two', $plain)['success']);
        $this->assertSame("You've used all 2 submissions.", $this->submit(0, 'Three', $plain)['message']);

        $rows = $this->rows($plain);
        $this->assertSame([1, 2], array_map('intval', array_column($rows, 'attempt_number')));
        $this->assertNull($rows[0]['group_id']);
        $this->assertNull($rows[0]['submitted_by_user_id']);

        $state = $this->service->getState($this->u[0], $plain);
        $this->assertSame(['attempts', 'latest', 'counted', 'result', 'attemptsUsed', 'attemptLimit', 'allowed', 'canSubmit', 'reason'], array_keys($state));
        $this->assertArrayNotHasKey('submitted_by', $state['latest']);
        $this->assertSame(0, $this->service->getState($this->u[1], $plain)['attemptsUsed']);
    }

    public function testSimultaneousDuplicateAttemptHandled(): void
    {
        $this->update(['attempt_limit' => 2]);
        $group = $this->group([0, 1]);
        // The teammate's request took attempt 1 between this request's check and its insert.
        db_connect()->table('assessment_submissions')->insert(['assessment_id' => $this->assignment, 'student_id' => $this->u[1]->id, 'group_id' => $group, 'submitted_by_user_id' => $this->u[1]->id,
            'attempt_number' => 1, 'started_at' => date('Y-m-d H:i:s'), 'submitted_at' => date('Y-m-d H:i:s'), 'status' => 'Submitted']);

        $racing = new class () extends AssignmentSubmissionService {
            public function __construct()
            {
                parent::__construct();
                $this->resolver = new class () extends GroupSubmissionResolver {
                    public function attemptNumberFor(int $assessmentId, int $studentId): int
                    {
                        return 1;
                    }
                };
            }
        };

        $this->assertSame(['success' => false, 'message' => "You've already submitted this assignment."], $racing->submit($this->u[0], $this->assignment, ['submission_text' => 'Same moment']));
        $this->assertCount(1, $this->rows());
    }

    public function testFinalizedOrClosedOfferingStillRefusesAsBefore(): void
    {
        $group = $this->group([0, 1]);
        $this->update(['status' => 'Closed']);

        $this->assertSame(['success' => false, 'message' => 'This assignment is closed.'], $this->submit(0));
        $this->assertFalse($this->frozen($group));

        // Grade finalization never blocked a student's hand-in; a group member gets the same answer as a solo student.
        $this->update(['status' => 'Published']);
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);
        $plain = $this->assignment();

        $this->assertSame($this->submit(2, 'Solo', $plain), $this->submit(0));
    }

    public function testGroupMateCanOpenTheSharedSubmissionFile(): void
    {
        $group = $this->group([0, 1]);
        db_connect()->table('files')->insert(['owner_user_id' => $this->u[0]->id, 'original_filename' => 'w.pdf', 'stored_filename' => 'w.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 1, 'storage_key' => 'assignment-submissions/w.pdf', 'status' => 'active']);
        $file = (int) db_connect()->insertID();
        db_connect()->table('assessment_submissions')->insert(['assessment_id' => $this->assignment, 'student_id' => $this->u[0]->id, 'group_id' => $group, 'submitted_by_user_id' => $this->u[0]->id,
            'attempt_number' => 1, 'started_at' => date('Y-m-d H:i:s'), 'submitted_at' => date('Y-m-d H:i:s'), 'status' => 'Submitted', 'submission_file_id' => $file]);

        $allowed = new ReflectionMethod(FileAccessService::class, 'isAllowed');

        $this->assertTrue($allowed->invoke(new FileAccessService(), $this->u[1], $file, (int) $this->u[0]->id));
        $this->assertFalse($allowed->invoke(new FileAccessService(), $this->u[2], $file, (int) $this->u[0]->id));
    }
}
