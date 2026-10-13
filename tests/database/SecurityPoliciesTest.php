<?php

declare(strict_types=1);

use App\Auth\Actions\AdminEmail2FA;
use App\Services\Admin\PlatformSettings;
use App\Services\Security\LoginLockout;
use App\Services\Security\PasswordPolicy;
use App\Services\Security\SecuritySettings;
use CodeIgniter\Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\PeopleFactory;

/**
 * The rules behind the security settings: password length, lockout after
 * failed sign-ins, the roles that need an emailed code, and saving the set.
 *
 * @internal
 */
final class SecurityPoliciesTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->now = new DateTimeImmutable('2026-10-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function attempt(string $email, bool $success, string $when): void
    {
        db_connect()->table('auth_logins')->insert([
            'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'id_type' => 'email_password',
            'identifier' => $email, 'user_id' => null, 'date' => $when, 'success' => $success ? 1 : 0,
        ]);
    }

    /** @param list<string> $whens */
    private function failures(string $email, array $whens): void
    {
        foreach ($whens as $when) {
            $this->attempt($email, false, $when);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Passwords
    |--------------------------------------------------------------------------
    */

    public function testThePasswordMinimumFollowsTheSetting(): void
    {
        $user   = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@example.com');
        $policy = new PasswordPolicy();

        $this->assertNull($policy->problem('Tr4ins-Lantern-Moss-92', $user));

        (new PlatformSettings())->set('passwordMinLength', 30);

        $this->assertStringContainsString('at least 30 characters', (string) $policy->problem('Tr4ins-Lantern-Moss-92', $user));
        $this->assertNull($policy->problem('Tr4ins-Lantern-Moss-92-and-a-bit-more-9', $user));
    }

    public function testCommonAndPersonalPasswordsAreStillRefused(): void
    {
        $user   = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@example.com');
        $policy = new PasswordPolicy();

        $this->assertNotNull($policy->problem('password', $user));
        $this->assertNotNull($policy->problem('stu@example.com', $user));
    }

    /*
    |--------------------------------------------------------------------------
    | Lockout
    |--------------------------------------------------------------------------
    */

    public function testFewerFailuresThanTheLimitDoNotLock(): void
    {
        $this->failures('a@example.com', array_fill(0, 4, '2026-10-10 11:59:00'));

        $this->assertNull((new LoginLockout())->lockedUntil('a@example.com', $this->now));
    }

    public function testReachingTheLimitLocksUntilTheLengthHasPassedSinceTheLastFailure(): void
    {
        $this->failures('a@example.com', ['2026-10-10 11:50:00', '2026-10-10 11:51:00', '2026-10-10 11:52:00', '2026-10-10 11:53:00', '2026-10-10 11:55:00']);

        $until = (new LoginLockout())->lockedUntil('A@Example.com', $this->now);

        $this->assertSame('2026-10-10 12:10:00', $until?->format('Y-m-d H:i:s'), '15 minutes after 11:55');
        $this->assertNull((new LoginLockout())->lockedUntil('a@example.com', new DateTimeImmutable('2026-10-10 12:10:00')), 'free again at that moment');
    }

    public function testASuccessResetsTheCount(): void
    {
        $this->failures('a@example.com', array_fill(0, 5, '2026-10-10 11:00:00'));
        $this->attempt('a@example.com', true, '2026-10-10 11:30:00');
        $this->failures('a@example.com', ['2026-10-10 11:55:00', '2026-10-10 11:56:00']);

        $this->assertNull((new LoginLockout())->lockedUntil('a@example.com', $this->now));
    }

    public function testOneEmailsFailuresDoNotLockAnother(): void
    {
        $this->failures('a@example.com', array_fill(0, 5, '2026-10-10 11:55:00'));

        $this->assertNull((new LoginLockout())->lockedUntil('b@example.com', $this->now));
    }

    public function testZeroAttemptsTurnsTheLockoutOff(): void
    {
        (new PlatformSettings())->set('lockoutAttempts', 0);
        $this->failures('a@example.com', array_fill(0, 20, '2026-10-10 11:55:00'));

        $this->assertNull((new LoginLockout())->lockedUntil('a@example.com', $this->now));
    }

    public function testTheLimitAndLengthComeFromTheSettings(): void
    {
        (new PlatformSettings())->set('lockoutAttempts', 2);
        (new PlatformSettings())->set('lockoutMinutes', 60);
        $this->failures('a@example.com', ['2026-10-10 11:30:00', '2026-10-10 11:40:00']);

        $this->assertSame('2026-10-10 12:40:00', (new LoginLockout())->lockedUntil('a@example.com', $this->now)?->format('Y-m-d H:i:s'));
    }

    /*
    |--------------------------------------------------------------------------
    | Emailed code
    |--------------------------------------------------------------------------
    */

    public function testOnlyAdministratorsNeedACodeByDefault(): void
    {
        $action = new AdminEmail2FA();

        $this->assertTrue($action->appliesTo(PeopleFactory::person('admin')));
        $this->assertFalse($action->appliesTo(PeopleFactory::person('student')));
    }

    public function testChosenRolesNeedACodeAndAdministratorsAlwaysDo(): void
    {
        (new SecuritySettings())->save(['passwordMinLength' => 8, 'sessionTimeoutMinutes' => 60, 'lockoutAttempts' => 5, 'lockoutMinutes' => 15, 'twoFactorRoles' => ['instructor']]);

        $action = new AdminEmail2FA();

        $this->assertTrue($action->appliesTo(PeopleFactory::person('instructor')));
        $this->assertTrue($action->appliesTo(PeopleFactory::person('admin')));
        $this->assertFalse($action->appliesTo(PeopleFactory::person('student')));
        $this->assertSame(['admin', 'instructor'], (new SecuritySettings())->twoFactorRoles());
    }

    public function testAdministratorsCannotBeTakenOutOfTheCodeEvenByHandEditingTheSetting(): void
    {
        (new PlatformSettings())->set('twoFactorRoles', 'student,bogus');

        $this->assertSame(['admin', 'student'], (new SecuritySettings())->twoFactorRoles());
    }

    /*
    |--------------------------------------------------------------------------
    | Saving
    |--------------------------------------------------------------------------
    */

    public function testSavingStoresEveryValue(): void
    {
        (new SecuritySettings())->save(['passwordMinLength' => '12', 'sessionTimeoutMinutes' => '45', 'lockoutAttempts' => '3', 'lockoutMinutes' => '30', 'twoFactorRoles' => ['student', 'parent']]);

        $this->assertSame(['passwordMinLength' => 12, 'sessionTimeoutMinutes' => 45, 'lockoutAttempts' => 3, 'lockoutMinutes' => 30, 'twoFactorRoles' => ['admin', 'student', 'parent']], (new SecuritySettings())->all());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function provideBadInput(): iterable
    {
        $ok = ['passwordMinLength' => 8, 'sessionTimeoutMinutes' => 60, 'lockoutAttempts' => 5, 'lockoutMinutes' => 15, 'twoFactorRoles' => []];

        yield 'password length too small' => [['passwordMinLength' => 4] + $ok, 'Shortest password'];
        yield 'password length not a number' => [['passwordMinLength' => 'long'] + $ok, 'Shortest password'];
        yield 'timeout too long' => [['sessionTimeoutMinutes' => 999] + $ok, 'Sign out after'];
        yield 'timeout too short' => [['sessionTimeoutMinutes' => 1] + $ok, 'Sign out after'];
        yield 'negative attempts' => [['lockoutAttempts' => -1] + $ok, 'Failed sign-ins'];
        yield 'zero minutes' => [['lockoutMinutes' => 0] + $ok, 'Lockout length'];
        yield 'missing value' => [array_diff_key($ok, ['lockoutMinutes' => 1]), 'Lockout length'];
        yield 'unknown role' => [['twoFactorRoles' => ['wizard']] + $ok, 'not a role'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[PHPUnit\Framework\Attributes\DataProvider('provideBadInput')]
    public function testBadInputIsRefusedAndNothingIsSaved(array $input, string $mentions): void
    {
        $before = (new SecuritySettings())->all();

        try {
            (new SecuritySettings())->save($input);
            $this->fail('This should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($mentions, $e->getMessage());
        }

        $this->assertSame($before, (new SecuritySettings())->all());
    }
}
