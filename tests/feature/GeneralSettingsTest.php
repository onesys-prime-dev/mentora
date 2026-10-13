<?php

declare(strict_types=1);

use App\Services\Admin\PlatformSettings;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The general settings page, and the settings at work: maintenance mode turns
 * everyone but system administrators away, the announcement and system name
 * show, and the timezone is applied.
 *
 * @internal
 */
final class GeneralSettingsTest extends RouteTestCase
{
    private User $admin;

    private string $originalZone;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');

        $this->originalZone = date_default_timezone_get();
        $this->admin        = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalZone);
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    /** @return array<string, string> */
    private function form(array $override = []): array
    {
        return array_merge([
            'systemName' => 'Acme LMS', 'supportContact' => 'help@acme.example', 'timezone' => 'Asia/Manila',
            'maintenanceMessage' => 'Back at noon.', 'announcement' => 'Exams start Monday.',
            'expiryWarningDays' => '21', 'expiryGraceDays' => '10', 'expiryReadOnlyDays' => '20', 'backupAlertHours' => '12',
        ], $override);
    }

    private function signInAs(string $role): User
    {
        $user = PeopleFactory::person($role, 'Some', 'One', "{$role}@example.com", $role === 'admin' ? null : PeopleFactory::institution('ACME'));

        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();

        return $user;
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
        $this->signInAs($role);

        $this->assertSame(302, $this->step('GET', 'admin/settings/general')->response()->getStatusCode());
        $this->step('POST', 'admin/settings/general', $this->form(['maintenanceMode' => '1']));

        $this->assertFalse((bool) (new PlatformSettings())->get('maintenanceMode'));
    }

    /*
    |--------------------------------------------------------------------------
    | The page
    |--------------------------------------------------------------------------
    */

    public function testSavingNeedsThePasswordAgain(): void
    {
        $response = $this->step('POST', 'admin/settings/general', $this->form());

        $this->assertStringContainsString('admin/reauth', $response->response()->getHeaderLine('Location'));
        $this->assertSame('Mentora', (new PlatformSettings())->get('systemName'));
    }

    public function testSavingStoresEveryValueAndRecordsTheChanges(): void
    {
        $this->confirmedPassword();

        $this->step('POST', 'admin/settings/general', $this->form(['maintenanceMode' => '1']));

        $settings = new PlatformSettings();

        $this->assertSame('Acme LMS', $settings->get('systemName'));
        $this->assertSame('Asia/Manila', $settings->get('timezone'));
        $this->assertTrue((bool) $settings->get('maintenanceMode'));
        $this->assertSame(21, (int) $settings->get('expiryWarningDays'));
        $this->assertSame(12, (int) $settings->get('backupAlertHours'));
        $this->assertGreaterThanOrEqual(8, db_connect()->table('audit_logs')->where('action', 'settings.changed')->countAllResults());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideBadInput(): iterable
    {
        yield 'no name' => [['systemName' => ' '], 'System name'];
        yield 'an unknown timezone' => [['timezone' => 'Mars/Olympus'], 'Timezone'];
        yield 'no maintenance message' => [['maintenanceMessage' => ''], 'Maintenance message'];
        yield 'a very long announcement' => [['announcement' => str_repeat('a', 501)], 'Announcement'];
        yield 'a timeline that is not a number' => [['expiryGraceDays' => 'many'], 'Grace'];
        yield 'a backup age of zero' => [['backupAlertHours' => '0'], 'Backup'];
    }

    /**
     * @param array<string, string> $override
     */
    #[DataProvider('provideBadInput')]
    public function testBadValuesAreRefusedAndNothingIsSaved(array $override, string $mentions): void
    {
        $this->confirmedPassword();

        $this->step('POST', 'admin/settings/general', $this->form($override));

        $this->assertStringContainsString($mentions, $this->step('GET', 'admin/settings/general')->response()->getBody());
        $this->assertSame('Mentora', (new PlatformSettings())->get('systemName'), 'not even the good values were kept');
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance mode
    |--------------------------------------------------------------------------
    */

    private function maintenanceOn(string $message = 'Back at noon.'): void
    {
        $settings = new PlatformSettings();
        $settings->set('maintenanceMode', true);
        $settings->set('maintenanceMessage', $message);
        $settings->set('supportContact', 'help@acme.example');
    }

    /** @return iterable<string, array{string}> */
    public static function provideEveryoneElse(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideEveryoneElse')]
    public function testMaintenanceTurnsEveryoneButAdministratorsAway(string $role): void
    {
        $this->maintenanceOn();
        $this->signInAs($role);

        $response = $this->step('GET', '/');

        $this->assertSame(503, $response->response()->getStatusCode());
        $this->assertSame('300', $response->response()->getHeaderLine('Retry-After'));
        $this->assertStringContainsString('Back at noon.', $response->response()->getBody());
        $this->assertStringContainsString('help@acme.example', $response->response()->getBody());
    }

    public function testMaintenanceDoesNotStopAnAdministrator(): void
    {
        $this->maintenanceOn();

        $this->assertSame(200, $this->step('GET', 'admin/settings/general')->response()->getStatusCode());
        $this->assertSame(200, $this->step('GET', 'admin/users')->response()->getStatusCode());
    }

    public function testAnAdministratorViewingAsSomeoneIsNotStoppedEither(): void
    {
        $student = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@example.com', PeopleFactory::institution('ACME'));
        $this->maintenanceOn();

        $this->confirmedPassword();
        $this->step('POST', "admin/users/{$student->id}/impersonate", ['reason' => 'Checking maintenance']);
        Services::resetSingle('auth');

        $this->assertNotSame(503, $this->step('GET', 'student/dashboard')->response()->getStatusCode());
    }

    public function testTheSignInPageAndHealthStayOpenSoAnAdministratorCanGetIn(): void
    {
        $this->maintenanceOn();

        auth()->logout();
        $this->withSession([]);

        $this->assertSame(200, $this->step('GET', 'login')->response()->getStatusCode());
        $this->assertSame(200, $this->step('GET', 'health')->response()->getStatusCode());
    }

    public function testAScriptGetsAJsonRefusal(): void
    {
        $this->maintenanceOn();
        $this->signInAs('student');

        $response = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->get('student/api/calendar/events');

        $this->assertSame(503, $response->response()->getStatusCode());
        $this->assertSame('maintenance', json_decode($response->response()->getBody(), true)['error']);
    }

    public function testSwitchedOffEveryoneIsLetBackIn(): void
    {
        $this->maintenanceOn();
        (new PlatformSettings())->set('maintenanceMode', false);
        $this->signInAs('student');

        $this->assertNotSame(503, $this->step('GET', 'student/dashboard')->response()->getStatusCode());
    }

    /*
    |--------------------------------------------------------------------------
    | The rest at work
    |--------------------------------------------------------------------------
    */

    public function testTheAnnouncementShowsForEveryoneAndNothingWhenEmpty(): void
    {
        $this->assertStringNotContainsString('data-announcement', $this->step('GET', 'admin/dashboard')->response()->getBody());

        (new PlatformSettings())->set('announcement', 'Exams start <b>Monday</b>.');

        $admin = $this->step('GET', 'admin/dashboard')->response()->getBody();

        $this->assertStringContainsString('data-announcement', $admin);
        $this->assertStringContainsString('Exams start &lt;b&gt;Monday&lt;/b&gt;.', $admin, 'shown as text, never as markup');

        $this->signInAs('student');

        $this->assertStringContainsString('data-announcement', $this->step('GET', 'student/dashboard')->response()->getBody());
    }

    public function testTheSystemNameShowsInTheAdminHeader(): void
    {
        (new PlatformSettings())->set('systemName', 'Acme LMS');

        $this->assertStringContainsString('Acme LMS administration', $this->step('GET', 'admin/dashboard')->response()->getBody());
    }

    public function testTheTimezoneSettingIsApplied(): void
    {
        (new PlatformSettings())->set('timezone', 'Asia/Manila');

        $this->step('GET', 'admin/dashboard');

        $this->assertSame('Asia/Manila', date_default_timezone_get());
    }

    public function testAnUnknownStoredTimezoneIsIgnored(): void
    {
        setting()->set('Config\Platform.timezone', 'Mars/Olympus');
        date_default_timezone_set('UTC');

        $this->assertSame(200, $this->step('GET', 'admin/dashboard')->response()->getStatusCode());
        $this->assertSame('UTC', date_default_timezone_get());
    }
}
