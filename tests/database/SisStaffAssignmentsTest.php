<?php

declare(strict_types=1);

use App\Services\Sis\SisOwnership;
use App\Services\Sis\SisStaffAssignments;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The record of what a SIS supplied (sis_staff_assignments): one row per assignment, kept up to date rather than
 * repeated, read per institution and role, forgotten when it is over, and removed with its institution. And the
 * ownership questions built on it: it counts only while the institution's connection is enabled.
 *
 * @internal
 */
final class SisStaffAssignmentsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private SisStaffAssignments $staff;

    private int $institution;

    private int $other;

    private int $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff       = new SisStaffAssignments();
        $this->institution = SisFixture::institution('STA');
        $this->other       = SisFixture::institution('STB');
        $this->person      = (int) PeopleFactory::person('instructor', 'Ivy', 'Tan', null, $this->institution)->id;
    }

    private function connect(int $institution, bool $enabled): void
    {
        db_connect()->table('sis_connections')->insert([
            'institution_id' => $institution, 'connector' => 'file', 'enabled' => $enabled ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testTheThreeRolesAreTheOnesTheFileCanName(): void
    {
        $this->assertSame(['dean', 'chairperson', 'instructor'], SisStaffAssignments::roles());
    }

    public function testAnAssignmentIsRememberedOnceAndItsRowIsKeptUpToDateNotRepeated(): void
    {
        $this->staff->track($this->institution, 'chairperson', $this->person, 7, 100, '2026-10-10 09:00:00');
        $this->staff->track($this->institution, 'chairperson', $this->person, 7, 205, '2026-10-11 09:00:00');

        $this->assertSame(1, db_connect()->table('sis_staff_assignments')->countAllResults());

        $row = $this->staff->find($this->institution, 'chairperson', $this->person, 7);

        $this->assertSame(205, (int) $row['local_id'], 'it follows the assignment row it became');
        $this->assertSame('2026-10-10 09:00:00', $row['created_at']);
        $this->assertSame('2026-10-11 09:00:00', $row['updated_at']);
    }

    public function testTheSamePersonCanBeTrackedForDifferentThingsAndRoles(): void
    {
        $this->staff->track($this->institution, 'chairperson', $this->person, 7, 1, '2026-10-10 09:00:00');
        $this->staff->track($this->institution, 'chairperson', $this->person, 8, 2, '2026-10-10 09:00:00');
        $this->staff->track($this->institution, 'instructor', $this->person, 7, 3, '2026-10-10 09:00:00');

        $this->assertCount(3, $this->staff->listFor($this->institution));
        $this->assertCount(2, $this->staff->listFor($this->institution, 'chairperson'));
        $this->assertNull($this->staff->find($this->institution, 'dean', $this->person, 7));
        $this->assertNull($this->staff->find($this->institution, 'chairperson', $this->person, 9));
    }

    public function testEachInstitutionSeesOnlyItsOwn(): void
    {
        $this->staff->track($this->institution, 'dean', $this->person, 7, 1, '2026-10-10 09:00:00');
        $this->staff->track($this->other, 'dean', $this->person, 7, 2, '2026-10-10 09:00:00');

        $this->assertCount(1, $this->staff->listFor($this->institution));
        $this->assertSame(1, (int) $this->staff->find($this->institution, 'dean', $this->person, 7)['local_id']);
        $this->assertSame(2, (int) $this->staff->find($this->other, 'dean', $this->person, 7)['local_id']);
    }

    public function testListingIsOldestFirst(): void
    {
        $this->staff->track($this->institution, 'instructor', $this->person, 1, 11, '2026-10-10 09:00:00');
        $this->staff->track($this->institution, 'dean', $this->person, 2, 12, '2026-10-10 09:00:00');

        $this->assertSame([11, 12], array_map(static fn (array $r): int => (int) $r['local_id'], $this->staff->listFor($this->institution)));
    }

    public function testForgettingRemovesOnlyThatRow(): void
    {
        $this->staff->track($this->institution, 'dean', $this->person, 1, 11, '2026-10-10 09:00:00');
        $this->staff->track($this->institution, 'dean', $this->person, 2, 12, '2026-10-10 09:00:00');

        $this->staff->forget((int) $this->staff->find($this->institution, 'dean', $this->person, 1)['id']);

        $this->assertNull($this->staff->find($this->institution, 'dean', $this->person, 1));
        $this->assertNotNull($this->staff->find($this->institution, 'dean', $this->person, 2));
    }

    public function testTheRowsGoWithTheirInstitution(): void
    {
        $this->staff->track($this->other, 'dean', $this->person, 1, 11, '2026-10-10 09:00:00');

        db_connect()->table('institutions')->where('id', $this->other)->delete();

        $this->assertSame(0, db_connect()->table('sis_staff_assignments')->where('institution_id', $this->other)->countAllResults());
    }

    public function testAnAssignmentIsTheSisOnlyWhileItsConnectionIsEnabled(): void
    {
        $this->connect($this->institution, true);
        $this->staff->track($this->institution, 'chairperson', $this->person, 7, 100, '2026-10-10 09:00:00');
        $own = new SisOwnership();

        $this->assertTrue($own->managesAssignment('chairperson', 100));
        $this->assertFalse($own->managesAssignment('instructor', 100), 'the role is part of the question');
        $this->assertFalse($own->managesAssignment('chairperson', 101), 'and so is the row');
        $this->assertSame([100 => true], $own->managedAssignments('chairperson', $this->institution));

        db_connect()->table('sis_connections')->where('institution_id', $this->institution)->update(['enabled' => 0]);

        $this->assertFalse($own->managesAssignment('chairperson', 100));
        $this->assertSame([], $own->managedAssignments('chairperson', $this->institution));
    }

    public function testAnInstitutionWithNoConnectionOwnsNothing(): void
    {
        $this->staff->track($this->institution, 'dean', $this->person, 7, 100, '2026-10-10 09:00:00');

        $this->assertFalse((new SisOwnership())->managesAssignment('dean', 100));
    }

    public function testAnotherInstitutionsConnectionDoesNotLockThisOnesAssignments(): void
    {
        $this->connect($this->other, true);
        $this->staff->track($this->institution, 'dean', $this->person, 7, 100, '2026-10-10 09:00:00');

        $this->assertFalse((new SisOwnership())->managesAssignment('dean', 100));
        $this->assertSame([], (new SisOwnership())->managedAssignments('dean', $this->other));
    }
}
