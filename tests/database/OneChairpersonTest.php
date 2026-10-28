<?php

declare(strict_types=1);

use App\Database\Migrations\OneChairpersonPerSubDepartment;
use App\Services\Courses\Grades\GradeOverrideRequestService;
use App\Services\Institutions\ChairAssignmentService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\GradeFactory;
use Tests\Support\PeopleFactory;

require_once APPPATH . 'Database/Migrations/2026-11-15-000001_OneChairpersonPerSubDepartment.php';

/**
 * Exactly one chairperson per sub-department (audit decisions 16.0, 16.0b–d, U5).
 *
 * @internal
 */
final class OneChairpersonTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $sub;

    private User $old;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        session()->destroy();

        $home              = DeanFactory::department('CH');
        $this->institution = $home['institution'];
        $this->sub         = DeanFactory::subDepartment($home['department'], 'CH');
        $this->old         = DeanFactory::chair($this->sub, 'Olga');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
    }

    private function newChair(): User
    {
        return PeopleFactory::person('chairperson', 'Nina', 'New', null, $this->institution);
    }

    private function statusOf(User $chair): ?string
    {
        return db_connect()->table('chairperson_assignments')->where(['sub_department_id' => $this->sub, 'chairperson_user_id' => $chair->id])->get()->getRowArray()['status'] ?? null;
    }

    public function testASecondChairIsRefusedWithoutConfirmation(): void
    {
        $new = $this->newChair();

        try {
            (new ChairAssignmentService())->assign($this->institution, $this->sub, (int) $new->id, $this->admin);
            $this->fail('a second chair needs confirmation');
        } catch (StructureException $e) {
            $this->assertStringContainsString('Olga Chair chairs Sub CH', $e->getMessage());
            $this->assertStringContainsString('Nina New', $e->getMessage());
        }

        $this->assertSame('active', $this->statusOf($this->old));
        $this->assertNull($this->statusOf($new));
    }

    public function testConfirmingReplacesTheChairAndKeepsTheHistory(): void
    {
        $new = $this->newChair();

        (new ChairAssignmentService())->assign($this->institution, $this->sub, (int) $new->id, $this->admin, true);

        $this->assertSame('revoked', $this->statusOf($this->old));
        $this->assertSame('active', $this->statusOf($new));
        $this->assertSame(1, db_connect()->table('notifications')->where('user_id', $this->old->id)->countAllResults());

        $actions = array_column(db_connect()->table('audit_logs')->orderBy('id')->get()->getResultArray(), 'action');
        $this->assertContains('structure.chairperson_revoked', $actions);
        $this->assertContains('structure.chairperson_assigned', $actions);
    }

    public function testWaitingRequestsPassToTheNewChair(): void
    {
        $program  = DeanFactory::program($this->sub, 'CH');
        $offering = DeanFactory::offering($program, DeanFactory::term($this->institution), 'IT601');
        DeanFactory::dean($this->institution, (int) db_connect()->table('sub_departments')->where('id', $this->sub)->get()->getRow('department_id'));
        $teacher = PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution);
        $student = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        CourseFactory::assignInstructor($offering, $teacher);
        CourseFactory::enroll($offering, $student);
        db_connect()->table('gradebook_records')->insert(['course_offering_id' => $offering, 'student_id' => $student->id, 'grading_scheme_id' => GradeFactory::scheme($offering), 'calculated_percentage' => 80.0, 'is_override' => 0]);

        $service = new GradeOverrideRequestService();
        $id      = (int) $service->request($teacher, $offering, (int) $student->id, 85.0, 'Excused.')['request']['id'];
        $new     = $this->newChair();

        (new ChairAssignmentService())->assign($this->institution, $this->sub, (int) $new->id, $this->admin, true);

        $this->assertSame([$id], array_map('intval', array_column($service->listForChair($new), 'id')));
        $this->assertSame([], $service->listForChair($this->old));
        $this->assertGreaterThan(0, db_connect()->table('notifications')->where(['user_id' => $new->id, 'type' => 'grade_override'])->countAllResults());
        $this->assertFalse($service->endorse($this->old, $id, 'ok')['success']);
    }

    /** A request by $teacher in a course of this sub-department, in a department with a dean. @return int request id */
    private function requestBy(User $teacher): int
    {
        $program  = DeanFactory::program($this->sub, 'CH');
        $offering = DeanFactory::offering($program, DeanFactory::term($this->institution), 'IT602');
        DeanFactory::dean($this->institution, (int) db_connect()->table('sub_departments')->where('id', $this->sub)->get()->getRow('department_id'));
        $student = PeopleFactory::person('student', 'Ann', 'Lee', null, $this->institution);
        CourseFactory::assignInstructor($offering, $teacher);
        CourseFactory::enroll($offering, $student);
        db_connect()->table('gradebook_records')->insert(['course_offering_id' => $offering, 'student_id' => $student->id, 'grading_scheme_id' => GradeFactory::scheme($offering), 'calculated_percentage' => 80.0, 'is_override' => 0]);

        session()->set('active_role', 'instructor');
        $id = (int) (new GradeOverrideRequestService())->request($teacher, $offering, (int) $student->id, 85.0, 'Excused.')['request']['id'];
        session()->remove('active_role');

        return $id;
    }

    private function requestStatus(int $id): string
    {
        return (string) db_connect()->table('grade_override_requests')->where('id', $id)->get()->getRow('status');
    }

    public function testTheNewChairsOwnWaitingRequestMovesOnToTheDean(): void
    {
        $new = PeopleFactory::person('instructor', 'Nina', 'New', null, $this->institution);
        $new->addGroup('chairperson');
        $id = $this->requestBy($new);
        $this->assertSame('awaiting_chair', $this->requestStatus($id));

        (new ChairAssignmentService())->assign($this->institution, $this->sub, (int) $new->id, $this->admin, true);

        $this->assertSame('awaiting_dean', $this->requestStatus($id), 'nobody else could endorse it');
        $this->assertContains('grade.override_rerouted', array_column(db_connect()->table('audit_logs')->get()->getResultArray(), 'action'));
    }

    public function testRemovingTheOnlyChairMovesTheirWaitingRequestsOn(): void
    {
        $id         = $this->requestBy(PeopleFactory::person('instructor', 'Tess', 'Teacher', null, $this->institution));
        $assignment = (int) db_connect()->table('chairperson_assignments')->where(['sub_department_id' => $this->sub, 'status' => 'active'])->get()->getRow('id');

        (new ChairAssignmentService())->revoke($this->institution, $assignment, $this->admin);

        $this->assertSame('awaiting_dean', $this->requestStatus($id));
    }

    public function testAChairTheSisManagesIsRefusedBeforeAskingToConfirm(): void
    {
        $assignment = (int) db_connect()->table('chairperson_assignments')->where(['sub_department_id' => $this->sub, 'status' => 'active'])->get()->getRow('id');
        db_connect()->table('sis_staff_assignments')->insert(['institution_id' => $this->institution, 'role' => 'chairperson', 'person_user_id' => $this->old->id, 'target_id' => $this->sub, 'local_id' => $assignment, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        \Tests\Support\SisFixture::connect($this->institution, PeopleFactory::person('admin', 'Root', 'Admin', 'root@ch.test'));

        try {
            (new ChairAssignmentService())->assign($this->institution, $this->sub, (int) $this->newChair()->id, $this->admin);
            $this->fail('the SIS owns this chairperson');
        } catch (StructureException $e) {
            $this->assertSame(ChairAssignmentService::SIS_LOCK, $e->getMessage(), 'said first, not "confirm to replace"');
        }
    }

    public function testTwoAdministratorsAssigningAtOnceGetTheRaceMessage(): void
    {
        // What the other administrator's save leaves for a moment: the active place is taken (active_marker),
        // while the read of who chairs it no longer sees an active chair.
        db_connect()->table('chairperson_assignments')->where(['sub_department_id' => $this->sub, 'chairperson_user_id' => $this->old->id])->update(['status' => 'revoked']);
        $notifications = db_connect()->table('notifications')->countAllResults();
        $new           = $this->newChair();
        $name          = (string) db_connect()->table('sub_departments')->where('id', $this->sub)->get()->getRow('name');

        try {
            (new ChairAssignmentService())->assign($this->institution, $this->sub, (int) $new->id, $this->admin);
            $this->fail('expected the race to be told');
        } catch (StructureException $e) {
            $this->assertSame("Someone else just changed the chairperson of {$name}. Reload the page to see who chairs it now.", $e->getMessage());
        }

        $this->assertNull($this->statusOf($new), 'the choice of the second administrator left no row');
        $this->assertSame($notifications, db_connect()->table('notifications')->countAllResults(), 'nobody is told of a change that did not happen');
    }

    public function testTheDatabaseKeepsOneActiveChair(): void
    {
        $new = $this->newChair();

        try {
            $added = db_connect()->table('chairperson_assignments')->insert(['sub_department_id' => $this->sub, 'chairperson_user_id' => $new->id, 'status' => 'active', 'active_marker' => 1, 'assigned_at' => date('Y-m-d H:i:s')]);
        } catch (DatabaseException) {
            $added = false;
        }

        $this->assertFalse($added);
    }

    public function testTheMigrationKeepsTheMostRecentlyAssigned(): void
    {
        $this->assertSame([3, 5], OneChairpersonPerSubDepartment::assignmentsToRevoke([
            ['id' => 3, 'sub_department_id' => 1, 'assigned_at' => '2026-01-01 08:00:00'],
            ['id' => 4, 'sub_department_id' => 1, 'assigned_at' => '2026-03-01 08:00:00'],
            ['id' => 5, 'sub_department_id' => 2, 'assigned_at' => '2026-01-01 08:00:00'],
            ['id' => 6, 'sub_department_id' => 2, 'assigned_at' => '2026-01-01 08:00:00'],
        ]));
    }
}
