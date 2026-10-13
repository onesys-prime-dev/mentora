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

    private int $department;

    private int $subDepartment;

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

        $this->department    = (int) $db->table('departments')->where('institution_id', $this->institution)->get()->getRowArray()['id'];
        $this->subDepartment = (int) $db->table('sub_departments')->where('department_id', $this->department)->get()->getRowArray()['id'];
        $this->program       = (int) $db->table('programs')->where('sub_department_id', $this->subDepartment)->get()->getRowArray()['id'];

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
    | Departments
    |--------------------------------------------------------------------------
    */

    public function testADepartmentIsCreatedWithItsCodeInCapitalsAndAudited(): void
    {
        $id = $this->service->createDepartment($this->institution, ['code' => ' ccs ', 'name' => ' College of Computing ', 'location' => 'Main', 'established_year' => '1999'], $this->actor);

        $row = db_connect()->table('departments')->where('id', $id)->get()->getRowArray();

        $this->assertSame('CCS', $row['code']);
        $this->assertSame('College of Computing', $row['name']);
        $this->assertSame('Main', $row['location']);
        $this->assertSame(1999, (int) $row['established_year']);
        $this->assertSame(1, (int) $row['is_active']);
        $this->assertSame($this->institution, (int) $row['institution_id']);

        $log = $this->audit('structure.department_created');
        $this->assertNotNull($log);
        $this->assertSame($this->institution, (int) $log['institution_id']);
        $this->assertSame((string) $id, (string) $log['target_id']);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideBadDepartments(): iterable
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

    #[\PHPUnit\Framework\Attributes\DataProvider('provideBadDepartments')]
    public function testBadInputIsRefusedWithTheFieldNamed(array $input, string $field): void
    {
        $e = $this->refused(fn () => $this->service->createDepartment($this->institution, $input, $this->actor));

        $this->assertArrayHasKey($field, $e->errors());
        $this->assertNull($this->audit('structure.department_created'));
    }

    public function testACodeIsUniqueWithinTheInstitutionButNotAcrossInstitutions(): void
    {
        $this->service->createDepartment($this->institution, ['code' => 'SHARED', 'name' => 'First one'], $this->actor);

        $e = $this->refused(fn () => $this->service->createDepartment($this->institution, ['code' => 'shared', 'name' => 'Second one'], $this->actor));
        $this->assertArrayHasKey('code', $e->errors());

        $this->service->createDepartment($this->other, ['code' => 'SHARED', 'name' => 'Another institution'], $this->actor);
        $this->addToAssertionCount(1);
    }

    public function testRenamingKeepsTheCodeFreeForItself(): void
    {
        $this->service->updateDepartment($this->institution, $this->department, ['code' => 'NEWCODE', 'name' => 'A new name here', 'location' => '', 'established_year' => ''], $this->actor);

        $row = db_connect()->table('departments')->where('id', $this->department)->get()->getRowArray();
        $this->assertSame('NEWCODE', $row['code']);
        $this->assertSame('A new name here', $row['name']);

        $log = $this->audit('structure.department_updated');
        $this->assertStringContainsString('NEWCODE', (string) $log['new_values']);
        $this->assertStringContainsString('"code"', (string) $log['old_values']);
    }

    public function testSavingWithNothingChangedWritesNoAuditEntry(): void
    {
        $row = db_connect()->table('departments')->where('id', $this->department)->get()->getRowArray();

        $this->service->updateDepartment($this->institution, $this->department, ['code' => $row['code'], 'name' => $row['name'], 'location' => (string) $row['location'], 'established_year' => (string) $row['established_year']], $this->actor);

        $this->assertNull($this->audit('structure.department_updated'));
    }

    public function testADepartmentOfAnotherInstitutionIsA404(): void
    {
        $foreign = (int) db_connect()->table('departments')->where('institution_id', $this->other)->get()->getRowArray()['id'];

        foreach ([
            fn () => $this->service->updateDepartment($this->institution, $foreign, ['code' => 'X1', 'name' => 'Hacked name'], $this->actor),
            fn () => $this->service->setDepartmentActive($this->institution, $foreign, false, $this->actor),
            fn () => $this->service->createSubDepartment($this->institution, $foreign, ['code' => 'X1', 'name' => 'Hacked name'], $this->actor),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A foreign department must be a 404.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, (int) db_connect()->table('departments')->where('id', $foreign)->get()->getRowArray()['is_active']);
    }

    /*
    |--------------------------------------------------------------------------
    | Switching off and on
    |--------------------------------------------------------------------------
    */

    public function testADepartmentWithActiveSubDepartmentsCannotBeSwitchedOff(): void
    {
        $e = $this->refused(fn () => $this->service->setDepartmentActive($this->institution, $this->department, false, $this->actor));

        $this->assertStringContainsString('1 active sub-department', $e->getMessage());
        $this->assertSame(1, (int) db_connect()->table('departments')->where('id', $this->department)->get()->getRowArray()['is_active']);
    }

    public function testTheWholeBranchCanBeSwitchedOffFromTheBottomUpAndBackOnFromTheTopDown(): void
    {
        $this->service->setProgramStatus($this->institution, $this->program, 'inactive', $this->actor);
        $this->service->setSubDepartmentActive($this->institution, $this->subDepartment, false, $this->actor);
        $this->service->setDepartmentActive($this->institution, $this->department, false, $this->actor);

        $this->assertNotNull($this->audit('structure.department_deactivated'));

        // Not from the bottom while the parent is off.
        $e = $this->refused(fn () => $this->service->setSubDepartmentActive($this->institution, $this->subDepartment, true, $this->actor));
        $this->assertStringContainsString('inactive', $e->getMessage());

        $this->service->setDepartmentActive($this->institution, $this->department, true, $this->actor);
        $this->service->setSubDepartmentActive($this->institution, $this->subDepartment, true, $this->actor);
        $this->service->setProgramStatus($this->institution, $this->program, 'active', $this->actor);

        $this->assertNotNull($this->audit('structure.department_reactivated'));
        $this->assertNotNull($this->audit('structure.sub_department_reactivated'));
        $this->assertNotNull($this->audit('structure.program_reactivated'));
    }

    public function testNothingIsCreatedUnderAnInactiveParent(): void
    {
        $this->service->setProgramStatus($this->institution, $this->program, 'inactive', $this->actor);
        $this->service->setSubDepartmentActive($this->institution, $this->subDepartment, false, $this->actor);

        $e = $this->refused(fn () => $this->service->createProgram($this->institution, $this->subDepartment, ['code' => 'NEWP', 'name' => 'New program'], $this->actor));
        $this->assertStringContainsString('inactive', $e->getMessage());

        $this->service->setDepartmentActive($this->institution, $this->department, false, $this->actor);

        $e = $this->refused(fn () => $this->service->createSubDepartment($this->institution, $this->department, ['code' => 'NEWS', 'name' => 'New sub'], $this->actor));
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
        $this->service->setDepartmentActive($this->institution, $this->department, true, $this->actor);

        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'structure.program_deactivated')->countAllResults());
        $this->assertNull($this->audit('structure.department_reactivated'));
    }

    public function testAnUnknownProgramStatusIsRefused(): void
    {
        $e = $this->refused(fn () => $this->service->setProgramStatus($this->institution, $this->program, 'deleted', $this->actor));

        $this->assertStringContainsString('not a status', $e->getMessage());
    }

    /*
    |--------------------------------------------------------------------------
    | Sub-departments and programs
    |--------------------------------------------------------------------------
    */

    public function testSubDepartmentCodesAreUniqueWithinTheirDepartmentOnly(): void
    {
        $second = $this->service->createDepartment($this->institution, ['code' => 'SECOND', 'name' => 'Second department'], $this->actor);

        $first = (string) db_connect()->table('sub_departments')->where('id', $this->subDepartment)->get()->getRowArray()['code'];

        $e = $this->refused(fn () => $this->service->createSubDepartment($this->institution, $this->department, ['code' => strtolower($first), 'name' => 'Duplicate code'], $this->actor));
        $this->assertArrayHasKey('code', $e->errors());

        // The same code under another department is fine.
        $this->service->createSubDepartment($this->institution, $second, ['code' => $first, 'name' => 'Same code, other department'], $this->actor);
        $this->addToAssertionCount(1);
    }

    public function testProgramCodesAreUniqueWithinTheirSubDepartmentOnly(): void
    {
        $otherDepartment = $this->service->createDepartment($this->institution, ['code' => 'ELSE', 'name' => 'Elsewhere dept'], $this->actor);
        $otherSub        = $this->service->createSubDepartment($this->institution, $otherDepartment, ['code' => 'ELSES', 'name' => 'Elsewhere sub'], $this->actor);

        $code = (string) db_connect()->table('programs')->where('id', $this->program)->get()->getRowArray()['code'];

        $e = $this->refused(fn () => $this->service->createProgram($this->institution, $this->subDepartment, ['code' => strtolower($code), 'name' => 'Same code again'], $this->actor));

        $this->assertArrayHasKey('code', $e->errors());
        $this->assertStringContainsString('program already uses', $e->getMessage());

        // Another sub-department may use the same code.
        $this->service->createProgram($this->institution, $otherSub, ['code' => $code, 'name' => 'Same code elsewhere'], $this->actor);
        $this->addToAssertionCount(1);
    }

    public function testAProgramIsCreatedUnderItsSubDepartmentOfThisInstitution(): void
    {
        $id = $this->service->createProgram($this->institution, $this->subDepartment, ['code' => 'bsn', 'name' => 'BS Nursing'], $this->actor);

        $row = db_connect()->table('programs')->where('id', $id)->get()->getRowArray();

        $this->assertSame('BSN', $row['code']);
        $this->assertSame($this->subDepartment, (int) $row['sub_department_id']);
        $this->assertSame('active', $row['status']);
    }

    public function testAProgramCannotBeCreatedUnderAnotherInstitutionsSubDepartment(): void
    {
        $foreignDept = (int) db_connect()->table('departments')->where('institution_id', $this->other)->get()->getRowArray()['id'];
        $foreignSub  = (int) db_connect()->table('sub_departments')->where('department_id', $foreignDept)->get()->getRowArray()['id'];

        $this->expectException(PageNotFoundException::class);

        $this->service->createProgram($this->institution, $foreignSub, ['code' => 'EVIL', 'name' => 'Evil program'], $this->actor);
    }

    /*
    |--------------------------------------------------------------------------
    | The tree
    |--------------------------------------------------------------------------
    */

    public function testTheTreeHoldsOnlyThisInstitutionsStructureInOrder(): void
    {
        $this->service->createDepartment($this->institution, ['code' => 'AAA', 'name' => 'First by code'], $this->actor);
        $this->service->createDepartment($this->other, ['code' => 'ZZZ', 'name' => 'Not ours'], $this->actor);
        SisFixture::course($this->institution, 'C1', 'IT101');

        $tree = $this->service->tree($this->institution);

        $codes = array_column($tree, 'code');
        $this->assertSame('AAA', $codes[0]);
        $this->assertNotContains('ZZZ', $codes);

        $withSubs = array_values(array_filter($tree, static fn (array $d): bool => $d['subDepartments'] !== []))[0];
        $program  = $withSubs['subDepartments'][0]['programs'][0];

        $this->assertSame(1, (int) $program['course_count']);
    }
}
