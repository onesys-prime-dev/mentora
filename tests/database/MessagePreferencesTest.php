<?php

declare(strict_types=1);

use App\Services\Messaging\MessagePreferences;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ParentScenario;

/**
 * Email alerts for everyone, quiet hours for the roles that are offered them.
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
        $this->assertSame(['quiet_start' => null, 'quiet_end' => null, 'email_alerts' => true], $this->prefs()->get((int) $this->instructor->id));
    }

    public function testQuietHoursAreSavedAsHoursAndMinutes(): void
    {
        $this->assertTrue($this->prefs()->save($this->instructor, '22:00', '07:30', false));
        $this->assertSame(['quiet_start' => '22:00', 'quiet_end' => '07:30', 'email_alerts' => false], $this->prefs()->get((int) $this->instructor->id));

        $this->assertTrue($this->prefs()->save($this->instructor, '', '', true), 'empty clears them');
        $this->assertSame(['quiet_start' => null, 'quiet_end' => null, 'email_alerts' => true], $this->prefs()->get((int) $this->instructor->id));
    }

    public function testBadTimesAndHalfAPairAreRefusedAndChangeNothing(): void
    {
        $this->prefs()->save($this->instructor, '22:00', '07:00', true);

        foreach ([['25:00', '07:00'], ['22:00', '7am'], ['22:60', '07:00'], ['22:00', null], [null, '07:00'], ['22:00', '24:00']] as [$start, $end]) {
            $this->assertFalse($this->prefs()->save($this->instructor, $start, $end, false), json_encode([$start, $end]));
        }

        $this->assertSame('22:00', $this->prefs()->get((int) $this->instructor->id)['quiet_start']);
        $this->assertTrue($this->prefs()->get((int) $this->instructor->id)['email_alerts']);
    }

    public function testOnlyInstructorsChairpersonsAndDeansAreOfferedQuietHoursButEveryoneGetsTheEmailSwitch(): void
    {
        $dean = \Tests\Support\DeanFactory::dean($this->institution, $this->department);

        foreach ([$this->instructor, $this->chair, $dean] as $allowed) {
            $this->assertTrue($this->prefs()->quietHoursAllowed($allowed), (string) $allowed->first_name);
            $this->assertTrue($this->prefs()->save($allowed, '21:00', '06:00', true), (string) $allowed->first_name);
        }

        foreach ([$this->child, $this->parent] as $other) {
            $this->assertFalse($this->prefs()->quietHoursAllowed($other), (string) $other->first_name);
            $this->assertFalse($this->prefs()->save($other, '21:00', '06:00', true), (string) $other->first_name);
            $this->assertTrue($this->prefs()->save($other, null, null, false), 'the email switch is for everyone');
            $this->assertFalse($this->prefs()->get((int) $other->id)['email_alerts']);
        }
    }

    public function testQuietHoursThatSpanMidnightAndThoseThatDoNot(): void
    {
        $id = (int) $this->instructor->id;

        $this->prefs()->save($this->instructor, '22:00', '07:00', true);
        $this->assertTrue($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 22:00:00')));
        $this->assertTrue($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 03:15:00')));
        $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 07:00:00')), 'the end is not quiet');
        $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 12:00:00')));

        $this->prefs()->save($this->instructor, '13:00', '14:00', true);
        $this->assertTrue($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 13:30:00')));
        $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 23:30:00')));

        $this->prefs()->save($this->instructor, '09:00', '09:00', true);
        $this->assertFalse($this->prefs()->isQuiet($id, new DateTimeImmutable('2026-11-03 09:00:00')), 'equal times are never quiet');
    }
}
