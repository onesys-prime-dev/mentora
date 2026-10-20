<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeAudit;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The gradebook's audit trail: each entry names the action, the section, who
 * did it and in which role, and what changed.
 *
 * @internal
 */
final class GradeAuditTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private function entries(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id')->get()->getResultArray();
    }

    public function testAnEntryNamesTheActionTheSectionAndWhoDidIt(): void
    {
        $offering = CourseFactory::offering();
        $teacher = CourseFactory::user('instructor');

        GradeAudit::record('grades.manual_score', $offering, [
            'old' => ['score' => 10],
            'new' => ['score' => 12],
        ], $teacher);

        $entries = $this->entries('grades.manual_score');

        $this->assertCount(1, $entries);
        $this->assertSame('course_offering', $entries[0]['target_type']);
        $this->assertSame((string) $offering, $entries[0]['target_id']);
        $this->assertSame((int) $teacher->id, (int) $entries[0]['actor_user_id']);
        $this->assertNotSame('', (string) $entries[0]['acting_role']);
        $this->assertSame(['score' => 10], json_decode($entries[0]['old_values'], true));
        $this->assertSame(['score' => 12], json_decode($entries[0]['new_values'], true));
    }

    public function testTheActingRoleIsWhoeverActsNotAlwaysTheInstructor(): void
    {
        $offering = CourseFactory::offering();
        $chair = CourseFactory::user('chairperson');

        GradeAudit::record('grades.recalculated', $offering, ['new' => ['students' => 3]], $chair);

        $entry = $this->entries('grades.recalculated')[0];

        $this->assertSame((int) $chair->id, (int) $entry['actor_user_id']);
        $this->assertStringContainsString('chair', strtolower((string) $entry['acting_role']), 'a chairperson\'s edit says so');
    }

    public function testWithoutAnActorTheEntryIsStillWrittenForTheSystem(): void
    {
        $offering = CourseFactory::offering();

        GradeAudit::record('grades.recalculated', $offering, ['new' => ['students' => 1]]);

        $entry = $this->entries('grades.recalculated')[0];

        $this->assertNull($entry['actor_user_id']);
        $this->assertSame((string) $offering, $entry['target_id']);
    }

    public function testASecretLookingValueIsNeverStored(): void
    {
        $offering = CourseFactory::offering();

        GradeAudit::record('grades.scheme_updated', $offering, ['new' => ['name' => 'Scheme', 'api_key' => 'abc123']], CourseFactory::user('instructor'));

        $stored = json_decode($this->entries('grades.scheme_updated')[0]['new_values'], true);

        $this->assertSame('Scheme', $stored['name']);
        $this->assertNotSame('abc123', $stored['api_key']);
    }

    public function testTheTargetCanBeSomethingMoreSpecificThanTheSection(): void
    {
        $offering = CourseFactory::offering();

        GradeAudit::record('grades.item_restored', $offering, ['target_type' => 'grade_item', 'target_id' => 42], CourseFactory::user('instructor'));

        $entry = $this->entries('grades.item_restored')[0];

        $this->assertSame('grade_item', $entry['target_type']);
        $this->assertSame('42', $entry['target_id']);
    }
}
