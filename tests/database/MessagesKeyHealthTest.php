<?php

declare(strict_types=1);

use App\Models\Flags\FeatureFlagOverrideModel;
use App\Services\Flags\FeatureFlagAdmin;
use App\Services\Monitoring\SystemHealth;
use CodeIgniter\Config\Services;
use Config\Messages;
use Tests\Support\DatabaseTestCase;
use Tests\Support\DeanFactory;

/**
 * Fail closed (spec 5.1): the flag cannot be switched on without a usable key, and the health
 * check says so when Messages is on without one.
 *
 * @internal
 */
final class MessagesKeyHealthTest extends DatabaseTestCase
{
    private const KEY = 'hex2bin:6161616161616161616161616161616161616161616161616161616161616161';

    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        $this->setKey('', '');
    }

    protected function tearDown(): void
    {
        $this->setKey('', '');

        parent::tearDown();
    }

    private function setKey(string $key, string $previous): void
    {
        $config                = config(Messages::class);
        $config->encryptionKey = $key;
        $config->previousKeys  = $previous;
    }

    /** @return array{status: string, detail: string} */
    private function messagesCheck(): array
    {
        $up = static fn (): array => ['status' => SystemHealth::UP, 'detail' => ''];

        return (new SystemHealth(['database' => $up, 'storage' => $up, 'disk' => $up, 'ai' => $up, 'jobs' => $up, 'backups' => $up]))->run()['messages'];
    }

    private function switchOn(): void
    {
        (new FeatureFlagOverrideModel())->insert(['flag_key' => 'messages', 'institution_id' => null, 'enabled' => 1]);
    }

    public function testOffWithNoKeyIsFine(): void
    {
        $this->assertSame(SystemHealth::UP, $this->messagesCheck()['status']);
        $this->assertSame('Messages is off.', $this->messagesCheck()['detail']);
    }

    public function testOnWithNoKeyIsDownAndSaysHowToFixIt(): void
    {
        $this->switchOn();

        $check = $this->messagesCheck();

        $this->assertSame(SystemHealth::DOWN, $check['status']);
        $this->assertStringContainsString('messages.encryptionKey', $check['detail']);
    }

    public function testOnWithAShortKeyIsDown(): void
    {
        $this->switchOn();
        $this->setKey('too-short', '');

        $this->assertSame(SystemHealth::DOWN, $this->messagesCheck()['status']);
    }

    public function testAUsableKeyIsUpAndTheDetailNeverHoldsKeyMaterial(): void
    {
        $this->switchOn();
        $this->setKey(self::KEY, '');

        $check = $this->messagesCheck();

        $this->assertSame(SystemHealth::UP, $check['status']);
        $this->assertStringNotContainsString('6161', $check['detail']);
    }

    public function testAMalformedPreviousKeyIsDegraded(): void
    {
        $this->setKey(self::KEY, 'nope');

        $check = $this->messagesCheck();

        $this->assertSame(SystemHealth::DEGRADED, $check['status']);
        $this->assertStringContainsString('1 previous', $check['detail']);
    }

    public function testTheFlagCannotBeSwitchedOnWithoutAKey(): void
    {
        $admin = new FeatureFlagAdmin();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('encryption key');

        $admin->setGlobal('messages', FeatureFlagAdmin::ON, 'Pilot', null);
    }

    public function testItCannotBeSwitchedOnForOneInstitutionEither(): void
    {
        $institution = DeanFactory::department('KH1')['institution'];

        try {
            (new FeatureFlagAdmin())->setForInstitution($institution, 'messages', FeatureFlagAdmin::ON, 'Pilot', null);
            $this->fail('switching it on without a key must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('encryption key', $e->getMessage());
        }

        $this->assertSame(0, db_connect()->table('feature_flag_overrides')->where('flag_key', 'messages')->countAllResults());
    }

    public function testWithAKeyItCanBeSwitchedOnAndOffAndOffNeedsNoKey(): void
    {
        $admin = new FeatureFlagAdmin();

        $this->setKey(self::KEY, '');
        $this->assertTrue($admin->setGlobal('messages', FeatureFlagAdmin::ON, 'Pilot', null));

        $this->setKey('', '');
        $this->assertTrue($admin->setGlobal('messages', FeatureFlagAdmin::OFF, 'Pause', null), 'turning it off never needs a key');
    }
}
