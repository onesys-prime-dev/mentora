<?php

declare(strict_types=1);

use App\Services\Admin\PlatformSettings;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * How PlatformSettings checks and types a value before it is saved: whole
 * numbers within limits, yes/no, and bounded text. Pure logic, no database.
 *
 * @internal
 */
final class PlatformSettingsCoerceTest extends CIUnitTestCase
{
    private const INT = ['type' => 'int', 'min' => 5, 'max' => 10];

    private const BOOL = ['type' => 'bool'];

    private const STRING = ['type' => 'string', 'maxLength' => 5];

    /** @return iterable<string, array{mixed, int}> */
    public static function provideAcceptedInts(): iterable
    {
        yield 'an int' => [7, 7];
        yield 'the lower limit' => [5, 5];
        yield 'the upper limit' => [10, 10];
        yield 'a numeric string' => ['8', 8];
        yield 'a padded numeric string' => [' 9 ', 9];
    }

    #[DataProvider('provideAcceptedInts')]
    public function testAcceptsWholeNumbersWithinTheLimits(mixed $given, int $expected): void
    {
        $this->assertSame($expected, PlatformSettings::coerce(self::INT, $given));
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideRejectedInts(): iterable
    {
        yield 'below the minimum' => [4];
        yield 'above the maximum' => [11];
        yield 'a decimal' => [7.5];
        yield 'a decimal string' => ['7.5'];
        yield 'words' => ['seven'];
        yield 'an empty string' => [''];
        yield 'a boolean' => [true];
        yield 'null' => [null];
        yield 'an array' => [[7]];
    }

    #[DataProvider('provideRejectedInts')]
    public function testRejectsAnythingElseForAWholeNumber(mixed $given): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlatformSettings::coerce(self::INT, $given);
    }

    public function testAnIntWithNoLimitsAcceptsNegativesAndLargeValues(): void
    {
        $this->assertSame(-3, PlatformSettings::coerce(['type' => 'int'], '-3'));
        $this->assertSame(99999, PlatformSettings::coerce(['type' => 'int'], 99999));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function provideBools(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield '1' => [1, true];
        yield '0' => [0, false];
        yield 'the string 1' => ['1', true];
        yield 'the string 0' => ['0', false];
        yield 'the string true' => ['true', true];
        yield 'the string FALSE' => ['FALSE', false];
    }

    #[DataProvider('provideBools')]
    public function testAcceptsYesOrNoInTheUsualForms(mixed $given, bool $expected): void
    {
        $this->assertSame($expected, PlatformSettings::coerce(self::BOOL, $given));
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideRejectedBools(): iterable
    {
        yield 'maybe' => ['maybe'];
        yield 'two' => [2];
        yield 'an empty string' => [''];
        yield 'null' => [null];
    }

    #[DataProvider('provideRejectedBools')]
    public function testRejectsAmbiguousYesOrNo(mixed $given): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlatformSettings::coerce(self::BOOL, $given);
    }

    public function testTextIsTrimmedAndBounded(): void
    {
        $this->assertSame('abc', PlatformSettings::coerce(self::STRING, '  abc '));
        $this->assertSame('abcde', PlatformSettings::coerce(self::STRING, 'abcde'));

        $this->expectException(InvalidArgumentException::class);
        PlatformSettings::coerce(self::STRING, 'abcdef');
    }

    public function testTextMustBeText(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlatformSettings::coerce(self::STRING, 12);
    }

    public function testAnUnknownTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlatformSettings::coerce(['type' => 'date'], '2026-01-01');
    }
}
