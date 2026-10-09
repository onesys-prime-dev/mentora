<?php

declare(strict_types=1);

use App\Services\Subscriptions\SubscriptionStatus;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The subscription timeline: running, expiring, grace, read-only, expired.
 * The date is passed in, so each boundary is tested on both sides.
 * The default timeline is 30 days' warning, 14 of grace and 30 read-only.
 *
 * @internal
 */
final class SubscriptionStatusTest extends CIUnitTestCase
{
    private const END = '2026-12-31';

    private static function at(string $today, int $warning = 30, int $grace = 14, int $readOnly = 30, string $end = self::END): SubscriptionStatus
    {
        return SubscriptionStatus::at($end, new DateTimeImmutable($today), $warning, $grace, $readOnly);
    }

    /** @return iterable<string, array{string, string, string}> today, state, institution status */
    public static function provideBoundaries(): iterable
    {
        yield 'long before the end' => ['2026-01-01', 'active', 'active'];
        yield 'the day before warning starts' => ['2026-11-30', 'active', 'active'];
        yield 'the first day of warning' => ['2026-12-01', 'expiring', 'active'];
        yield 'mid warning' => ['2026-12-15', 'expiring', 'active'];
        yield 'the day before the end' => ['2026-12-30', 'expiring', 'active'];
        yield 'the end date itself' => ['2026-12-31', 'expiring', 'active'];
        yield 'the first day after the end' => ['2027-01-01', 'grace', 'active'];
        yield 'the last day of grace' => ['2027-01-14', 'grace', 'active'];
        yield 'the first day of read-only' => ['2027-01-15', 'read_only', 'read_only'];
        yield 'the last day of read-only' => ['2027-02-13', 'read_only', 'read_only'];
        yield 'the first day expired' => ['2027-02-14', 'expired', 'past'];
        yield 'long after' => ['2028-06-01', 'expired', 'past'];
    }

    #[DataProvider('provideBoundaries')]
    public function testEachBoundaryFallsOnTheRightDay(string $today, string $state, string $institution): void
    {
        $status = self::at($today);

        $this->assertSame($state, $status->state);
        $this->assertSame($institution, $status->institutionStatus());
    }

    public function testTheTimeOfDayDoesNotMatter(): void
    {
        $this->assertSame('expiring', SubscriptionStatus::at(self::END, new DateTimeImmutable('2026-12-31 23:59:59'), 30, 14, 30)->state);
        $this->assertSame('grace', SubscriptionStatus::at(self::END, new DateTimeImmutable('2027-01-01 00:00:00'), 30, 14, 30)->state);
    }

    public function testTheKeyDatesAreWorkedOut(): void
    {
        $status = self::at('2026-12-20');

        $this->assertSame('2026-12-31', $status->endDate);
        $this->assertSame('2027-01-14', $status->graceEnds);
        $this->assertSame('2027-02-13', $status->readOnlyEnds);
    }

    public function testDaysToEndCountsDownAndGoesNegative(): void
    {
        $this->assertSame(11, self::at('2026-12-20')->daysToEnd);
        $this->assertSame(0, self::at('2026-12-31')->daysToEnd);
        $this->assertSame(-1, self::at('2027-01-01')->daysToEnd);
        $this->assertSame(364, self::at('2026-01-01')->daysToEnd);
    }

    public function testNoWarningPeriodMeansNeverExpiring(): void
    {
        $this->assertSame('active', self::at('2026-12-31', 0)->state);
        $this->assertSame('grace', self::at('2027-01-01', 0)->state);
    }

    public function testNoGraceGoesStraightToReadOnly(): void
    {
        $this->assertSame('expiring', self::at('2026-12-31', 30, 0)->state);
        $this->assertSame('read_only', self::at('2027-01-01', 30, 0)->state);
    }

    public function testNoReadOnlyGoesStraightFromGraceToExpired(): void
    {
        $this->assertSame('grace', self::at('2027-01-14', 30, 14, 0)->state);
        $this->assertSame('expired', self::at('2027-01-15', 30, 14, 0)->state);
    }

    public function testAllZeroMeansExpiredTheDayAfter(): void
    {
        $this->assertSame('active', self::at('2026-12-31', 0, 0, 0)->state);
        $this->assertSame('expired', self::at('2027-01-01', 0, 0, 0)->state);
    }

    public function testALongerTimelineMovesTheBoundaries(): void
    {
        $this->assertSame('grace', self::at('2027-02-10', 30, 60, 30)->state);
        $this->assertSame('read_only', self::at('2027-03-05', 30, 60, 30)->state);
        $this->assertSame('expired', self::at('2027-04-05', 30, 60, 30)->state);
    }

    public function testBoundariesCrossMonthsAndLeapYears(): void
    {
        // A February end date in a leap year, then grace across the month boundary.
        $this->assertSame('grace', self::at('2028-02-29', 30, 14, 30, '2028-02-28')->state);
        $this->assertSame('2028-03-13', self::at('2028-02-29', 30, 14, 30, '2028-02-28')->graceEnds);
        $this->assertSame('read_only', self::at('2028-03-14', 30, 14, 30, '2028-02-28')->state);
    }

    public function testOnlyReadOnlyAndExpiredChangeTheInstitution(): void
    {
        foreach ([['active', 'active'], ['expiring', 'active'], ['grace', 'active'], ['read_only', 'read_only'], ['expired', 'past']] as [$state, $institution]) {
            $today = ['active' => '2026-01-01', 'expiring' => '2026-12-15', 'grace' => '2027-01-05', 'read_only' => '2027-01-20', 'expired' => '2027-03-01'][$state];

            $this->assertSame($institution, self::at($today)->institutionStatus(), $state);
        }
    }
}
