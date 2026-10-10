<?php

declare(strict_types=1);

use App\Services\Institutions\InvitationService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use Tests\Support\CourseFactory;
use Tests\Support\FakeEmail;
use Tests\Support\RouteTestCase;

/**
 * The page behind an emailed invitation link, which an invited person reaches
 * with no account and no session: the form, choosing a password, and every
 * way a link can stop working (used, replaced, expired, unknown, institution
 * gone).
 *
 * @internal
 */
final class InvitationLinkTest extends RouteTestCase
{
    private const STRONG = 'Tr4ins-Lantern-Moss-92';

    private const OTHER = 'An0ther-Str0ng-Phrase-77';

    private FakeEmail $mailer;

    private int $institution;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->mailer = FakeEmail::install();

        db_connect()->table('institutions')->insert(['name' => 'Acme University', 'code' => 'ACME', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $this->institution = (int) db_connect()->insertID();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** Invites someone and returns the secret from the email, as they would find it. */
    private function invited(array $person = []): string
    {
        (new InvitationService())->invite(
            $this->institution,
            $person + ['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@example.com'],
            CourseFactory::user('admin'),
        );

        $last = end($this->mailer->sent);
        preg_match('#/invitation/([A-Za-z0-9_-]{43})#', $last['message'], $match);

        return $match[1];
    }

    private function open(string $token): TestResponse
    {
        return $this->step('GET', "invitation/{$token}");
    }

    /** @param array<string, string> $data */
    private function submit(string $token, array $data): TestResponse
    {
        return $this->step('POST', "invitation/{$token}", $data);
    }

    private function accounts(): int
    {
        return db_connect()->table('institution_users')->countAllResults();
    }

    /*
    |--------------------------------------------------------------------------
    | The form
    |--------------------------------------------------------------------------
    */

    public function testTheLinkOpensAPasswordFormForTheInvitedPersonWithoutSigningIn(): void
    {
        $token = $this->invited();

        $response = $this->open($token);
        $body     = $response->response()->getBody();

        $this->assertSame(200, $response->response()->getStatusCode());
        $this->assertStringContainsString('Welcome, Maria', $body);
        $this->assertStringContainsString('Acme University', $body);
        $this->assertStringContainsString('maria.santos@example.com', $body);
        $this->assertStringContainsString('name="password"', $body);
        $this->assertStringContainsString('name="password_confirm"', $body);
        $this->assertStringContainsString('action="' . site_url("invitation/{$token}") . '"', $body);
    }

    public function testOpeningTheFormCreatesNothing(): void
    {
        $this->open($this->invited());

        $this->assertSame(0, $this->accounts());
        $this->assertNull(model(UserModel::class)->findByCredentials(['email' => 'maria.santos@example.com']));
    }

    public function testTheInvitedPersonsNameIsEscaped(): void
    {
        $token = $this->invited(['first_name' => '<script>alert(1)</script>', 'last_name' => 'Santos']);

        $body = $this->open($token)->response()->getBody();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Choosing a password
    |--------------------------------------------------------------------------
    */

    public function testAGoodPasswordMakesTheAccountAndSendsThemToSignIn(): void
    {
        $token = $this->invited();

        $response = $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);

        $this->assertStringEndsWith('login', $response->response()->getHeaderLine('Location'));

        $user = model(UserModel::class)->findByCredentials(['email' => 'maria.santos@example.com']);

        $this->assertNotNull($user);
        $this->assertTrue($user->inGroup('institutional_admin'));
        $this->assertSame(1, db_connect()->table('institution_users')->where(['institution_id' => $this->institution, 'user_id' => $user->id])->countAllResults());

        $this->assertStringContainsString('Your password is set', $this->step('GET', 'login')->response()->getBody());
    }

    public function testTheNewAccountCanSignInWithTheChosenPasswordAndNoOther(): void
    {
        $token = $this->invited();
        $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);

        $wrong = $this->step('POST', 'login', ['email' => 'maria.santos@example.com', 'password' => self::OTHER . 'x']);
        $this->assertFalse(auth()->loggedIn());
        $this->assertStringContainsString('login', $wrong->response()->getHeaderLine('Location'));

        $right = $this->step('POST', 'login', ['email' => 'maria.santos@example.com', 'password' => self::STRONG]);
        $this->assertTrue(auth()->loggedIn(), 'the password they chose works');
        $this->assertStringNotContainsString('login', $right->response()->getHeaderLine('Location'));
    }

    public function testPasswordsThatDoNotMatchAreRefusedAndTheLinkStillWorks(): void
    {
        $token = $this->invited();

        $response = $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG . 'x']);

