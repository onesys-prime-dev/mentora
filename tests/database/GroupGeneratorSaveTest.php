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
final class GroupGeneratorSaveTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GroupSetService $sets;

    private int $offering;

    private int $teacher;

    private int $set;

    /** @var list<int> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sets     = new GroupSetService();
        $this->offering = CourseFactory::offering();
        $this->teacher  = (int) CourseFactory::user('instructor')->id;
        $this->set      = $this->sets->create($this->offering, ['name' => 'S', 'formation_mode' => 'auto', 'min_size' => 1, 'max_size' => 2], $this->teacher);

        for ($i = 0; $i < 5; $i++) {
            $user = CourseFactory::user();
            CourseFactory::enroll($this->offering, $user);
            $this->students[] = (int) $user->id;
        }
    }

    private function preview(): array
    {
        return ['groups' => [[$this->students[0], $this->students[1]], [$this->students[2], $this->students[3]]], 'ungrouped' => [$this->students[4]], 'conflicts' => []];
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

    private function state(): array
    {
        $groups  = db_connect()->table('student_groups')->where('set_id', $this->set)->orderBy('id')->get()->getResultArray();
        $members = db_connect()->table('student_group_members')->where('set_id', $this->set)->orderBy('id')->get()->getResultArray();

        return [$groups, $members];
    }

    private function audited(): int
    {
        return db_connect()->table('audit_logs')->where('action', 'groups.generated')->countAllResults();
    }

    public function testSaveWritesOneAuditRow(): void
    {
        $this->sets->saveGenerated($this->set, $this->preview(), false, $this->teacher);

        [$groups, $members] = $this->state();
        $this->assertSame(['Group 1', 'Group 2'], array_column($groups, 'name'));
        $this->assertCount(4, $members);
        $this->assertSame(1, $this->audited());
    }

    public function testSaveRefusesReplaceWithoutFlag(): void
    {
        $this->sets->saveGenerated($this->set, $this->preview(), false, $this->teacher);
        $before = $this->state();

        $this->refused(fn () => $this->sets->saveGenerated($this->set, $this->preview(), false, $this->teacher));
        $this->assertSame($before, $this->state());
    }

    public function testSaveReplacesExistingGroupsAtomically(): void
    {
        $g = $this->sets->addGroup($this->set, 'Old', $this->teacher);
        (new GroupMembershipService())->addToSet($g, $this->students[4], $this->teacher);
        $before = $this->state();

        $stranger = (int) CourseFactory::user()->id;
        $bad      = ['groups' => [[$this->students[0], $stranger]], 'ungrouped' => [], 'conflicts' => []];

        $this->refused(fn () => $this->sets->saveGenerated($this->set, $bad, true, $this->teacher));
        $this->assertSame($before, $this->state());
        $this->assertSame(0, $this->audited());

        $this->sets->saveGenerated($this->set, $this->preview(), true, $this->teacher);
        [$groups, $members] = $this->state();
        $this->assertSame(['Group 1', 'Group 2'], array_column($groups, 'name'));
        $this->assertCount(4, $members);
        $this->assertSame(1, $this->audited());
    }

    public function testSaveRefusedWhenFinalized(): void
    {
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $this->refused(fn () => $this->sets->saveGenerated($this->set, $this->preview(), false, $this->teacher));
        $this->assertSame([[], []], $this->state());
    }

    public function testSavedGroupsRespectMaxSize(): void
    {
        $tooBig = ['groups' => [[$this->students[0], $this->students[1], $this->students[2]]], 'ungrouped' => [], 'conflicts' => []];

        $this->refused(fn () => $this->sets->saveGenerated($this->set, $tooBig, false, $this->teacher));
        $this->assertSame([[], []], $this->state());
    }
}
