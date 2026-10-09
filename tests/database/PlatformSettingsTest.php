<?php

declare(strict_types=1);

use App\Services\Admin\PlatformSettings;
use CodeIgniter\Config\Services;
use Config\Platform;
use Tests\Support\DatabaseTestCase;

/**
 * PlatformSettings against the real settings table: defaults, saved global
 * values, and the per-institution override that wins for that institution
 * only.
 *
 * @internal
 */
final class PlatformSettingsTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private PlatformSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new PlatformSettings();

        // The settings service caches what it read; start every test with a fresh one.
        Services::resetSingle('settings');
    }

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */

    public function testNothingSavedGivesTheDefault(): void
    {
        $this->assertSame(config(Platform::class)->auditRetentionDays, $this->settings->get('auditRetentionDays'));
        $this->assertSame(730, $this->settings->get('auditRetentionDays'));
        $this->assertFalse($this->settings->get('auditShowSourceIp'));
    }

    public function testEveryDefinedKeyHasADefaultOfTheRightType(): void
    {
        foreach ($this->settings->definitions() as $name => $definition) {
            $value = $this->settings->get($name);

            $this->assertSame($definition['type'], match (true) {
                is_int($value)    => 'int',
                is_bool($value)   => 'bool',
                is_string($value) => 'string',
                default           => 'other',
            }, "{$name} default has the wrong type");

            // And the default must itself pass its own rules.
            $this->assertSame($value, PlatformSettings::coerce($definition, $value), "{$name} default breaks its own limits");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Saving
    |--------------------------------------------------------------------------
    */

    public function testASavedValueWinsOverTheDefaultAndKeepsItsType(): void
    {
        $this->settings->set('impersonationMinutes', 45);
        $this->settings->set('auditShowSourceIp', true);

        Services::resetSingle('settings');

        $this->assertSame(45, $this->settings->get('impersonationMinutes'));
        $this->assertTrue($this->settings->get('auditShowSourceIp'));
    }

    public function testANumberTypedAsTextIsSavedAsANumber(): void
    {
        $this->settings->set('backupAlertHours', '12');

        Services::resetSingle('settings');

        $this->assertSame(12, $this->settings->get('backupAlertHours'));
    }

    public function testForgettingASavedValueBringsTheDefaultBack(): void
    {
        $this->settings->set('backupAlertHours', 48);
        $this->settings->forget('backupAlertHours');

        $this->assertSame(24, $this->settings->get('backupAlertHours'));
    }

    public function testAnInvalidValueIsRefusedAndNothingIsSaved(): void
    {
        try {
            $this->settings->set('impersonationMinutes', 1000);
            $this->fail('1000 minutes is above the limit');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(30, $this->settings->get('impersonationMinutes'));
        $this->assertSame(0, db_connect()->table('settings')->where('key', 'impersonationMinutes')->countAllResults());
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown setting');

        $this->settings->set('notASetting', 1);
    }

    public function testAnUnknownKeyCannotBeRead(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->get('notASetting');
    }

    public function testAnUnusableStoredValueFallsBackToTheDefault(): void
    {
        // Someone edited the table by hand into something outside the limits.
        service('settings')->set('Platform.impersonationMinutes', 99999);
        Services::resetSingle('settings');

        $this->assertSame(30, $this->settings->get('impersonationMinutes'));
    }

    /*
    |--------------------------------------------------------------------------
    | Per institution
    |--------------------------------------------------------------------------
    */

    public function testAnInstitutionWithNoOverrideSeesTheGlobalValue(): void
    {
        $this->settings->set('expiryGraceDays', 21);

        $this->assertSame(21, $this->settings->get('expiryGraceDays', 5));
        $this->assertFalse($this->settings->hasOverride('expiryGraceDays', 5));
    }

    public function testAnInstitutionOverrideWinsForThatInstitutionOnly(): void
    {
        $this->settings->set('expiryGraceDays', 21);
        $this->settings->set('expiryGraceDays', 60, 5);

        Services::resetSingle('settings');

        $this->assertSame(60, $this->settings->get('expiryGraceDays', 5));
        $this->assertSame(21, $this->settings->get('expiryGraceDays', 6), 'another institution is unaffected');
        $this->assertSame(21, $this->settings->get('expiryGraceDays'), 'the global value is unaffected');
        $this->assertTrue($this->settings->hasOverride('expiryGraceDays', 5));
        $this->assertFalse($this->settings->hasOverride('expiryGraceDays', 6));
    }

    public function testRemovingAnInstitutionOverrideFallsBackToTheGlobalValue(): void
    {
        $this->settings->set('expiryGraceDays', 21);
        $this->settings->set('expiryGraceDays', 60, 5);
        $this->settings->forget('expiryGraceDays', 5);

        $this->assertSame(21, $this->settings->get('expiryGraceDays', 5));
        $this->assertFalse($this->settings->hasOverride('expiryGraceDays', 5));
    }

    public function testAnInstitutionOverrideIsValidatedLikeAnyOtherValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->set('expiryGraceDays', 9999, 5);
    }

    public function testAPlatformWideKeyCannotBeOverriddenPerInstitution(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('platform-wide');

        $this->settings->set('auditRetentionDays', 365, 5);
    }

    public function testAPlatformWideKeyCannotBeReadPerInstitution(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings->get('auditRetentionDays', 5);
    }

    /*
    |--------------------------------------------------------------------------
    | The audit trail
    |--------------------------------------------------------------------------
    */

    /** @return list<array<string, mixed>> */
    private function auditEntries(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id')->get()->getResultArray();
    }

    public function testChangingASettingIsRecordedWithOldAndNewValue(): void
    {
        $this->settings->set('impersonationMinutes', 45);

        $entries = $this->auditEntries('settings.changed');

        $this->assertCount(1, $entries);
        $this->assertSame('setting', $entries[0]['target_type']);
        $this->assertSame('impersonationMinutes', $entries[0]['target_id']);
        $this->assertSame(['impersonationMinutes' => 30], json_decode($entries[0]['old_values'], true));
        $this->assertSame(['impersonationMinutes' => 45], json_decode($entries[0]['new_values'], true));
    }

    public function testTheSignedInPersonIsTheActor(): void
    {
        $admin = \Tests\Support\CourseFactory::user('admin');
        auth()->login($admin);

        $this->settings->set('backupAlertHours', 12);

        auth()->logout();

        $this->assertSame((string) $admin->id, (string) $this->auditEntries('settings.changed')[0]['actor_user_id']);
    }

    public function testSavingTheValueAlreadyInForceLeavesNoEntry(): void
    {
        $this->settings->set('impersonationMinutes', 30);

        $this->assertSame([], $this->auditEntries('settings.changed'));
    }

    public function testASecondChangeShowsTheFirstAsItsOldValue(): void
    {
        $this->settings->set('impersonationMinutes', 45);
        $this->settings->set('impersonationMinutes', 60);

        $entries = $this->auditEntries('settings.changed');

        $this->assertCount(2, $entries);
        $this->assertSame(['impersonationMinutes' => 45], json_decode($entries[1]['old_values'], true));
        $this->assertSame(['impersonationMinutes' => 60], json_decode($entries[1]['new_values'], true));
    }

    public function testAnInstitutionOverrideIsRecordedAgainstThatInstitution(): void
    {
        $this->settings->set('expiryGraceDays', 60, 5);

        $entry = $this->auditEntries('settings.changed')[0];

        $this->assertSame('5', (string) $entry['institution_id']);
        $this->assertSame(['expiryGraceDays' => 14], json_decode($entry['old_values'], true));
        $this->assertSame(['expiryGraceDays' => 60], json_decode($entry['new_values'], true));
    }

    public function testResettingASettingIsRecorded(): void
    {
        $this->settings->set('backupAlertHours', 48);
        $this->settings->forget('backupAlertHours');

        $entries = $this->auditEntries('settings.reset');

        $this->assertCount(1, $entries);
        $this->assertSame(['backupAlertHours' => 48], json_decode($entries[0]['old_values'], true));
        $this->assertSame(['backupAlertHours' => 24], json_decode($entries[0]['new_values'], true));
    }

    public function testResettingASettingThatWasNeverSetLeavesNoEntry(): void
    {
        $this->settings->forget('backupAlertHours');

        $this->assertSame([], $this->auditEntries('settings.reset'));
    }

    public function testARefusedValueLeavesNoEntry(): void
    {
        try {
            $this->settings->set('impersonationMinutes', 1000);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame([], $this->auditEntries('settings.changed'));
    }
}