        $this->assertStringEndsWith("invitation/{$token}", $response->response()->getHeaderLine('Location'));
        $this->assertStringContainsString('not the same', $this->open($token)->response()->getBody());
        $this->assertSame(0, $this->accounts());
    }

    public function testAWeakPasswordIsRefusedWithTheReasonAndTheLinkStillWorks(): void
    {
        $token = $this->invited();

        $this->submit($token, ['password' => 'password', 'password_confirm' => 'password']);

        $page = $this->open($token);

        $this->assertSame(200, $page->response()->getStatusCode(), 'the form is shown again, not a dead link');
        $this->assertStringContainsString('role="alert"', $page->response()->getBody());
        $this->assertSame(0, $this->accounts());

        $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);

        $this->assertSame(1, $this->accounts());
    }

    public function testAMissingPasswordIsRefused(): void
    {
        $token = $this->invited();

        $this->submit($token, []);

        $this->assertSame(0, $this->accounts());
        $this->assertSame(200, $this->open($token)->response()->getStatusCode());
    }

    /*
    |--------------------------------------------------------------------------
    | Links that no longer work
    |--------------------------------------------------------------------------
    */

    public function testALinkWorksOnlyOnce(): void
    {
        $token = $this->invited();
        $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);

        $page = $this->open($token);

        $this->assertSame(410, $page->response()->getStatusCode());
        $this->assertStringContainsString('already been used', $page->response()->getBody());
        $this->assertStringNotContainsString('name="password"', $page->response()->getBody());

        $again = $this->submit($token, ['password' => self::OTHER, 'password_confirm' => self::OTHER]);

        $this->assertSame(410, $again->response()->getStatusCode());
        $this->assertSame(1, $this->accounts(), 'a second use makes no second account');
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $token = $this->invited();
        db_connect()->table('institution_invitations')->update(['expires_at' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);

        $page = $this->open($token);

        $this->assertSame(410, $page->response()->getStatusCode());
        $this->assertStringContainsString('has expired', $page->response()->getBody());

        $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);

        $this->assertSame(0, $this->accounts());
    }

    public function testALinkStillWorksJustBeforeItExpires(): void
    {
        $token = $this->invited();
        db_connect()->table('institution_invitations')->update(['expires_at' => date('Y-m-d H:i:s', strtotime('+1 minute'))]);

        $this->assertSame(200, $this->open($token)->response()->getStatusCode());
    }

    public function testALinkThatWasReplacedByANewerInvitationIsRefused(): void
    {
        $old = $this->invited(['email' => 'typo@example.com']);
        $new = $this->invited(['email' => 'right@example.com']);

        $page = $this->open($old);

        $this->assertSame(410, $page->response()->getStatusCode());
        $this->assertStringContainsString('replaced by a newer one', $page->response()->getBody());
        $this->assertSame(200, $this->open($new)->response()->getStatusCode());

        $this->submit($old, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);

        $this->assertNull(model(UserModel::class)->findByCredentials(['email' => 'typo@example.com']));
    }

    public function testAnUnknownLinkIsNotFoundAndNeverMakesAnAccount(): void
    {
        $this->invited();

        foreach ([str_repeat('a', 43), 'short', str_repeat('Z', 43)] as $token) {
            $page = $this->open($token);

            $this->assertSame(404, $page->response()->getStatusCode(), $token);
            $this->assertStringContainsString('not valid', $page->response()->getBody());

            $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);
        }

        $this->assertSame(0, $this->accounts());
    }

    public function testTheLinkOfADeletedInstitutionIsRefused(): void
    {
        $token = $this->invited();
        db_connect()->table('institutions')->where('id', $this->institution)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $page = $this->open($token);

        $this->assertSame(410, $page->response()->getStatusCode());
        $this->assertStringContainsString('no longer available', $page->response()->getBody());
    }

    public function testAProblemPageOffersTheSignInPageAndNothingAboutTheInstitution(): void
    {
        $token = $this->invited();
        db_connect()->table('institution_invitations')->update(['revoked_at' => date('Y-m-d H:i:s')]);

        $body = $this->open($token)->response()->getBody();

        $this->assertStringContainsString('data-invitation-problem', $body);
        $this->assertStringContainsString('Go to sign in', $body);
        $this->assertStringNotContainsString('Acme University', $body);
        $this->assertStringNotContainsString('maria.santos@example.com', $body);
    }
}
