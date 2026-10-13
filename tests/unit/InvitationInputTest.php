<?php

declare(strict_types=1);

use App\Services\Institutions\InvitationInput;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Who is being invited: how the name and email are read and refused. Pure
 * logic, no database.
 *
 * @internal
 */
final class InvitationInputTest extends CIUnitTestCase
{
    public function testAValidPersonIsTidiedUp(): void
    {
        $person = InvitationInput::person(['first_name' => '  Maria ', 'last_name' => 'Santos', 'email' => '  Maria.Santos@Example.COM ']);

        $this->assertSame(['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@example.com'], $person);
    }

    public function testNamesWithAccentsAndSpacesAreFine(): void
    {
        $person = InvitationInput::person(['first_name' => 'José Luis', 'last_name' => 'de la Peña', 'email' => 'jose@example.com']);

        $this->assertSame('José Luis', $person['first_name']);
        $this->assertSame('de la Peña', $person['last_name']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function provideRefusedInput(): iterable
    {
        $ok = ['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@example.com'];

        yield 'no first name' => [['first_name' => ''] + $ok, 'first name'];
        yield 'first name of one letter' => [['first_name' => 'M'] + $ok, 'first name'];
        yield 'first name too long' => [['first_name' => str_repeat('a', 101)] + $ok, 'first name'];
        yield 'first name is not text' => [['first_name' => ['Maria']] + $ok, 'first name'];
        yield 'no last name' => [['last_name' => '   '] + $ok, 'last name'];
        yield 'name with a line break' => [['last_name' => "Santos\nBcc: x@y.z"] + $ok, 'last name'];
        yield 'name with a control character' => [['first_name' => "Ma\x07ria"] + $ok, 'first name'];
        yield 'no email' => [['email' => ''] + $ok, 'email'];
        yield 'email without an @' => [['email' => 'maria.example.com'] + $ok, 'email'];
        yield 'email without a domain' => [['email' => 'maria@'] + $ok, 'email'];
        yield 'two addresses' => [['email' => 'a@example.com, b@example.com'] + $ok, 'email'];
        yield 'email with a line break' => [['email' => "maria@example.com\nBcc: x@y.z"] + $ok, 'email'];
        yield 'email too long' => [['email' => str_repeat('a', 250) . '@example.com'] + $ok, 'email'];
        yield 'email is not text' => [['email' => ['maria@example.com']] + $ok, 'email'];
        yield 'missing everything' => [[], 'first name'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('provideRefusedInput')]
    public function testBadInputIsRefusedWithAReadableReason(array $input, string $mentions): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($mentions, '/') . '/i');

        InvitationInput::person($input);
    }
}
