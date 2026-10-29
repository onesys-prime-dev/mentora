<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\TestEnv;

/**
 * A test sets the settings it needs itself, whatever the local .env holds (audit decision 14.1).
 *
 * @internal
 */
final class TestEnvTest extends CIUnitTestCase
{
    private const NAME = 'testEnv.probeSetting';

    protected function setUp(): void
    {
        parent::setUp();

        // As a developer's .env would have it.
        $_ENV[self::NAME]    = 'from-dotenv';
        $_SERVER[self::NAME] = 'from-dotenv';
        putenv(self::NAME . '=from-dotenv');
    }

    protected function tearDown(): void
    {
        TestEnv::restore();
        unset($_ENV[self::NAME], $_SERVER[self::NAME]);
        putenv(self::NAME);

        parent::tearDown();
    }

    public function testASettingIsSeenByEnvWhateverDotEnvSays(): void
    {
        TestEnv::set(self::NAME, 'from-test');

        $this->assertSame('from-test', env(self::NAME));
    }

    public function testUnsetHidesADotEnvValue(): void
    {
        TestEnv::unset(self::NAME);

        $this->assertSame('none', env(self::NAME, 'none'));
    }

    public function testRestorePutsTheDotEnvValueBack(): void
    {
        TestEnv::set(self::NAME, 'from-test');
        TestEnv::restore();

        $this->assertSame('from-dotenv', env(self::NAME));
        $this->assertSame('from-dotenv', getenv(self::NAME));
    }
}
