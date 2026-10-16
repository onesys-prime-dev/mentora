<?php

declare(strict_types=1);

use App\Services\Institutions\DeanAssignmentService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Exceptions\PageNotFoundException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

final class DeanAssignmentServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;
    private int $department;
    private DeanAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = PeopleFactory::institution('DEA');
        db_connect()->table('departments')->insert([
            'institution_id' => $this->institution,
            'code' => 'D1',
            'name' => 'Department One',
        ]);
        $this->department = (int) db_connect()->insertID();
        $this->service = new DeanAssignmentService();
    }

    public function testAssigningGrantsDeanRoleAndRecordsCurrentAssignment(): void
    {
        $person = PeopleFactory::person('instructor', 'Dana', 'Dean', null, $this->institution);

        $id = $this->service->assign($this->institution, $this->department, (int) $person->id);

        $this->assertSame($id, (int) $this->service->currentDeanOfDepartment($this->department)['id']);
        $this->assertSame(1, db_connect()->table('auth_groups_users')->where([
            'user_id' => $person->id, 'group' => 'dean',
        ])->countAllResults());
    }

    public function testAChairpersonCannotBeMadeDean(): void
    {
        $chair = PeopleFactory::person('chairperson', 'Cho', 'Chair', null, $this->institution);

        try {
            $this->service->assign($this->institution, $this->department, (int) $chair->id);
            $this->fail('Expected the assignment to be refused.');
        } catch (StructureException $e) {
            $this->assertArrayHasKey('user_id', $e->errors());
            $this->assertStringContainsString('cannot also be a chairperson', $e->getMessage());
        }

        $this->assertNull($this->service->currentDeanOfDepartment($this->department));
        $this->assertSame(0, db_connect()->table('auth_groups_users')->where(['user_id' => $chair->id, 'group' => 'dean'])->countAllResults());
    }

    public function testSomeoneWithAnActiveChairpersonAssignmentCannotBeMadeDeanEvenWithoutTheRole(): void
    {
        $person = PeopleFactory::person('instructor', 'Ivy', 'Teacher', null, $this->institution);
        $sub    = Tests\Support\DeanFactory::subDepartment($this->department, 'CA');
        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id' => $sub, 'chairperson_user_id' => $person->id, 'status' => 'active', 'assigned_at' => date('Y-m-d H:i:s'),
        ]);

        $this->expectException(StructureException::class);

        $this->service->assign($this->institution, $this->department, (int) $person->id);
    }

    public function testAnInstructorCanBeMadeDeanAndKeepsTeaching(): void
    {
        $person = PeopleFactory::person('instructor', 'Ivy', 'Teacher', null, $this->institution);

        $this->service->assign($this->institution, $this->department, (int) $person->id);

        $groups = array_column(db_connect()->table('auth_groups_users')->where('user_id', $person->id)->get()->getResultArray(), 'group');
        $this->assertEqualsCanonicalizing(['instructor', 'dean'], $groups);
    }

    public function testReplacingDeanEndsTheEarlierAssignmentAndKeepsHistory(): void
    {
        $first = PeopleFactory::person('instructor', 'First', 'Dean', null, $this->institution);
        $second = PeopleFactory::person('instructor', 'Second', 'Dean', null, $this->institution);

        $firstId = $this->service->assign($this->institution, $this->department, (int) $first->id);
        $this->service->assign($this->institution, $this->department, (int) $second->id);

        $rows = db_connect()->table('dean_assignments')->orderBy('id')->get()->getResultArray();
        $this->assertCount(2, $rows);
        $this->assertSame('ended', $rows[0]['status']);
        $this->assertNull($rows[0]['active_marker']);
        $this->assertSame($firstId, (int) $rows[0]['id']);
        $this->assertSame((int) $second->id, (int) $rows[1]['dean_user_id']);
    }

    public function testEndingRemovesCurrentAccessButKeepsAssignment(): void
    {
        $person = PeopleFactory::person('instructor', 'Dana', 'Dean', null, $this->institution);
        $id = $this->service->assign($this->institution, $this->department, (int) $person->id);

        $this->service->end($this->institution, $id);

        $this->assertNull($this->service->currentDeanOfDepartment($this->department));
        $this->assertSame('ended', db_connect()->table('dean_assignments')->where('id', $id)->get()->getRowArray()['status']);
    }

    public function testAssigningAnExistingDeanRoleDoesNotDuplicateTheRole(): void
    {
        $person = PeopleFactory::person('dean', 'Existing', 'Dean', null, $this->institution);

        $this->service->assign($this->institution, $this->department, (int) $person->id);

        $this->assertSame(1, db_connect()->table('auth_groups_users')->where([
            'user_id' => $person->id, 'group' => 'dean',
        ])->countAllResults());
    }

    public function testAssigningTheSamePersonElsewhereEndsTheirPreviousAssignment(): void
    {
        $db = db_connect();
        $db->table('departments')->insert([
            'institution_id' => $this->institution,
            'code' => 'D2',
            'name' => 'Department Two',
        ]);
        $secondDepartment = (int) $db->insertID();
        $person = PeopleFactory::person('instructor', 'One', 'Dean', null, $this->institution);

        $firstId = $this->service->assign($this->institution, $this->department, (int) $person->id);
        $this->service->assign($this->institution, $secondDepartment, (int) $person->id);

        $this->assertSame('ended', $db->table('dean_assignments')->where('id', $firstId)->get()->getRowArray()['status']);
        $this->assertSame($secondDepartment, (int) $this->service->departmentOfDean((int) $person->id)['department_id']);
    }

    public function testAssigningUnknownDepartmentFails(): void
    {
        $person = PeopleFactory::person('instructor', 'Missing', 'Department', null, $this->institution);

        $this->expectException(PageNotFoundException::class);
        $this->service->assign($this->institution, 999999, (int) $person->id);
    }

    public function testAssigningNonMemberFails(): void
    {
        $person = PeopleFactory::person('instructor');

        $this->expectException(StructureException::class);
        $this->service->assign($this->institution, $this->department, (int) $person->id);
    }

    public function testEndingAnAlreadyEndedAssignmentIsIdempotent(): void
    {
        $person = PeopleFactory::person('instructor', 'Ended', 'Twice', null, $this->institution);
        $id = $this->service->assign($this->institution, $this->department, (int) $person->id);

        $this->service->end($this->institution, $id);
        $this->service->end($this->institution, $id);

        $this->assertSame('ended', db_connect()->table('dean_assignments')->where('id', $id)->get()->getRowArray()['status']);
    }

    public function testEndingUnknownAssignmentFails(): void
    {
        $this->expectException(PageNotFoundException::class);
        $this->service->end($this->institution, 999999);
    }
}