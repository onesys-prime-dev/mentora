<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;

/**
 * A fresh MySQL database must reach the full schema from the migrations
 * alone (App + Shield), since that is exactly what `php spark migrate --all`
 * does on a new server.
 *
 * @internal
 */
final class MigrationsTest extends DatabaseTestCase
{
    public function testRunsAgainstTheTestDatabaseNotTheAppDatabase(): void
    {
        $this->assertStringContainsStringIgnoringCase('test', db_connect()->getDatabase());
    }

    public function testEveryAppMigrationHasRun(): void
    {
        $files = count(glob(APPPATH . 'Database/Migrations/*.php'));

        $ran = db_connect()->table('migrations')->like('namespace', 'App')->countAllResults();

        $this->assertSame($files, $ran, 'Some App migrations did not run on the test database.');
    }

    #[DataProvider('provideCoreTables')]
    public function testCoreTablesExist(string $table): void
    {
        $this->assertTrue(db_connect()->tableExists($table), "Table '{$table}' was not created by the migrations.");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCoreTables(): iterable
    {
        $tables = [
            // Shield (auth)
            'users', 'auth_identities', 'auth_groups_users',
            // Institution and academic structure
            'institutions', 'departments', 'sub_departments', 'programs', 'courses', 'course_offerings', 'enrollments', 'instructor_assignments',
            // Learning content and progress
            'course_modules', 'learning_units', 'learning_items', 'learning_completions', 'learning_item_prerequisites',
            // Assessments and grades
            'assessments', 'assessment_submissions',
            // Course features
            'announcements', 'discussion_threads', 'discussion_replies',
            // Platform
            'audit_logs', 'settings', 'role_permissions', 'plans', 'subscriptions', 'subscription_renewals',
        ];

        foreach ($tables as $table) {
            yield $table => [$table];
        }
    }
}
