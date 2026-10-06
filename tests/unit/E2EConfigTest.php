<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Database;

/**
 * The end-to-end test switches in app/Config/App.php and Database.php. The
 * database one is a safety guard (the browser tests reset and reseed their
 * database), so what it refuses matters as much as what it allows.
 *
 * @internal
 */
final class E2EConfigTest extends CIUnitTestCase
{
    private const VARIABLES = ['E2E_DATABASE', 'E2E_DB_HOST', 'E2E_DB_USER', 'E2E_DB_PASSWORD', 'E2E_BASE_URL'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearVariables();
    }

    protected function tearDown(): void
    {
        $this->clearVariables();

        parent::tearDown();
    }

    private function clearVariables(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    private function set(string $name, string $value): void
    {
        putenv("{$name}={$value}");
    }

    public function testNothingChangesWhenTheSwitchesAreNotSet(): void
    {
        $plain = new Database();

        $this->assertNotSame('mentora_e2e', $plain->default['database']);
        $this->assertStringNotContainsString('127.0.0.1:8089', (new App())->baseURL);
    }

    public function testTheE2eDatabaseReplacesTheDefaultConnection(): void
    {
        $this->set('E2E_DATABASE', 'mentora_e2e');

        $config = new Database();

        $this->assertSame('mentora_e2e', $config->default['database']);
        $this->assertSame('127.0.0.1', $config->default['hostname']);
        $this->assertSame('root', $config->default['username']);
        $this->assertSame('', $config->default['password']);
    }

    public function testTheE2eConnectionDetailsCanBeSet(): void
    {
        $this->set('E2E_DATABASE', 'app_test_run');
        $this->set('E2E_DB_HOST', 'db.internal');
        $this->set('E2E_DB_USER', 'tester');
        $this->set('E2E_DB_PASSWORD', 's3cret');

        $config = new Database();

        $this->assertSame('app_test_run', $config->default['database']);
        $this->assertSame('db.internal', $config->default['hostname']);
        $this->assertSame('tester', $config->default['username']);
        $this->assertSame('s3cret', $config->default['password']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNamesThatAreNotTestDatabases(): iterable
    {
        yield 'the real database' => ['mentora_db'];
        yield 'production' => ['mentora'];
        yield 'staging' => ['mentora_staging'];
        yield 'a name that only contains e' => ['mentora_e'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideNamesThatAreNotTestDatabases')]
    public function testADatabaseThatDoesNotLookLikeATestDatabaseIsRefused(string $name): void
    {
        $this->set('E2E_DATABASE', $name);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Refusing to use '{$name}'");

        new Database();
    }

    public function testTheBaseUrlIsTakenFromTheE2eSwitchWithOneTrailingSlash(): void
    {
        $this->set('E2E_BASE_URL', 'http://127.0.0.1:8089///');

        $this->assertSame('http://127.0.0.1:8089/', (new App())->baseURL);
    }
}
