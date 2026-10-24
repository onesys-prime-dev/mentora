<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use App\Services\Admin\Impersonation;
use App\Services\Users\AccountControl;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * A system administrator viewing as someone: starting (reason, password, who
 * can be viewed), what the view is (read-only, time-limited, always marked),
 * how it ends, and that the record carries the administrator throughout.
 *
 * @internal
 */
final class ImpersonationTest extends RouteTestCase
{
    private User $admin;

    private User $student;

    private int $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->admin   = model(UserModel::class)->findById(CourseFactory::user('admin')->id);
        $this->acme    = PeopleFactory::institution('ACME', 'active', 'Acme University');
        $this->student = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@example.com', $this->acme);

        $this->loggedInAs($this->admin);
        $this->withSession();
        $_SESSION[AccountStateFilter::SIGNED_IN_AT] = time() - 60;
        $this->withSession();

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('settings');

        parent::tearDown();
    }

    /**
     * One request. A real request starts a new process that reads the session
     * afresh; the test process keeps the authenticator between requests, so it
     * is reset here or it would still think it is the person who was signed in
     * before the session changed.
     *
     * @param array<string, mixed> $data
     */
    private function go(string $verb, string $uri, array $data = []): TestResponse
    {
        Services::resetSingle('auth');

        return $this->step($verb, $uri, $data);
    }

    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    private function viewAs(User $user, string $reason = 'Support ticket 1234'): TestResponse
    {
        $this->confirmedPassword();

        return $this->go('POST', "admin/users/{$user->id}/impersonate", ['reason' => $reason]);
    }

    private function signedInId(): int
    {
        return (int) ($_SESSION['user']['id'] ?? 0);
    }

    private function audit(string $action): ?array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->get()->getRowArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Starting
    |--------------------------------------------------------------------------
    */

    public function testStartingNeedsThePasswordAgain(): void
    {
        $response = $this->go('POST', "admin/users/{$this->student->id}/impersonate", ['reason' => 'Support ticket 1234']);

        $this->assertStringContainsString('admin/reauth', $response->response()->getHeaderLine('Location'));
        $this->assertSame((int) $this->admin->id, $this->signedInId());
        $this->assertFalse(Impersonation::active());
    }

    public function testStartingMakesTheSessionThePersonsAndRecordsIt(): void
    {
        $response = $this->viewAs($this->student);

        $this->assertSame(302, $response->response()->getStatusCode());
        $this->assertSame((int) $this->student->id, $this->signedInId());
        $this->assertSame((int) $this->admin->id, (int) $_SESSION['impersonator_id']);

        $entry = $this->audit('impersonation.started');

        $this->assertSame((int) $this->admin->id, (int) $entry['actor_user_id']);
        $this->assertSame((int) $this->student->id, (int) $entry['target_id']);
        $this->assertSame($this->acme, (int) $entry['institution_id']);
        $this->assertStringContainsString('Support ticket 1234', (string) $entry['new_values']);
    }

    public function testTheViewLastsAsLongAsTheSettingSays(): void
    {
        (new App\Services\Admin\PlatformSettings())->set('impersonationMinutes', 10);

        $before = time();
        $this->viewAs($this->student);

        $this->assertEqualsWithDelta($before + 600, $_SESSION['impersonation']['expires_at'], 5);
    }

    public function testAReasonIsRequired(): void
    {
        $response = $this->viewAs($this->student, 'hi');

        $this->assertStringEndsWith("admin/users/{$this->student->id}", $response->response()->getHeaderLine('Location'));
        $this->assertSame((int) $this->admin->id, $this->signedInId());
        $this->assertNull($this->audit('impersonation.started'));
        $this->assertStringContainsString('Say why', $this->go('GET', "admin/users/{$this->student->id}")->response()->getBody());
    }

    public function testAnotherSystemAdministratorCannotBeViewed(): void
    {
        $other = PeopleFactory::person('admin', 'Other', 'Admin');

        $this->viewAs($other);

        $this->assertSame((int) $this->admin->id, $this->signedInId());
        $this->assertFalse(Impersonation::active());
    }

    public function testYouCannotViewAsYourself(): void
    {
        $this->viewAs($this->admin);

        $this->assertFalse(Impersonation::active());
    }

    public function testADeactivatedAccountCannotBeViewed(): void
    {
        (new AccountControl())->deactivate((int) $this->student->id);

        $this->viewAs($this->student);

        $this->assertFalse(Impersonation::active());
        $this->assertSame((int) $this->admin->id, $this->signedInId());
    }

    public function testAnUnknownPersonCannotBeViewed(): void
    {
        $this->confirmedPassword();
        $this->go('POST', 'admin/users/999999/impersonate', ['reason' => 'Support ticket 1234']);

        $this->assertFalse(Impersonation::active());
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseCanStartOne(string $role): void
    {
        $other = CourseFactory::user($role);

        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs($other);
        $this->withSession();

        $this->go('POST', "admin/users/{$this->student->id}/impersonate", ['reason' => 'Support ticket 1234']);

        $this->assertSame((int) $other->id, $this->signedInId());
        $this->assertFalse(Impersonation::active());
        $this->assertNull($this->audit('impersonation.started'));
    }

    /*
    |--------------------------------------------------------------------------
    | While viewing
    |--------------------------------------------------------------------------
    */

    public function testThePageIsShownAsThePersonSeesItWithTheBannerAlwaysThere(): void
    {
        $this->viewAs($this->student);

        $response = $this->go('GET', 'student/dashboard');
        $body     = $response->response()->getBody();

        $this->assertSame(200, $response->response()->getStatusCode());
        $this->assertStringContainsString('data-impersonation-banner', $body);
        $this->assertStringContainsString('stu@example.com', $body);
        $this->assertStringContainsString('Support ticket 1234', $body);
        $this->assertStringContainsString('impersonation/stop', $body);
    }

    public function testNothingCanBeChangedWhileViewing(): void
    {
        $this->viewAs($this->student);

        $response = $this->go('POST', 'student/api/calendar/reminders', ['title' => 'Sneaky', 'remind_at' => '2026-12-01 10:00:00']);

        $this->assertSame(302, $response->response()->getStatusCode());
        $this->assertSame(0, db_connect()->table('reminders')->countAllResults());

        $this->go('PATCH', 'student/api/calendar/reminders/1/done');
        $this->go('DELETE', 'student/api/calendar/reminders/1');

        $this->assertSame(0, db_connect()->table('reminders')->countAllResults());
    }

    public function testAScriptGetsAJsonRefusal(): void
    {
        $this->viewAs($this->student);

        $response = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->post('student/api/calendar/reminders', ['title' => 'x']);

        $this->assertSame(403, $response->response()->getStatusCode());
        $this->assertSame('impersonation_view_only', json_decode($response->response()->getBody(), true)['error']);
    }

    public function testTheAdminAreaIsNotReachableWhileViewing(): void
    {
        $this->viewAs($this->student);

        $this->assertSame(302, $this->go('GET', 'admin/users')->response()->getStatusCode());
        $this->assertTrue(Impersonation::active(), 'still just viewing');
    }

    public function testViewingDoesNotChangeThePersonsSavedRole(): void
    {
        $both = PeopleFactory::person('instructor', 'Dual', 'Role', 'dual@example.com', $this->acme);
        db_connect()->table('auth_groups_users')->insert(['user_id' => $both->id, 'group' => 'chairperson']);

        $this->viewAs($both);

        $this->assertSame('chairperson', $_SESSION['active_role'], 'dual-role people open as chairperson');
        $this->assertNull(db_connect()->table('user_profiles')->where('user_id', $both->id)->get()->getRowArray()['active_role'], 'and nothing is saved for them');
    }

    public function testEntriesWrittenWhileViewingCarryTheAdministrator(): void
    {
        $this->viewAs($this->student);
        Services::resetSingle('auth');

        service('auditLogger')->log('test.view', auth()->user(), ['target_type' => 'thing']);

        $entry = $this->audit('test.view');

        $this->assertSame((int) $this->student->id, (int) $entry['actor_user_id']);
        $this->assertSame((int) $this->admin->id, (int) $entry['impersonator_user_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Ending
    |--------------------------------------------------------------------------
    */

    public function testExitingPutsTheAdministratorBackAndRecordsIt(): void
    {
        $roleBefore = $_SESSION['active_role'] ?? null;

        $this->viewAs($this->student);

        $response = $this->go('POST', 'impersonation/stop');

        $this->assertStringEndsWith("admin/users/{$this->student->id}", $response->response()->getHeaderLine('Location'));
        $this->assertSame((int) $this->admin->id, $this->signedInId());
        $this->assertFalse(Impersonation::active());
        $this->assertArrayNotHasKey('impersonator_id', $_SESSION);
        $this->assertSame($roleBefore, $_SESSION['active_role'] ?? null, 'their own role is back');

        $entry = $this->audit('impersonation.ended');

        $this->assertSame((int) $this->admin->id, (int) $entry['actor_user_id']);
        $this->assertNull($entry['impersonator_user_id'], 'they are themselves again');
        $this->assertStringContainsString('stopped', (string) $entry['new_values']);

        $this->assertSame(200, $this->go('GET', 'admin/users')->response()->getStatusCode(), 'the admin area works again');
    }

    public function testTheViewEndsByItselfWhenTheTimeIsUp(): void
    {
        $this->viewAs($this->student);
        $_SESSION['impersonation']['expires_at'] = time() - 1;
        $this->withSession();

        $response = $this->go('GET', 'student/dashboard');

        $this->assertStringEndsWith("admin/users/{$this->student->id}", $response->response()->getHeaderLine('Location'));
        $this->assertSame((int) $this->admin->id, $this->signedInId());
        $this->assertStringContainsString('expired', (string) $this->audit('impersonation.ended')['new_values']);
        $this->assertStringContainsString('time limit', $this->go('GET', "admin/users/{$this->student->id}")->response()->getBody());
    }

    public function testTheTimeLimitAlsoStopsWritesAtOnce(): void
    {
        $this->viewAs($this->student);
        $_SESSION['impersonation']['expires_at'] = time() - 1;
        $this->withSession();

        $this->go('POST', 'student/api/calendar/reminders', ['title' => 'late']);

        $this->assertFalse(Impersonation::active());
        $this->assertSame(0, db_connect()->table('reminders')->countAllResults());
    }

    public function testSigningOutEndsTheViewInsteadOfSigningTheAdministratorOut(): void
    {
        $this->viewAs($this->student);

        $response = $this->go('POST', 'logout');

        $this->assertStringEndsWith("admin/users/{$this->student->id}", $response->response()->getHeaderLine('Location'));
        $this->assertSame((int) $this->admin->id, $this->signedInId());
        $this->assertFalse(Impersonation::active());
    }

    public function testTheViewEndsWhenThePersonIsDeactivatedMeanwhile(): void
    {
        $this->viewAs($this->student);
        (new AccountControl())->deactivate((int) $this->student->id);

        $response = $this->go('GET', 'student/dashboard');

        $this->assertStringEndsWith("admin/users/{$this->student->id}", $response->response()->getHeaderLine('Location'));
        $this->assertSame((int) $this->admin->id, $this->signedInId());
    }

    public function testTheViewEndsAndTheAdministratorIsSentToSignInIfTheirOwnAccountIsSwitchedOff(): void
    {
        $this->viewAs($this->student);
        (new AccountControl())->deactivate((int) $this->admin->id);

        $response = $this->go('GET', 'student/dashboard');

        $this->assertFalse(Impersonation::active());
        $this->assertStringEndsWith('login', $response->response()->getHeaderLine('Location'));
    }

    public function testExitingWithNoViewInProgressJustGoesHome(): void
    {
        $response = $this->go('POST', 'impersonation/stop');

        $this->assertSame(302, $response->response()->getStatusCode());
        $this->assertSame((int) $this->admin->id, $this->signedInId());
    }

    public function testStartingWhileAlreadyViewingIsRefused(): void
    {
        $other = PeopleFactory::person('instructor', 'Other', 'Person', 'other@example.com', $this->acme);

        $this->viewAs($this->student);
        $service = new Impersonation();

        $this->expectException(InvalidArgumentException::class);

        $service->start($this->admin, (int) $other->id, 'Another reason');
    }

    public function testThePersonPageOffersTheViewOnlyForSomeoneWhoCanBeViewed(): void
    {
        $this->assertStringContainsString('data-section="view-as"', $this->go('GET', "admin/users/{$this->student->id}")->response()->getBody());

        $other = PeopleFactory::person('admin', 'Other', 'Admin');
        $this->assertStringNotContainsString('data-section="view-as"', $this->go('GET', "admin/users/{$other->id}")->response()->getBody());
        $this->assertStringNotContainsString('data-section="view-as"', $this->go('GET', "admin/users/{$this->admin->id}")->response()->getBody());
    }
}
