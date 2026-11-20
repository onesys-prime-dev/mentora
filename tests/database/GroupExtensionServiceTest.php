<?php

declare(strict_types=1);

use App\Services\Groups\ActivityGroupService;
use App\Services\Groups\GroupExtensionService;
use App\Services\Institutions\StructureException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Giving one group more time on an assignment.
 *
 * @internal
 */
final class GroupExtensionServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private GroupExtensionService $svc;

    private int $offering;

    private int $teacher;

    private int $assignment;

    private int $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = new GroupExtensionService();
        $this->offering = CourseFactory::offering();
        $module         = CourseFactory::module($this->offering);
        $instructor     = CourseFactory::user('instructor');
        $this->teacher  = (int) $instructor->id;

        $this->assignment = CourseFactory::assessment($this->offering, $instructor, $module, null, 'Assignment');
        $config           = (new ActivityGroupService())->attachAssessment($this->assignment, null, ['formation_mode' => 'manual'], $this->teacher);
        $this->group      = (new ActivityGroupService())->addGroup($config, 'Team', $this->teacher);
    }

    private function refuses(callable $fn): void
    {
        try {
            $fn();
        } catch (StructureException $e) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected a StructureException.');
    }

    public function testEffectiveDueUsesExtension(): void
    {
        $this->svc->extend($this->group, $this->assignment, '2026-12-20 10:00:00', 'Power outage', $this->teacher);
        $this->assertSame('2026-12-20 10:00:00', $this->svc->extensionFor($this->group, $this->assignment)['until_at']);

        $this->svc->extend($this->group, $this->assignment, '2026-12-25 10:00:00', 'More time', $this->teacher);

        $this->assertSame(1, db_connect()->table('group_extensions')->countAllResults());
        $this->assertSame('2026-12-25 10:00:00', $this->svc->extensionFor($this->group, $this->assignment)['until_at']);
    }

    public function testExtensionNeedsReasonAndLaterDate(): void
    {
        $this->refuses(fn () => $this->svc->extend($this->group, $this->assignment, '2026-12-20 10:00:00', '  ', $this->teacher));
        $this->refuses(fn () => $this->svc->extend($this->group, $this->assignment, '2026-12-20 10:00:00', str_repeat('a', 501), $this->teacher));
        $this->refuses(fn () => $this->svc->extend($this->group, $this->assignment, '2026-12-01 23:59:00', 'Same as due', $this->teacher));
        $this->refuses(fn () => $this->svc->extend($this->group, $this->assignment, 'not a date', 'Why', $this->teacher));
        $this->assertNull($this->svc->extensionFor($this->group, $this->assignment));
    }

    public function testExtensionOnlyForGroupOfThatActivity(): void
    {
        $other = CourseFactory::assessment($this->offering, CourseFactory::user('instructor'), null, null, 'Assignment');

        $this->refuses(fn () => $this->svc->extend($this->group, $other, '2026-12-20 10:00:00', 'Wrong activity', $this->teacher));
        $this->assertNull($this->svc->extensionFor($this->group, $other));
    }

    public function testExtensionAudited(): void
    {
        $this->svc->extend($this->group, $this->assignment, '2026-12-20 10:00:00', 'Power outage', $this->teacher);

        $row = db_connect()->table('audit_logs')->where('action', 'groups.deadline_extended')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertStringContainsString('Power outage', json_encode($row));
    }

    public function testFinalizedOfferingRefuses(): void
    {
        db_connect()->table('grade_finalizations')->insert(['course_offering_id' => $this->offering, 'finalized_by' => 1, 'finalized_at' => date('Y-m-d H:i:s'), 'open_marker' => 1]);

        $this->refuses(fn () => $this->svc->extend($this->group, $this->assignment, '2026-12-20 10:00:00', 'Late', $this->teacher));
        $this->assertNull($this->svc->extensionFor($this->group, $this->assignment));
    }
}
