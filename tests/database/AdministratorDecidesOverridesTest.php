<?php

declare(strict_types=1);

use App\Services\Admin\PermissionMatrix;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Courses\Grades\OverrideRequestChain;
use App\Services\Institutions\DeanAssignmentService;
use App\Services\Institutions\InstitutionRoles;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * With no dean in the department, the institution's administrators decide a
 * grade-override request (audit decisions 1.1–1.6, 7.2, 7.3).
 *
 * @internal
 */
final class AdministratorDecidesOverridesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $department;

    private int $offering;

    private User $teacher;

    private User $student;

    private User $adminA;

    private User $adminB;

    private GradeOverrideRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        PermissionMatrix::flush();
        session()->destroy();

        $home              = DeanFactory::department('AD');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];

        // Another department has a dean, so the institution uses approval; this one has none.
        $other = DeanFactory::department('AE', $this->institution);
        DeanFactory::dean($this->institution, $other['department'], 'Otto');

        $program        = DeanFactory::program(DeanFactory::subDepartment($this->department, 'AD'), 'AD');
        $this->offering = DeanFactory::offering($program, DeanFactory::term($this->institution), 'IT201', 'A', 'active', 'Data');

        $this->teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->student = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->adminA  = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->adminB  = PeopleFactory::person('institutional_admin', 'Ben', 'Admin', null, $this->institution);

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);

        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $this->offering, 'student_id' => $this->student->id,
            'grading_scheme_id'  => GradeFactory::scheme($this->offering), 'calculated_percentage' => 88.0, 'is_override' => 0,
        ]);

        $this->service = new GradeOverrideRequestService();
    }

    protected function tearDown(): void
    {
        PermissionMatrix::flush();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function ask(?User $as = null): array
    {
        return $this->service->request($as ?? $this->teacher, $this->offering, (int) $this->student->id, 92.0, 'Late quiz excused.');
    }

    private function statusOf(int $id): string
    {
        return (string) db_connect()->table('grade_override_requests')->where('id', $id)->get()->getRowArray()['status'];
    }

    private function notified(User $user): int
    {
        return db_connect()->table('notifications')->where(['user_id' => $user->id, 'type' => 'grade_override'])->countAllResults();
    }

    public function testARequestWithNoDeanWaitsForTheAdministratorsAndTellsThemAll(): void
    {
        $id = (int) $this->ask()['request']['id'];

        $this->assertSame(OverrideRequestChain::AWAITING_INSTITUTION, $this->statusOf($id));
        $this->assertSame(1, $this->notified($this->adminA));
        $this->assertSame(1, $this->notified($this->adminB));
    }

    public function testAnAdministratorApprovesAndTheGradeChanges(): void
    {
        $id = (int) $this->ask()['request']['id'];

        $result = $this->service->approve($this->adminA, $id);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(OverrideRequestChain::APPROVED, $this->statusOf($id));
        $this->assertSame(1, (int) db_connect()->table('gradebook_records')->where('student_id', $this->student->id)->get()->getRowArray()['is_override']);

        $told = db_connect()->table('notifications')->where(['user_id' => $this->teacher->id, 'type' => 'grade_override'])->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertStringContainsString('the administrator approved your request', (string) $told['body']);
    }

    public function testAnAdministratorRejectsWithAReason(): void
    {
        $id = (int) $this->ask()['request']['id'];

        $this->assertFalse($this->service->reject($this->adminA, $id, '  ')['success']);
        $this->assertTrue($this->service->reject($this->adminA, $id, 'Not supported by the work.')['success']);
        $this->assertSame(OverrideRequestChain::REJECTED, $this->statusOf($id));
    }

    public function testTheFirstAdministratorsDecisionWins(): void
    {
        $id = (int) $this->ask()['request']['id'];

        $this->assertTrue($this->service->approve($this->adminA, $id)['success']);

        $second = $this->service->reject($this->adminB, $id, 'Too late.');

        $this->assertFalse($second['success']);
        $this->assertSame(OverrideRequestChain::APPROVED, $this->statusOf($id));
    }

    public function testNobodyDecidesTheirOwnRequest(): void
    {
        $this->adminA->addGroup('instructor');
        CourseFactory::assignInstructor($this->offering, $this->adminA);
        (new \App\Services\Auth\ActiveRoleService())->switchTo($this->adminA, 'instructor');
        $id = (int) $this->ask($this->adminA)['request']['id'];

        (new \App\Services\Auth\ActiveRoleService())->switchTo($this->adminA, 'institutional_admin');

        $this->assertFalse($this->service->approve($this->adminA, $id)['success']);
        $this->assertTrue($this->service->approve($this->adminB, $id)['success']);
    }

    public function testWithOneAdministratorTheirOwnRequestWaitsAndSaysWhy(): void
    {
        db_connect()->table('auth_groups_users')->where(['user_id' => $this->adminB->id, 'group' => 'institutional_admin'])->delete();
        $this->adminA->addGroup('instructor');
        CourseFactory::assignInstructor($this->offering, $this->adminA);
        (new \App\Services\Auth\ActiveRoleService())->switchTo($this->adminA, 'instructor');

        $result = $this->ask($this->adminA);

        $this->assertSame(OverrideRequestChain::AWAITING_INSTITUTION, $this->statusOf((int) $result['request']['id']));
        $this->assertStringEndsWith("This needs another administrator: you can't decide your own request.", $result['message']);
    }

    public function testWithNobodyHoldingThePermissionItWaitsAndSaysWhy(): void
    {
        db_connect()->table('role_permissions')->insert(['institution_id' => 0, 'role' => 'institutional_admin', 'permission' => 'grades.approve', 'granted' => 0]);
        PermissionMatrix::flush();

        $result = $this->ask();

        $this->assertSame(OverrideRequestChain::AWAITING_INSTITUTION, $this->statusOf((int) $result['request']['id']));
        $this->assertStringEndsWith('Nobody in your institution can decide this yet. An administrator needs the permission to approve overrides.', $result['message']);
        $this->assertFalse($this->service->approve($this->adminA, (int) $result['request']['id'])['success']);
    }

    public function testADeanWhoLosesThePermissionHandsTheirQueueToTheAdministrators(): void
    {
        $dean = DeanFactory::dean($this->institution, $this->department, 'Dana');
        $id   = (int) $this->ask()['request']['id'];
        $this->assertSame(OverrideRequestChain::AWAITING_DEAN, $this->statusOf($id));

        (new InstitutionRoles())->set($this->institution, 'dean', 'grades.approve', false, $this->adminA);
        $this->assertSame(OverrideRequestChain::AWAITING_INSTITUTION, $this->statusOf($id));
        $this->assertFalse($this->service->approve($dean, $id)['success']);

        (new InstitutionRoles())->set($this->institution, 'dean', 'grades.approve', true, $this->adminA);
        $this->assertSame(OverrideRequestChain::AWAITING_DEAN, $this->statusOf($id));
    }

    public function testAnAssignedDeanTakesOverWhatWaitsForTheAdministrators(): void
    {
        $id   = (int) $this->ask()['request']['id'];
        $dean = PeopleFactory::person('dean', 'Dora', 'Dean', null, $this->institution);

        (new DeanAssignmentService())->assign($this->institution, $this->department, (int) $dean->id, $this->adminA);

        $this->assertSame(OverrideRequestChain::AWAITING_DEAN, $this->statusOf($id));
    }
}
