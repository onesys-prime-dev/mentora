<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Messaging\MessageBody;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class MessageBodyTest extends CIUnitTestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function provideBodies(): iterable
    {
        yield 'trimmed'                 => ['  hi  ', 'hi'];
        yield 'empty'                   => ['', null];
        yield 'whitespace only'         => ["   \n ", null];
        yield 'control characters'      => ["a\x00b\x07c", 'abc'];
        yield 'newline kept'            => ["line1\nline2", "line1\nline2"];
        yield 'crlf becomes newline'    => ["a\r\nb", "a\nb"];
        yield '4000 multibyte'          => [str_repeat('é', 4000), str_repeat('é', 4000)];
        yield '4001 multibyte'          => [str_repeat('é', 4001), null];
        yield 'emoji'                   => ['𝟘🙂', '𝟘🙂'];
        yield 'right to left'           => ['שלום עולם', 'שלום עולם'];
        yield 'controls stripped first' => [str_repeat('a', 4000) . str_repeat("\x01", 20), str_repeat('a', 4000)];
        yield 'invalid utf-8'           => ["\xC3\x28", null];
    }

    #[DataProvider('provideBodies')]
    public function testClean(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, MessageBody::clean($raw));
    }
}
