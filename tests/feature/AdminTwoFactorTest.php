<?php

declare(strict_types=1);

use App\Auth\Actions\AdminEmail2FA;
use App\Services\Admin\SystemAdminService;
use Tests\Support\CourseFactory;
use Tests\Support\FakeEmail;
use Tests\Support\RouteTestCase;

/**
 * Two-factor sign-in for system administrators: a correct password is not
 * enough, the emailed code must be entered too, and nobody else is asked for
 * one.
 *
 * @internal
 */
final class AdminTwoFactorTest extends RouteTestCase
{
    private const PASSWORD = 'a-long-password-1';

    private FakeEmail $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = FakeEmail::install();
    }

    protected function tearDown(): void
    {
        \CodeIgniter\Config\Services::resetSingle('email');

        parent::tearDown();
    }

    private function adminEmail(): string
    {
        (new SystemAdminService())->create('root@mentora.test', 'root', self::PASSWORD);

        return 'root@mentora.test';
    }

    private function signIn(string $email, string $password = self::PASSWORD): string
    {
        return $this->step('POST', 'login', ['email' => $email, 'password' => $password])->response()->getHeaderLine('Location');
    }

    private function location(string $verb, string $uri): string
    {
        return $this->step($verb, $uri)->response()->getHeaderLine('Location');
    }

    /*
    |--------------------------------------------------------------------------
    | The action only applies to administrators
    |--------------------------------------------------------------------------
    */

    public function testTheCodeStepAppliesToAdministratorsOnly(): void
    {
        $action = new AdminEmail2FA();

        $this->assertTrue($action->appliesTo(CourseFactory::user('admin')));

        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            $this->assertFalse($action->appliesTo(CourseFactory::user($role)), "{$role} must not be asked for a code");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Signing in as an administrator
    |--------------------------------------------------------------------------
    */

    public function testAPasswordAloneSendsAnAdministratorToTheCodeStep(): void
    {
        $email = $this->adminEmail();

        $this->assertStringContainsString('auth/a/show', $this->signIn($email));
    }

    public function testThePendingAdministratorCannotOpenTheAdminArea(): void
    {
        $this->signIn($this->adminEmail());

        $location = $this->location('GET', 'admin/dashboard');

        $this->assertStringNotContainsString('admin/dashboard', $location);
        $this->assertNotSame('', $location, 'they are redirected, not shown the page');
    }

    public function testTheEmailedCodeCompletesTheSignIn(): void
    {
        $email = $this->adminEmail();
        $this->signIn($email);

        $this->step('POST', 'auth/a/handle', ['email' => $email]);

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame($email, $this->mailer->sent[0]['to']);

        $code = $this->mailer->lastCode();
        $this->assertNotNull($code);

        $this->step('POST', 'auth/a/verify', ['token' => $code]);

        $this->assertTrue($this->step('GET', 'admin/dashboard')->isOK());
    }

    public function testAWrongCodeDoesNotSignThemIn(): void
    {
        $email = $this->adminEmail();
        $this->signIn($email);
        $this->step('POST', 'auth/a/handle', ['email' => $email]);

        $wrong = $this->mailer->lastCode() === '111111' ? '222222' : '111111';
        $this->step('POST', 'auth/a/verify', ['token' => $wrong]);

        $this->assertStringNotContainsString('admin/dashboard', $this->location('GET', 'admin/dashboard'));
        $this->assertFalse(auth()->loggedIn());
    }

    public function testAWrongPasswordNeverReachesTheCodeStep(): void
    {
        $email = $this->adminEmail();

        $location = $this->signIn($email, 'not-the-password');

        $this->assertStringEndsWith('/login', $location);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testSigningOutMeansTheCodeIsAskedForAgain(): void
    {
        $email = $this->adminEmail();
        $this->signIn($email);
        $this->step('POST', 'auth/a/handle', ['email' => $email]);
        $this->step('POST', 'auth/a/verify', ['token' => $this->mailer->lastCode()]);

        $this->assertTrue(auth()->loggedIn());

        auth()->logout();
        $this->withSession();

        $this->assertStringContainsString('auth/a/show', $this->signIn($email), 'a second sign-in needs a second code');
    }

    /*
    |--------------------------------------------------------------------------
    | Everyone else is unaffected
    |--------------------------------------------------------------------------
    */

    public function testOtherRolesSignInWithAPasswordAlone(): void
    {
        foreach (['student', 'instructor'] as $role) {
            $user = CourseFactory::user($role);
            $user = model(\CodeIgniter\Shield\Models\UserModel::class)->findById($user->id);

            $user->email    = "{$role}@mentora.test";
            $user->password = self::PASSWORD;
            model(\CodeIgniter\Shield\Models\UserModel::class)->save($user);

            $location = $this->signIn("{$role}@mentora.test");

            $this->assertStringNotContainsString('auth/a/', $location, "{$role} must not be asked for a code");
            $this->assertTrue(auth()->loggedIn(), "{$role} is signed in after the password");

            auth()->logout();
            $this->withSession();
        }

        $this->assertSame([], $this->mailer->sent);
    }
}
