<?php

declare(strict_types=1);

use App\Services\Courses\ChairpersonAccessService;
use App\Services\Institutions\ChairAssignmentService;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * Assigning chairpersons to departments: who can be assigned, that a
 * department can have several and a person several, that revoking keeps
 * the row and assigning again reuses it, scoping, audit, and that the
 * chairperson's reach follows the assignment at once.
 *
 * @internal
 */
final class ChairAssignmentServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private int $department;

    private User $admin;

    private User $chair;

    private ChairAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('CHA');
        $this->other       = SisFixture::institution('CHB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->chair       = PeopleFactory::person('chairperson', 'Cho', 'Chair', 'cho@chairs.example', $this->institution);
        $this->service     = new ChairAssignmentService();
        $this->department         = $this->departmentOf($this->institution);

        db_connect()->table('audit_logs')->emptyTable();
    }

    private function departmentOf(int $institutionId): int
    {
        return (int) db_connect()->query('SELECT sd.id FROM departments sd JOIN colleges d ON d.id = sd.college_id WHERE d.institution_id = ?', [$institutionId])->getRow()->id;
    }

    private function refused(callable $do): StructureException
    {
        try {
            $do();
        } catch (StructureException $e) {
            return $e;
        }

        $this->fail('Expected the assignment to be refused.');
    }

    public function testOnlyChairpersonsOfTheInstitutionAreOffered(): void
    {
        PeopleFactory::person('instructor', 'Ivy', 'Teacher', null, $this->institution);
        PeopleFactory::person('chairperson', 'Els', 'Where', null, $this->other);
        $inactive = PeopleFactory::person('chairperson', 'Off', 'Duty', null, $this->institution);
        db_connect()->table('users')->where('id', $inactive->id)->update(['active' => 0]);

        $candidates = $this->service->candidates($this->institution);

        $this->assertSame([(int) $this->chair->id], array_column($candidates, 'id'));
        $this->assertSame('Cho Chair', $candidates[0]['name']);
        $this->assertSame('cho@chairs.example', $candidates[0]['email']);
    }

    public function testAssigningRecordsItAndShowsItUnderTheDepartment(): void
    {
        $this->service->assign($this->institution, $this->department, (int) $this->chair->id, $this->admin);

        $chairs = $this->service->byDepartment($this->institution);

        $this->assertSame([(int) $this->chair->id], array_column($chairs[$this->department], 'user_id'));

        $log = db_connect()->table('audit_logs')->where('action', 'structure.chairperson_assigned')->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame($this->institution, (int) $log['institution_id']);
    }

    public function testADepartmentHasOneChairpersonAndAPersonMayChairSeveral(): void
    {
        $second = PeopleFactory::person('chairperson', 'Dee', 'Second', null, $this->institution);

        $collegeId = (int) db_connect()->table('colleges')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        db_connect()->table('departments')->insert(['college_id' => $collegeId, 'code' => 'SUB2', 'name' => 'Second department', 'is_active' => 1]);
        $department2 = (int) db_connect()->insertID();

        $this->service->assign($this->institution, $this->department, (int) $this->chair->id, $this->admin);
        $e = $this->refused(fn () => $this->service->assign($this->institution, $this->department, (int) $second->id, $this->admin));
        $this->service->assign($this->institution, $department2, (int) $this->chair->id, $this->admin);

        $chairs = $this->service->byDepartment($this->institution);

        $this->assertStringContainsString('Confirm to replace them', $e->getMessage());
        $this->assertCount(1, $chairs[$this->department]);
        $this->assertCount(1, $chairs[$department2]);
    }

    public function testAssigningTheSamePersonTwiceIsRefused(): void
    {
        $this->service->assign($this->institution, $this->department, (int) $this->chair->id, $this->admin);

        $e = $this->refused(fn () => $this->service->assign($this->institution, $this->department, (int) $this->chair->id, $this->admin));

        $this->assertStringContainsString('already chairs', $e->getMessage());
        $this->assertSame(1, db_connect()->table('chairperson_assignments')->where('chairperson_user_id', $this->chair->id)->countAllResults());
    }

    public function testSomeoneWhoIsNotAChairpersonOfThisInstitutionCannotBeAssigned(): void
    {
        $instructor = PeopleFactory::person('instructor', 'Ivy', 'Teacher', null, $this->institution);
        $elsewhere  = PeopleFactory::person('chairperson', 'Els', 'Where', null, $this->other);

        foreach ([(int) $instructor->id, (int) $elsewhere->id, 999999] as $userId) {
            $e = $this->refused(fn () => $this->service->assign($this->institution, $this->department, $userId, $this->admin));
            $this->assertArrayHasKey('user_id', $e->errors());
        }

        $this->assertSame(0, db_connect()->table('chairperson_assignments')->countAllResults());
    }

    public function testADeanIsNotOfferedAndCannotBeMadeChairperson(): void
    {
        $dean = PeopleFactory::person('chairperson', 'Dee', 'Dean', null, $this->institution);
        $dean->addGroup('dean');

        $this->assertSame([(int) $this->chair->id], array_column($this->service->candidates($this->institution), 'id'));

        $e = $this->refused(fn () => $this->service->assign($this->institution, $this->department, (int) $dean->id, $this->admin));

        $this->assertArrayHasKey('user_id', $e->errors());
        $this->assertStringContainsString('dean cannot also be a chairperson', $e->getMessage());
        $this->assertSame(0, db_connect()->table('chairperson_assignments')->where('chairperson_user_id', $dean->id)->countAllResults());
    }

    public function testNobodyIsAssignedToAnInactiveDepartment(): void
    {
        db_connect()->table('departments')->where('id', $this->department)->update(['is_active' => 0]);

        $e = $this->refused(fn () => $this->service->assign($this->institution, $this->department, (int) $this->chair->id, $this->admin));

        $this->assertStringContainsString('inactive', $e->getMessage());
    }

    public function testADepartmentOfAnotherInstitutionIsA404(): void
    {
        $this->expectException(PageNotFoundException::class);

        $this->service->assign($this->institution, $this->departmentOf($this->other), (int) $this->chair->id, $this->admin);
    }

    public function testRevokingKeepsTheRowAndAssigningAgainReusesIt(): void
    {
        $this->service->assign($this->institution, $this->department, (int) $this->chair->id, $this->admin);
        $id = (int) db_connect()->table('chairperson_assignments')->get()->getRowArray()['id'];

        $this->service->revoke($this->institution, $id, $this->admin);

        $this->assertSame('revoked', db_connect()->table('chairperson_assignments')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertSame([], $this->service->byDepartment($this->institution));
        $this->assertNotNull(db_connect()->table('audit_logs')->where('action', 'structure.chairperson_revoked')->get()->getRowArray());

        $this->service->assign($this->institution, $this->department, (int) $this->chair->id, $this->admin);

        $this->assertSame(1, db_connect()->table('chairperson_assignments')->countAllResults(), 'the same row, not a second');
        $this->assertSame('active', db_connect()->table('chairperson_assignments')->where('id', $id)->get()->getRowArray()['status']);
    }

    public function testRevokingTwiceIsHarmlessAndAForeignAssignmentIsA404(): void
    {
        $this->service->assign($this->institution, $this->department, (int) $this->chair->id, $this->admin);
        $id = (int) db_connect()->table('chairperson_assignments')->get()->getRowArray()['id'];

        $this->service->revoke($this->institution, $id, $this->admin);
        $this->service->revoke($this->institution, $id, $this->admin);

        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'structure.chairperson_revoked')->countAllResults());

        $this->expectException(PageNotFoundException::class);
        $this->service->revoke($this->other, $id, $this->admin);
    }

    public function testTheChairpersonsReachFollowsTheAssignmentAtOnce(): void
    {
        $course = SisFixture::course($this->institution, 'C1', 'IT101');
        $access = new ChairpersonAccessService();

        $this->assertFalse($access->canAccess($this->chair, $course['offering']));

        $this->service->assign($this->institution, $this->department, (int) $this->chair->id, $this->admin);
        $this->assertTrue($access->canAccess($this->chair, $course['offering']));

        $id = (int) db_connect()->table('chairperson_assignments')->get()->getRowArray()['id'];
        $this->service->revoke($this->institution, $id, $this->admin);
        $this->assertFalse($access->canAccess($this->chair, $course['offering']));
    }
}
