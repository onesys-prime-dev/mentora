<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionSnapshot;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The numbers on the institutional administrator's home page, against known
 * fixtures, and that another institution's people never leak in.
 *
 * @internal
 */
final class InstitutionSnapshotTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    public function testAnEmptyInstitutionIsAllZerosWithNoActiveTerm(): void
    {
        $id = SisFixture::institution('SNAPEMPTY', false);

        $snapshot = (new InstitutionSnapshot())->for($id);

        $this->assertSame(0, $snapshot['peopleTotal']);
        $this->assertSame(0, $snapshot['courses']);
        $this->assertNull($snapshot['activeTerm']);
        $this->assertSame([0, 0], [$snapshot['offerings'], $snapshot['enrolments']]);
        $this->assertSame(0, array_sum($snapshot['people']));
    }

    public function testPeopleAreCountedByRoleAndOnlyForThisInstitution(): void
    {
        $id    = SisFixture::institution('SNAPA');
        $other = SisFixture::institution('SNAPB');

        PeopleFactory::person('student', 'S', 'One', null, $id);
        PeopleFactory::person('student', 'S', 'Two', null, $id);
        PeopleFactory::person('instructor', 'I', 'One', null, $id);
        PeopleFactory::person('chairperson', 'C', 'One', null, $id);
        PeopleFactory::person('institutional_admin', 'A', 'One', null, $id);
        PeopleFactory::person('student', 'S', 'Elsewhere', null, $other);

        $removed = PeopleFactory::person('student', 'S', 'Gone', null, $id);
        db_connect()->table('users')->where('id', $removed->id)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $snapshot = (new InstitutionSnapshot())->for($id);

        $this->assertSame(2, $snapshot['people']['student']);
        $this->assertSame(1, $snapshot['people']['instructor']);
        $this->assertSame(1, $snapshot['people']['chairperson']);
        $this->assertSame(1, $snapshot['people']['institutional_admin']);
        $this->assertSame(0, $snapshot['people']['parent']);
        $this->assertSame(5, $snapshot['peopleTotal']);
    }

    public function testCoursesOfferingsAndEnrolmentsAreCountedForTheActiveTerm(): void
    {
        $id     = SisFixture::institution('SNAPC');
        $course = SisFixture::course($id, 'C1', 'IT101');
        $second = SisFixture::course($id, 'C2', 'IT102');

        $first  = PeopleFactory::person('student', 'Stu', 'One', null, $id);
        $second2 = PeopleFactory::person('student', 'Stu', 'Two', null, $id);
        $third  = PeopleFactory::person('student', 'Stu', 'Three', null, $id);

        CourseFactory::enroll($course['offering'], $first);
        CourseFactory::enroll($course['offering'], $second2);
        CourseFactory::enroll($second['offering'], $third, 'dropped');

        db_connect()->table('courses')->where('id', $second['course'])->update(['status' => 'archived']);

        $snapshot = (new InstitutionSnapshot())->for($id);

        $this->assertSame(1, $snapshot['courses'], 'an archived course is not counted');
        $this->assertSame('First Term', $snapshot['activeTerm']['name']);
        $this->assertSame(2, $snapshot['offerings']);
        $this->assertSame(2, $snapshot['enrolments'], 'only enrolled students');
    }

    public function testAnotherInstitutionsTermAndCoursesAreNotCounted(): void
    {
        $id    = SisFixture::institution('SNAPD');
        $other = SisFixture::institution('SNAPE');
        SisFixture::course($other, 'C9', 'IT900');

        $snapshot = (new InstitutionSnapshot())->for($id);

        $this->assertSame(0, $snapshot['courses']);
        $this->assertSame(0, $snapshot['offerings']);
    }
}
