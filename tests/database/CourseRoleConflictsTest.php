<?php

declare(strict_types=1);

use App\Services\Courses\CourseRoleConflicts;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Nobody is both a student and staff of one course (audit decisions 9.2–9.5, D7, D10).
 *
 * @internal
 */
final class CourseRoleConflictsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $department;

    private int $sub;

    private int $term;

    private int $laterTerm;

    private int $offering;

    private CourseRoleConflicts $conflicts;

    protected function setUp(): void
    {
        parent::setUp();

        $home              = DeanFactory::department('CF');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->sub         = DeanFactory::subDepartment($this->department, 'CF');
        $this->term        = DeanFactory::term($this->institution, 'First', '2026-08-01');
        $this->laterTerm   = DeanFactory::term($this->institution, 'Second', '2027-01-10');
        $this->offering    = DeanFactory::offering(DeanFactory::program($this->sub, 'CF'), $this->term, 'IT110');
        $this->conflicts   = new CourseRoleConflicts();
    }

    private function person(string $role = 'student'): User
    {
        return PeopleFactory::person($role, 'Pat', 'Person', null, $this->institution);
    }

    /** Another section of the same course, in the given term. */
    private function section(int $term, string $code = 'B'): int
    {
        $course = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRow('course_id');
        db_connect()->table('course_offerings')->insert(['course_id' => $course, 'term_id' => $term, 'section_code' => $code, 'status' => 'active']);

        return (int) db_connect()->insertID();
    }

    public function testSomeoneWhoTeachesASectionCannotEnrolInAnother(): void
    {
        $person = $this->person('instructor');
        CourseFactory::assignInstructor($this->section($this->term), $person);

        $this->assertSame('teaches or has taught this course', $this->conflicts->forEnrolment((int) $person->id, $this->offering));
    }

    public function testSomeoneWhoTaughtTheCourseInAnEarlierTermCannotEnrol(): void
    {
        $person = $this->person('instructor');
        CourseFactory::assignInstructor($this->offering, $person);

        $this->assertSame('teaches or has taught this course', $this->conflicts->forEnrolment((int) $person->id, $this->section($this->laterTerm)));
    }

    public function testAChairOrFormerChairCannotEnrolInTheSubDepartmentsCourses(): void
    {
        $chair  = DeanFactory::chair($this->sub, 'Maria');
        $former = DeanFactory::chair(DeanFactory::subDepartment($this->department, 'CG'), 'Fred');
        db_connect()->table('chairperson_assignments')->insert(['sub_department_id' => $this->sub, 'chairperson_user_id' => $former->id, 'status' => 'revoked', 'assigned_at' => '2025-01-01 08:00:00']);

        $this->assertSame('chairs or has chaired the sub-department of this course', $this->conflicts->forEnrolment((int) $chair->id, $this->offering));
        $this->assertSame('chairs or has chaired the sub-department of this course', $this->conflicts->forEnrolment((int) $former->id, $this->offering));
    }

    public function testADeanOrFormerDeanCannotEnrolInTheDepartmentsCourses(): void
    {
        $dean   = DeanFactory::dean($this->institution, $this->department);
        $former = $this->person('instructor');
        db_connect()->table('dean_assignments')->insert(['department_id' => $this->department, 'dean_user_id' => $former->id, 'status' => 'ended', 'active_marker' => null, 'assigned_at' => '2025-01-01 08:00:00', 'ended_at' => '2025-06-01 08:00:00']);

        $this->assertSame("is or was the dean of this course's department", $this->conflicts->forEnrolment((int) $dean->id, $this->offering));
        $this->assertSame("is or was the dean of this course's department", $this->conflicts->forEnrolment((int) $former->id, $this->offering));
    }

    public function testAnAdministratorCannotEnrolInTheInstitutionsCourses(): void
    {
        $admin = $this->person('institutional_admin');

        $this->assertSame('is an administrator of this institution', $this->conflicts->forEnrolment((int) $admin->id, $this->offering));
    }

    public function testSomeoneWithNoTieMayEnrol(): void
    {
        $this->assertNull($this->conflicts->forEnrolment((int) $this->person()->id, $this->offering));
    }

    public function testAFinishedEnrolmentClashesOnlyWithTeachingItThatTerm(): void
    {
        $person = $this->person('instructor');
        $later  = $this->section($this->laterTerm);
        CourseFactory::assignInstructor($later, $person);

        $this->assertNull($this->conflicts->forExistingEnrolment((int) $person->id, $this->offering, 'completed'), 'taught it later: a former student');
        $this->assertSame(CourseRoleConflicts::TAUGHT, $this->conflicts->forExistingEnrolment((int) $person->id, $later, 'completed'));
        $this->assertSame(CourseRoleConflicts::TAUGHT, $this->conflicts->forExistingEnrolment((int) $person->id, $this->offering, 'enrolled'));
    }

    public function testAStudentCannotTeachTheCourseInTheSameTerm(): void
    {
        $person = $this->person();
        CourseFactory::enroll($this->offering, $person);

        $this->assertSame('is enrolled in this course this term', $this->conflicts->forTeaching((int) $person->id, $this->section($this->term)));
    }

    public function testAFormerStudentMayTeachTheCourseInALaterTerm(): void
    {
        $person = $this->person();
        CourseFactory::enroll($this->offering, $person, 'completed');

        $this->assertNull($this->conflicts->forTeaching((int) $person->id, $this->section($this->laterTerm)));
    }

    public function testAnEnrolledStudentCannotBecomeChairDeanOrAdministratorThere(): void
    {
        $person = $this->person();
        CourseFactory::enroll($this->offering, $person);

        $this->assertSame('is enrolled in a course of this sub-department', $this->conflicts->forChairing((int) $person->id, $this->sub));
        $this->assertSame('is enrolled in a course of this department', $this->conflicts->forDean((int) $person->id, $this->department));
        $this->assertSame('is enrolled in a course of this institution', $this->conflicts->forAdministrator((int) $person->id, $this->institution));
    }

    public function testAnEnrolmentEndedInAPastTermBlocksNoStaffRole(): void
    {
        $person = $this->person();
        CourseFactory::enroll($this->offering, $person, 'completed');

        $this->assertNull($this->conflicts->forChairing((int) $person->id, $this->sub));
        $this->assertNull($this->conflicts->forDean((int) $person->id, $this->department));
        $this->assertNull($this->conflicts->forAdministrator((int) $person->id, $this->institution));
    }
}
