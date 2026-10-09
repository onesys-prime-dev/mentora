<?php

declare(strict_types=1);

use App\Services\Subscriptions\PlanLimitState;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * How full a plan limit is: unlimited, fine, a warning from 90%, full at 100%.
 * Pure arithmetic.
 *
 * @internal
 */
final class PlanLimitStateTest extends CIUnitTestCase
{
    /** @return iterable<string, array{int, ?int, string}> used, limit, state */
    public static function provideStates(): iterable
    {
        yield 'no limit, nothing used' => [0, null, 'unlimited'];
        yield 'no limit, a lot used' => [9_999_999, null, 'unlimited'];
        yield 'nothing used' => [0, 100, 'ok'];
        yield 'half' => [50, 100, 'ok'];
        yield '89 percent' => [89, 100, 'ok'];
        yield 'exactly 90 percent' => [90, 100, 'warning'];
        yield '99 percent' => [99, 100, 'warning'];
        yield 'exactly full' => [100, 100, 'full'];
        yield 'over' => [150, 100, 'full'];
        yield 'rounds down: 89.9 percent' => [899, 1000, 'ok'];
        yield 'rounds down: 90.0 percent' => [900, 1000, 'warning'];
        yield 'a limit of one, unused' => [0, 1, 'ok'];
        yield 'a limit of one, used' => [1, 1, 'full'];
        yield 'a small limit: 9 of 10' => [9, 10, 'warning'];
        yield 'a zero limit is full' => [0, 0, 'full'];
    }

    #[DataProvider('provideStates')]
    public function testTheStateFollowsTheThresholds(int $used, ?int $limit, string $state): void
    {
        $this->assertSame($state, PlanLimitState::of($used, $limit));
    }

    public function testPercentIsRoundedDownAndMayExceedOneHundred(): void
    {
        $this->assertSame(0, PlanLimitState::percent(0, 100));
        $this->assertSame(33, PlanLimitState::percent(1, 3));
        $this->assertSame(99, PlanLimitState::percent(999, 1000));
        $this->assertSame(100, PlanLimitState::percent(100, 100));
        $this->assertSame(150, PlanLimitState::percent(150, 100));
    }

    public function testNoLimitHasNoPercent(): void
    {
        $this->assertNull(PlanLimitState::percent(50, null));
    }

    public function testAZeroLimitCountsAsFullRatherThanDividingByZero(): void
    {
        $this->assertSame(100, PlanLimitState::percent(0, 0));
    }

    /** @return iterable<string, array{int, int, ?int, bool}> used, adding, limit, would exceed */
    public static function provideAdditions(): iterable
    {
        yield 'unlimited never exceeds' => [1_000_000, 1_000_000, null, false];
        yield 'room to spare' => [10, 5, 100, false];
        yield 'fills it exactly' => [95, 5, 100, false];
        yield 'one over' => [95, 6, 100, true];
        yield 'already full, adding one' => [100, 1, 100, true];
        yield 'already full, adding nothing' => [100, 0, 100, false];
        yield 'already over, adding one' => [120, 1, 100, true];
        yield 'one seat left' => [99, 1, 100, false];
        yield 'none left' => [100, 1, 100, true];
    }

    #[DataProvider('provideAdditions')]
    public function testWhetherAnAdditionWouldGoBeyondTheLimit(int $used, int $adding, ?int $limit, bool $exceeds): void
    {
        $this->assertSame($exceeds, PlanLimitState::wouldExceed($used, $adding, $limit));
    }
}
