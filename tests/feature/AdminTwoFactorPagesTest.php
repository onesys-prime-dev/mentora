<?php

declare(strict_types=1);

use App\Auth\Actions\AdminEmail2FA;
use App\Services\Admin\SystemAdminService;
use CodeIgniter\Config\Services;
use Tests\Support\FakeEmail;
use Tests\Support\RouteTestCase;

/**
 * What an administrator actually sees between the password and the admin
 * area: a page that asks to send the code, then a page to type it. (An
 * earlier version showed blank pages, because the forms were rendered into a
 * section the layout did not print.) Also the development-only help for
 * machines with no mail server, and that it stays off everywhere else.
 *
 * @internal
 */
final class AdminTwoFactorPagesTest extends RouteTestCase
{
    private const EMAIL = 'root@mentora.test';

    private const PASSWORD = 'a-long-password-1';

    private FakeEmail $mailer;

    /** @var class-string */
    private string $originalAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer         = FakeEmail::install();
        $this->originalAction = config('Auth')->actions['login'];

        (new SystemAdminService())->create(self::EMAIL, 'root', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        config('Auth')->actions['login'] = $this->originalAction;
        Services::resetSingle('email');

        parent::tearDown();
    }

    private function enterPassword(): void
    {
        $this->step('POST', 'login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
    }

    private function body(string $verb, string $uri, array $data = []): string
    {
        return $this->step($verb, $uri, $data)->response()->getBody();
    }

    /** Makes the sign-in step behave as it does in the development environment. */
    private function asInDevelopment(): void
    {
        config('Auth')->actions['login'] = DevelopmentAdminEmail2FA::class;
    }

    private function todaysLog(): string
    {
        $file = WRITEPATH . 'logs/log-' . date('Y-m-d') . '.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /*
    |--------------------------------------------------------------------------
    | The pages are not blank
    |--------------------------------------------------------------------------
    */

    public function testTheFirstPageAsksToSendTheCodeToTheirAddress(): void
    {
        $this->enterPassword();

        $body = $this->body('GET', 'auth/a/show');

        $this->assertStringContainsString('One more step', $body);
        $this->assertStringContainsString('auth/a/handle', $body, 'the form posts to the send step');
        $this->assertStringContainsString('name="email"', $body);
        $this->assertStringContainsString('value="' . self::EMAIL . '"', $body, 'their address is filled in');
        $this->assertStringContainsString('Send code', $body);
    }

    public function testTheSecondPageAsksForTheCode(): void
    {
        $this->enterPassword();

        $body = $this->body('POST', 'auth/a/handle', ['email' => self::EMAIL]);

        $this->assertStringContainsString('Enter your code', $body);
        $this->assertStringContainsString('auth/a/verify', $body, 'the form posts to the verify step');
        $this->assertStringContainsString('name="token"', $body);
        $this->assertStringContainsString('Confirm', $body);
    }

    public function testAWrongCodeShowsTheCodePageAgainWithAMessage(): void
    {
        $this->enterPassword();
        $this->step('POST', 'auth/a/handle', ['email' => self::EMAIL]);

        $wrong = $this->mailer->lastCode() === '111111' ? '222222' : '111111';
        $body  = $this->body('POST', 'auth/a/verify', ['token' => $wrong]);

        $this->assertStringContainsString('name="token"', $body, 'they can try again');
        $this->assertStringContainsString('role="alert"', $body);
    }

    public function testBothPagesOfferAWayOut(): void
    {
        $this->enterPassword();

        $this->assertStringContainsString('logout', $this->body('GET', 'auth/a/show'));
        $this->assertStringContainsString('logout', $this->body('POST', 'auth/a/handle', ['email' => self::EMAIL]));
    }

    public function testSigningOutFromTheCodeStepReturnsToTheLoginPage(): void
    {
        $this->enterPassword();

        $response = $this->step('POST', 'logout')->response();

        $this->assertStringContainsString('login', $response->getHeaderLine('Location'));

        $this->withSession([]);
        $this->assertStringContainsString('/login', $this->step('GET', 'admin/dashboard')->response()->getHeaderLine('Location'), 'no longer pending');
    }

    public function testTheWholeJourneyThroughThePagesEndsInTheAdminArea(): void
    {
        $this->enterPassword();
        $this->body('GET', 'auth/a/show');
        $this->step('POST', 'auth/a/handle', ['email' => self::EMAIL]);
        $this->step('POST', 'auth/a/verify', ['token' => (string) $this->mailer->lastCode()]);

        $this->assertStringContainsString('System Administration', $this->body('GET', 'admin/dashboard'));
    }

    /*
    |--------------------------------------------------------------------------
    | Outside development nothing is relaxed
    |--------------------------------------------------------------------------
    */

    public function testTheRealStepIsNotInDevelopmentModeHere(): void
    {
        $this->assertSame(AdminEmail2FA::class, config('Auth')->actions['login']);
        $this->assertNotSame('development', ENVIRONMENT);
    }

    public function testTheCodeIsNotLoggedOutsideDevelopment(): void
    {
        $this->enterPassword();
        $this->step('POST', 'auth/a/handle', ['email' => self::EMAIL]);

        $this->assertStringNotContainsString('sign-in code for ' . self::EMAIL . ': ' . $this->mailer->lastCode(), $this->todaysLog());
    }

    public function testAMailFailureStopsTheSignInOutsideDevelopment(): void
    {
        $this->mailer->failToSend = true;
        $this->enterPassword();

        $this->expectException(CodeIgniter\Shield\Exceptions\RuntimeException::class);
        $this->expectExceptionMessage('Cannot send email');

        $this->step('POST', 'auth/a/handle', ['email' => self::EMAIL]);
    }

    public function testTheCodePageHasNoDevelopmentNoteHere(): void
    {
        $this->enterPassword();

        $this->assertStringNotContainsString('writable/logs', $this->body('POST', 'auth/a/handle', ['email' => self::EMAIL]));
    }

    /*
    |--------------------------------------------------------------------------
    | In development
    |--------------------------------------------------------------------------
    */

    public function testInDevelopmentTheCodeIsWrittenToTheLog(): void
    {
        $this->asInDevelopment();
        $this->enterPassword();
        $this->step('POST', 'auth/a/handle', ['email' => self::EMAIL]);

        $code = $this->mailer->lastCode();

        $this->assertNotNull($code);
        $this->assertStringContainsString('sign-in code for ' . self::EMAIL . ': ' . $code, $this->todaysLog());
    }

    public function testInDevelopmentAMissingMailServerDoesNotStopTheSignIn(): void
    {
        $this->asInDevelopment();
        $this->mailer->failToSend = true;
        $this->enterPassword();

        $body = $this->body('POST', 'auth/a/handle', ['email' => self::EMAIL]);

        $this->assertStringContainsString('Enter your code', $body, 'they still reach the code page');

        preg_match('/sign-in code for ' . preg_quote(self::EMAIL, '/') . ': (\d{6})/', $this->todaysLog(), $match);
        $this->assertNotEmpty($match, 'the code is in the log');

        // The newest logged code is the one that works.
        preg_match_all('/sign-in code for ' . preg_quote(self::EMAIL, '/') . ': (\d{6})/', $this->todaysLog(), $all);
        $this->step('POST', 'auth/a/verify', ['token' => end($all[1])]);

        $this->assertStringContainsString('System Administration', $this->body('GET', 'admin/dashboard'));
    }

    public function testInDevelopmentTheCodeIsNotLoggedForAnAddressThatIsNotTheirs(): void
    {
        $this->asInDevelopment();
        $this->enterPassword();

        $before = $this->todaysLog();
        $this->step('POST', 'auth/a/handle', ['email' => 'someone.else@mentora.test']);

        // Only what was written by this request, which also logs the framework's "CSRF token verified".
        $this->assertStringNotContainsString('sign-in code', substr($this->todaysLog(), strlen($before)), 'no code was logged');
        $this->assertSame([], $this->mailer->sent);
    }
}

/**
 * The sign-in step as it behaves in the development environment (tests run
 * in the testing environment, where it is off).
 *
 * @internal
 */
final class DevelopmentAdminEmail2FA extends AdminEmail2FA
{
    protected function isDevelopment(): bool
    {
        return true;
    }
}
