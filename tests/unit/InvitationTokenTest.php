<?php

declare(strict_types=1);

use App\Services\Institutions\InvitationToken;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The secret in an invitation link: random, URL-safe, and stored only as a hash.
 *
 * @internal
 */
final class InvitationTokenTest extends CIUnitTestCase
{
    public function testATokenIsLongRandomAndSafeInAnAddress(): void
    {
        $made = InvitationToken::generate();

        $this->assertSame(InvitationToken::LENGTH, strlen($made['token']));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $made['token']);
        $this->assertTrue(InvitationToken::looksValid($made['token']));
    }

    public function testTheHashIsSha256OfTheTokenAndIsNotTheToken(): void
    {
        $made = InvitationToken::generate();

        $this->assertSame(hash('sha256', $made['token']), $made['hash']);
        $this->assertSame(InvitationToken::hash($made['token']), $made['hash']);
        $this->assertStringNotContainsString($made['token'], $made['hash']);
        $this->assertSame(64, strlen($made['hash']));
    }

    public function testNoTwoTokensAreTheSame(): void
    {
        $tokens = [];

        for ($i = 0; $i < 50; $i++) {
            $tokens[InvitationToken::generate()['token']] = true;
        }

        $this->assertCount(50, $tokens);
    }

    /** @return iterable<string, array{string}> */
    public static function provideThingsThatAreNotTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['abc'];
        yield 'one too long' => [str_repeat('a', InvitationToken::LENGTH + 1)];
        yield 'one too short' => [str_repeat('a', InvitationToken::LENGTH - 1)];
        yield 'has a space' => [str_repeat('a', InvitationToken::LENGTH - 1) . ' '];
        yield 'has a slash' => [str_repeat('a', InvitationToken::LENGTH - 1) . '/'];
        yield 'has a quote' => [str_repeat('a', InvitationToken::LENGTH - 1) . "'"];
        yield 'has a line break' => [str_repeat('a', InvitationToken::LENGTH - 1) . "\n"];
    }

    #[DataProvider('provideThingsThatAreNotTokens')]
    public function testNonsenseIsRecognisedWithoutAskingTheDatabase(string $text): void
    {
        $this->assertFalse(InvitationToken::looksValid($text));
    }
}
