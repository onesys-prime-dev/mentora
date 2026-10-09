<?php

declare(strict_types=1);

use App\Services\Subscriptions\PlanInput;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a plan form may send: the name, description, feature keys and the
 * three optional caps. Pure logic, no database.
 *
 * @internal
 */
final class PlanInputTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private static function valid(array $override = []): array
    {
        return array_merge(['name' => 'Standard', 'description' => '', 'features' => '', 'seat_limit' => '', 'storage_limit_mb' => '', 'ai_generation_limit' => ''], $override);
    }

    public function testAMinimalPlanHasEverythingElseEmptyAndUnlimited(): void
    {
        $this->assertSame(
            ['name' => 'Standard', 'description' => null, 'features' => [], 'seat_limit' => null, 'storage_limit_mb' => null, 'ai_generation_limit' => null],
            PlanInput::parse(self::valid()),
        );
    }

    public function testAFullPlanIsReadAsGiven(): void
    {
        $plan = PlanInput::parse(self::valid([
            'name' => '  Premium  ', 'description' => ' Everything. ', 'features' => "sis.sync\nai.advanced",
            'seat_limit' => '2000', 'storage_limit_mb' => '51200', 'ai_generation_limit' => '1000',
        ]));

        $this->assertSame('Premium', $plan['name']);
        $this->assertSame('Everything.', $plan['description']);
        $this->assertSame(['ai.advanced', 'sis.sync'], $plan['features']);
        $this->assertSame(2000, $plan['seat_limit']);
        $this->assertSame(51200, $plan['storage_limit_mb']);
        $this->assertSame(1000, $plan['ai_generation_limit']);
    }

    public function testMissingFieldsAreTreatedAsEmpty(): void
    {
        $plan = PlanInput::parse(['name' => 'Bare plan']);

        $this->assertNull($plan['description']);
        $this->assertSame([], $plan['features']);
        $this->assertNull($plan['seat_limit']);
    }

    /*
    |--------------------------------------------------------------------------
    | Name
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{mixed}> */
    public static function provideBadNames(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too short' => ['Ab'];
        yield 'too long' => [str_repeat('a', 101)];
        yield 'an array' => [['Standard']];
        yield 'a number' => [123];
    }

    #[DataProvider('provideBadNames')]
    public function testABadNameIsRefused(mixed $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('plan name');

        PlanInput::parse(self::valid(['name' => $name]));
    }

    public function testTheShortestAndLongestNamesAreFine(): void
    {
        $this->assertSame('abc', PlanInput::parse(self::valid(['name' => 'abc']))['name']);
        $this->assertSame(100, mb_strlen(PlanInput::parse(self::valid(['name' => str_repeat('é', 100)]))['name']));
    }

    /*
    |--------------------------------------------------------------------------
    | Description
    |--------------------------------------------------------------------------
    */

    public function testABlankDescriptionIsNothing(): void
    {
        $this->assertNull(PlanInput::parse(self::valid(['description' => "  \n "]))['description']);
    }

    public function testATooLongDescriptionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('description');

        PlanInput::parse(self::valid(['description' => str_repeat('a', 1001)]));
    }

    public function testADescriptionMustBeText(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanInput::parse(self::valid(['description' => ['x']]));
    }

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    */

    public function testFeaturesMayBeSeparatedByNewLinesCommasOrSpaces(): void
    {
        $this->assertSame(['a.one', 'b.two', 'c.three'], PlanInput::parse(self::valid(['features' => "c.three, b.two\r\n a.one"]))['features']);
    }

    public function testFeaturesAreLowerCasedDeduplicatedAndSorted(): void
    {
        $this->assertSame(['sis.sync'], PlanInput::parse(self::valid(['features' => 'SIS.Sync, sis.sync , SIS.SYNC']))['features']);
    }

    /** @return iterable<string, array{string}> */
    public static function provideBadFeatureKeys(): iterable
    {
        yield 'a digit first' => ['1abc'];
        yield 'one character' => ['a'];
        yield 'a space inside quotes' => ['"a b"'];
        yield 'an exclamation mark' => ['sis!sync'];
        yield 'a slash' => ['sis/sync'];
        yield 'markup' => ['<script>'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[DataProvider('provideBadFeatureKeys')]
    public function testABadFeatureKeyIsRefused(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid feature key');

        PlanInput::parse(self::valid(['features' => $key]));
    }

    public function testTooManyFeaturesAreRefused(): void
    {
        $keys = implode(',', array_map(static fn (int $i): string => "feature.n{$i}", range(1, 51)));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 50');

        PlanInput::parse(self::valid(['features' => $keys]));
    }

    public function testFiftyFeaturesAreFine(): void
    {
        $keys = implode(',', array_map(static fn (int $i): string => "feature.n{$i}", range(1, 50)));

        $this->assertCount(50, PlanInput::parse(self::valid(['features' => $keys]))['features']);
    }

    public function testFeaturesMustBeText(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanInput::parse(self::valid(['features' => ['sis.sync']]));
    }

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    public function testEmptyLimitsMeanUnlimited(): void
    {
        foreach (['', '   ', null] as $empty) {
            $plan = PlanInput::parse(self::valid(['seat_limit' => $empty, 'storage_limit_mb' => $empty, 'ai_generation_limit' => $empty]));

            $this->assertNull($plan['seat_limit']);
            $this->assertNull($plan['storage_limit_mb']);
            $this->assertNull($plan['ai_generation_limit']);
        }
    }

    public function testLimitsAreWholeNumbers(): void
    {
        $plan = PlanInput::parse(self::valid(['seat_limit' => ' 25 ', 'storage_limit_mb' => 1, 'ai_generation_limit' => '10000000']));

        $this->assertSame(25, $plan['seat_limit']);
        $this->assertSame(1, $plan['storage_limit_mb']);
        $this->assertSame(10_000_000, $plan['ai_generation_limit']);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function provideBadLimits(): iterable
    {
        foreach (['seat_limit', 'storage_limit_mb', 'ai_generation_limit'] as $field) {
            yield "{$field}: zero" => [$field, '0'];
            yield "{$field}: negative" => [$field, '-5'];
            yield "{$field}: a decimal" => [$field, '2.5'];
            yield "{$field}: words" => [$field, 'lots'];
            yield "{$field}: a huge number" => [$field, '999999999999'];
            yield "{$field}: an array" => [$field, ['5']];
        }

        yield 'seats over the cap' => ['seat_limit', '10000001'];
        yield 'ai over the cap' => ['ai_generation_limit', '10000001'];
        yield 'storage over the cap' => ['storage_limit_mb', '100000001'];
    }

    #[DataProvider('provideBadLimits')]
    public function testABadLimitIsRefused(string $field, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unlimited');

        PlanInput::parse(self::valid([$field => $value]));
    }

    public function testTheMessageNamesTheLimitThatIsWrong(): void
    {
        try {
            PlanInput::parse(self::valid(['storage_limit_mb' => 'lots']));
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Storage (MB)', $e->getMessage());
        }
    }
}
