<?php

declare(strict_types=1);

use App\Services\Users\UsernameGenerator;
use Tests\Support\DatabaseTestCase;

/**
 * Usernames made from a person's name: plain letters, short, and unique.
 *
 * @internal
 */
final class UsernameGeneratorTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private function take(string $username): void
    {
        db_connect()->table('users')->insert(['username' => $username, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function testItIsFirstDotLastInLowerCaseWithoutAccentsOrSymbols(): void
    {
        $this->assertSame('jeanpaul.obrien', (new UsernameGenerator())->from('Jean-Paul', "O'Brien"));
        $this->assertMatchesRegularExpression('/^[a-z0-9.]+$/', (new UsernameGenerator())->from('María', 'Núñez'), 'accents never reach the username');
    }

    public function testItNeverRunsOutOfNameParts(): void
    {
        $this->assertSame('admin.user', (new UsernameGenerator())->from('', ''));
    }

    public function testItIsAtMostTwentySixCharactersBeforeAnyNumber(): void
    {
        $name = (new UsernameGenerator())->from(str_repeat('a', 40), str_repeat('b', 40));

        $this->assertSame(26, strlen($name));
    }

    public function testATakenNameGetsTheNextFreeNumber(): void
    {
        $this->take('ann.lee');
        $this->take('ann.lee1');

        $this->assertSame('ann.lee2', (new UsernameGenerator())->from('Ann', 'Lee'));
    }

    public function testItGivesUpWhenEveryCandidateIsTaken(): void
    {
        $this->take('x.y');

        for ($n = 1; $n < 1000; $n++) {
            $this->take("x.y{$n}");
        }

        $this->expectException(RuntimeException::class);

        (new UsernameGenerator())->from('x', 'y');
    }
}
