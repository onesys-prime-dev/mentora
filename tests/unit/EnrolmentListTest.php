<?php

declare(strict_types=1);

use App\Services\Institutions\EnrolmentList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an administrator pastes into "add students" becomes a clean list.
 *
 * @internal
 */
final class EnrolmentListTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function provideText(): iterable
    {
        yield 'one per line' => ["2026-001\n2026-002\n2026-003", ['2026-001', '2026-002', '2026-003']];
        yield 'commas and semicolons' => ['a@x.com, b@x.com;c@x.com', ['a@x.com', 'b@x.com', 'c@x.com']];
        yield 'windows line ends and blanks' => ["a@x.com\r\n\r\n  \r\nb@x.com\r\n", ['a@x.com', 'b@x.com']];
        yield 'repeats are dropped, case-insensitively, first spelling kept' => ["Ann@X.com\nann@x.com\n2026-001\n2026-001", ['Ann@X.com', '2026-001']];
        yield 'quotes from a spreadsheet are trimmed' => ["\"2026-001\"\n'2026-002'", ['2026-001', '2026-002']];
        yield 'nothing' => ["  \n ,; ", []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideText')]
    public function testItIsParsed(string $text, array $expected): void
    {
        $this->assertSame($expected, EnrolmentList::parse($text));
    }
}
