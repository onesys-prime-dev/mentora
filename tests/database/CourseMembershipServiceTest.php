<?php

declare(strict_types=1);

use App\Services\Courses\CourseMembership;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Whether a person belongs to an offering in a given role: the instructor who
 * teaches it, the student enrolled (or done), the chairperson of its
 * sub-department, and nobody else.
 *
 * @internal
 */
final class CourseMembershipServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private CourseMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->membership = new CourseMembership();
    }

    private function chair(int $offeringId): CodeIgniter\Shield\Entities\User
    {
        $user = CourseFactory::user('chairperson');
        $sub  = (int) db_connect()->query(
            'SELECT sd.id FROM course_offerings o JOIN courses c ON c.id = o.course_id
               JOIN programs p ON p.id = c.program_id JOIN sub_departments sd ON sd.id = p.sub_department_id WHERE o.id = ?',
            [$offeringId],
        )->getRow()->id;

        db_connect()->table('chairperson_assignments')->insert(['sub_department_id' => $sub, 'chairperson_user_id' => $user->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s')]);

        return $user;
    }

    public function testTheInstructorWhoTeachesItBelongsAndAnotherDoesNot(): void
    {
        $offering = CourseFactory::offering();
        $teacher  = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $teacher);

        $this->assertTrue($this->membership->canAccessAs($teacher, 'instructor', $offering));
        $this->assertFalse($this->membership->canAccessAs(CourseFactory::user('instructor'), 'instructor', $offering));
    }

    public function testAStudentBelongsWhileEnrolledOrCompletedAndNotOnceDropped(): void
    {
        $offering = CourseFactory::offering();

        $enrolled = CourseFactory::user('student');
        CourseFactory::enroll($offering, $enrolled);

        $done = CourseFactory::user('student');
        CourseFactory::enroll($offering, $done, 'completed');

        $dropped = CourseFactory::user('student');
        CourseFactory::enroll($offering, $dropped, 'dropped');

        $this->assertTrue($this->membership->canAccessAs($enrolled, 'student', $offering));
        $this->assertTrue($this->membership->canAccessAs($done, 'student', $offering));
        $this->assertFalse($this->membership->canAccessAs($dropped, 'student', $offering));
        $this->assertFalse($this->membership->canAccessAs(CourseFactory::user('student'), 'student', $offering));
    }

    public function testAChairpersonBelongsToTheirSubDepartmentsOfferingsOnly(): void
    {
        $mine   = CourseFactory::offering('A');
        $theirs = CourseFactory::offering('B');
        $chair  = $this->chair($mine);

        $this->assertTrue($this->membership->canAccessAs($chair, 'chairperson', $mine));
        $this->assertFalse($this->membership->canAccessAs($chair, 'chairperson', $theirs));
    }

    public function testTheRoleMustBeOneThePersonHolds(): void
    {
        $offering = CourseFactory::offering();
        $teacher  = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($offering, $teacher);

        $this->assertFalse($this->membership->canAccessAs($teacher, 'student', $offering));
        $this->assertFalse($this->membership->canAccessAs($teacher, 'chairperson', $offering));
        $this->assertFalse($this->membership->canAccessAs($teacher, 'admin', $offering));
    }

    public function testNoOfferingAndNoSuchOfferingAreNotAccess(): void
    {
        $teacher = CourseFactory::user('instructor');

        $this->assertFalse($this->membership->canAccessAs($teacher, 'instructor', 0));
        $this->assertFalse($this->membership->canAccessAs($teacher, 'instructor', 999999));
    }
}
