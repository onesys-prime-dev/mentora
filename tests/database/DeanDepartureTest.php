<?php

declare(strict_types=1);

use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Courses\Grades\OverrideRequestChain as Chain;
use App\Services\Institutions\DeanAssignmentService;
use App\Services\Institutions\PeopleService;
use App\Services\Users\UserAdminService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\FakeEmail;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

/**
 * When a dean leaves: taking the dean role away, or deactivating the person, ends
 * their deanship (the history stays, their other roles are not touched), and the
 * override requests that were waiting for them move up to the institution's
 * administrator instead of waiting for nobody. A new dean takes them back.
 *
 * @internal
 */
final class DeanDepartureTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;
    private int $department;
    private int $offering;
    private int $scheme;
    private User $teacher;
    private User $chair;
    private User $dean;
    private User $student;
    private User $sysadmin;
    private User $institutionAdmin;
    private GradeOverrideRequestService $requests;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        FakeEmail::install();

        $home              = DeanFactory::department('D');
        $this->institution = $home['institution'];
        $this->department  = $home['department'];
        $sub               = DeanFactory::subDepartment($this->department, 'D');
        $this->offering    = DeanFactory::offering(DeanFactory::program($sub, 'D'), DeanFactory::term($this->institution), 'IT101', 'A', 'active', 'Intro to Computing');

        $this->teacher          = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $this->chair            = DeanFactory::chair($sub, 'Maria');
        $this->dean             = DeanFactory::dean($this->institution, $this->department);
        $this->student          = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        $this->sysadmin         = PeopleFactory::person('admin', 'Root', 'Admin', 'root@dd.test');
        $this->institutionAdmin = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@dd.test', $this->institution);

        CourseFactory::assignInstructor($this->offering, $this->teacher);
        CourseFactory::enroll($this->offering, $this->student);

        $this->scheme = GradeFactory::scheme($this->offering);
        $this->record($this->student, 88.0);

        $this->requests = new GradeOverrideRequestService();
    }

    private function record(User $student, float $calculated): void
    {
        db_connect()->table('gradebook_records')->insert([
            'course_offering_id' => $this->offering, 'student_id' => $student->id, 'grading_scheme_id' => $this->scheme,
            'calculated_percentage' => $calculated, 'is_override' => 0,
        ]);
    }

    /** A request that has reached the dean. @return int request id */
    private function waitingForDean(?User $student = null): int
    {
        $student ??= $this->student;
        $id = $this->requests->request($this->teacher, $this->offering, (int) $student->id, 92.0, 'Late quiz excused (medical).')['request']['id'];
        $this->requests->endorse($this->chair, $id, '');

        return $id;
    }

    private function requestStatus(int $id): string
    {
        return (string) db_connect()->table('grade_override_requests')->where('id', $id)->get()->getRowArray()['status'];
    }

    private function assignment(): array
    {
        return db_connect()->table('dean_assignments')->where('dean_user_id', $this->dean->id)->get()->getRowArray();
    }

    /** @return list<string> */
    private function rolesOf(User $user): array
    {
        $groups = array_values(model(CodeIgniter\Shield\Models\UserModel::class)->findById($user->id)->getGroups());
        sort($groups);

        return $groups;
    }

    private function assertDeanshipEnded(): void
    {
        $row = $this->assignment();

        $this->assertSame('ended', $row['status']);
        $this->assertNull($row['active_marker']);
        $this->assertNotNull($row['ended_at'], 'the row stays as history');
    }

    public function testTheSystemAdministratorTakingTheDeanRoleAwayEndsTheDeanship(): void
    {
        $this->dean->addGroup('instructor');

        (new UserAdminService())->setRoles((int) $this->dean->id, ['instructor'], $this->sysadmin);

        $this->assertDeanshipEnded();
        $this->assertSame(['instructor'], $this->rolesOf($this->dean), 'only the dean role went');
    }

    public function testTheInstitutionAdministratorTakingTheDeanRoleAwayEndsTheDeanship(): void
    {
        $this->dean->addGroup('instructor');

        (new PeopleService())->setRoles($this->institution, (int) $this->dean->id, ['instructor'], $this->institutionAdmin);

        $this->assertDeanshipEnded();
    }

    public function testDeactivatingTheDeanEndsTheDeanshipOnBothScreens(): void
    {
        (new UserAdminService())->deactivate((int) $this->dean->id, $this->sysadmin);
        $this->assertDeanshipEnded();
        $this->assertSame(['dean'], $this->rolesOf($this->dean), 'the role stays: the person may come back');
    }

    public function testDeactivatingFromThePeopleScreenEndsItToo(): void
    {
        (new PeopleService())->deactivate($this->institution, (int) $this->dean->id, $this->institutionAdmin);

        $this->assertDeanshipEnded();
    }

    public function testChangingTheRolesOfSomeoneWhoIsNotADeanChangesNothingAboutDeans(): void
    {
        $this->waitingForDean();

        (new UserAdminService())->setRoles((int) $this->teacher->id, ['instructor', 'chairperson'], $this->sysadmin);
        (new UserAdminService())->deactivate((int) $this->student->id, $this->sysadmin);

        $this->assertSame('active', $this->assignment()['status'], 'the dean is still the dean');
    }

    public function testKeepingTheDeanRoleKeepsTheDeanship(): void
    {
        $this->dean->addGroup('instructor');

        (new UserAdminService())->setRoles((int) $this->dean->id, ['dean', 'student'], $this->sysadmin);

        $this->assertSame('active', $this->assignment()['status']);
    }

    public function testRequestsWaitingForTheDeanMoveUpWhenTheyLeave(): void
    {
        $waiting = $this->waitingForDean();
        $this->assertSame(Chain::AWAITING_DEAN, $this->requestStatus($waiting));

        (new UserAdminService())->deactivate((int) $this->dean->id, $this->sysadmin);

        $this->assertSame(Chain::AWAITING_INSTITUTION, $this->requestStatus($waiting), 'nobody is left to decide it: it moves up one level');

        $log = db_connect()->table('audit_logs')->where('action', 'grade.override_rerouted')->get()->getRowArray();
        $this->assertSame('system', $log['actor_label']);
        $this->assertSame((string) $waiting, $log['target_id']);
        $this->assertSame($this->institution, (int) $log['institution_id']);
        $this->assertSame(['status' => 'awaiting_dean'], json_decode($log['old_values'], true));
        $this->assertSame(['status' => 'awaiting_institution'], json_decode($log['new_values'], true));
    }

    public function testARequestStillWithTheChairpersonOrSentBackIsNotMoved(): void
    {
        $atChair = $this->requests->request($this->teacher, $this->offering, (int) $this->student->id, 92.0, 'Late quiz')['request']['id'];

        (new UserAdminService())->deactivate((int) $this->dean->id, $this->sysadmin);

        $this->assertSame(Chain::AWAITING_CHAIR, $this->requestStatus($atChair), 'the chairperson step is not the dean\'s');

        $this->requests->sendBack($this->chair, $atChair, 'Attach the note');
        $this->assertSame(Chain::RETURNED, $this->requestStatus($atChair));
    }

    public function testANewDeanTakesBackTheRequestsThatWaitedForTheAdministrator(): void
    {
        $waiting = $this->waitingForDean();
        (new UserAdminService())->deactivate((int) $this->dean->id, $this->sysadmin);
        $this->assertSame(Chain::AWAITING_INSTITUTION, $this->requestStatus($waiting));

        $new = PeopleFactory::person('instructor', 'Nora', 'Dean', 'nora@dd.test', $this->institution);
        (new DeanAssignmentService())->assign($this->institution, $this->department, (int) $new->id);

        $this->assertSame(Chain::AWAITING_DEAN, $this->requestStatus($waiting), 'a dean is there to decide it again');

        $told = db_connect()->table('notifications')->where(['user_id' => $new->id, 'type' => 'grade_override'])->get()->getResultArray();
        $this->assertCount(1, $told, 'the new dean is told it is waiting');
        $this->assertStringEndsWith('/dean/approvals', $told[0]['link']);
    }

    public function testANewDeanDoesNotTakeTheirOwnRequest(): void
    {
        // The future dean teaches here and has already asked for an override.
        $nora = PeopleFactory::person('instructor', 'Nora', 'Dean', 'nora@dd.test', $this->institution);
        CourseFactory::assignInstructor($this->offering, $nora);
        (new DeanAssignmentService())->end($this->institution, (int) $this->assignment()['id']);

        $own = $this->requests->request($nora, $this->offering, (int) $this->student->id, 91.0, 'My appeal')['request']['id'];
        $this->requests->endorse($this->chair, $own, '');
        $this->assertSame(Chain::AWAITING_INSTITUTION, $this->requestStatus($own), 'no dean at the moment');

        (new DeanAssignmentService())->assign($this->institution, $this->department, (int) $nora->id);

        $this->assertSame(Chain::AWAITING_INSTITUTION, $this->requestStatus($own), 'she cannot decide her own request');
    }

    public function testReplacingTheDeanLeavesTheirRequestsWithTheNewOne(): void
    {
        $waiting = $this->waitingForDean();

        $new = PeopleFactory::person('instructor', 'Nora', 'Dean', 'nora@dd.test', $this->institution);
        (new DeanAssignmentService())->assign($this->institution, $this->department, (int) $new->id);

        $this->assertSame(Chain::AWAITING_DEAN, $this->requestStatus($waiting), 'no bounce to the administrator and back');
        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'grade.override_rerouted')->countAllResults());
    }

    public function testReroutingTwiceMovesNothingTheSecondTime(): void
    {
        $this->waitingForDean();
        (new UserAdminService())->deactivate((int) $this->dean->id, $this->sysadmin);

        $this->assertSame(0, $this->requests->retargetDepartment($this->department));
    }

    public function testTheDepartedDeanCanNoLongerDecideAndTheListIsEmptyForThem(): void
    {
        $waiting = $this->waitingForDean();
        (new UserAdminService())->setRoles((int) $this->dean->id, ['instructor'], $this->sysadmin);

        $this->assertFalse($this->requests->approve($this->dean, $waiting)['success']);
        $this->assertSame([], $this->requests->listForDean($this->dean, new App\Services\Courses\Grades\OverrideApprovalFilters()));
    }
}
