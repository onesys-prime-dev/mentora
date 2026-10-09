<?php

declare(strict_types=1);

use App\Services\Subscriptions\SubscriptionInput;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the subscription forms may send: the plan, the period, and the
 * reference-only payment details. Pure logic, no database.
 *
 * @internal
 */
final class SubscriptionInputTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private static function valid(array $override = []): array
    {
        return array_merge(['plan_id' => '3', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'amount' => '', 'currency' => '', 'reference' => '', 'notes' => ''], $override);
    }

    public function testAMinimalTermHasNoPaymentDetailsAndTheDefaultCurrency(): void
    {
        $this->assertSame(
            ['plan_id' => 3, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'amount' => null, 'currency' => 'PHP', 'reference' => null, 'notes' => null],
            SubscriptionInput::term(self::valid()),
        );
    }

    public function testAFullTermIsReadAsGiven(): void
    {
        $term = SubscriptionInput::term(self::valid(['amount' => '1,250,000.5', 'currency' => ' usd ', 'reference' => ' INV-2026-001 ', 'notes' => ' Paid by transfer. ']));

        $this->assertSame('1250000.50', $term['amount']);
        $this->assertSame('USD', $term['currency']);
        $this->assertSame('INV-2026-001', $term['reference']);
        $this->assertSame('Paid by transfer.', $term['notes']);
    }

    /*
    |--------------------------------------------------------------------------
    | Plan
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{mixed}> */
    public static function provideBadPlans(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-2'];
        yield 'words' => ['gold'];
        yield 'a decimal' => ['2.5'];
        yield 'an array' => [['3']];
    }

    #[DataProvider('provideBadPlans')]
    public function testABadPlanIsRefused(mixed $plan): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Choose a plan');

        SubscriptionInput::term(self::valid(['plan_id' => $plan]));
    }

    public function testAnIntegerPlanIdIsAccepted(): void
    {
        $this->assertSame(7, SubscriptionInput::term(self::valid(['plan_id' => 7]))['plan_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Dates
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{mixed}> */
    public static function provideBadDates(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'words' => ['next year'];
        yield 'wrong order' => ['01-01-2026'];
        yield 'not a real day' => ['2026-02-30'];
        yield 'month 13' => ['2026-13-01'];
        yield 'with a time' => ['2026-01-01 10:00'];
        yield 'an array' => [['2026-01-01']];
    }

    #[DataProvider('provideBadDates')]
    public function testABadStartDateIsRefused(mixed $date): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('start date');

        SubscriptionInput::term(self::valid(['period_start' => $date]));
    }

    #[DataProvider('provideBadDates')]
    public function testABadEndDateIsRefused(mixed $date): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('end date');

        SubscriptionInput::term(self::valid(['period_end' => $date]));
    }

    public function testTheEndMustBeAfterTheStart(): void
    {
        foreach (['2026-01-01', '2025-12-31'] as $end) {
            try {
                SubscriptionInput::term(self::valid(['period_start' => '2026-01-01', 'period_end' => $end]));
                $this->fail("{$end} must not be accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('after the start', $e->getMessage());
            }
        }

        $this->assertSame('2026-01-02', SubscriptionInput::term(self::valid(['period_start' => '2026-01-01', 'period_end' => '2026-01-02']))['period_end']);
    }

    public function testAPeriodOfMoreThanTenYearsIsRefused(): void
    {
        $this->assertSame('2036-01-01', SubscriptionInput::term(self::valid(['period_start' => '2026-01-01', 'period_end' => '2036-01-01']))['period_end']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 10 years');

        SubscriptionInput::term(self::valid(['period_start' => '2026-01-01', 'period_end' => '2036-01-02']));
    }

    /*
    |--------------------------------------------------------------------------
    | Amount
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{mixed, ?string}> */
    public static function provideAmounts(): iterable
    {
        yield 'empty' => ['', null];
        yield 'blank' => ['   ', null];
        yield 'null' => [null, null];
        yield 'whole' => ['1000', '1000.00'];
        yield 'one decimal' => ['10.5', '10.50'];
        yield 'two decimals' => ['10.55', '10.55'];
        yield 'thousands separators' => ['12,345.60', '12345.60'];
        yield 'zero' => ['0', '0.00'];
        yield 'an int' => [250, '250.00'];
        yield 'a float' => [99.5, '99.50'];
    }

    #[DataProvider('provideAmounts')]
    public function testAmountsAreNormalisedToTwoDecimals(mixed $given, ?string $expected): void
    {
        $this->assertSame($expected, SubscriptionInput::term(self::valid(['amount' => $given]))['amount']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideBadAmounts(): iterable
    {
        yield 'negative' => ['-5'];
        yield 'three decimals' => ['1.234'];
        yield 'words' => ['lots'];
        yield 'a currency symbol' => ['₱500'];
        yield 'too big' => ['12345678901'];
        yield 'an array' => [['5']];
    }

    #[DataProvider('provideBadAmounts')]
    public function testABadAmountIsRefused(mixed $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('amount');

        SubscriptionInput::term(self::valid(['amount' => $amount]));
    }

    /*
    |--------------------------------------------------------------------------
    | Currency, reference, notes
    |--------------------------------------------------------------------------
    */

    public function testTheCurrencyMustBeThreeLetters(): void
    {
        foreach (['PH', 'PHPP', '123', 'P-P', ['PHP']] as $bad) {
            try {
                SubscriptionInput::term(self::valid(['currency' => $bad]));
                $this->fail('currency ' . json_encode($bad) . ' must be refused');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('three-letter', $e->getMessage());
            }
        }
    }

    public function testReferenceAndNotesAreBounded(): void
    {
        $this->assertSame(100, mb_strlen((string) SubscriptionInput::term(self::valid(['reference' => str_repeat('a', 100)]))['reference']));
        $this->assertSame(1000, mb_strlen((string) SubscriptionInput::term(self::valid(['notes' => str_repeat('a', 1000)]))['notes']));

        foreach ([['reference', 101], ['notes', 1001]] as [$field, $length]) {
            try {
                SubscriptionInput::term(self::valid([$field => str_repeat('a', $length)]));
                $this->fail("{$field} of {$length} characters must be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('at most', $e->getMessage());
            }
        }
    }

    public function testReferenceAndNotesMustBeText(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SubscriptionInput::term(self::valid(['reference' => ['x']]));
    }

    /*
    |--------------------------------------------------------------------------
    | Payment alone (a correction)
    |--------------------------------------------------------------------------
    */

    public function testPaymentDetailsCanBeReadWithoutAPeriodOrPlan(): void
    {
        $this->assertSame(
            ['amount' => '500.00', 'currency' => 'PHP', 'reference' => 'OR-1', 'notes' => null],
            SubscriptionInput::payment(['amount' => '500', 'reference' => 'OR-1']),
        );
    }

    public function testPaymentDetailsAreValidatedToo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SubscriptionInput::payment(['amount' => 'lots']);
    }
}
