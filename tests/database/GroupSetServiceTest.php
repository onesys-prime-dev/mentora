<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeFinalizationService;
use App\Services\Groups\GroupLimits;
use App\Services\Groups\GroupMembershipService;
use App\Services\Groups\GroupSetService;
use App\Services\Institutions\StructureException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class GroupSetServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GroupSetService $service;

    private int $offering;

    private int $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service  = new GroupSetService();
        $this->offering = CourseFactory::offering();
        $this->teacher  = (int) CourseFactory::user('instructor')->id;
    }

    private function data(array $over = []): array
    {
        return $over + ['name' => 'Set', 'formation_mode' => 'manual', 'min_size' => 2, 'max_size' => 4];
    }

    private function audited(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
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

    public function test21stSetRefused(): void
    {
        for ($i = 0; $i < GroupLimits::MAX_SETS_PER_OFFERING; $i++) {
            $this->service->create($this->offering, $this->data(['name' => "S{$i}"]), $this->teacher);
        }

        $this->assertStringContainsString("at most", $this->refused(fn () => $this->service->create($this->offering, $this->data(), $this->teacher))->getMessage());
    }

    public function testArchivedSetsDoNotCount(): void
    {
        $first = $this->service->create($this->offering, $this->data(), $this->teacher);

        for ($i = 1; $i < GroupLimits::MAX_SETS_PER_OFFERING; $i++) {
            $this->service->create($this->offering, $this->data(['name' => "S{$i}"]), $this->teacher);
        }

        $this->service->archive($first, $this->teacher);

        $this->assertGreaterThan(0, $this->service->create($this->offering, $this->data(), $this->teacher));
    }

    public function testInvalidSizeRangeRefused(): void
    {
        $this->assertArrayHasKey('max_size', $this->refused(fn () => $this->service->create($this->offering, $this->data(['min_size' => 5, 'max_size' => 3]), $this->teacher))->errors());
        $this->assertArrayHasKey('max_size', $this->refused(fn () => $this->service->create($this->offering, $this->data(['max_size' => GroupLimits::MAX_MEMBERS + 1]), $this->teacher))->errors());
        $this->assertArrayHasKey('min_size', $this->refused(fn () => $this->service->create($this->offering, $this->data(['min_size' => 0]), $this->teacher))->errors());
    }

    public function testNameModeAndSignupWindowValidated(): void
    {
        $this->assertArrayHasKey('name', $this->refused(fn () => $this->service->create($this->offering, $this->data(['name' => '']), $this->teacher))->errors());
        $this->assertArrayHasKey('name', $this->refused(fn () => $this->service->create($this->offering, $this->data(['name' => str_repeat('x', 151)]), $this->teacher))->errors());
        $this->assertArrayHasKey('formation_mode', $this->refused(fn () => $this->service->create($this->offering, $this->data(['formation_mode' => 'random']), $this->teacher))->errors());
        $this->assertArrayHasKey('signup_closes_at', $this->refused(fn () => $this->service->create($this->offering, $this->data(['signup_opens_at' => '2026-12-02 10:00:00', 'signup_closes_at' => '2026-12-01 10:00:00']), $this->teacher))->errors());
    }

    public function testGroupLimitPerSet(): void
    {
        $set = $this->service->create($this->offering, $this->data(), $this->teacher);

        for ($i = 0; $i < GroupLimits::MAX_GROUPS_PER_ACTIVITY; $i++) {
            $this->service->addGroup($set, "G{$i}", $this->teacher);
        }

        $this->assertStringContainsString("at most", $this->refused(fn () => $this->service->addGroup($set, "One too many", $this->teacher))->getMessage());
    }

    public function testDeleteGroupRemovesMembers(): void
    {
        $ada = CourseFactory::user();
        CourseFactory::enroll($this->offering, $ada);
        $set   = $this->service->create($this->offering, $this->data(), $this->teacher);
        $group = $this->service->addGroup($set, 'A', $this->teacher);
        (new GroupMembershipService())->addToSet($group, (int) $ada->id, $this->teacher);

        $this->service->deleteGroup($group, $this->teacher);

        $this->assertSame(0, db_connect()->table('student_group_members')->countAllResults());
        $this->assertSame(0, db_connect()->table('student_groups')->countAllResults());
    }

    public function testFinalizedOfferingRefusesWrites(): void
    {
        $set = $this->service->create($this->offering, $this->data(), $this->teacher);
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $e = $this->refused(fn () => $this->service->create($this->offering, $this->data(), $this->teacher));
        $this->assertSame(GradeFinalizationService::LOCKED_MESSAGE, $e->getMessage());
        $this->refused(fn () => $this->service->addGroup($set, 'G', $this->teacher));
        $this->refused(fn () => $this->service->update($set, ['name' => 'New'], $this->teacher));
        $this->refused(fn () => $this->service->archive($set, $this->teacher));
    }

    public function testEachWriteAuditsWithOldAndNew(): void
    {
        $set = $this->service->create($this->offering, $this->data(), $this->teacher);
        $this->service->update($set, ['name' => 'Renamed'], $this->teacher);
        $group = $this->service->addGroup($set, 'A', $this->teacher);
        $this->service->renameGroup($group, 'B', $this->teacher);
        $this->service->deleteGroup($group, $this->teacher);
        $this->service->archive($set, $this->teacher);

        foreach (['set_created', 'set_updated', 'group_added', 'group_renamed', 'group_deleted', 'set_archived'] as $action) {
            $this->assertSame(1, $this->audited("groups.{$action}"), $action);
        }

        $row = db_connect()->table('audit_logs')->where('action', 'groups.set_updated')->get()->getRowArray();
        $this->assertStringContainsString('"Set"', $row['old_values']);
        $this->assertStringContainsString('"Renamed"', $row['new_values']);
    }
}
