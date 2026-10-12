<?php

declare(strict_types=1);

use App\Services\Admin\ReauthService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The password step in front of dangerous actions, for the institutional
 * administrator: the same filter and service as the system administrator's,
 * their own confirm address (reauth), JSON for the on-page dialog, and the
 * return address staying inside an administrative area. The tests mount
 * their own guarded routes (the real ones arrive with the features that need
 * them).
 *
 * @internal
 */
final class InstitutionReauthTest extends RouteTestCase
{
    private const PASSWORD = 'a-long-password-1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');

        $institution = SisFixture::institution('REAUTHIA');
        $user        = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@reauth.example', $institution);
        db_connect()->table('auth_identities')->where('user_id', $user->id)->where('type', 'email_password')->update(['secret2' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
        $this->admin = model(UserModel::class)->findById($user->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        $this->withRoutes([
            ['get', 'institution/guarded', '\App\Controllers\Institution\HomeController::index', ['filter' => ['role:institutional_admin', 'reauth']]],
            ['post', 'institution/guarded', '\App\Controllers\Institution\HomeController::index', ['filter' => ['role:institutional_admin', 'reauth']]],
            ['get', 'institution/open', '\App\Controllers\Institution\HomeController::index', ['filter' => 'role:institutional_admin']],
            ['get', 'reauth', '\App\Controllers\ReauthController::show', ['filter' => 'role:institutional_admin']],
            ['post', 'reauth', '\App\Controllers\ReauthController::confirm', ['filter' => 'role:institutional_admin']],
            ['get', 'login', '\CodeIgniter\Shield\Controllers\LoginController::loginView'],
        ]);
    }

    private function location(string $verb, string $uri, array $data = []): string
    {
        return $this->step($verb, $uri, $data)->response()->getHeaderLine('Location');
    }

    private function confirmedSecondsAgo(int $seconds): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time() - $seconds];
        $this->withSession();
    }

    public function testAGuardedPageSendsTheAdministratorToTheSharedConfirmPageNotTheAdminOne(): void
    {
        $location = $this->location('GET', 'institution/guarded');

        $this->assertStringEndsWith('/reauth', $location);
        $this->assertStringNotContainsString('admin/reauth', $location);
    }

    public function testAnUnguardedPageIsNotAffected(): void
    {
        $this->assertTrue($this->step('GET', 'institution/open')->isOK());
    }

    public function testAGuardedPageOpensInsideTheWindowAndClosesAfterIt(): void
    {
        $this->confirmedSecondsAgo(ReauthService::WINDOW_SECONDS - 5);
        $this->assertTrue($this->step('GET', 'institution/guarded')->isOK());

        $this->confirmedSecondsAgo(ReauthService::WINDOW_SECONDS + 5);
        $this->assertStringEndsWith('/reauth', $this->location('GET', 'institution/guarded'));
    }

    public function testAGuardedPostIsStoppedBeforeItRuns(): void
    {
        $response = $this->step('POST', 'institution/guarded', ['x' => '1'])->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/reauth', $response->getHeaderLine('Location'));
    }

    public function testAScriptGetsJsonSoThePageCanAskInADialog(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/guarded');

        $this->assertSame(403, $result->response()->getStatusCode());
        $this->assertSame('reauth_required', json_decode($result->getJSON(), true)['error']);
    }

    public function testTheConfirmPageUsesTheAppShellAndPostsToItself(): void
    {
        $body = $this->step('GET', 'reauth')->response()->getBody();

        $this->assertStringContainsString('data-reauth-page', $body);
        $this->assertStringContainsString('name="password"', $body);
        $this->assertStringContainsString('Confirm your password', $body);
        $this->assertStringNotContainsString('data-admin-menu', $body, 'not the system administrator layout');
    }

    public function testTheRightPasswordReturnsToTheRequestedInstitutionPage(): void
    {
        $this->step('GET', 'institution/guarded?tab=2');

        $location = $this->location('POST', 'reauth', ['password' => self::PASSWORD]);

        $this->assertStringEndsWith('institution/guarded?tab=2', $location);
        $this->assertTrue($this->step('GET', 'institution/guarded')->isOK());
    }

    public function testWithNothingPendingTheRightPasswordGoesToTheInstitutionHome(): void
    {
        $this->assertStringEndsWith('institution/dashboard', $this->location('POST', 'reauth', ['password' => self::PASSWORD]));
    }

    public function testAPlantedReturnAddressCannotLeaveTheAdministrativeAreas(): void
    {
        foreach (['https://evil.example/steal', 'student/dashboard', '//evil.example/x'] as $planted) {
            $_SESSION['reauth_return'] = $planted;
            $this->withSession();

            $location = $this->location('POST', 'reauth', ['password' => self::PASSWORD]);

            $this->assertStringEndsWith('institution/dashboard', $location, $planted);
            $this->assertStringNotContainsString('evil.example', $location);
        }
    }

    public function testAWrongPasswordStaysOnThePageAndDoesNotUnlock(): void
    {
        $this->assertStringEndsWith('/reauth', $this->location('POST', 'reauth', ['password' => 'not-the-password']));
        $this->assertStringEndsWith('/reauth', $this->location('GET', 'institution/guarded'), 'still locked');
    }

    public function testTooManyWrongPasswordsEndTheSession(): void
    {
        for ($i = 1; $i < ReauthService::MAX_ATTEMPTS; $i++) {
            $this->location('POST', 'reauth', ['password' => 'wrong' . $i]);
        }

        $this->assertStringEndsWith('/login', $this->location('POST', 'reauth', ['password' => 'wrong-last']));
        $this->assertFalse(auth()->loggedIn());
    }

    public function testTheDialogPostsAnswerInJson(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $wrong = $this->step('POST', 'reauth', ['password' => 'nope']);
        $this->assertSame(422, $wrong->response()->getStatusCode());
        $this->assertSame('That password is not correct.', json_decode($wrong->getJSON(), true)['errors']['password']);

        $right = $this->step('POST', 'reauth', ['password' => self::PASSWORD]);
        $this->assertTrue($right->isOK());
        $this->assertTrue(json_decode($right->getJSON(), true)['ok']);

        $this->withHeaders(['Accept' => 'text/html']);
        $this->assertTrue($this->step('GET', 'institution/guarded')->isOK(), 'the dialog unlocked the page');
    }

    public function testTheDialogEndsTheSessionAfterTooManyWrongPasswordsAndSaysSo(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        for ($i = 1; $i < ReauthService::MAX_ATTEMPTS; $i++) {
            $this->step('POST', 'reauth', ['password' => 'wrong' . $i]);
        }

        $last = $this->step('POST', 'reauth', ['password' => 'wrong-last']);

        $this->assertSame(401, $last->response()->getStatusCode());
        $this->assertSame('signed_out', json_decode($last->getJSON(), true)['error']);
        $this->assertFalse(auth()->loggedIn());
    }

    public function testSomeoneElseCannotUseTheSharedConfirmPage(): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs(PeopleFactory::person('student', 'Stu', 'Dent', 'stu@reauth.example'));
        $this->withSession();

        $response = $this->step('GET', 'reauth')->response();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringNotContainsString('/reauth', $response->getHeaderLine('Location'));
    }

    public function testThePageCanCarryTheConfirmDialog(): void
    {
        $body = view('layouts/partials/reauth-dialog');

        $this->assertStringContainsString('id="reauth-dialog"', $body);
        $this->assertStringContainsString('data-reauth-form', $body);
        $this->assertStringContainsString('name="password"', $body);
    }
}
