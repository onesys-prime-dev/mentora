<?php

declare(strict_types=1);

use Tests\Support\DatabaseTestCase;

/**
 * The migration that puts departments and sub-departments between institutions
 * and programs. It rebuilds a table that already holds data on deploy, so the
 * test puts the schema back to how it was, adds programs the old way, runs the
 * migration and checks where they ended up, then leaves the schema as it found it.
 *
 * DDL commits on its own, so this class cannot use the rolled-back transaction the others do: it
 * removes the institutions it made (everything below them goes with them) instead.
 *
 * @internal
 */
final class AddDepartmentsAndSubDepartmentsMigrationTest extends DatabaseTestCase
{
    private const FILE = APPPATH . 'Database/Migrations/2026-10-08-000001_AddDepartmentsAndSubDepartments.php';

    /** @var list<int> */
    private array $institutionIds = [];

    private function migration(): App\Database\Migrations\AddDepartmentsAndSubDepartments
    {
        require_once self::FILE;

        return new App\Database\Migrations\AddDepartmentsAndSubDepartments();
    }

    protected function tearDown(): void
    {
        $db = db_connect();

        if ($this->institutionIds !== []) {
            $db->table('institutions')->whereIn('id', $this->institutionIds)->delete();
        }

        parent::tearDown();
    }

    private function institution(string $code): int
    {
        $db = db_connect();
        $db->table('institutions')->insert(['name' => "Institution {$code}", 'code' => $code]);
        $this->institutionIds[] = (int) $db->insertID();

        return end($this->institutionIds);
    }

    /** Whether programs has the column right now (the connection caches column and table lists). */
    private function programsHas(string $column): bool
    {
        db_connect()->resetDataCache();

        return db_connect()->fieldExists($column, 'programs');
    }

    private function tableExistsNow(string $table): bool
    {
        db_connect()->resetDataCache();

        return db_connect()->tableExists($table);
    }

    /** Programs the way the old schema stored them. */
    private function legacyProgram(int $institutionId, string $code): int
    {
        $db = db_connect();
        $db->table('programs')->insert(['institution_id' => $institutionId, 'name' => "Program {$code}", 'code' => $code]);

        return (int) $db->insertID();
    }

    /** Runs $assertions against the migrated schema, whatever happens to the schema on the way. */
    private function migratingFromTheOldSchema(callable $seed, callable $assertions): void
    {
        $migration = $this->migration();
        $db = db_connect();

        $migration->down();

        try {
            $this->assertTrue($this->programsHas('institution_id'), 'the old schema is back');
            $this->assertFalse($this->tableExistsNow('departments'));

            $seed();

            $migration->up();

            $assertions();
        } finally {
            if (! $this->programsHas('sub_department_id')) {
                // The migration stopped half way: clear what it made so it can run again, and let the
                // original failure be the one reported.
                $db->query('SET FOREIGN_KEY_CHECKS = 0');
                $db->query('DROP TABLE IF EXISTS sub_departments');
                $db->query('DROP TABLE IF EXISTS departments');
                $db->query('SET FOREIGN_KEY_CHECKS = 1');

                $migration->up();
            }
        }
    }

    public function testExistingProgramsMoveUnderAGeneralDepartmentOfTheirOwnInstitution(): void
    {
        $first = $this->institution('MIG1');
        $second = $this->institution('MIG2');
        $ids = [];

        $this->migratingFromTheOldSchema(
            function () use ($first, $second, &$ids): void {
                $ids = [
                    'a' => $this->legacyProgram($first, 'BSIT'),
                    'b' => $this->legacyProgram($first, 'BSCS'),
                    'c' => $this->legacyProgram($second, 'BSIT'),
                ];
            },
            function () use ($first, $second, &$ids): void {
                $db = db_connect();

                $this->assertFalse($this->programsHas('institution_id'));
                $this->assertTrue($this->programsHas('sub_department_id'));

                foreach ([$first, $second] as $institution) {
                    $departments = $db->table('departments')->where('institution_id', $institution)->get()->getResultArray();
                    $this->assertCount(1, $departments, 'one department per institution that had programs');
                    $this->assertSame('GEN', $departments[0]['code']);
                    $this->assertSame('General', $departments[0]['name']);

                    $subs = $db->table('sub_departments')->where('department_id', $departments[0]['id'])->get()->getResultArray();
                    $this->assertCount(1, $subs);
                    $this->assertSame('1', (string) $subs[0]['is_active']);
                }

                $institutionOf = static fn (int $programId): int => (int) db_connect()->table('programs')
                    ->select('departments.institution_id')
                    ->join('sub_departments', 'sub_departments.id = programs.sub_department_id')
                    ->join('departments', 'departments.id = sub_departments.department_id')
                    ->where('programs.id', $programId)->get()->getRowArray()['institution_id'];

                $this->assertSame($first, $institutionOf($ids['a']));
                $this->assertSame($first, $institutionOf($ids['b']));
                $this->assertSame($second, $institutionOf($ids['c']));

                $this->assertSame(
                    (int) $db->table('programs')->where('id', $ids['a'])->get()->getRowArray()['sub_department_id'],
                    (int) $db->table('programs')->where('id', $ids['b'])->get()->getRowArray()['sub_department_id'],
                    'programs of one institution share its General sub-department'
                );
            }
        );
    }

