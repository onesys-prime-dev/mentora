<?php

declare(strict_types=1);

use App\Services\Messaging\MessagePreferences;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ParentScenario;

/**
 * Email alerts and quiet hours for everyone: the school default, their own times, or none.
 *
 * @internal
 */
final class MessagePreferencesTest extends DatabaseTestCase
{
    use ParentScenario;

    protected bool $isolateTests = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildParentScenario('MP');
    }

    private function prefs(): MessagePreferences
    {
        return new MessagePreferences();
    }

    public function testTheDefaultsAreNoQuietHoursAndEmailsOn(): void
    {
        $this->assertSame(['quiet_mode' => 'default', 'quiet_start' => null, 'quiet_end' => null, 'email_alerts' => true, 'email_scope' => 'everyone'], $this->prefs()->get((int) $this->instructor->id));
    }

    public function testQuietHoursAreSavedAsHoursAndMinutes(): void
    {
        $this->assertTrue($this->prefs()->save($this->instructor, '22:00', '07:30', 'off', 'own'));
        $this->assertSame(['quiet_mode' => 'own', 'quiet_start' => '22:00', 'quiet_end' => '07:30', 'email_alerts' => false, 'email_scope' => 'off'], $this->prefs()->get((int) $this->instructor->id));

        $this->assertTrue($this->prefs()->save($this->instructor, '', '', 'everyone', 'default'), 'empty clears them');
        $this->assertSame(['quiet_mode' => 'default', 'quiet_start' => null, 'quiet_end' => null, 'email_alerts' => true, 'email_scope' => 'everyone'], $this->prefs()->get((int) $this->instructor->id));
    }

    public function testEachScopeRoundTripsAndAnUnknownOneIsRefusedWithNothingStored(): void
    {
        foreach (['talked', 'off', 'everyone', 'talked'] as $scope) {
            $this->assertTrue($this->prefs()->save($this->instructor, null, null, $scope, 'default'));
            $this->assertSame($scope, $this->prefs()->get((int) $this->instructor->id)['email_scope']);
        }

        foreach (['', 'all', 'Off', 'talked '] as $bad) {
            $this->assertFalse($this->prefs()->save($this->instructor, null, null, $bad, 'default'), $bad);
        }

        $this->assertSame('talked', $this->prefs()->get((int) $this->instructor->id)['email_scope']);
    }

    public function testBadTimesAndHalfAPairAreRefusedAndChangeNothing(): void
    {
        $this->prefs()->save($this->instructor, '22:00', '07:00', 'everyone', 'own');

        foreach ([['25:00', '07:00'], ['22:00', '7am'], ['22:60', '07:00'], ['22:00', null], [null, '07:00'], ['22:00', '24:00']] as [$start, $end]) {
            $this->assertFalse($this->prefs()->save($this->instructor, $start, $end, 'off', 'own'), json_encode([$start, $end]));
        }

        $this->assertSame('22:00', $this->prefs()->get((int) $this->instructor->id)['quiet_start']);
        $this->assertTrue($this->prefs()->get((int) $this->instructor->id)['email_alerts']);
    }

    public function testStudentCanSetQuietHours(): void
    {
        foreach ([$this->instructor, $this->child, $this->parent] as $person) {
            $this->assertTrue($this->prefs()->save($person, '21:00', '06:00', 'everyone', 'own'), (string) $person->first_name);
            $this->assertSame(['own', '21:00', '06:00'], array_values(array_intersect_key($this->prefs()->get((int) $person->id), array_flip(['quiet_mode', 'quiet_start', 'quiet_end']))));
        }

        $this->assertTrue($this->prefs()->save($this->child, null, null, 'off', 'default'));
        $this->assertFalse($this->prefs()->get((int) $this->child->id)['email_alerts']);
    }

    private function schoolDefault(string $start, string $end): void
    {
        $settings = new \App\Services\Admin\PlatformSettings();
        $settings->set('defaultQuietStart', $start, $this->institution);
        $settings->set('defaultQuietEnd', $end, $this->institution);
    }

    public function testNeverSetUsesInstitutionDefault(): void
    {
        $id = (int) $this->child->id;
        $this->schoolDefault('21:00', '07:00');

        $this->assertTrue($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 22:30:00'), $this->institution));
        $this->assertTrue($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 22:30:00')), 'the institution is looked up when not given');
        $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 12:00:00'), $this->institution));
        $this->assertSame(['default', '21:00', '07:00'], [$this->prefs()->get($id)['quiet_mode'], $this->prefs()->get($id)['quiet_start'], $this->prefs()->get($id)['quiet_end']]);
    }

    public function testOwnTimesOverrideTheDefaultAndNoneOverridesIt(): void
    {
        $id = (int) $this->child->id;
        $this->schoolDefault('21:00', '07:00');

        $this->prefs()->save($this->child, '13:00', '14:00', 'everyone', 'own');
        $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 22:30:00'), $this->institution));
        $this->assertTrue($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 13:30:00'), $this->institution));

        $this->assertTrue($this->prefs()->save($this->child, null, null, 'everyone', 'none'));
        $this->assertSame(['none', null, null], [$this->prefs()->get($id)['quiet_mode'], $this->prefs()->get($id)['quiet_start'], $this->prefs()->get($id)['quiet_end']]);
        $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 22:30:00'), $this->institution));

        $this->assertTrue($this->prefs()->save($this->child, null, null, 'everyone', 'default'));
        $this->assertTrue($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 22:30:00'), $this->institution));
    }

    public function testAnUnknownModeOrOwnWithoutTimesIsRefused(): void
    {
        $this->assertFalse($this->prefs()->save($this->child, null, null, 'everyone', 'sometimes'));
        $this->assertFalse($this->prefs()->save($this->child, null, null, 'everyone', 'own'));
        $this->assertSame('default', $this->prefs()->get((int) $this->child->id)['quiet_mode']);
    }

    public function testQuietHoursThatSpanMidnightAndThoseThatDoNot(): void
    {
        $id = (int) $this->instructor->id;

        $this->prefs()->save($this->instructor, '22:00', '07:00', 'everyone', 'own');
        $this->assertTrue($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 22:00:00')));
        $this->assertTrue($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 03:15:00')));
        $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 07:00:00')), 'the end is not quiet');
        $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 12:00:00')));

        $this->prefs()->save($this->instructor, '13:00', '14:00', 'everyone', 'own');
        $this->assertTrue($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 13:30:00')));
        $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 23:30:00')));

        $this->prefs()->save($this->instructor, '09:00', '09:00', 'everyone', 'own');
        $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 09:00:00')), 'equal times are never quiet');
    }

    public function testQuietHoursAreReadInThePlatformTimezone(): void
    {
        $settings = new \App\Services\Admin\PlatformSettings();
        $original = $settings->get('timezone');
        $settings->set('timezone', 'Asia/Manila');

        try {
            $this->prefs()->save($this->instructor, '22:00', '07:00', 'everyone', 'own');
            $id = (int) $this->instructor->id;

            // 15:00 UTC is 23:00 in Manila (quiet); 03:00 UTC is 11:00 there (not quiet).
            $this->assertTrue($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 15:00:00', new DateTimeZone('UTC'))));
            $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 03:00:00', new DateTimeZone('UTC'))));
        } finally {
            $settings->set('timezone', (string) $original);
        }
    }
}
