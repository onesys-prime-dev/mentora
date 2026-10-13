<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use App\Services\Admin\PlatformSettings;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The security settings page, and the settings at work on real requests:
 * sign-in lockout, sign-out after inactivity, and who is refused the page.
 *
 * @internal
 */
final class SecuritySettingsTest extends RouteTestCase
{
    private const STRONG = 'Tr4ins-Lantern-Moss-92';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->admin = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    /** @return array<string, mixed> */
    private function form(array $override = []): array
    {
        return array_merge(['passwordMinLength' => '10', 'sessionTimeoutMinutes' => '45', 'lockoutAttempts' => '3', 'lockoutMinutes' => '20', 'twoFactorRoles' => ['instructor']], $override);
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseCanSeeOrChangeThem(string $role): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        $this->assertSame(302, $this->step('GET', 'admin/settings/security')->response()->getStatusCode());
        $this->step('POST', 'admin/settings/security', $this->form());

        $this->assertSame(8, (int) (new PlatformSettings())->get('passwordMinLength'));
    }

    public function testThePageShowsTheValuesInForce(): void
    {
        $body = $this->step('GET', 'admin/settings/security')->response()->getBody();

        $this->assertStringContainsString('name="passwordMinLength"', $body);
        $this->assertStringContainsString('value="120"', $body);
        $this->assertStringContainsString('(always)', $body);
        $this->assertStringContainsString('value="instructor"', $body);
        $this->assertStringNotContainsString('value="admin"', $body);
    }

    public function testSavingNeedsThePasswordAgain(): void
    {
        $response = $this->step('POST', 'admin/settings/security', $this->form());

        $this->assertStringContainsString('admin/reauth', $response->response()->getHeaderLine('Location'));
        $this->assertSame(8, (int) (new PlatformSettings())->get('passwordMinLength'));
    }

    public function testSavingStoresTheValuesAndRecordsEachChange(): void
    {
        $this->confirmedPassword();

        $this->step('POST', 'admin/settings/security', $this->form());

        $this->assertSame(10, (int) (new PlatformSettings())->get('passwordMinLength'));
        $this->assertSame(45, (int) (new PlatformSettings())->get('sessionTimeoutMinutes'));
        $this->assertSame('admin,instructor', (new PlatformSettings())->get('twoFactorRoles'));
        $this->assertSame(5, db_connect()->table('audit_logs')->where('action', 'settings.changed')->countAllResults());
        $this->assertStringContainsString('Security settings saved', $this->step('GET', 'admin/settings/security')->response()->getBody());
    }

    public function testABadValueGoesBackWithTheReasonAndTheInput(): void
    {
        $this->confirmedPassword();

        $this->step('POST', 'admin/settings/security', $this->form(['sessionTimeoutMinutes' => '999', 'lockoutMinutes' => '7']));

        $body = $this->step('GET', 'admin/settings/security')->response()->getBody();

        $this->assertStringContainsString('Sign out after', $body);
        $this->assertStringContainsString('value="999"', $body);
        $this->assertSame(120, (int) (new PlatformSettings())->get('sessionTimeoutMinutes'));
        $this->assertSame(15, (int) (new PlatformSettings())->get('lockoutMinutes'));
    }

    /*
    |--------------------------------------------------------------------------
    | At work
    |--------------------------------------------------------------------------
    */

    public function testALockedAccountCannotSignInEvenWithTheRightPassword(): void
    {
        $user = PeopleFactory::person('student', 'Lock', 'Out', 'lock@example.com');
        db_connect()->table('auth_identities')->where(['user_id' => $user->id, 'type' => 'email_password'])->update(['secret2' => password_hash(self::STRONG, PASSWORD_DEFAULT)]);

        auth()->logout();
        $this->withSession([]);

        for ($i = 0; $i < 5; $i++) {
            $this->step('POST', 'login', ['email' => 'lock@example.com', 'password' => 'wrong-' . $i]);
        }

        $response = $this->step('POST', 'login', ['email' => 'lock@example.com', 'password' => self::STRONG]);

        $this->assertFalse(auth()->loggedIn());
        $this->assertStringContainsString('login', $response->response()->getHeaderLine('Location'));
        $this->assertStringContainsString('Too many failed sign-in attempts', (string) session('error'));
    }

    public function testBelowTheLimitTheRightPasswordStillWorks(): void
    {
        $user = PeopleFactory::person('student', 'Fine', 'Person', 'fine@example.com');
        db_connect()->table('auth_identities')->where(['user_id' => $user->id, 'type' => 'email_password'])->update(['secret2' => password_hash(self::STRONG, PASSWORD_DEFAULT)]);

        auth()->logout();
        $this->withSession([]);

        for ($i = 0; $i < 4; $i++) {
            $this->step('POST', 'login', ['email' => 'fine@example.com', 'password' => 'wrong-' . $i]);
        }

        $this->step('POST', 'login', ['email' => 'fine@example.com', 'password' => self::STRONG]);

        $this->assertTrue(auth()->loggedIn());
    }

    public function testASessionIdleForLongerThanTheLimitIsSignedOut(): void
    {
        $student = PeopleFactory::person('student', 'Idle', 'Person', 'idle@example.com', PeopleFactory::institution('ACME'));
        (new PlatformSettings())->set('sessionTimeoutMinutes', 30);

        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs($student);
        $this->withSession();
        $_SESSION[AccountStateFilter::LAST_SEEN_AT] = time() - 31 * 60;
        $this->withSession();

        $response = $this->step('GET', 'student/dashboard');

        $this->assertStringEndsWith('login', $response->response()->getHeaderLine('Location'));
        $this->assertFalse(auth()->loggedIn());
        $this->assertStringContainsString('inactive', (string) session('error'));
    }

    public function testARecentlyActiveSessionStaysInAndIsRefreshed(): void
    {
        $student = PeopleFactory::person('student', 'Busy', 'Person', 'busy@example.com', PeopleFactory::institution('ACME'));
        (new PlatformSettings())->set('sessionTimeoutMinutes', 30);

        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs($student);
        $this->withSession();
        $_SESSION[AccountStateFilter::LAST_SEEN_AT] = time() - 29 * 60;
        $this->withSession();

        $this->assertNotSame(302, $this->step('GET', 'student/dashboard')->response()->getStatusCode());
        $this->assertGreaterThan(time() - 5, (int) $_SESSION[AccountStateFilter::LAST_SEEN_AT]);
    }
}
