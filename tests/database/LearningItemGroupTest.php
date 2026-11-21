<?php

declare(strict_types=1);

use App\Services\Courses\LearningProgressService;
use App\Services\Groups\ActivityGroupMembershipService;
use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupMembershipService;
use App\Services\Groups\GroupSetService;
use App\Services\Institutions\StructureException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Groups on ungraded learning items (D12, D13, D26): grouping only, never frozen, completion "any" or "each".
 *
 * @internal
 */
final class LearningItemGroupTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ActivityGroupService $service;

    private ActivityGroupMembershipService $members;

    private int $offering;

    private int $module;

    private int $teacher;

    private $instructor;

    /** @var list<\CodeIgniter\Shield\Entities\User> */
    private array $users = [];

    /** @var list<int> */
    private array $students = [];

    private int $set;

    private int $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service    = new ActivityGroupService();
        $this->members    = new ActivityGroupMembershipService();
        $this->offering   = CourseFactory::offering();
        $this->module     = CourseFactory::module($this->offering);
        $this->instructor = CourseFactory::user('instructor');
        $this->teacher    = (int) $this->instructor->id;

        for ($i = 0; $i < 4; $i++) {
            $student          = CourseFactory::user();
            $this->users[]    = $student;
            $this->students[] = (int) $student->id;
            CourseFactory::enroll($this->offering, $student);
        }

        $sets = new GroupSetService();
        $this->set = $sets->create($this->offering, ['name' => 'Set', 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 3], $this->teacher);
        $a = $sets->addGroup($this->set, 'A', $this->teacher);
        $b = $sets->addGroup($this->set, 'B', $this->teacher);
        $m = new GroupMembershipService();
        $m->addToSet($a, $this->students[0], $this->teacher);
        $m->addToSet($a, $this->students[1], $this->teacher);
        $m->addToSet($b, $this->students[2], $this->teacher);

        $unit       = CourseFactory::unit($this->module);
        $this->item = CourseFactory::items($unit, [['title' => 'Reading', 'type' => 'reading']])[0];
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

    private function groupId(int $configId, string $name): int
    {
        return (int) db_connect()->table('student_groups')->where('config_id', $configId)->where('name', $name)->get()->getRowArray()['id'];
    }

    private function completed(int $student, ?int $item = null): bool
    {
        return db_connect()->table('learning_completions')->where('learning_item_id', $item ?? $this->item)->where('student_user_id', $student)->where('status', 'completed')->countAllResults() === 1;
    }

    private function markComplete(int $index): array
    {
        return (new LearningProgressService())->markComplete($this->users[$index], $this->offering, $this->item);
    }

    public function testAttachRefusesQuizAndAssignmentItems(): void
    {
        $unit = CourseFactory::unit($this->module, 'published', 2);
        [$quizItem, $assignmentItem] = CourseFactory::items($unit, [['type' => 'quiz'], ['type' => 'assignment']]);
        CourseFactory::assessment($this->offering, $this->instructor, $this->module, $quizItem, 'Quiz');
        CourseFactory::assessment($this->offering, $this->instructor, $this->module, $assignmentItem, 'Assignment');

        foreach ([$quizItem, $assignmentItem] as $id) {
            $e = $this->refused(fn () => $this->service->attachLearningItem($id, $this->set, 'any', $this->teacher));
            $this->assertStringContainsString('reading', $e->getMessage());
        }

        $this->assertSame(0, db_connect()->table('activity_group_configs')->where('learning_item_id IS NOT NULL', null, false)->countAllResults());
    }

    public function testAttachCopiesSetIndependently(): void
    {
        $id     = $this->service->attachLearningItem($this->item, $this->set, 'any', $this->teacher);
        $config = $this->service->findByLearningItem($this->item);

        $this->assertSame($id, (int) $config['id']);
        $this->assertSame($this->set, (int) $config['source_set_id']);
        $this->assertSame('any', $config['completion_mode']);
        $this->assertNull($config['assessment_id']);
        $this->assertSame(3, db_connect()->table('student_group_members')->where('config_id', $id)->countAllResults());

        db_connect()->table('student_group_members')->where('set_id', $this->set)->delete();
        $this->assertSame(3, db_connect()->table('student_group_members')->where('config_id', $id)->countAllResults());

        $this->refused(fn () => $this->service->attachLearningItem($this->item, null, 'any', $this->teacher));
    }

    public function testPoolIsOfferingPool(): void
    {
        $id = $this->service->attachLearningItem($this->item, null, 'each', $this->teacher);

        $pool = $this->service->pool($id);
        sort($pool);
        $this->assertSame($this->students, $pool);
    }

    public function testGroupsNeverFreezeOnItems(): void
    {
        $id = $this->service->attachLearningItem($this->item, $this->set, 'any', $this->teacher);
        $a  = $this->groupId($id, 'A');
        $b  = $this->groupId($id, 'B');

        $this->members->freezeGroup($a);
        $this->assertNull(db_connect()->table('student_groups')->where('id', $a)->get()->getRowArray()['frozen_at']);

        $this->members->moveMember($this->students[0], $b, $this->teacher);
        $this->members->removeMember($this->students[1], $id, $this->teacher);
        $this->members->addMember($a, $this->students[3], $this->teacher);
        $this->members->addLateEnrollee($a, $this->students[1], 'Joined late', $this->teacher);
        $this->service->detach($id, $this->teacher);

        $this->assertNull($this->service->findByLearningItem($this->item));
    }

    public function testAnyModeCompletesAllMembers(): void
    {
        $id = $this->service->attachLearningItem($this->item, $this->set, 'any', $this->teacher);

        $this->assertTrue($this->markComplete(0)['success']);

        $this->assertTrue($this->completed($this->students[0]));
        $this->assertTrue($this->completed($this->students[1]));
        $this->assertFalse($this->completed($this->students[2]));
        $row = db_connect()->table('learning_completions')->where('student_user_id', $this->students[1])->get()->getRowArray();
        $this->assertSame(100, (int) $row['progress_percent']);
        $this->assertNotNull($row['completed_at']);
        $this->assertGreaterThan(0, $id);
    }

    public function testEachModeCompletesOnlyTheStudent(): void
    {
        $this->service->attachLearningItem($this->item, $this->set, 'each', $this->teacher);
        $this->markComplete(0);

        $this->assertTrue($this->completed($this->students[0]));
        $this->assertFalse($this->completed($this->students[1]));
    }

    public function testLeaverKeepsCompletion(): void
    {
        $id = $this->service->attachLearningItem($this->item, $this->set, 'any', $this->teacher);
        $this->markComplete(0);

        $this->members->removeMember($this->students[1], $id, $this->teacher);

        $this->assertTrue($this->completed($this->students[1]));
    }

    public function testNewJoinerGetsCompletionWhenGroupDone(): void
    {
        $id = $this->service->attachLearningItem($this->item, $this->set, 'any', $this->teacher);
        $this->markComplete(0);

        $this->members->addMember($this->groupId($id, 'A'), $this->students[3], $this->teacher);

        $this->assertTrue($this->completed($this->students[3]));
    }

    public function testNewJoinerGetsNothingWhenNobodyCompleted(): void
    {
        $id = $this->service->attachLearningItem($this->item, $this->set, 'any', $this->teacher);

        $this->members->addMember($this->groupId($id, 'A'), $this->students[3], $this->teacher);

        $this->assertSame(0, db_connect()->table('learning_completions')->where('learning_item_id', $this->item)->countAllResults());
    }

    public function testPrerequisiteSeesGroupCompletion(): void
    {
        $unit = CourseFactory::unit($this->module, 'published', 2);
        [$first, $second] = CourseFactory::items($unit, [['type' => 'reading'], ['mode' => 'previous']]);
        $this->service->attachLearningItem($first, $this->set, 'any', $this->teacher);
        $progress = new LearningProgressService();

        $this->assertNotNull($progress->lockFor($this->users[1], $second));
        $this->assertTrue($progress->markComplete($this->users[0], $this->offering, $first)['success']);
        $this->assertNull($progress->lockFor($this->users[1], $second));
    }

    public function testCompletionIsAtomicAndNoDuplicateRows(): void
    {
        $this->service->attachLearningItem($this->item, $this->set, 'any', $this->teacher);
        CourseFactory::progress($this->item, $this->users[1], 'in_progress', 40);

        $this->markComplete(0);
        (new LearningProgressService())->completeForGroup($this->item, $this->students[0]);

        $this->assertSame(2, db_connect()->table('learning_completions')->where('learning_item_id', $this->item)->countAllResults());
        $this->assertTrue($this->completed($this->students[1]));
    }

    public function testFinalizedOfferingRefusesAttach(): void
    {
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $this->refused(fn () => $this->service->attachLearningItem($this->item, $this->set, 'any', $this->teacher));
        $this->assertNull($this->service->findByLearningItem($this->item));
    }

    public function testDetachRemovesGroups(): void
    {
        $id = $this->service->attachLearningItem($this->item, $this->set, 'any', $this->teacher);
        $this->service->detach($id, $this->teacher);

        $this->assertNull($this->service->findByLearningItem($this->item));
        $this->assertSame(0, db_connect()->table('student_groups')->where('config_id', $id)->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'groups.activity_detached')->countAllResults());
    }

    public function testAuditRow(): void
    {
        $this->service->attachLearningItem($this->item, $this->set, 'any', $this->teacher);

        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'groups.activity_attached')->countAllResults());
    }
}
