<?php

declare(strict_types=1);

use App\Services\Institutions\DeanAssignmentService;
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
}