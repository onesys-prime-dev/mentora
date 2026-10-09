<?php

declare(strict_types=1);

use App\Services\Institutions\PurgePolicy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * When a deleted institution is due to be purged. The clock is passed in, so
 * the boundaries are exact.
 *
 * @internal
 */
final class PurgePolicyTest extends CIUnitTestCase
{
    private const DELETED = '2026-07-01 09:30:00';

    private static function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    public function testItIsDueExactlyRetentionDaysAfterTheDeletion(): void
    {
        $this->assertSame('2026-09-29 09:30:00', PurgePolicy::dueAt(self::DELETED, 90)->format('Y-m-d H:i:s'));
    }

    public function testNotDueOneSecondBeforeTheMoment(): void
    {
        $this->assertFalse(PurgePolicy::isDue(self::DELETED, self::at('2026-09-29 09:29:59'), 90));
    }

    public function testDueAtTheMoment(): void
    {
        $this->assertTrue(PurgePolicy::isDue(self::DELETED, self::at('2026-09-29 09:30:00'), 90));
    }

    public function testStillDueLongAfter(): void
    {
        $this->assertTrue(PurgePolicy::isDue(self::DELETED, self::at('2027-01-01 00:00:00'), 90));
    }

    public function testNotDueRightAfterTheDeletion(): void
    {
        $this->assertFalse(PurgePolicy::isDue(self::DELETED, self::at(self::DELETED), 90));
    }

    public function testADifferentRetentionPeriodMovesTheMoment(): void
    {
        $this->assertFalse(PurgePolicy::isDue(self::DELETED, self::at('2026-07-20 00:00:00'), 30));
        $this->assertTrue(PurgePolicy::isDue(self::DELETED, self::at('2026-07-31 09:30:00'), 30));
        $this->assertTrue(PurgePolicy::isDue(self::DELETED, self::at('2026-07-31 09:30:00'), 30));
        $this->assertFalse(PurgePolicy::isDue(self::DELETED, self::at('2026-09-29 09:30:00'), 365));
    }

    public function testDaysLeftRoundsUpAndNeverGoesBelowZero(): void
    {
        $this->assertSame(90, PurgePolicy::daysLeft(self::DELETED, self::at(self::DELETED), 90));
        $this->assertSame(90, PurgePolicy::daysLeft(self::DELETED, self::at('2026-07-01 09:30:01'), 90), 'a part of a day counts as a day');
        $this->assertSame(89, PurgePolicy::daysLeft(self::DELETED, self::at('2026-07-02 09:30:00'), 90));
        $this->assertSame(1, PurgePolicy::daysLeft(self::DELETED, self::at('2026-09-28 09:30:01'), 90));
        $this->assertSame(0, PurgePolicy::daysLeft(self::DELETED, self::at('2026-09-29 09:30:00'), 90));
        $this->assertSame(0, PurgePolicy::daysLeft(self::DELETED, self::at('2027-06-01 00:00:00'), 90));
    }

    public function testTheCutoffIsTheNewestDeletionThatIsDue(): void
    {
        $now    = self::at('2026-10-12 12:00:00');
        $cutoff = PurgePolicy::cutoff($now, 90);

        $this->assertSame('2026-07-14 12:00:00', $cutoff->format('Y-m-d H:i:s'));

        // Whatever was deleted at or before the cutoff is due now; anything later is not.
        $this->assertTrue(PurgePolicy::isDue($cutoff->format('Y-m-d H:i:s'), $now, 90));
        $this->assertFalse(PurgePolicy::isDue($cutoff->modify('+1 second')->format('Y-m-d H:i:s'), $now, 90));
    }
}
