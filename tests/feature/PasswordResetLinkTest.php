<?php

declare(strict_types=1);

use App\Services\Users\AccountControl;
use App\Services\Users\PasswordResetService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The page behind a password reset link, which a locked-out person reaches
 * with no session: the form, choosing a password, signing in with it, and every
 * way a link can stop working.
 *
 * @internal
 */
final class PasswordResetLinkTest extends RouteTestCase
{
    private const STRONG = 'Tr4ins-Lantern-Moss-92';

    private const OTHER = 'An0ther-Str0ng-Phrase-77';

    private FakeEmail $mailer;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->mailer = FakeEmail::install();
        $this->person = PeopleFactory::person('instructor', 'Ivo', 'Teacher', 'ivo@example.com', PeopleFactory::institution('ACME'));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /** Sends a reset and returns the secret from the email, as the person would find it. */
    private function sent(): string
    {
        (new PasswordResetService())->send((int) $this->person->id, PeopleFactory::person('admin', 'Ada', 'Admin'));

        preg_match('#/reset-password/([A-Za-z0-9_-]{43})#', end($this->mailer->sent)['message'], $match);

        return $match[1];
    }

    private function open(string $token): TestResponse
    {
        return $this->step('GET', "reset-password/{$token}");
    }

    /** @param array<string, string> $data */
    private function submit(string $token, array $data): TestResponse
    {
        return $this->step('POST', "reset-password/{$token}", $data);
    }

    private function passwordHash(): string
    {
        return (string) db_connect()->table('auth_identities')->where(['user_id' => $this->person->id, 'type' => 'email_password'])->get()->getRowArray()['secret2'];
    }

    public function testTheLinkOpensAFormWithoutSigningIn(): void
    {
        $token = $this->sent();

        $response = $this->open($token);
        $body     = $response->response()->getBody();

        $this->assertSame(200, $response->response()->getStatusCode());
        $this->assertStringContainsString('Choose a new password', $body);
        $this->assertStringContainsString('ivo@example.com', $body);
        $this->assertStringContainsString('name="password"', $body);
        $this->assertStringContainsString('name="password_confirm"', $body);
        $this->assertFalse(auth()->loggedIn());
    }

    public function testChoosingAPasswordSetsItAndSendsThemToSignIn(): void
    {
        $token  = $this->sent();
        $before = $this->passwordHash();

        $response = $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);

        $this->assertStringEndsWith('login', $response->response()->getHeaderLine('Location'));
        $this->assertNotSame($before, $this->passwordHash());
        $this->assertTrue(service('passwords')->verify(self::STRONG, $this->passwordHash()));
        $this->assertStringContainsString('new password is set', $this->step('GET', 'login')->response()->getBody());
    }

    public function testTheNewPasswordSignsThemInAndTheOldOneDoesNot(): void
    {
        $token = $this->sent();
        $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);

        $this->step('POST', 'login', ['email' => 'ivo@example.com', 'password' => 'placeholder-1']);
        $this->assertFalse(auth()->loggedIn());

        $this->step('POST', 'login', ['email' => 'ivo@example.com', 'password' => self::STRONG]);
        $this->assertTrue(auth()->loggedIn());
    }

    public function testASessionTheyHadBeforeIsEndedByTheReset(): void
    {
        $token = $this->sent();

        $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);

        $this->assertSame(
            AccountControl::REASON_SIGNED_OUT,
            (new AccountControl())->blockReason((int) $this->person->id, time() - 3600),
        );
    }

    public function testPasswordsThatDoNotMatchAreRefusedAndTheLinkStillWorks(): void
    {
        $token = $this->sent();

        $response = $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG . 'x']);

        $this->assertStringEndsWith("reset-password/{$token}", $response->response()->getHeaderLine('Location'));
        $this->assertStringContainsString('not the same', $this->open($token)->response()->getBody());
    }

    public function testAWeakPasswordIsRefusedAndTheLinkStillWorks(): void
    {
        $token  = $this->sent();
        $before = $this->passwordHash();

        $this->submit($token, ['password' => 'password', 'password_confirm' => 'password']);

        $this->assertSame($before, $this->passwordHash());
        $this->assertSame(200, $this->open($token)->response()->getStatusCode());

        $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);

        $this->assertNotSame($before, $this->passwordHash());
    }

    public function testALinkWorksOnlyOnce(): void
    {
        $token = $this->sent();
        $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);
        $after = $this->passwordHash();

        $page = $this->open($token);

        $this->assertSame(410, $page->response()->getStatusCode());
        $this->assertStringContainsString('already been used', $page->response()->getBody());

        $again = $this->submit($token, ['password' => self::OTHER, 'password_confirm' => self::OTHER]);

        $this->assertSame(410, $again->response()->getStatusCode());
        $this->assertSame($after, $this->passwordHash(), 'a second use changes nothing');
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        $token = $this->sent();
        db_connect()->table('password_resets')->update(['expires_at' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);

        $page = $this->open($token);

        $this->assertSame(410, $page->response()->getStatusCode());
        $this->assertStringContainsString('has expired', $page->response()->getBody());
    }

    public function testALinkThatWasReplacedIsRefused(): void
    {
        $old = $this->sent();
        $new = $this->sent();

        $this->assertSame(410, $this->open($old)->response()->getStatusCode());
        $this->assertStringContainsString('replaced by a newer one', $this->open($old)->response()->getBody());
        $this->assertSame(200, $this->open($new)->response()->getStatusCode());
    }

    public function testAnUnknownLinkIsNotFound(): void
    {
        $this->sent();

        foreach ([str_repeat('a', 43), 'short'] as $token) {
            $page = $this->open($token);

            $this->assertSame(404, $page->response()->getStatusCode());
            $this->assertStringContainsString('not valid', $page->response()->getBody());

            $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);
        }

        $this->assertFalse(service('passwords')->verify(self::STRONG, $this->passwordHash()));
    }

    public function testALinkSentBeforeTheAccountWasDeactivatedNoLongerWorks(): void
    {
        $token = $this->sent();
        (new AccountControl())->deactivate((int) $this->person->id);

        $page = $this->open($token);

        $this->assertSame(410, $page->response()->getStatusCode());
        $this->assertStringContainsString('deactivated', $page->response()->getBody());

        $this->submit($token, ['password' => self::STRONG, 'password_confirm' => self::STRONG]);

        $this->assertFalse(service('passwords')->verify(self::STRONG, $this->passwordHash()));
    }
}
