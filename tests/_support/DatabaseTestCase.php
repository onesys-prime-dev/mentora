<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;

/**
 * Base class for every test that touches the database.
 *
 * Runs on the `tests` connection (MySQL, database `mentora_test` by default)
 * with all migrations applied, Shield's included. The check in setUp() runs
 * before anything is migrated or truncated, so a misconfigured
 * `database.tests.database` pointing at a real database fails loudly instead
 * of wiping it.
 *
 * @internal
 */
abstract class DatabaseTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /** All namespaces: App, Shield and the test-support migrations. */
    protected $namespace = null;

    /** Build the schema once per run, not before every test. */
    protected $migrateOnce = true;

    /** Never roll the schema back (see $isolateTests for starting each test clean). */
    protected $refresh = false;

    /**
     * Set to true in a test class for full isolation: the tables are emptied
     * once before the class's first test, and every test then runs inside a
     * transaction that is rolled back afterwards, so no test sees (or leaves)
     * rows from another. Much faster than emptying every table per test.
     */
    protected bool $isolateTests = false;

    /** @var array<class-string, true> classes whose tables were already emptied this run */
    private static array $emptied = [];

    protected function setUp(): void
    {
        $database = (string) (config('Database')->tests['database'] ?? '');

        if (! str_contains(strtolower($database), 'test')) {
            throw new RuntimeException(
                "Refusing to run database tests against '{$database}': the name must contain 'test'. "
                . 'Check database.tests.database in .env.'
            );
        }

        parent::setUp();

        if ($this->isolateTests) {
            if (! isset(self::$emptied[static::class])) {
                $this->emptyAllTables();
                self::$emptied[static::class] = true;
            }

            self::forgetEarlierQueryFailure();
            db_connect()->transBegin();
        }
    }

    protected function tearDown(): void
    {
        if ($this->isolateTests) {
            db_connect()->transRollback();
            self::forgetEarlierQueryFailure();
        }

        parent::tearDown();
    }

    /**
     * A query that fails inside a transaction (a test that checks a unique key
     * refuses a duplicate, say) makes CodeIgniter remember "something failed" on
     * the shared connection, and nothing clears it. Code that later asks
     * transStatus() would then see that old failure and wrongly report its own
     * work as failed, in a different test. Clearing it keeps every test's
     * outcome its own.
     */
    private static function forgetEarlierQueryFailure(): void
    {
        $db = db_connect();

        (new \ReflectionProperty($db, 'transStatus'))->setValue($db, true);
    }

    private function emptyAllTables(): void
    {
        $db = db_connect();

        // Parents and children are emptied in any order with FK checks off.
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($db->listTables() as $table) {
            if ($table !== 'migrations') {
                $db->query('TRUNCATE TABLE `' . $table . '`');
            }
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
