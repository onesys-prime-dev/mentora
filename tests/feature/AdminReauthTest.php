<?php

declare(strict_types=1);

use App\Services\Admin\ReauthService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\CourseFactory;
use Tests\Support\RouteTestCase;

/**
 * The password-confirmation step in front of dangerous admin actions: the
 * `reauth` route filter, the confirm page, and what happens after wrong
 * passwords. The tests mount their own guarded routes (the real ones arrive
 * with the features that need them).
 *
 * @internal
 */
final class AdminReauthTest extends RouteTestCase
{
    private const PASSWORD = 'a-long-password-1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminWithAPassword();
        $this->loggedInAs($this->admin);
        $this->withSession();

        $this->withRoutes([
            ['get', 'admin/guarded', '\App\Controllers\Admin\HomeController::index', ['filter' => ['group:admin', 'reauth']]],
            ['post', 'admin/guarded', '\App\Controllers\Admin\HomeController::index', ['filter' => ['group:admin', 'reauth']]],
            ['get', 'admin/open', '\App\Controllers\Admin\HomeController::index', ['filter' => 'group:admin']],
            ['get', 'admin/reauth', '\App\Controllers\Admin\ReauthController::show', ['filter' => 'group:admin']],
            ['post', 'admin/reauth', '\App\Controllers\Admin\ReauthController::confirm', ['filter' => 'group:admin']],
            ['get', 'login', '\CodeIgniter\Shield\Controllers\LoginController::loginView'],
        ]);
    }

    private function adminWithAPassword(): User
    {
        $user = CourseFactory::user('admin');
        $user->createEmailIdentity(['email' => 'root@mentora.test', 'password' => self::PASSWORD]);

        return model(UserModel::class)->findById($user->id);
    }

    private function location(string $verb, string $uri, array $data = []): string
    {
        return $this->step($verb, $uri, $data)->response()->getHeaderLine('Location');
    }

    /** Pretends the password was confirmed this many seconds ago. */
    private function confirmedSecondsAgo(int $seconds, ?int $userId = null): void
    {
        $_SESSION['reauth'] = ['user' => $userId ?? $this->admin->id, 'at' => time() - $seconds];
        $this->withSession();
    }

    /*
    |--------------------------------------------------------------------------
    | The filter
    |--------------------------------------------------------------------------
    */

    public function testAGuardedPageSendsAnUnconfirmedAdministratorToTheConfirmPage(): void
    {
        $this->assertStringEndsWith('admin/reauth', $this->location('GET', 'admin/guarded'));
    }

    public function testAnUnguardedPageIsNotAffected(): void
    {
        $this->assertTrue($this->step('GET', 'admin/open')->isOK());
    }

    public function testAGuardedPageOpensRightAfterConfirming(): void
    {
        $this->confirmedSecondsAgo(0);

        $this->assertTrue($this->step('GET', 'admin/guarded')->isOK());
    }

    public function testAGuardedPageStillOpensJustInsideTheWindow(): void
    {
        $this->confirmedSecondsAgo(ReauthService::WINDOW_SECONDS - 5);

        $this->assertTrue($this->step('GET', 'admin/guarded')->isOK());
    }

    public function testAGuardedPageClosesOnceTheWindowHasPassed(): void
    {
        $this->confirmedSecondsAgo(ReauthService::WINDOW_SECONDS + 5);

        $this->assertStringEndsWith('admin/reauth', $this->location('GET', 'admin/guarded'));
    }

    public function testAnotherAdministratorsConfirmationDoesNotCount(): void
    {
        $this->confirmedSecondsAgo(0, $this->admin->id + 1000);

        $this->assertStringEndsWith('admin/reauth', $this->location('GET', 'admin/guarded'));
    }

    public function testAGuardedPostIsStoppedBeforeItRuns(): void
    {
        $response = $this->step('POST', 'admin/guarded', ['anything' => 'x'])->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('admin/reauth', $response->getHeaderLine('Location'));
    }

    public function testAScriptGetsAJsonRefusalInsteadOfARedirect(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('GET', 'admin/guarded');

        $this->assertSame(403, $result->response()->getStatusCode());
        $this->assertSame('reauth_required', json_decode($result->getJSON(), true)['error']);
    }

    /*
    |--------------------------------------------------------------------------
    | The confirm page
    |--------------------------------------------------------------------------
    */

    public function testTheConfirmPageShowsAPasswordForm(): void
    {
        $result = $this->step('GET', 'admin/reauth');

        $this->assertTrue($result->isOK());
        $this->assertStringContainsString('name="password"', $result->getBody());
    }

    public function testTheRightPasswordReturnsToTheRequestedPage(): void
    {
        $this->step('GET', 'admin/guarded?tab=2');

        $location = $this->location('POST', 'admin/reauth', ['password' => self::PASSWORD]);

        $this->assertStringEndsWith('admin/guarded?tab=2', $location);
        $this->assertTrue($this->step('GET', 'admin/guarded')->isOK(), 'the page now opens');
    }

    public function testTheRightPasswordWithNoPendingPageGoesToTheDashboard(): void
    {
        $location = $this->location('POST', 'admin/reauth', ['password' => self::PASSWORD]);

        $this->assertStringEndsWith('admin/dashboard', $location);
    }

    public function testAWrongPasswordStaysOnTheConfirmPageAndDoesNotUnlock(): void
    {
        $location = $this->location('POST', 'admin/reauth', ['password' => 'not-the-password']);

        $this->assertStringEndsWith('admin/reauth', $location);
        $this->assertStringEndsWith('admin/reauth', $this->location('GET', 'admin/guarded'), 'still locked');
    }

    public function testAnEmptyPasswordIsRefused(): void
    {
        $location = $this->location('POST', 'admin/reauth', ['password' => '']);

        $this->assertStringEndsWith('admin/reauth', $location);
        $this->assertStringEndsWith('admin/reauth', $this->location('GET', 'admin/guarded'));
    }

    public function testTooManyWrongPasswordsEndTheSession(): void
    {
        for ($i = 1; $i < ReauthService::MAX_ATTEMPTS; $i++) {
            $this->assertStringEndsWith('admin/reauth', $this->location('POST', 'admin/reauth', ['password' => 'wrong' . $i]));
        }

        $location = $this->location('POST', 'admin/reauth', ['password' => 'wrong-last']);

        $this->assertStringEndsWith('/login', $location);
        $this->assertFalse(auth()->loggedIn(), 'the session is over');
    }

    public function testAPlantedReturnAddressCannotLeaveTheAdminArea(): void
    {
        $_SESSION['reauth_return'] = 'https://evil.example/steal';
        $this->withSession();

        $location = $this->location('POST', 'admin/reauth', ['password' => self::PASSWORD]);

        $this->assertStringEndsWith('admin/dashboard', $location);
        $this->assertStringNotContainsString('evil.example', $location);
    }
}
