<?php

declare(strict_types=1);

use App\Services\Courses\CourseRoleConflicts;
use App\Services\Institutions\ChairAssignmentService;
use App\Services\Institutions\DeanAssignmentService;
use App\Services\Institutions\EnrolmentService;
use App\Services\Institutions\OfferingService;
use App\Services\Institutions\StructureException;
use App\Services\Users\UserAdminService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

/**
 * Every writer of enrolments and staff roles refuses a student-and-staff conflict, in the guard's words
 * (audit decisions 9.2–9.5, D7).
 *
 * @internal
 */
final class CourseRoleWritersTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $department;

    private int $sub;

    private int $offering;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        session()->destroy();

        $home              = DeanFactory::department('CW');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $this->sub         = DeanFactory::subDepartment($this->department, 'CW');
        $this->offering    = DeanFactory::offering(DeanFactory::program($this->sub, 'CW'), DeanFactory::term($this->institution), 'IT120');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
    }

    private function student(string ...$also): User
    {
        $person = PeopleFactory::person('student', 'Pat', 'Person', 'pat@cw.test', $this->institution);

        foreach ($also as $group) {
            $person->addGroup($group);
        }

        return $person;
    }

    private function enrollments(User $person): int
    {
        return db_connect()->table('enrollments')->where(['student_user_id' => $person->id, 'status' => 'enrolled'])->countAllResults();
    }

    /** @return non-empty-string the refusal's message */
    private function refused(callable $write, string $type = StructureException::class): string
    {
        try {
            $write();
        } catch (Throwable $e) {
            $this->assertInstanceOf($type, $e);

            return $e->getMessage();
        }

        $this->fail('the write was not refused');
    }

    public function testTheEnrolmentPageSkipsAConflictWithItsReason(): void
    {
        $person = $this->student('instructor');
        CourseFactory::assignInstructor($this->offering, $person);

        $message = $this->refused(fn () => (new EnrolmentService())->add($this->institution, $this->offering, 'pat@cw.test', false, $this->admin));

        $this->assertStringContainsString('pat@cw.test ' . CourseRoleConflicts::TAUGHT, $message);
        $this->assertSame(0, $this->enrollments($person));
    }

    public function testReinstatingAConflictingEnrolmentIsRefused(): void
    {
        $person = $this->student();
        db_connect()->table('enrollments')->insert(['offering_id' => $this->offering, 'student_user_id' => $person->id, 'status' => 'dropped', 'enrolled_at' => date('Y-m-d H:i:s')]);
        $id = (int) db_connect()->insertID();
        db_connect()->table('dean_assignments')->insert(['department_id' => $this->department, 'dean_user_id' => $person->id, 'status' => 'ended', 'assigned_at' => '2025-01-01 08:00:00', 'ended_at' => '2025-06-01 08:00:00']);

        $message = $this->refused(fn () => (new EnrolmentService())->setStatus($this->institution, $id, 'enrolled', false, $this->admin));

        $this->assertSame('Pat Person ' . CourseRoleConflicts::LED . '.', $message);
        $this->assertSame(0, $this->enrollments($person));
    }

    public function testAssigningAnEnrolledStudentToTeachIsRefused(): void
    {
        $person = $this->student('instructor');
        CourseFactory::enroll($this->offering, $person);

        $message = $this->refused(fn () => (new OfferingService())->assignInstructor($this->institution, $this->offering, (int) $person->id, $this->admin));

        $this->assertSame('Pat Person ' . CourseRoleConflicts::STUDIES_COURSE . '.', $message);
        $this->assertSame(0, db_connect()->table('instructor_assignments')->where('instructor_user_id', $person->id)->countAllResults());
    }

    public function testAssigningAnEnrolledStudentAsChairIsRefused(): void
    {
        $person = $this->student('chairperson');
        CourseFactory::enroll($this->offering, $person);

        $message = $this->refused(fn () => (new ChairAssignmentService())->assign($this->institution, $this->sub, (int) $person->id, $this->admin));

        $this->assertSame('Pat Person ' . CourseRoleConflicts::STUDIES_SUB . '.', $message);
        $this->assertSame(0, db_connect()->table('chairperson_assignments')->where('chairperson_user_id', $person->id)->countAllResults());
    }

    public function testAssigningAnEnrolledStudentAsDeanIsRefused(): void
    {
        $person = $this->student('dean');
        CourseFactory::enroll($this->offering, $person);

        $message = $this->refused(fn () => (new DeanAssignmentService())->assign($this->institution, $this->department, (int) $person->id, $this->admin));

        $this->assertSame('Pat Person ' . CourseRoleConflicts::STUDIES_DEPARTMENT . '.', $message);
        $this->assertSame(0, db_connect()->table('dean_assignments')->where('dean_user_id', $person->id)->countAllResults());
    }

    public function testGrantingTheAdministratorRoleToAnEnrolledStudentIsRefused(): void
    {
        $person = $this->student();
        CourseFactory::enroll($this->offering, $person);

        $message = $this->refused(fn () => (new UserAdminService())->setRoles((int) $person->id, ['student', 'institutional_admin'], $this->admin), InvalidArgumentException::class);

        $this->assertSame('Pat Person ' . CourseRoleConflicts::STUDIES_INSTITUTION . '.', $message);
        $this->assertNotContains('institutional_admin', model(CodeIgniter\Shield\Models\UserModel::class)->findById($person->id)->getGroups());
    }
}
