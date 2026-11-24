<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The /settings page and its Password card (D13, D21): every role reaches it, a change keeps this session and
 * ends the others (Review Focus 5), the emailed-link fallback, a read-only institution, and CSRF.
 *
 * @internal
 */
final class SettingsPasswordTest extends RouteTestCase
{
    private const CURRENT = 'Tr4ins-Lantern-Moss-92';

    private const NEW = 'An0ther-Str0ng-Phrase-77';

    private int $institution;

    private User $ivo;

    private FakeEmail $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        Services::resetSingle('throttler');

        $this->mailer      = FakeEmail::install();
        $this->institution = PeopleFactory::institution('SET');
        $this->ivo         = PeopleFactory::person('instructor', 'Ivo', 'Teacher', 'ivo@example.com', $this->institution);

        db_connect()->table('auth_identities')->where(['user_id' => $this->ivo->id, 'type' => 'email_password'])->update(['secret2' => password_hash(self::CURRENT, PASSWORD_DEFAULT)]);
        $this->ivo = model(UserModel::class)->findById($this->ivo->id);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        Services::resetSingle('throttler');

        parent::tearDown();
    }

    /** A browser signed in as $user since $startedAt (unix time). */
    private function browserSignedInSince(User $user, int $startedAt): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession([AccountStateFilter::SIGNED_IN_AT => $startedAt]);
    }

    private function hash(): string
    {
        return (string) db_connect()->table('auth_identities')->where(['user_id' => $this->ivo->id, 'type' => 'email_password'])->get()->getRow('secret2');
    }

    private function change(string $current = self::CURRENT, string $new = self::NEW, ?string $confirm = null): CodeIgniter\Test\TestResponse
    {
        return $this->step('POST', 'settings/password', ['current_password' => $current, 'password' => $new, 'password_confirm' => $confirm ?? $new]);
    }

    public function testEveryRoleGetsTheSettingsPage(): void
    {
        foreach (['student', 'instructor', 'chairperson', 'dean', 'institutional_admin', 'parent', 'admin'] as $role) {
            $user = PeopleFactory::person($role, 'Pat', ucfirst($role), null, $role === 'admin' ? null : $this->institution);
            $this->browserSignedInSince($user, time() - 60);

            $response = $this->call('GET', 'settings')->response();
            $body     = (string) $response->getBody();

            $this->assertSame(200, $response->getStatusCode(), $role);
            $this->assertStringContainsString('Your password, sign-in and the emails Mentora sends you.', $body, $role);
            $this->assertStringContainsString('Change password', $body, $role);
            $this->assertStringContainsString('Email me a reset link instead', $body, $role);
            $this->assertStringContainsString('href="#password"', $body, $role);
        }
    }

    public function testThePageShowsTheInstitutionsMinimum(): void
    {
        (new App\Services\Institutions\InstitutionSecurity())->save($this->institution, ['passwordMinLength' => '12']);
        $this->browserSignedInSince($this->ivo, time() - 60);

        $this->assertStringContainsString('At least 12 characters.', (string) $this->call('GET', 'settings')->response()->getBody());
    }

    public function testTheHeaderSettingsItemLinksToSettings(): void
    {
        $this->browserSignedInSince($this->ivo, time() - 60);

        $body = (string) $this->call('GET', 'profile')->response()->getBody();

        $this->assertMatchesRegularExpression('#<a[^>]+href="[^"]*/settings"[^>]*>(?:(?!</a>).)*Settings#s', $body);
    }

    public function testAChangeKeepsThisBrowserAndSignsOutTheOther(): void
    {
        // Browser A changes the password.
        $this->browserSignedInSince($this->ivo, time() - 3600);
        $response = $this->change();

        $this->assertStringEndsWith('settings', $response->response()->getHeaderLine('Location'));
        $this->assertSame('Password changed', session('message'));
        $this->assertTrue(password_verify(self::NEW, $this->hash()));

        $next = $this->step('GET', 'settings');
        $this->assertSame(200, $next->response()->getStatusCode(), 'browser A stays signed in');
        $this->assertTrue(auth()->loggedIn());

        // Browser B signed in before the change: its next request signs it out.
        $this->browserSignedInSince($this->ivo, time() - 3600);
        $other = $this->step('GET', 'settings');

        $this->assertStringEndsWith('login', $other->response()->getHeaderLine('Location'));
        $this->assertFalse(auth()->loggedIn());
    }

    public function testARefusedChangeShowsTheErrorOnItsField(): void
    {
        $this->browserSignedInSince($this->ivo, time() - 60);
        $this->change('wrong-password');

        $page = (string) $this->step('GET', 'settings')->response()->getBody();

        $this->assertStringContainsString('Your current password is not right.', $page);
        $this->assertStringContainsString('aria-invalid="true"', $page);
        $this->assertStringNotContainsString('wrong-password', $page, 'a typed password is never echoed back');
        $this->assertTrue(password_verify(self::CURRENT, $this->hash()));
    }

    public function testTheSixthAttemptIsRefused(): void
    {
        $this->browserSignedInSince($this->ivo, time() - 60);

        for ($i = 0; $i < 5; $i++) {
            $this->change('wrong-' . $i);
        }
        $this->change();

        $this->assertSame('Too many attempts. Try again in 15 minutes.', session('errors')['current_password'] ?? null);
        $this->assertTrue(password_verify(self::CURRENT, $this->hash()));
    }

    public function testTheResetLinkIsEmailedAndThrottled(): void
    {
        $this->browserSignedInSince($this->ivo, time() - 60);

        $this->step('POST', 'settings/password-link');

        $this->assertSame('We emailed you a link to set a new password.', session('message'));
        $this->assertSame(['ivo@example.com'], array_column($this->mailer->sent, 'to'));
        $this->assertSame(1, db_connect()->table('password_resets')->where('user_id', $this->ivo->id)->countAllResults());

        $this->step('POST', 'settings/password-link');
        $this->step('POST', 'settings/password-link');
        $this->step('POST', 'settings/password-link');

        $this->assertSame('Too many attempts. Try again in 15 minutes.', session('error'));
        $this->assertCount(3, $this->mailer->sent, 'three links per 15 minutes');
    }

    public function testAReadOnlyInstitutionStillLetsYouChangeYourPassword(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'past']);
        $this->browserSignedInSince($this->ivo, time() - 60);

        $this->change();
        $this->assertTrue(password_verify(self::NEW, $this->hash()));

        $this->step('POST', 'settings/password-link');
        $this->assertCount(2, $this->mailer->sent, 'the change notice and the link');
    }

    public function testAChangeWithoutACsrfTokenIsRefused(): void
    {
        $this->browserSignedInSince($this->ivo, time() - 60);
        $this->withoutCsrfToken();

        $this->change();

        $this->assertTrue(password_verify(self::CURRENT, $this->hash()));
        $this->assertSame(App\Filters\CsrfFilter::MESSAGE, session('error'));
    }
}
