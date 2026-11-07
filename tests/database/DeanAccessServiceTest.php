<?php

declare(strict_types=1);

use App\Services\Courses\DeanAccessService;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;
use Tests\Support\PeopleFactory;

final class DeanAccessServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    public function testAccessIsLimitedToTheAssignedCollege(): void
    {
        $db = db_connect();
        $institution = PeopleFactory::institution('DAC');
        $otherInstitution = PeopleFactory::institution('DAX');
        $db->table('colleges')->insertBatch([
            ['institution_id' => $institution, 'code' => 'D1', 'name' => 'Home'],
            ['institution_id' => $otherInstitution, 'code' => 'D2', 'name' => 'Other'],
        ]);
        $home = (int) $db->table('colleges')->where('code', 'D1')->get()->getRowArray()['id'];
        $other = (int) $db->table('colleges')->where('code', 'D2')->get()->getRowArray()['id'];
        $db->table('departments')->insertBatch([
            ['college_id' => $home, 'code' => 'S1', 'name' => 'Home Sub'],
            ['college_id' => $other, 'code' => 'S2', 'name' => 'Other Sub'],
        ]);
        $homeDepartment = (int) $db->table('departments')->where('code', 'S1')->get()->getRowArray()['id'];
        $otherDepartment = (int) $db->table('departments')->where('code', 'S2')->get()->getRowArray()['id'];
        $db->table('programs')->insertBatch([
            ['department_id' => $homeDepartment, 'code' => 'P1', 'name' => 'Home Program'],
            ['department_id' => $otherDepartment, 'code' => 'P2', 'name' => 'Other Program'],
        ]);
        $homeProgram = (int) $db->table('programs')->where('code', 'P1')->get()->getRowArray()['id'];
        $otherProgram = (int) $db->table('programs')->where('code', 'P2')->get()->getRowArray()['id'];
        $dean = PeopleFactory::person('dean', 'Della', 'Dean', null, $institution);
        $db->table('dean_assignments')->insert([
            'college_id' => $home, 'dean_user_id' => $dean->id, 'status' => 'active',
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

    public function testADeanWithoutAnActiveAssignmentReachesNoOfferingProgramOrPerson(): void
    {
        $offering = CourseFactory::offering('N');
        $program  = (int) db_connect()->table('courses c')->select('c.program_id')->join('course_offerings co', 'co.course_id = c.id')->where('co.id', $offering)->get()->getRowArray()['program_id'];
        $dean     = PeopleFactory::person('dean', 'Un', 'Assigned');
        $service  = new DeanAccessService();

        $this->assertFalse($service->canAccessOffering($dean, $offering));
        $this->assertFalse($service->canAccessProgram($dean, $program));
        $this->assertFalse($service->canAccessPerson($dean, (int) $dean->id));
        $this->assertSame([], $service->programIds($dean));
    }

    public function testProgramIdsAreEveryProgramUnderTheCollegeAndNoOther(): void
    {
        $home      = DeanFactory::college('PI');
        $department       = DeanFactory::department($home['college'], 'PI');
        $first     = DeanFactory::program($department, 'P1');
        $second    = DeanFactory::program(DeanFactory::department($home['college'], 'PJ'), 'P2');
        $elsewhere = DeanFactory::college('PX');
        DeanFactory::program(DeanFactory::department($elsewhere['college'], 'PX'), 'P3');
        $dean = DeanFactory::dean($home['institution'], $home['college']);

        $ids = (new DeanAccessService())->programIds($dean);
        sort($ids);

        $this->assertSame([$first, $second], $ids);
    }

    public function testCollegeAndOfferingAccessAreAvailableForAnActiveDean(): void
    {
        $db = db_connect();
        $offering = CourseFactory::offering('D');
        $offeringRow = $db->table('course_offerings co')
            ->select('co.id, c.program_id, sd.college_id, i.id AS institution_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('departments sd', 'sd.id = p.department_id')
            ->join('colleges d', 'd.id = sd.college_id')
            ->join('institutions i', 'i.id = d.institution_id')
            ->where('co.id', $offering)->get()->getRowArray();
        $dean = PeopleFactory::person('dean', 'Active', 'Dean', null, (int) $offeringRow['institution_id']);
        $db->table('dean_assignments')->insert([
            'college_id' => $offeringRow['college_id'],
            'dean_user_id' => $dean->id,
            'status' => 'active',
            'active_marker' => 1,
            'assigned_at' => date('Y-m-d H:i:s'),
        ]);
        $service = new DeanAccessService();

        $this->assertSame((int) $offeringRow['college_id'], $service->collegeIds($dean)[0]);
        $this->assertSame((int) $offeringRow['college_id'], (int) $service->collegeFor($dean)['college_id']);
        $this->assertTrue($service->canAccessOffering($dean, $offering));
        $this->assertTrue($service->canAccess($dean, $offering));
        $this->assertTrue($service->canAccessProgram($dean, (int) $offeringRow['program_id']));
    }

    public function testPersonAccessIncludesInstitutionMembersAndAssignedInstructors(): void
    {
        $db = db_connect();
        $offering = CourseFactory::offering('P');
        $row = $db->table('course_offerings co')
            ->select('co.id, sd.college_id, i.id AS institution_id')
            ->join('courses c', 'c.id = co.course_id')
            ->join('programs p', 'p.id = c.program_id')
            ->join('departments sd', 'sd.id = p.department_id')
            ->join('colleges d', 'd.id = sd.college_id')
            ->join('institutions i', 'i.id = d.institution_id')
            ->where('co.id', $offering)->get()->getRowArray();
        $dean = PeopleFactory::person('dean', 'People', 'Dean', null, (int) $row['institution_id']);
        $student = PeopleFactory::person('student', 'Institution', 'Member', null, (int) $row['institution_id']);
        $instructor = PeopleFactory::person('instructor', 'Assigned', 'Teacher', null, (int) $row['institution_id']);
        CourseFactory::assignInstructor($offering, $instructor);
        $db->table('dean_assignments')->insert([
            'college_id' => $row['college_id'], 'dean_user_id' => $dean->id,
            'status' => 'active', 'active_marker' => 1, 'assigned_at' => date('Y-m-d H:i:s'),
        ]);
        $service = new DeanAccessService();

        $this->assertTrue($service->canAccessPerson($dean, (int) $student->id));
        $this->assertTrue($service->canAccessPerson($dean, (int) $instructor->id));
    }
}