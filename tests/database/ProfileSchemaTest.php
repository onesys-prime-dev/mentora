<?php

declare(strict_types=1);

use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * The profile rebuild's schema: new optional columns that default to empty, the correction and email-change
 * tables, and migrations that leave existing rows alone and can be undone.
 *
 * @internal
 */
final class ProfileSchemaTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const MIGRATIONS = [
        '2026-12-02-000001_AddProfileColumns'               => 'AddProfileColumns',
        '2026-12-02-000002_CreateProfileCorrectionRequests' => 'CreateProfileCorrectionRequests',
        '2026-12-02-000003_CreatePendingEmailChanges'       => 'CreatePendingEmailChanges',
    ];

    private const NEW_COLUMNS = [
        'preferred_first_name', 'pronouns', 'bio', 'office_location', 'consultation_hours', 'work_phone',
        'photo_file_id', 'home_department_id', 'two_factor_opt_in', 'checklist_dismissed_at',
    ];

    /** @return list<object> */
    private function migrations(): array
    {
        $out = [];

        foreach (self::MIGRATIONS as $file => $class) {
            require_once APPPATH . "Database/Migrations/{$file}.php";
            $fqcn  = "App\\Database\\Migrations\\{$class}";
            $out[] = new $fqcn();
        }

        return $out;
    }

    public function testNewColumnsDefaultNullOrZero(): void
    {
        $user = (int) CourseFactory::user()->id;
        db_connect()->table('user_profiles')->insert(['user_id' => $user, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $row = db_connect()->table('user_profiles')->where('user_id', $user)->get()->getRowArray();

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertArrayHasKey($column, $row);
            $this->assertEquals($column === 'two_factor_opt_in' ? 0 : null, $row[$column], $column);
        }

        db_connect()->table('institutions')->insert(['name' => 'Schema U', 'code' => 'SCHEMAU']);
        $inst = db_connect()->table('institutions')->where('code', 'SCHEMAU')->get()->getRowArray();
        $this->assertSame(1, (int) $inst['profile_photos_enabled']);
    }

    public function testTablesTakeTheirKeys(): void
    {
        $user = (int) CourseFactory::user()->id;
        $db   = db_connect();

        $db->table('profile_correction_requests')->insert([
            'user_id' => $user, 'field' => 'last_name', 'new_value' => 'Byron', 'routed_to' => 'platform',
        ]);
        $request = (int) $db->insertID();
        $this->assertSame('pending', $db->table('profile_correction_requests')->where('id', $request)->get()->getRow()->status);

        $db->table('pending_email_changes')->insert([
            'user_id' => $user, 'request_id' => $request, 'new_email' => 'a@b.test', 'token_hash' => str_repeat('a', 64), 'expires_at' => '2026-12-03 00:00:00',
        ]);
        $this->assertSame(1, $db->table('pending_email_changes')->countAllResults());

        $db->table('profile_correction_requests')->where('id', $request)->delete();
        $this->assertNull($db->table('pending_email_changes')->get()->getRow()->request_id);
    }

    public function testExistingProfileRowsSurviveMigration(): void
    {
        $db         = db_connect();
        $migrations = $this->migrations();

        // DDL commits: leave the isolation transaction, take the schema back to before this change, seed, migrate forward.
        $db->transRollback();

        $db->query('INSERT INTO users (username, active, created_at) VALUES (?, 1, NOW())', ['survivor' . uniqid()]);
        $user = (int) $db->insertID();

        try {
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }

            $db->query("INSERT INTO user_profiles (user_id, first_name, middle_name, last_name, suffix, student_number, active_role, created_at)
                VALUES (?, 'Ada', 'King', 'Lovelace', 'Jr', '2026-0001', 'student', '2026-11-01 08:00:00')", [$user]);
            $before = $db->table('user_profiles')->where('user_id', $user)->get()->getRowArray();

            foreach ($migrations as $migration) {
                $migration->up();
            }

            $db->resetDataCache();
            $after = $db->table('user_profiles')->where('user_id', $user)->get()->getRowArray();

            foreach ($before as $column => $value) {
                $this->assertSame($value, $after[$column], $column);
            }
        } finally {
            $db->table('users')->where('id', $user)->delete();
            $db->transBegin();
        }
    }

    public function testMigrationsRollBack(): void
    {
        $db         = db_connect();
        $migrations = $this->migrations();

        $db->transRollback();

        try {
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }

            $db->resetDataCache();
            $this->assertFalse($db->tableExists('profile_correction_requests'));
            $this->assertFalse($db->tableExists('pending_email_changes'));
            $this->assertFalse($db->fieldExists('bio', 'user_profiles'));
            $this->assertFalse($db->fieldExists('profile_photos_enabled', 'institutions'));
        } finally {
            if ($db->tableExists('profile_correction_requests') === false) {
                foreach ($migrations as $migration) {
                    $migration->up();
                }
            }

            $db->resetDataCache();
            $db->transBegin();
        }

        $this->assertTrue($db->tableExists('pending_email_changes'));
        $this->assertTrue($db->fieldExists('bio', 'user_profiles'));
    }
}