    public function testTheSameProgramCodeCanStillBeUsedByDifferentInstitutions(): void
    {
        $first = $this->institution('MIG3');
        $second = $this->institution('MIG4');

        $this->migratingFromTheOldSchema(
            function () use ($first, $second): void {
                $this->legacyProgram($first, 'BSCS');
                $this->legacyProgram($second, 'BSCS');
            },
            function (): void {
                $this->assertSame(2, db_connect()->table('programs')->where('code', 'BSCS')->countAllResults());
            }
        );
    }

    public function testACodeIsUniqueWithinASubDepartment(): void
    {
        $institution = $this->institution('MIG5');
        $db = db_connect();

        $db->table('departments')->insert(['institution_id' => $institution, 'code' => 'D', 'name' => 'Dept']);
        $department = (int) $db->insertID();
        $db->table('sub_departments')->insert(['department_id' => $department, 'code' => 'S', 'name' => 'Sub']);
        $sub = (int) $db->insertID();
        $other = (static function () use ($db, $department): int {
            $db->table('sub_departments')->insert(['department_id' => $department, 'code' => 'T', 'name' => 'Other sub']);

            return (int) $db->insertID();
        })();

        $db->table('programs')->insert(['sub_department_id' => $sub, 'name' => 'One', 'code' => 'BSIT']);
        $db->table('programs')->insert(['sub_department_id' => $other, 'name' => 'Two', 'code' => 'BSIT']);

        $this->assertSame(2, $db->table('programs')->where('code', 'BSIT')->countAllResults(), 'another sub-department may reuse the code');

        $this->expectException(CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->expectExceptionMessage('Duplicate entry');

        $db->table('programs')->insert(['sub_department_id' => $sub, 'name' => 'Three', 'code' => 'BSIT']);
    }

    public function testRemovingAnInstitutionRemovesEverythingUnderIt(): void
    {
        $institution = $this->institution('MIG6');
        $db = db_connect();

        $db->table('departments')->insert(['institution_id' => $institution, 'code' => 'D', 'name' => 'Dept']);
        $department = (int) $db->insertID();
        $db->table('sub_departments')->insert(['department_id' => $department, 'code' => 'S', 'name' => 'Sub']);
        $sub = (int) $db->insertID();
        $db->table('programs')->insert(['sub_department_id' => $sub, 'name' => 'One', 'code' => 'BSIT']);

        $db->table('institutions')->where('id', $institution)->delete();

        $this->assertSame(0, $db->table('departments')->where('id', $department)->countAllResults());
        $this->assertSame(0, $db->table('sub_departments')->where('id', $sub)->countAllResults());
        $this->assertSame(0, $db->table('programs')->where('sub_department_id', $sub)->countAllResults());
    }

    public function testRunningTheMigrationBackwardsRestoresTheInstitutionOfEachProgram(): void
    {
        $institution = $this->institution('MIG7');
        $db = db_connect();

        $db->table('departments')->insert(['institution_id' => $institution, 'code' => 'D', 'name' => 'Dept']);
        $department = (int) $db->insertID();
        $db->table('sub_departments')->insert(['department_id' => $department, 'code' => 'S', 'name' => 'Sub']);
        $sub = (int) $db->insertID();
        $db->table('programs')->insert(['sub_department_id' => $sub, 'name' => 'One', 'code' => 'BSIT']);
        $program = (int) $db->insertID();

        $migration = $this->migration();
        $migration->down();

        try {
            $this->assertSame($institution, (int) $db->table('programs')->where('id', $program)->get()->getRowArray()['institution_id']);
            $this->assertFalse($this->tableExistsNow('departments'));
        } finally {
            $migration->up();
        }
    }
}
