<?php

declare(strict_types=1);

use Tests\Support\DatabaseTestCase;

/**
 * The inventory of tables: every table the migrations create must be touched
 * by at least one test (named in a test, or reached through its model), so a
 * new table cannot arrive without tests or without someone deciding, here and
 * in writing, why it has none. The table list is read from the migrated
 * database, so nothing has to be remembered.
 *
 * @internal
 */
final class TableTestInventoryTest extends DatabaseTestCase
{
    /**
     * Tables owned by the framework packages (Shield, Settings, the migration
     * history), not by the application.
     *
     * @var list<string>
     */
    private const PACKAGE_TABLES = [
        'migrations', 'settings',
        'auth_groups_users', 'auth_identities', 'auth_logins',
        'auth_permissions_users', 'auth_remember_tokens', 'auth_token_logins',
        'users',
    ];

    /**
     * Application tables no test touches directly, each with the reason that
     * is true today. An entry that gets a test, or whose table is dropped, must
     * be deleted from here (the tests below fail until it is).
     *
     * @var array<string, string>
     */
    private const NOT_TOUCHED_BY_A_TEST = [
        'discussion_thread_reads' => 'Written and read only through DiscussionService (its unread tests); no test names the table or DiscussionReadModel.',
        'chairperson_assignments' => 'Only the model and the seeder use it; no application code reads or writes it yet.',
    ];

    /**
     * @return list<string> application tables in the migrated database
     */
    private static function appTables(): array
    {
        $tables = array_values(array_diff(db_connect()->listTables(), self::PACKAGE_TABLES));
        sort($tables);

        return $tables;
    }

    /**
     * Table name => the short class name of the model that declares it.
     *
     * @return array<string, string>
     */
    private static function models(): array
    {
        $models = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH . 'Models', \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/protected\s+\$table\s*=\s*[\'"]([a-z0-9_]+)[\'"]/', (string) file_get_contents($file->getPathname()), $m)) {
                $models[$m[1]] = $file->getBasename('.php');
            }
        }

        return $models;
    }

    /** All test code except the two inventory guards, so they cannot satisfy themselves. */
    private static function testSource(): string
    {
        $source = '';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOTPATH . 'tests', \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && ! in_array($file->getBasename(), ['TableTestInventoryTest.php', 'ServiceTestInventoryTest.php'], true)) {
                $source .= file_get_contents($file->getPathname()) . "\n";
            }
        }

        return $source;
    }

    private static function isTouched(string $table, string $source, array $models): bool
    {
        // The table as a string ('files', "files", `files`), not the plain word:
        // words like files or courses appear in ordinary text and comments.
        if (preg_match('/[\'"`]' . preg_quote($table, '/') . '[\'"`]/', $source) === 1) {
            return true;
        }

        return isset($models[$table]) && preg_match('/\b' . preg_quote($models[$table], '/') . '\b/', $source) === 1;
    }

    public function testTheTablesWereDiscovered(): void
    {
        $this->assertGreaterThanOrEqual(45, count(self::appTables()), 'The application tables were not discovered; are the migrations applied?');
    }

    public function testEveryTableIsTouchedByATestOrListedWithAReason(): void
    {
        $source  = self::testSource();
        $models  = self::models();
        $missing = [];

        foreach (self::appTables() as $table) {
            if (! self::isTouched($table, $source, $models) && ! isset(self::NOT_TOUCHED_BY_A_TEST[$table])) {
                $missing[] = $table;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'A table has no test. Write tests for the service that uses it (see docs/testing.md); only if that is truly not possible, add it to NOT_TOUCHED_BY_A_TEST with the reason. If it belongs to a framework package, add it to PACKAGE_TABLES.'
        );
    }

    public function testTheExceptionListsHaveNoStaleEntries(): void
    {
        $source = self::testSource();
        $models = self::models();
        $all    = db_connect()->listTables();
        $stale  = [];

        foreach (self::NOT_TOUCHED_BY_A_TEST as $table => $reason) {
            if (! in_array($table, $all, true)) {
                $stale[] = "{$table}: no such table any more";
            } elseif (self::isTouched($table, $source, $models)) {
                $stale[] = "{$table}: now touched by a test";
            } elseif (strlen($reason) < 20) {
                $stale[] = "{$table}: the reason is too short to be useful";
            }
        }

        foreach (self::PACKAGE_TABLES as $table) {
            if (! in_array($table, $all, true)) {
                $stale[] = "{$table}: listed as a package table but it does not exist";
            }
        }

        $this->assertSame([], $stale, 'Remove these from the exception lists (or give a real reason).');
    }
}
