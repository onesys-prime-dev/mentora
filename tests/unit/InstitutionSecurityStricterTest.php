<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionSecurity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Stricter of the two" for each security setting, and what counts as a
 * looser value (refused). Pure: the values are handed in.
 *
 * @internal
 */
final class InstitutionSecurityStricterTest extends TestCase
{
    /** @return iterable<string, array{string, int, ?int, int}> */
    public static function provideStricter(): iterable
    {
        yield 'no institution value: the platform stays' => ['passwordMinLength', 8, null, 8];
        yield 'a longer password wins' => ['passwordMinLength', 8, 12, 12];
        yield 'a shorter password loses' => ['passwordMinLength', 12, 8, 12];
        yield 'an equal password' => ['passwordMinLength', 10, 10, 10];

        yield 'fewer idle minutes win' => ['sessionTimeoutMinutes', 120, 30, 30];
        yield 'more idle minutes lose' => ['sessionTimeoutMinutes', 30, 120, 30];

        yield 'fewer attempts win' => ['lockoutAttempts', 5, 3, 3];
        yield 'more attempts lose' => ['lockoutAttempts', 3, 5, 3];
        yield 'any number beats the platform having none' => ['lockoutAttempts', 0, 4, 4];
        yield 'never (0) cannot loosen the platform' => ['lockoutAttempts', 5, 0, 5];
        yield 'never on both stays never' => ['lockoutAttempts', 0, 0, 0];

        yield 'a longer lockout wins' => ['lockoutMinutes', 15, 60, 60];
        yield 'a shorter lockout loses' => ['lockoutMinutes', 60, 15, 60];
    }

    #[DataProvider('provideStricter')]
    public function testTheStricterValueIsTheOneInForce(string $key, int $platform, ?int $institution, int $expected): void
    {
        $this->assertSame($expected, InstitutionSecurity::stricter($key, $platform, $institution));
    }

    /** @return iterable<string, array{string, int, int, bool}> */
    public static function provideAllowed(): iterable
    {
        yield 'a longer password' => ['passwordMinLength', 8, 12, true];
        yield 'the same password length' => ['passwordMinLength', 8, 8, true];
        yield 'a shorter password' => ['passwordMinLength', 12, 8, false];

        yield 'fewer idle minutes' => ['sessionTimeoutMinutes', 120, 30, true];
        yield 'more idle minutes' => ['sessionTimeoutMinutes', 30, 120, false];

        yield 'fewer attempts' => ['lockoutAttempts', 5, 2, true];
        yield 'more attempts' => ['lockoutAttempts', 5, 9, false];
        yield 'never when the platform locks' => ['lockoutAttempts', 5, 0, false];
        yield 'some number when the platform never locks' => ['lockoutAttempts', 0, 3, true];
        yield 'never when the platform never locks' => ['lockoutAttempts', 0, 0, true];

        yield 'a longer lockout' => ['lockoutMinutes', 15, 30, true];
        yield 'a shorter lockout' => ['lockoutMinutes', 15, 5, false];
    }

    #[DataProvider('provideAllowed')]
    public function testALooserValueIsRefusedAnEqualOrStricterOneAllowed(string $key, int $platform, int $institution, bool $allowed): void
    {
        $this->assertSame($allowed, InstitutionSecurity::isStricterOrEqual($key, $platform, $institution));
    }
}
