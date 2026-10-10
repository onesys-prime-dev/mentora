<?php

declare(strict_types=1);

use App\Services\Admin\GeneralSettings;
use App\Services\Admin\PlatformSettings;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * The general settings as a set: defaults, saving, and that a bad value keeps
 * everything else unsaved.
 *
 * @internal
 */
final class GeneralSettingsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function input(array $override = []): array
    {
        return array_merge([
            'systemName' => 'Acme LMS', 'supportContact' => '', 'timezone' => 'UTC', 'maintenanceMessage' => 'Soon.', 'announcement' => '',
            'expiryWarningDays' => '30', 'expiryGraceDays' => '14', 'expiryReadOnlyDays' => '30', 'backupAlertHours' => '24',
        ], $override);
    }

    public function testTheDefaults(): void
    {
        $all = (new GeneralSettings())->all();

        $this->assertSame('Mentora', $all['systemName']);
        $this->assertSame('UTC', $all['timezone']);
        $this->assertFalse((bool) $all['maintenanceMode']);
        $this->assertSame('', $all['announcement']);
    }

    public function testSavingStoresTheSetAndTheMaintenanceSwitch(): void
    {
        (new GeneralSettings())->save($this->input(['maintenanceMode' => '1', 'announcement' => ' Hello ']));

        $all = (new GeneralSettings())->all();

        $this->assertSame('Acme LMS', $all['systemName']);
        $this->assertTrue((bool) $all['maintenanceMode']);
        $this->assertSame('Hello', $all['announcement'], 'trimmed');

        (new GeneralSettings())->save($this->input());

        $this->assertFalse((bool) (new GeneralSettings())->all()['maintenanceMode'], 'an unticked box turns it off');
    }

    public function testABadValueSavesNothingAtAll(): void
    {
        try {
            (new GeneralSettings())->save($this->input(['systemName' => 'Changed', 'backupAlertHours' => '0']));
            $this->fail('This should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Backup', $e->getMessage());
        }

        $this->assertSame('Mentora', (new PlatformSettings())->get('systemName'));
    }

    public function testNonTextIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GeneralSettings())->save($this->input(['systemName' => ['x']]));
    }
}
