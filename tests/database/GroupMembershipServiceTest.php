<?php

declare(strict_types=1);

use App\Services\Groups\GroupMembershipService;
use App\Services\Groups\GroupSetService;
use App\Services\Institutions\StructureException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class GroupMembershipServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GroupMembershipService $members;

    private GroupSetService $sets;

    private int $offering;

    private int $teacher;

    private int $set;

    private int $a;

    private int $b;

    private int $ada;

    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->members  = new GroupMembershipService();
        $this->sets     = new GroupSetService();
        $this->offering = CourseFactory::offering();
        $this->teacher  = (int) CourseFactory::user('instructor')->id;
        $this->ada      = $this->student();
        $this->bob      = $this->student();
        $this->set      = $this->sets->create($this->offering, ['name' => 'S', 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 1], $this->teacher);
        $this->a        = $this->sets->addGroup($this->set, 'A', $this->teacher);
        $this->b        = $this->sets->addGroup($this->set, 'B', $this->teacher);
    }

    private function student(string $status = 'enrolled'): int
    {
        $user = CourseFactory::user();
        CourseFactory::enroll($this->offering, $user, $status);

        return (int) $user->id;
    }

    private function refused(callable $fn): void
    {
        try {
            $fn();
        } catch (StructureException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected a StructureException.');
    }

    private function groupOf(int $student): ?int
    {
        $row = db_connect()->table('student_group_members')->where('student_user_id', $student)->get()->getRowArray();

        return $row === null ? null : (int) $row['group_id'];
    }

    public function testPoolIncludesEnrolledAndCompletedOnly(): void
    {
        $done = $this->student('completed');
        $this->student('dropped');
        $this->student('withdrawn');

        $pool = $this->members->poolForOffering($this->offering);
        sort($pool);

        $this->assertSame([$this->ada, $this->bob, $done], $pool);
    }

    public function testDroppedAndUnenrolledStudentsRefused(): void
    {
        $dropped    = $this->student('dropped');
        $unenrolled = (int) CourseFactory::user()->id;

        $this->refused(fn () => $this->members->addToSet($this->a, $dropped, $this->teacher));
        $this->refused(fn () => $this->members->addToSet($this->a, $unenrolled, $this->teacher));
    }

    public function testGroupOverMaxSizeRefused(): void
    {
        $this->members->addToSet($this->a, $this->ada, $this->teacher);

        $this->refused(fn () => $this->members->addToSet($this->a, $this->bob, $this->teacher));
    }

    public function testStudentAlreadyInAnotherGroupOfSetRefused(): void
    {
        $this->members->addToSet($this->a, $this->ada, $this->teacher);

        $this->refused(fn () => $this->members->addToSet($this->b, $this->ada, $this->teacher));
        $this->assertSame($this->a, $this->groupOf($this->ada));
    }

    public function testStudentCannotJoinGroupOfAnotherOfferingsSet(): void
    {
        $other    = CourseFactory::offering('B');
        $otherSet = $this->sets->create($other, ['name' => 'X', 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 5], $this->teacher);
        $group    = $this->sets->addGroup($otherSet, 'G', $this->teacher);

        $this->refused(fn () => $this->members->addToSet($group, $this->ada, $this->teacher));
    }

    public function testMoveChangesGroup(): void
    {
        $this->members->addToSet($this->a, $this->ada, $this->teacher);
        $this->members->moveInSet($this->ada, $this->b, $this->teacher);

        $this->assertSame($this->b, $this->groupOf($this->ada));
        $this->assertSame(1, db_connect()->table('student_group_members')->countAllResults());
    }

    public function testFailingMoveLeavesStudentInOriginalGroup(): void
    {
        $this->members->addToSet($this->a, $this->ada, $this->teacher);
        $this->members->addToSet($this->b, $this->bob, $this->teacher);

        $this->refused(fn () => $this->members->moveInSet($this->ada, $this->b, $this->teacher));

        $this->assertSame($this->a, $this->groupOf($this->ada));
        $this->assertSame($this->b, $this->groupOf($this->bob));
    }

    public function testRemoveFromSet(): void
    {
        $this->members->addToSet($this->a, $this->ada, $this->teacher);
        $this->members->removeFromSet($this->ada, $this->set, $this->teacher);

        $this->assertNull($this->groupOf($this->ada));
    }

    public function testFinalizedOfferingRefusesWrites(): void
    {
        $this->members->addToSet($this->a, $this->ada, $this->teacher);
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $this->refused(fn () => $this->members->addToSet($this->b, $this->bob, $this->teacher));
        $this->refused(fn () => $this->members->moveInSet($this->ada, $this->b, $this->teacher));
        $this->refused(fn () => $this->members->removeFromSet($this->ada, $this->set, $this->teacher));
        $this->assertSame($this->a, $this->groupOf($this->ada));
    }

    public function testEachChangeWritesAuditRow(): void
    {
        $this->members->addToSet($this->a, $this->ada, $this->teacher);
        $this->members->moveInSet($this->ada, $this->b, $this->teacher);
        $this->members->removeFromSet($this->ada, $this->set, $this->teacher);

        foreach (['member_added', 'member_moved', 'member_removed'] as $action) {
            $this->assertSame(1, db_connect()->table('audit_logs')->where('action', "groups.{$action}")->countAllResults(), $action);
        }

        $moved = db_connect()->table('audit_logs')->where('action', 'groups.member_moved')->get()->getRowArray();
        $this->assertStringContainsString((string) $this->a, $moved['old_values']);
        $this->assertStringContainsString((string) $this->b, $moved['new_values']);
    }
}
