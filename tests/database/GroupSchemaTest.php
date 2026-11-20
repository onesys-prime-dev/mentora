<?php

declare(strict_types=1);

use CodeIgniter\Database\Exceptions\DatabaseException;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The group tables: one group per student per set (and per activity), nulls that do not collide, cascading
 * deletes, and a migration that can be undone.
 *
 * @internal
 */
final class GroupSchemaTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private int $offering;

    private int $teacher;

    private int $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering = CourseFactory::offering();
        $this->teacher  = (int) CourseFactory::user('instructor')->id;
        $this->ada      = (int) CourseFactory::user()->id;
    }

    private function set(string $name = 'Set'): int
    {
        db_connect()->table('group_sets')->insert([
            'offering_id' => $this->offering, 'name' => $name, 'formation_mode' => 'manual', 'min_size' => 1, 'max_size' => 5,
            'status' => 'active', 'created_by' => $this->teacher, 'created_at' => '2026-12-01 00:00:00', 'updated_at' => '2026-12-01 00:00:00',
        ]);

        return (int) db_connect()->insertID();
    }

    private function group(?int $setId, string $name = 'G'): int
    {
        db_connect()->table('student_groups')->insert(['set_id' => $setId, 'name' => $name, 'status' => 'active', 'created_at' => '2026-12-01 00:00:00', 'updated_at' => '2026-12-01 00:00:00']);

        return (int) db_connect()->insertID();
    }

    private function member(int $groupId, ?int $setId, ?int $configId = null): void
    {
        db_connect()->table('student_group_members')->insert(['group_id' => $groupId, 'set_id' => $setId, 'config_id' => $configId, 'student_user_id' => $this->ada, 'joined_at' => '2026-12-01 00:00:00']);
    }

    private function rows(string $table): int
    {
        return db_connect()->table($table)->countAllResults();
    }

    public function testStudentCannotBeInTwoGroupsOfOneSet(): void
    {
        $set = $this->set();
        $this->member($this->group($set, 'A'), $set);

        // Inside the isolation transaction CodeIgniter does not throw: the insert fails and the error is on the connection.
        try {
            $this->member($this->group($set, 'B'), $set);
        } catch (DatabaseException) {
            // Outside a transaction it throws; either way the error code is the duplicate-key one.
        }

        $this->assertSame(1062, db_connect()->error()['code']);
        $this->assertSame(1, $this->rows('student_group_members'));
    }

    public function testNullsAllowSameStudentAcrossSets(): void
    {
        $one = $this->set('One');
        $two = $this->set('Two');
        $this->member($this->group($one), $one);
        $this->member($this->group($two), $two);

        // Activity groups carry no set and (here) no config: NULLs never collide.
        $this->member($this->group(null), null);
        $this->member($this->group(null), null);

        $this->assertSame(4, $this->rows('student_group_members'));
    }

    public function testDeletingASetCascadesToGroupsAndMembers(): void
    {
        $set = $this->set();
        $this->member($this->group($set), $set);

        db_connect()->table('group_sets')->where('id', $set)->delete();

        $this->assertSame(0, $this->rows('student_groups'));
        $this->assertSame(0, $this->rows('student_group_members'));
    }

    public function testMigrationRollsBack(): void
    {
        $tables = ['group_sets', 'student_groups', 'student_group_members'];
        $files  = [
            '2026-12-01-000001_CreateGroupSets'          => 'CreateGroupSets',
            '2026-12-01-000002_CreateStudentGroups'      => 'CreateStudentGroups',
            '2026-12-01-000003_CreateStudentGroupMembers' => 'CreateStudentGroupMembers',
        ];

        $migrations = [];

        foreach ($files as $file => $class) {
            require_once APPPATH . "Database/Migrations/{$file}.php";
            $fqcn         = "App\\Database\\Migrations\\{$class}";
            $migrations[] = new $fqcn();
        }

        // DDL commits: leave the transaction the isolation opened, and always put the tables back.
        db_connect()->transRollback();

        try {
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }

            db_connect()->resetDataCache();

            foreach ($tables as $table) {
                $this->assertFalse(db_connect()->tableExists($table), "down removes {$table}");
            }
        } finally {
            foreach (array_reverse($tables) as $table) {
                db_connect()->query('DROP TABLE IF EXISTS ' . db_connect()->escapeIdentifiers($table));
            }

            foreach ($migrations as $migration) {
                $migration->up();
            }

            // Recreating the tables dropped their keys to activity_group_configs: put them back.
            require_once APPPATH . 'Database/Migrations/2026-12-01-000005_AddConfigForeignKeysToGroups.php';
            (new \App\Database\Migrations\AddConfigForeignKeysToGroups())->up();

            db_connect()->resetDataCache();
        }

        foreach ($tables as $table) {
            $this->assertTrue(db_connect()->tableExists($table), "up restores {$table}");
        }

        db_connect()->transBegin();
    }
}
