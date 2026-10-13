<?php

declare(strict_types=1);

use App\Services\Admin\ReauthService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ReauthService: how long a confirmed password counts, whose it counts for,
 * and where the confirm page may send someone back to. The clock is passed
 * in, so the boundaries are exact.
 *
 * @internal
 */
final class ReauthServiceTest extends CIUnitTestCase
{
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
    }

    private function service(): ReauthService
    {
        return new ReauthService(service('session'));
    }

    private function user(int $id = 7): User
    {
        return new User(['id' => $id]);
    }

    /** Marks the user as having confirmed at $at, without going through the password check. */
    private function confirmedAt(int $userId, int $at): void
    {
        service('session')->set('reauth', ['user' => $userId, 'at' => $at]);
    }

    /*
    |--------------------------------------------------------------------------
    | The window
    |--------------------------------------------------------------------------
    */

    public function testNobodyIsFreshBeforeConfirming(): void
    {
        $this->assertFalse($this->service()->isFresh($this->user(), self::NOW));
    }

    public function testFreshRightAfterConfirming(): void
    {
        $this->confirmedAt(7, self::NOW);

        $this->assertTrue($this->service()->isFresh($this->user(), self::NOW));
    }

    public function testStillFreshOneSecondBeforeTheWindowEnds(): void
    {
        $this->confirmedAt(7, self::NOW);

        $this->assertTrue($this->service()->isFresh($this->user(), self::NOW + ReauthService::WINDOW_SECONDS - 1));
    }

    public function testNoLongerFreshWhenTheWindowEnds(): void
    {
        $this->confirmedAt(7, self::NOW);

        $this->assertFalse($this->service()->isFresh($this->user(), self::NOW + ReauthService::WINDOW_SECONDS));
    }

    public function testAConfirmationFromTheFutureIsNotTrusted(): void
    {
        $this->confirmedAt(7, self::NOW + 60);

        $this->assertFalse($this->service()->isFresh($this->user(), self::NOW), 'a clock that went backwards must not extend the window');
    }

    public function testAnotherUsersConfirmationDoesNotCount(): void
    {
        $this->confirmedAt(8, self::NOW);

        $this->assertFalse($this->service()->isFresh($this->user(7), self::NOW));
    }

    public function testAMalformedMarkerDoesNotCount(): void
    {
        service('session')->set('reauth', 'yes');

        $this->assertFalse($this->service()->isFresh($this->user(), self::NOW));
    }

    /*
    |--------------------------------------------------------------------------
    | Wrong passwords
    |--------------------------------------------------------------------------
    */

    public function testFailuresAreCountedFromTheSession(): void
    {
        $service = $this->service();
        $this->assertSame(0, $service->failures());
        $this->assertFalse($service->tooManyFailures());

        service('session')->set('reauth_failures', ReauthService::MAX_ATTEMPTS - 1);
        $this->assertFalse($service->tooManyFailures());

        service('session')->set('reauth_failures', ReauthService::MAX_ATTEMPTS);
        $this->assertTrue($service->tooManyFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | Where the confirm page may return to
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{string, string}> */
    public static function provideReturnPaths(): iterable
    {
        yield 'a page in the admin area' => ['admin/institutions/3/purge', 'admin/institutions/3/purge'];
        yield 'with a leading slash' => ['/admin/users?page=2', 'admin/users?page=2'];
        yield 'the admin root' => ['admin', 'admin'];
        yield 'a student page' => ['student/dashboard', 'admin/dashboard'];
        yield 'another site' => ['https://evil.example/admin/x', 'admin/dashboard'];
        yield 'a protocol-relative address' => ['//evil.example/admin', 'admin/dashboard'];
        yield 'a sneaky double slash' => ['admin//evil.example', 'admin/dashboard'];
        yield 'a backslash' => ['admin\\evil', 'admin/dashboard'];
        yield 'a line break' => ["admin/x\r\nSet-Cookie: a=b", 'admin/dashboard'];
        yield 'the confirm page itself' => ['admin/reauth', 'admin/dashboard'];
        yield 'nothing' => ['', 'admin/dashboard'];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('provideReturnPaths')]
    public function testOnlyAdminPagesAreReturnedTo(string $given, string $expected): void
    {
        $this->assertSame($expected, ReauthService::safeReturn($given));
    }

    public function testTheReturnPageIsUsedOnce(): void
    {
        $service = $this->service();
        $service->rememberReturn('admin/users');

        $this->assertSame('admin/users', $service->pullReturn());
        $this->assertSame('admin/dashboard', $service->pullReturn(), 'a second pull falls back to the dashboard');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInstitutionReturns(): iterable
    {
        yield 'a page in the institution area' => ['institution/terms?x=1', 'institution/terms?x=1'];
        yield 'with a leading slash' => ['/institution/people', 'institution/people'];
        yield 'the confirm page itself' => ['institution/reauth', 'institution/dashboard'];
        yield 'the shared confirm page' => ['reauth', 'institution/dashboard'];
        yield 'a student page' => ['student/dashboard', 'institution/dashboard'];
        yield 'another site' => ['https://evil.example/institution', 'institution/dashboard'];
        yield 'protocol-relative' => ['//evil.example/institution/x', 'institution/dashboard'];
        yield 'a backslash trick' => ['institution\evil', 'institution/dashboard'];
        yield 'a prefix that merely starts the same' => ['institutional/x', 'institution/dashboard'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideInstitutionReturns')]
    public function testTheInstitutionAreaIsReturnedToWithItsOwnFallback(string $given, string $expected): void
    {
        $this->assertSame($expected, ReauthService::safeReturn($given, 'institution/dashboard'));
    }

    public function testThePendingPageFallsBackToTheCallersHome(): void
    {
        $service = $this->service();
        $service->rememberReturn('student/dashboard');

        $this->assertSame('institution/dashboard', $service->pullReturn('institution/dashboard'));
    }
}
