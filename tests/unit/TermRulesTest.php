<?php

declare(strict_types=1);

use App\Services\Institutions\TermRules;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The pure term rules: overlap, academic year, dates and length.
 *
 * @internal
 */
final class TermRulesTest extends CIUnitTestCase
{
    /** @return iterable<string, array{string, string, string, string, bool}> */
    public static function provideRanges(): iterable
    {
        yield 'apart' => ['2026-08-01', '2026-12-20', '2027-01-05', '2027-05-30', false];
        yield 'one inside the other' => ['2026-08-01', '2026-12-20', '2026-09-01', '2026-10-01', true];
        yield 'a partial overlap' => ['2026-08-01', '2026-12-20', '2026-12-01', '2027-03-01', true];
        yield 'sharing only the last day' => ['2026-08-01', '2026-12-20', '2026-12-20', '2027-03-01', true];
        yield 'the day after' => ['2026-08-01', '2026-12-20', '2026-12-21', '2027-03-01', false];
        yield 'the day before' => ['2026-08-01', '2026-12-20', '2026-06-01', '2026-07-31', false];
        yield 'identical' => ['2026-08-01', '2026-12-20', '2026-08-01', '2026-12-20', true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideRanges')]
    public function testOverlapIsInclusiveAndSymmetric(string $a1, string $a2, string $b1, string $b2, bool $expected): void
    {
        $this->assertSame($expected, TermRules::overlap($a1, $a2, $b1, $b2));
        $this->assertSame($expected, TermRules::overlap($b1, $b2, $a1, $a2));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function provideYears(): iterable
    {
        yield 'a range' => ['2026-2027', '2026-2027'];
        yield 'with spaces' => [' 2026-2027 ', '2026-2027'];
        yield 'with a slash' => ['2026/2027', '2026-2027'];
        yield 'with an en dash' => ['2026–2027', '2026-2027'];
        yield 'a single year' => ['2026', '2026'];
        yield 'the second year does not follow' => ['2026-2028', null];
        yield 'backwards' => ['2027-2026', null];
        yield 'words' => ['AY 2026', null];
        yield 'two digits' => ['26-27', null];
        yield 'empty' => ['', null];
        yield 'far too early' => ['1850-1851', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideYears')]
    public function testTheAcademicYear(string $given, ?string $expected): void
    {
        $this->assertSame($expected, TermRules::academicYear($given));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function provideDates(): iterable
    {
        yield 'a real date' => ['2026-08-01', '2026-08-01'];
        yield 'with spaces' => [' 2026-08-01 ', '2026-08-01'];
        yield 'a leap day' => ['2028-02-29', '2028-02-29'];
        yield 'not a leap year' => ['2027-02-29', null];
        yield 'a month that does not exist' => ['2026-13-01', null];
        yield 'day first' => ['01-08-2026', null];
        yield 'empty' => ['', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideDates')]
    public function testDates(string $given, ?string $expected): void
    {
        $this->assertSame($expected, TermRules::date($given));
    }

    public function testDaysCountsBothEnds(): void
    {
        $this->assertSame(1, TermRules::days('2026-08-01', '2026-08-01'));
        $this->assertSame(31, TermRules::days('2026-08-01', '2026-08-31'));
        $this->assertSame(366, TermRules::days('2027-01-01', '2028-01-01'));
    }
}
