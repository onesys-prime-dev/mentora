<?php

declare(strict_types=1);

use App\Services\Courses\DeanAccessService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

final class DeanAccessServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    public function testAccessIsLimitedToTheAssignedDepartment(): void
    {
        $db = db_connect();
        $institution = PeopleFactory::institution('DAC');
        $otherInstitution = PeopleFactory::institution('DAX');
        $db->table('departments')->insertBatch([
            ['institution_id' => $institution, 'code' => 'D1', 'name' => 'Home'],
            ['institution_id' => $otherInstitution, 'code' => 'D2', 'name' => 'Other'],
        ]);
        $home = (int) $db->table('departments')->where('code', 'D1')->get()->getRowArray()['id'];
        $other = (int) $db->table('departments')->where('code', 'D2')->get()->getRowArray()['id'];
        $db->table('sub_departments')->insertBatch([
            ['department_id' => $home, 'code' => 'S1', 'name' => 'Home Sub'],
            ['department_id' => $other, 'code' => 'S2', 'name' => 'Other Sub'],
        ]);
        $homeSub = (int) $db->table('sub_departments')->where('code', 'S1')->get()->getRowArray()['id'];
        $otherSub = (int) $db->table('sub_departments')->where('code', 'S2')->get()->getRowArray()['id'];
        $db->table('programs')->insertBatch([
            ['sub_department_id' => $homeSub, 'code' => 'P1', 'name' => 'Home Program'],
            ['sub_department_id' => $otherSub, 'code' => 'P2', 'name' => 'Other Program'],
        ]);
        $homeProgram = (int) $db->table('programs')->where('code', 'P1')->get()->getRowArray()['id'];
        $otherProgram = (int) $db->table('programs')->where('code', 'P2')->get()->getRowArray()['id'];
        $dean = PeopleFactory::person('dean', 'Della', 'Dean', null, $institution);
        $db->table('dean_assignments')->insert([
            'department_id' => $home, 'dean_user_id' => $dean->id, 'status' => 'active',
            'active_marker' => 1, 'assigned_at' => date('Y-m-d H:i:s'),
        ]);

        $service = new DeanAccessService();

        $this->assertTrue($service->canAccessProgram($dean, $homeProgram));
        $this->assertFalse($service->canAccessProgram($dean, $otherProgram));
    }

    public function testEndedAssignmentHasNoReach(): void
    {
        $dean = PeopleFactory::person('dean', 'Ended', 'Dean');
        $service = new DeanAccessService();

        $this->assertFalse($service->canAccessPerson($dean, (int) $dean->id));
    }
}