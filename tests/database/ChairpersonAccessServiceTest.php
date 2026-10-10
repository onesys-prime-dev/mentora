<?php

declare(strict_types=1);

use App\Services\Courses\ChairpersonAccessService;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * A chairperson's reach: derived live from chairperson_assignments and the
 * program hierarchy, never a stored snapshot — so a program added to their
 * sub-department, or a course added to that program, is covered without any
 * assignment ever being touched again.
 *
 * @internal
 */
final class ChairpersonAccessServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private ChairpersonAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ChairpersonAccessService();
    }

    /** @return array{sub: int, program: int} */
    private function subDepartment(string $tag): array
    {
        $db = db_connect();

        $db->table('institutions')->insert(['name' => "Inst {$tag}", 'code' => "I{$tag}"]);
        $institution = (int) $db->insertID();

        $db->table('departments')->insert(['institution_id' => $institution, 'code' => "D{$tag}", 'name' => "College {$tag}"]);
        $department = (int) $db->insertID();

        $db->table('sub_departments')->insert(['department_id' => $department, 'code' => "S{$tag}", 'name' => "Department {$tag}"]);
        $sub = (int) $db->insertID();

        $db->table('programs')->insert(['sub_department_id' => $sub, 'code' => "P{$tag}", 'name' => "Program {$tag}"]);

        return ['sub' => $sub, 'program' => (int) $db->insertID()];
    }

    private function program(int $subDepartmentId, string $code): int
    {
        $db = db_connect();

        $db->table('programs')->insert(['sub_department_id' => $subDepartmentId, 'code' => $code, 'name' => "Program {$code}"]);

        return (int) $db->insertID();
    }

    private function offering(int $programId, string $courseCode): int
    {
        $db = db_connect();

        $db->table('academic_terms')->insert([
            'institution_id' => $this->institutionOf($programId),
            'name'           => 'Term ' . $courseCode,
            'academic_year'  => '2026-2027',
            'start_date'     => '2026-08-01',
            'end_date'       => '2026-12-20',
        ]);
        $term = (int) $db->insertID();

        $db->table('courses')->insert(['program_id' => $programId, 'course_code' => $courseCode, 'course_title' => "Course {$courseCode}", 'units' => 3]);
        $course = (int) $db->insertID();

        $db->table('course_offerings')->insert(['course_id' => $course, 'term_id' => $term, 'section_code' => 'A']);

        return (int) $db->insertID();
    }

    private function institutionOf(int $programId): int
    {
        return (int) db_connect()->table('programs p')
            ->select('departments.institution_id')
            ->join('sub_departments', 'sub_departments.id = p.sub_department_id')
            ->join('departments', 'departments.id = sub_departments.department_id')
            ->where('p.id', $programId)
            ->get()->getRowArray()['institution_id'];
    }

    private function assign(int $userId, int $subDepartmentId, string $status = 'active'): void
    {
        db_connect()->table('chairperson_assignments')->insert([
            'sub_department_id'    => $subDepartmentId,
            'chairperson_user_id'  => $userId,
            'status'               => $status,
            'assigned_at'          => date('Y-m-d H:i:s'),
        ]);
    }

    public function testACourseInTheChairsSubDepartmentIsReachable(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home = $this->subDepartment('1');
        $this->assign($chair->id, $home['sub']);

        $offering = $this->offering($home['program'], 'IT101');

        $this->assertTrue($this->service->canAccess($chair, $offering));
    }

    public function testACourseInAnotherSubDepartmentIsNotReachable(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home = $this->subDepartment('1');
        $elsewhere = $this->subDepartment('2');
        $this->assign($chair->id, $home['sub']);

        $offering = $this->offering($elsewhere['program'], 'CS101');

        $this->assertFalse($this->service->canAccess($chair, $offering));
    }

    public function testAProgramAddedToTheSubDepartmentLaterIsCoveredWithoutTouchingTheAssignment(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home = $this->subDepartment('1');
        $this->assign($chair->id, $home['sub']);

        // A second program, under the same sub-department, added after the
        // chairperson was already assigned.
        $newProgram = $this->program($home['sub'], 'NEWP');
        $offering = $this->offering($newProgram, 'CS202');

        $this->assertTrue($this->service->canAccess($chair, $offering));
    }

    public function testARevokedAssignmentLosesAccessImmediately(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home = $this->subDepartment('1');
        $this->assign($chair->id, $home['sub'], 'revoked');

        $offering = $this->offering($home['program'], 'IT101');

        $this->assertFalse($this->service->canAccess($chair, $offering));
    }

    public function testAChairWithNoAssignmentReachesNothing(): void
    {
        $chair = CourseFactory::user('chairperson');
        $home = $this->subDepartment('1');
        $offering = $this->offering($home['program'], 'IT101');

        $this->assertFalse($this->service->canAccess($chair, $offering));
    }

    public function testOfferingsForListsEveryOfferingAcrossEverySubDepartmentChaired(): void
    {
        $chair = CourseFactory::user('chairperson');
        $a = $this->subDepartment('1');
        $b = $this->subDepartment('2');
        $elsewhere = $this->subDepartment('3');

        $this->assign($chair->id, $a['sub']);
        $this->assign($chair->id, $b['sub']);

        $this->offering($a['program'], 'AAA101');
        $this->offering($b['program'], 'BBB101');
        $this->offering($elsewhere['program'], 'CCC101');

        $codes = array_column($this->service->offeringsFor($chair), 'course_code');
        sort($codes);

        $this->assertSame(['AAA101', 'BBB101'], $codes);
    }
}
