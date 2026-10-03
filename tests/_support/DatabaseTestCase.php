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

    /** Never roll the schema back; tests clean up the rows they create. */
    protected $refresh = false;

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
    }
}
