<?php

declare(strict_types=1);

use App\Services\Users\AccountControl;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\RememberModel;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * "Remember me" and "sign out everywhere" (Task 8.1, B1). A remember-me sign-in fires no `login` event, so the
 * session must still learn when it began; and a sign-out everywhere must also forget the remembered browsers,
 * or a stale one would come straight back.
 *
 * @internal
 */
final class RememberMeSessionTest extends RouteTestCase
{
    private User $ivo;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('throttler');
        FakeEmail::install();
        $this->ivo = PeopleFactory::person('instructor', 'Ivo', 'Teacher', 'ivo-remember@example.com', PeopleFactory::institution('RMB'));
    }

    protected function tearDown(): void
    {
        service('superglobals')->unsetCookie('remember');
        Services::resetSingle('email');
        Services::resetSingle('throttler');

        parent::tearDown();
    }

    /** A remember-me cookie for $user, as Shield stores it: the selector, and the validator's hash. */
    private function rememberCookie(User $user): string
    {
        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(20));
        model(RememberModel::class)->rememberUser($user, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', time() + 3600));

        return $selector . ':' . $validator;
    }

    /** A browser with no session, only the remember-me cookie. */
    private function freshBrowserWith(string $cookie): void
    {
        $_SESSION = [];
        $this->withSession([]);
        service('superglobals')->setCookie('remember', $cookie);
        Services::resetSingle('auth');
    }

    private function tokens(): int
    {
        return db_connect()->table('auth_remember_tokens')->where('user_id', $this->ivo->id)->countAllResults();
    }

    public function testSigningOutEverywhereForgetsEveryRememberedBrowser(): void
    {
        $stale = $this->rememberCookie($this->ivo);

        (new AccountControl())->signOutEverywhere((int) $this->ivo->id);

        $this->assertSame(0, $this->tokens());

        $this->freshBrowserWith($stale);
        $this->step('GET', 'settings')->assertRedirectTo(site_url('login'));
        $this->assertFalse(auth()->loggedIn());
    }

    public function testDeactivatingForgetsThemTooSoAReactivationDoesNotBringThemBack(): void
    {
        $stale = $this->rememberCookie($this->ivo);

        (new AccountControl())->deactivate((int) $this->ivo->id);
        (new AccountControl())->reactivate((int) $this->ivo->id);

        $this->assertSame(0, $this->tokens());

        $this->freshBrowserWith($stale);
        $this->step('GET', 'settings')->assertRedirectTo(site_url('login'));
    }

    public function testARememberMeSignInAfterASignOutEverywhereStaysSignedIn(): void
    {
        (new AccountControl())->signOutEverywhere((int) $this->ivo->id, new DateTimeImmutable('-10 seconds'));

        // Signed in again with "remember me" after it; later the session expired and the cookie brings them back.
        $this->freshBrowserWith($this->rememberCookie($this->ivo));

        $this->assertSame(200, $this->step('GET', 'settings')->response()->getStatusCode());
        service('superglobals')->unsetCookie('remember');
        Services::resetSingle('auth');
        $this->assertSame(200, $this->step('GET', 'settings')->response()->getStatusCode(), 'the next request keeps the session');
        $this->assertTrue(auth()->loggedIn());
    }
}
