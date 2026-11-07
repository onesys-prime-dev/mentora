<?php

declare(strict_types=1);

use App\Services\Institutions\StructureException;
use App\Services\Institutions\StructureService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;
use Tests\Support\SisFixture;

/**
 * The academic structure's rules: codes, uniqueness, what cannot be created
 * or switched off while something depends on it, scoping to the institution,
 * and the audit entry of every change.
 *
 * @internal
 */
final class StructureServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $institution;

    private int $other;

    private int $college;

    private int $department;

    private int $program;

    private User $actor;

    private StructureService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->institution = SisFixture::institution('STRA');
        $this->other       = SisFixture::institution('STRB');
        $this->actor       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', null, $this->institution);
        $this->service     = new StructureService();

        $db = db_connect();

        $this->college    = (int) $db->table('colleges')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        $this->department = (int) $db->table('departments')->where('college_id', $this->college)->get()->getRowArray()['id'];
        $this->program       = (int) $db->table('programs')->where('department_id', $this->department)->get()->getRowArray()['id'];

        $db->table('audit_logs')->emptyTable();
    }

    private function audit(string $action): ?array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id', 'desc')->get()->getRowArray();
    }

    private function refused(callable $do): StructureException
    {
        try {
            $do();
        } catch (StructureException $e) {
            return $e;
        }

        $this->fail('Expected the change to be refused.');
    }

    /*
    |--------------------------------------------------------------------------
    | Colleges
    |--------------------------------------------------------------------------
    */

    public function testACollegeIsCreatedWithItsCodeInCapitalsAndAudited(): void
    {
        $id = $this->service->createCollege($this->institution, ['code' => ' ccs ', 'name' => ' College of Computing ', 'location' => 'Main', 'established_year' => '1999'], $this->actor);

        $row = db_connect()->table('colleges')->where('id', $id)->get()->getRowArray();

        $this->assertSame('CCS', $row['code']);
        $this->assertSame('College of Computing', $row['name']);
        $this->assertSame('Main', $row['location']);
        $this->assertSame(1999, (int) $row['established_year']);
        $this->assertSame(1, (int) $row['is_active']);
        $this->assertSame($this->institution, (int) $row['institution_id']);

        $log = $this->audit('structure.college_created');
        $this->assertNotNull($log);
        $this->assertSame($this->institution, (int) $log['institution_id']);
        $this->assertSame((string) $id, (string) $log['target_id']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideBadColleges(): iterable
    {
        yield 'no code' => [['code' => '', 'name' => 'Computing'], 'code'];
        yield 'a code with a space' => [['code' => 'C C', 'name' => 'Computing'], 'code'];
        yield 'a code that is too long' => [['code' => str_repeat('A', 51), 'name' => 'Computing'], 'code'];
        yield 'a code that starts with a dash' => [['code' => '-A', 'name' => 'Computing'], 'code'];
        yield 'a name that is too short' => [['code' => 'OK', 'name' => 'ab'], 'name'];
        yield 'no name' => [['code' => 'OK', 'name' => ''], 'name'];
        yield 'a year that is not four digits' => [['code' => 'OK', 'name' => 'Computing', 'established_year' => '99'], 'established_year'];
        yield 'a year in the future' => [['code' => 'OK', 'name' => 'Computing', 'established_year' => '2999'], 'established_year'];
        yield 'a year before 1800' => [['code' => 'OK', 'name' => 'Computing', 'established_year' => '1700'], 'established_year'];
        yield 'a location that is too long' => [['code' => 'OK', 'name' => 'Computing', 'location' => str_repeat('x', 256)], 'location'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadColleges')]
    public function testBadInputIsRefusedWithTheFieldNamed(array $input, string $field): void
    {
        $e = $this->refused(fn () => $this->service->createCollege($this->institution, $input, $this->actor));

        $this->assertArrayHasKey($field, $e->errors());
        $this->assertNull($this->audit('structure.college_created'));
    }

    public function testACodeIsUniqueWithinTheInstitutionButNotAcrossInstitutions(): void
    {
        $this->service->createCollege($this->institution, ['code' => 'SHARED', 'name' => 'First one'], $this->actor);

        $e = $this->refused(fn () => $this->service->createCollege($this->institution, ['code' => 'shared', 'name' => 'Second one'], $this->actor));
        $this->assertArrayHasKey('code', $e->errors());

        $this->service->createCollege($this->other, ['code' => 'SHARED', 'name' => 'Another institution'], $this->actor);
        $this->addToAssertionCount(1);
    }

    public function testRenamingKeepsTheCodeFreeForItself(): void
    {
        $this->service->updateCollege($this->institution, $this->college, ['code' => 'NEWCODE', 'name' => 'A new name here', 'location' => '', 'established_year' => ''], $this->actor);

        $row = db_connect()->table('colleges')->where('id', $this->college)->get()->getRowArray();
        $this->assertSame('NEWCODE', $row['code']);
        $this->assertSame('A new name here', $row['name']);

        $log = $this->audit('structure.college_updated');
        $this->assertStringContainsString('NEWCODE', (string) $log['new_values']);
        $this->assertStringContainsString('"code"', (string) $log['old_values']);
    }

    public function testSavingWithNothingChangedWritesNoAuditEntry(): void
    {
        $row = db_connect()->table('colleges')->where('id', $this->college)->get()->getRowArray();

        $this->service->updateCollege($this->institution, $this->college, ['code' => $row['code'], 'name' => $row['name'], 'location' => (string) $row['location'], 'established_year' => (string) $row['established_year']], $this->actor);

        $this->assertNull($this->audit('structure.college_updated'));
    }

    public function testACollegeOfAnotherInstitutionIsA404(): void
    {
        $foreign = (int) db_connect()->table('colleges')->where('institution_id', $this->other)->get()->getRowArray()['id'];

        foreach ([
            fn () => $this->service->updateCollege($this->institution, $foreign, ['code' => 'X1', 'name' => 'Hacked name'], $this->actor),
            fn () => $this->service->setCollegeActive($this->institution, $foreign, false, $this->actor),
            fn () => $this->service->createDepartment($this->institution, $foreign, ['code' => 'X1', 'name' => 'Hacked name'], $this->actor),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A foreign college must be a 404.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, (int) db_connect()->table('colleges')->where('id', $foreign)->get()->getRowArray()['is_active']);
    }

    /*
    |--------------------------------------------------------------------------
    | Switching off and on
    |--------------------------------------------------------------------------
    */

    public function testACollegeWithActiveDepartmentsCannotBeSwitchedOff(): void
    {
        $e = $this->refused(fn () => $this->service->setCollegeActive($this->institution, $this->college, false, $this->actor));

        $this->assertStringContainsString('1 active department', $e->getMessage());
        $this->assertSame(1, (int) db_connect()->table('colleges')->where('id', $this->college)->get()->getRowArray()['is_active']);
    }

    public function testTheWholeBranchCanBeSwitchedOffFromTheBottomUpAndBackOnFromTheTopDown(): void
    {
        $this->service->setProgramStatus($this->institution, $this->program, 'inactive', $this->actor);
        $this->service->setDepartmentActive($this->institution, $this->department, false, $this->actor);
        $this->service->setCollegeActive($this->institution, $this->college, false, $this->actor);

        $this->assertNotNull($this->audit('structure.college_deactivated'));

        // Not from the bottom while the parent is off.
        $e = $this->refused(fn () => $this->service->setDepartmentActive($this->institution, $this->department, true, $this->actor));
        $this->assertStringContainsString('inactive', $e->getMessage());

        $this->service->setCollegeActive($this->institution, $this->college, true, $this->actor);
        $this->service->setDepartmentActive($this->institution, $this->department, true, $this->actor);
        $this->service->setProgramStatus($this->institution, $this->program, 'active', $this->actor);

        $this->assertNotNull($this->audit('structure.college_reactivated'));
        $this->assertNotNull($this->audit('structure.department_reactivated'));
        $this->assertNotNull($this->audit('structure.program_reactivated'));
    }

    public function testNothingIsCreatedUnderAnInactiveParent(): void
    {
        $this->service->setProgramStatus($this->institution, $this->program, 'inactive', $this->actor);
        $this->service->setDepartmentActive($this->institution, $this->department, false, $this->actor);

        $e = $this->refused(fn () => $this->service->createProgram($this->institution, $this->department, ['code' => 'NEWP', 'name' => 'New program'], $this->actor));
        $this->assertStringContainsString('inactive', $e->getMessage());

        $this->service->setCollegeActive($this->institution, $this->college, false, $this->actor);

        $e = $this->refused(fn () => $this->service->createDepartment($this->institution, $this->college, ['code' => 'NEWS', 'name' => 'New sub'], $this->actor));
        $this->assertStringContainsString('inactive', $e->getMessage());
    }

    public function testAProgramWithActiveCoursesCannotBeSwitchedOff(): void
    {
        SisFixture::course($this->institution, 'C1', 'IT101');
        db_connect()->table('courses')->where('course_code', 'IT101')->update(['status' => 'active']);
        // The offering is in an active term too, so both reasons are given.

        $e = $this->refused(fn () => $this->service->setProgramStatus($this->institution, $this->program, 'inactive', $this->actor));

        $this->assertStringContainsString('1 active course', $e->getMessage());
        $this->assertStringContainsString('1 offering in an active term', $e->getMessage());
    }

    public function testOfferingsInAnActiveTermBlockEvenWhenTheCoursesAreArchived(): void
    {
        $course = SisFixture::course($this->institution, 'C1', 'IT101');
        db_connect()->table('courses')->where('id', $course['course'])->update(['status' => 'archived']);

        $e = $this->refused(fn () => $this->service->setProgramStatus($this->institution, $this->program, 'archived', $this->actor));

        $this->assertStringNotContainsString('active course', $e->getMessage());
        $this->assertStringContainsString('1 offering in an active term', $e->getMessage());

        // Once the term is over, it can go.
        db_connect()->table('academic_terms')->where('institution_id', $this->institution)->update(['status' => 'completed']);
        $this->service->setProgramStatus($this->institution, $this->program, 'archived', $this->actor);

        $this->assertSame('archived', db_connect()->table('programs')->where('id', $this->program)->get()->getRowArray()['status']);
        $this->assertNotNull($this->audit('structure.program_archived'));
    }

    public function testSwitchingOffTwiceOrToTheSameStateIsHarmless(): void
    {
        $this->service->setProgramStatus($this->institution, $this->program, 'inactive', $this->actor);
        $this->service->setProgramStatus($this->institution, $this->program, 'inactive', $this->actor);
        $this->service->setCollegeActive($this->institution, $this->college, true, $this->actor);

        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'structure.program_deactivated')->countAllResults());
        $this->assertNull($this->audit('structure.college_reactivated'));
    }

    public function testAnUnknownProgramStatusIsRefused(): void
    {
        $e = $this->refused(fn () => $this->service->setProgramStatus($this->institution, $this->program, 'deleted', $this->actor));

        $this->assertStringContainsString('not a status', $e->getMessage());
    }

    /*
    |--------------------------------------------------------------------------
    | Departments and programs
    |--------------------------------------------------------------------------
    */

    public function testDepartmentCodesAreUniqueWithinTheirCollegeOnly(): void
    {
        $second = $this->service->createCollege($this->institution, ['code' => 'SECOND', 'name' => 'Second college'], $this->actor);

        $first = (string) db_connect()->table('departments')->where('id', $this->department)->get()->getRowArray()['code'];

        $e = $this->refused(fn () => $this->service->createDepartment($this->institution, $this->college, ['code' => strtolower($first), 'name' => 'Duplicate code'], $this->actor));
        $this->assertArrayHasKey('code', $e->errors());

        // The same code under another college is fine.
        $this->service->createDepartment($this->institution, $second, ['code' => $first, 'name' => 'Same code, other college'], $this->actor);
        $this->addToAssertionCount(1);
    }

    public function testProgramCodesAreUniqueWithinTheirDepartmentOnly(): void
    {
        $otherCollege = $this->service->createCollege($this->institution, ['code' => 'ELSE', 'name' => 'Elsewhere college'], $this->actor);
        $otherDepartment        = $this->service->createDepartment($this->institution, $otherCollege, ['code' => 'ELSES', 'name' => 'Elsewhere department'], $this->actor);

        $code = (string) db_connect()->table('programs')->where('id', $this->program)->get()->getRowArray()['code'];

        $e = $this->refused(fn () => $this->service->createProgram($this->institution, $this->department, ['code' => strtolower($code), 'name' => 'Same code again'], $this->actor));

        $this->assertArrayHasKey('code', $e->errors());
        $this->assertStringContainsString('program already uses', $e->getMessage());

        // Another department may use the same code.
        $this->service->createProgram($this->institution, $otherDepartment, ['code' => $code, 'name' => 'Same code elsewhere'], $this->actor);
        $this->addToAssertionCount(1);
    }

    public function testAProgramIsCreatedUnderItsDepartmentOfThisInstitution(): void
    {
        $id = $this->service->createProgram($this->institution, $this->department, ['code' => 'bsn', 'name' => 'BS Nursing'], $this->actor);

        $row = db_connect()->table('programs')->where('id', $id)->get()->getRowArray();

        $this->assertSame('BSN', $row['code']);
        $this->assertSame($this->department, (int) $row['department_id']);
        $this->assertSame('active', $row['status']);
    }

    public function testAProgramCannotBeCreatedUnderAnotherInstitutionsDepartment(): void
    {
        $foreignCollege = (int) db_connect()->table('colleges')->where('institution_id', $this->other)->get()->getRowArray()['id'];
        $foreignDepartment  = (int) db_connect()->table('departments')->where('college_id', $foreignCollege)->get()->getRowArray()['id'];

        $this->expectException(PageNotFoundException::class);

        $this->service->createProgram($this->institution, $foreignDepartment, ['code' => 'EVIL', 'name' => 'Evil program'], $this->actor);
    }

    /*
    |--------------------------------------------------------------------------
    | The tree
    |--------------------------------------------------------------------------
    */

    public function testTheTreeHoldsOnlyThisInstitutionsStructureInOrder(): void
    {
        $this->service->createCollege($this->institution, ['code' => 'AAA', 'name' => 'First by code'], $this->actor);
        $this->service->createCollege($this->other, ['code' => 'ZZZ', 'name' => 'Not ours'], $this->actor);
        SisFixture::course($this->institution, 'C1', 'IT101');

        $tree = $this->service->tree($this->institution);

        $codes = array_column($tree, 'code');
        $this->assertSame('AAA', $codes[0]);
        $this->assertNotContains('ZZZ', $codes);

        $withDepartments = array_values(array_filter($tree, static fn (array $d): bool => $d['departments'] !== []))[0];
        $program  = $withDepartments['departments'][0]['programs'][0];

        $this->assertSame(1, (int) $program['course_count']);
    }
}
