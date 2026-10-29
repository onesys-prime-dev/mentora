<?php

declare(strict_types=1);

use App\Database\Migrations\EndCourseRoleConflicts;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\AssessmentFactory;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

require_once APPPATH . 'Database/Migrations/2026-11-16-000001_EndCourseRoleConflicts.php';

/**
 * Existing student-and-staff conflicts end the enrolment and keep the staff role (audit decisions 9.6, 9.7).
 *
 * @internal
 */
final class EndCourseRoleConflictsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $offering;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $home              = DeanFactory::department('EC');
        $this->institution = $home['institution'];
        $this->offering    = DeanFactory::offering(DeanFactory::program(DeanFactory::subDepartment($home['department'], 'EC'), 'EC'), DeanFactory::term($this->institution), 'IT130');
    }

    private function person(string ...$groups): User
    {
        $person = PeopleFactory::person('student', 'Pat', 'Person', null, $this->institution);

        foreach ($groups as $group) {
            $person->addGroup($group);
        }

        return $person;
    }

    private function statusOf(User $person): string
    {
        return (string) db_connect()->table('enrollments')->where(['offering_id' => $this->offering, 'student_user_id' => $person->id])->get()->getRow('status');
    }

    public function testAnEnrolmentInATaughtCourseIsDroppedAndTheTeachingStays(): void
    {
        $person = $this->person('instructor');
        CourseFactory::assignInstructor($this->offering, $person);
        CourseFactory::enroll($this->offering, $person);

        $this->assertSame(1, EndCourseRoleConflicts::endConflicts());

        $this->assertSame('dropped', $this->statusOf($person));
        $this->assertSame(1, db_connect()->table('instructor_assignments')->where('instructor_user_id', $person->id)->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'enrolment.status_changed')->countAllResults());
    }

    public function testAFormerStudentWhoNowTeachesKeepsTheirCompletedEnrolment(): void
    {
        $person = $this->person('instructor');
        CourseFactory::enroll($this->offering, $person, 'completed');
        $course = (int) db_connect()->table('course_offerings')->where('id', $this->offering)->get()->getRow('course_id');
        db_connect()->table('course_offerings')->insert(['course_id' => $course, 'term_id' => DeanFactory::term($this->institution, 'Later', '2027-08-01'), 'section_code' => 'B', 'status' => 'active']);
        CourseFactory::assignInstructor((int) db_connect()->insertID(), $person);

        $this->assertSame(0, EndCourseRoleConflicts::endConflicts());
        $this->assertSame('completed', $this->statusOf($person), 'D10: a former student may teach the course later');
    }

    public function testACompletedEnrolmentWhileTeachingTheCourseThatTermIsEnded(): void
    {
        $person = $this->person('instructor');
        CourseFactory::enroll($this->offering, $person, 'completed');
        CourseFactory::assignInstructor($this->offering, $person);

        $this->assertSame(1, EndCourseRoleConflicts::endConflicts());
        $this->assertSame('dropped', $this->statusOf($person));
    }

    public function testAnUnrelatedEnrolmentIsUntouched(): void
    {
        $person = $this->person();
        CourseFactory::enroll($this->offering, $person);

        $this->assertSame(0, EndCourseRoleConflicts::endConflicts());
        $this->assertSame('enrolled', $this->statusOf($person));
    }

    public function testTheWorkOfTheDroppedEnrolmentIsKept(): void
    {
        $person = $this->person('instructor');
        CourseFactory::assignInstructor($this->offering, $person);
        CourseFactory::enroll($this->offering, $person);
        $quiz    = CourseFactory::assessment($this->offering, $person, CourseFactory::module($this->offering));
        $attempt = AssessmentFactory::attempt($quiz, $person);

        EndCourseRoleConflicts::endConflicts();

        $this->assertSame(1, db_connect()->table('assessment_submissions')->where('id', $attempt)->countAllResults());
    }
}
