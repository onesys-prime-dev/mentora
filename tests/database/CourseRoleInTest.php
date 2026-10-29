<?php

declare(strict_types=1);

use App\Services\Courses\CourseMembership;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * A person's role in one course, whatever groups they hold (audit decision 9.1).
 *
 * @internal
 */
final class CourseRoleInTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $sub;

    private int $courseA;

    private int $courseB;

    protected function setUp(): void
    {
        parent::setUp();

        session()->destroy();

        $home              = DeanFactory::department('RI');
        $this->institution = $home['institution'];
        $this->sub         = DeanFactory::subDepartment($home['department'], 'RI');
        $program           = DeanFactory::program($this->sub, 'RI');
        $term              = DeanFactory::term($this->institution);
        $this->courseA     = DeanFactory::offering($program, $term, 'IT801');
        $this->courseB     = DeanFactory::offering($program, $term, 'IT802');
    }

    private function instructor(): User
    {
        return PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
    }

    public function testAnInstructorOfTheOfferingIsItsInstructor(): void
    {
        $teacher = $this->instructor();
        CourseFactory::assignInstructor($this->courseA, $teacher);

        $this->assertSame('instructor', (new CourseMembership())->roleIn($teacher, $this->courseA));
    }

    public function testAnInstructorOfAnotherCourseEnrolledHereIsAStudentHere(): void
    {
        $teacher = $this->instructor();
        $teacher->addGroup('student');
        CourseFactory::assignInstructor($this->courseA, $teacher);
        CourseFactory::enroll($this->courseB, $teacher);

        $this->assertSame('student', (new CourseMembership())->roleIn($teacher, $this->courseB));
    }

    public function testTheChairOfTheProgramIsItsChairperson(): void
    {
        $chair = DeanFactory::chair($this->sub, 'Maria');

        $this->assertSame('chairperson', (new CourseMembership())->roleIn($chair, $this->courseA));
    }

    public function testAChairWhoTeachesTheOfferingIsItsInstructor(): void
    {
        $chair = DeanFactory::chair($this->sub, 'Maria');
        $chair->addGroup('instructor');
        CourseFactory::assignInstructor($this->courseA, $chair);

        $this->assertSame('instructor', (new CourseMembership())->roleIn($chair, $this->courseA));
    }

    public function testSomeoneWithNoTieIsNothing(): void
    {
        $this->assertNull((new CourseMembership())->roleIn($this->instructor(), $this->courseA));
    }
}
