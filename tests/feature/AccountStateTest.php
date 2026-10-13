<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use App\Services\Users\AccountControl;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Deactivating an account and ending sessions has to work on someone who is
 * ALREADY signed in, on their very next request (Shield only looks at "banned"
 * while signing in). These tests make requests as a signed-in person and watch
 * what the global account-state filter does.
 *
 * @internal
 */
final class AccountStateTest extends RouteTestCase
{
    private const STRONG = 'Tr4ins-Lantern-Moss-92';

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->student = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@example.com', PeopleFactory::institution('ACME'));
    }

    /** Signs the student in, as a session that began at $startedAt (unix time; null = the time was not recorded). */
    private function signedInSince(?int $startedAt): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs($this->student);
        $this->withSession($startedAt === null ? [] : [AccountStateFilter::SIGNED_IN_AT => $startedAt]);
    }

    private function openDashboard(): CodeIgniter\Test\TestResponse
    {
        return $this->step('GET', 'student/dashboard');
    }

    public function testAnAccountInGoodStandingIsLetThrough(): void
    {
        $this->signedInSince(time() - 3600);

        $response = $this->openDashboard();

        $this->assertNotSame(302, $response->response()->getStatusCode(), 'not sent anywhere');
        $this->assertTrue(auth()->loggedIn());
    }

    public function testAPersonWhoseAccountWasDeactivatedIsSentToSignInOnTheNextRequest(): void
    {
        $this->signedInSince(time() - 3600);
        $this->assertNotSame(302, $this->openDashboard()->response()->getStatusCode());

        (new AccountControl())->deactivate((int) $this->student->id);

        $response = $this->openDashboard();

        $this->assertSame(302, $response->response()->getStatusCode());
        $this->assertStringEndsWith('login', $response->response()->getHeaderLine('Location'));
        $this->assertFalse(auth()->loggedIn(), 'they have been signed out');
        $this->assertStringContainsString('deactivated', (string) session('error'));
    }

    public function testEvenASessionStartedAfterTheDeactivationIsTurnedAway(): void
    {
        (new AccountControl())->deactivate((int) $this->student->id, new DateTimeImmutable('-1 hour'));
        $this->signedInSince(time());

        $this->assertStringEndsWith('login', $this->openDashboard()->response()->getHeaderLine('Location'));
    }

    public function testASessionThatBeganBeforeASignOutIsEndedAndANewOneIsNot(): void
    {
        $this->signedInSince(time() - 3600);
        (new AccountControl())->signOutEverywhere((int) $this->student->id, new DateTimeImmutable('-10 minutes'));

        $old = $this->openDashboard();

        $this->assertStringEndsWith('login', $old->response()->getHeaderLine('Location'));
        $this->assertStringContainsString('signed out by an administrator', (string) session('error'));

        $this->signedInSince(time() - 60);

        $this->assertNotSame(302, $this->openDashboard()->response()->getStatusCode(), 'signing in again works');
    }

    public function testASessionWithNoRecordedStartIsEndedByASignOut(): void
    {
        (new AccountControl())->signOutEverywhere((int) $this->student->id, new DateTimeImmutable('-10 minutes'));
        $this->signedInSince(null);

        $this->assertStringEndsWith('login', $this->openDashboard()->response()->getHeaderLine('Location'));
    }

    public function testAScriptIsToldWithAJsonErrorNotARedirect(): void
    {
        $this->signedInSince(time() - 3600);
        (new AccountControl())->deactivate((int) $this->student->id);

        $response = $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->get('student/dashboard');

        $this->assertSame(401, $response->response()->getStatusCode());
        $this->assertSame('deactivated', json_decode($response->response()->getBody(), true)['error']);
    }

    public function testSomeoneElsesDeactivationDoesNotTouchYou(): void
    {
        $other = PeopleFactory::person('student', 'Other', 'Student', 'other@example.com');
        (new AccountControl())->deactivate((int) $other->id);

        $this->signedInSince(time() - 3600);

        $this->assertNotSame(302, $this->openDashboard()->response()->getStatusCode());
    }

    public function testAnonymousVisitorsAreNotAffected(): void
    {
        (new AccountControl())->deactivate((int) $this->student->id);
        auth()->logout();
        $this->withSession([]);

        $this->assertSame(200, $this->step('GET', 'login')->response()->getStatusCode());
    }

    public function testSigningInRecordsWhenTheSessionBegan(): void
    {
        $user = PeopleFactory::person('student', 'Sign', 'In', 'signin@example.com');
        db_connect()->table('auth_identities')->where(['user_id' => $user->id, 'type' => 'email_password'])->update(['secret2' => password_hash(self::STRONG, PASSWORD_DEFAULT)]);

        auth()->logout();
        $this->withSession([]);

        $before = time();
        $this->step('POST', 'login', ['email' => 'signin@example.com', 'password' => self::STRONG]);

        $this->assertTrue(auth()->loggedIn());
        $this->assertGreaterThanOrEqual($before, (int) session(AccountStateFilter::SIGNED_IN_AT));
        $this->assertLessThanOrEqual(time(), (int) session(AccountStateFilter::SIGNED_IN_AT));
    }

    public function testADeactivatedAccountCannotStayInEvenRightAfterSigningIn(): void
    {
        $user = PeopleFactory::person('student', 'Lock', 'Out', 'lockout@example.com');
        db_connect()->table('auth_identities')->where(['user_id' => $user->id, 'type' => 'email_password'])->update(['secret2' => password_hash(self::STRONG, PASSWORD_DEFAULT)]);
        (new AccountControl())->deactivate((int) $user->id);

        auth()->logout();
        $this->withSession([]);

        $this->step('POST', 'login', ['email' => 'lockout@example.com', 'password' => self::STRONG]);
        $next = $this->step('GET', 'student/dashboard');

        $this->assertStringEndsWith('login', $next->response()->getHeaderLine('Location'));
        $this->assertFalse(auth()->loggedIn());
    }
}
