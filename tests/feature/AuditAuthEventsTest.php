<?php

declare(strict_types=1);

use App\Auth\AuditAuthListener;
use App\Services\Admin\SystemAdminService;
use App\Services\Audit\AuditLogger;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\CourseFactory;
use Tests\Support\FakeEmail;
use Tests\Support\RouteTestCase;

/**
 * Signing in, failing to, and signing out all leave an audit entry; the
 * entry for an administrator appears only once the emailed code is entered;
 * a password never reaches the log; and a broken audit log never stops
 * anyone signing in.
 *
 * @internal
 */
final class AuditAuthEventsTest extends RouteTestCase
{
    private const PASSWORD = 'a-long-password-1';

    protected function tearDown(): void
    {
        Services::resetSingle('auditLogger');
        service('superglobals')->setServer('REMOTE_ADDR', '0.0.0.0');

        parent::tearDown();
    }

    private function studentWithAPassword(string $email = 'sam@mentora.test'): User
    {
        $user = CourseFactory::user('student');
        $user->createEmailIdentity(['email' => $email, 'password' => self::PASSWORD]);

        return model(UserModel::class)->findById($user->id);
    }

    private function signIn(string $email, string $password = self::PASSWORD): void
    {
        $this->step('POST', 'login', ['email' => $email, 'password' => $password]);
    }

    /** @return list<array<string, mixed>> */
    private function entries(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->orderBy('id')->get()->getResultArray();
    }

    private function entryCount(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    /*
    |--------------------------------------------------------------------------
    | Sign-in
    |--------------------------------------------------------------------------
    */

    public function testASuccessfulSignInIsLogged(): void
    {
        $user = $this->studentWithAPassword();

        $this->signIn('sam@mentora.test');

        $entries = $this->entries('auth.login');

        $this->assertCount(1, $entries);
        $this->assertSame((string) $user->id, (string) $entries[0]['actor_user_id']);
        $this->assertSame('sam@mentora.test', $entries[0]['actor_label']);
        $this->assertSame('student', $entries[0]['acting_role']);
        $this->assertSame('user', $entries[0]['target_type']);
    }

    public function testTheSourceAddressIsRecorded(): void
    {
        $this->studentWithAPassword();
        service('superglobals')->setServer('REMOTE_ADDR', '203.0.113.9');

        $this->signIn('sam@mentora.test');

        $this->assertSame('203.0.113.9', $this->entries('auth.login')[0]['ip_address']);
    }

    public function testAWrongPasswordIsLoggedAsAFailureWithoutTheActorSignedIn(): void
    {
        $user = $this->studentWithAPassword();

        $this->signIn('sam@mentora.test', 'not-the-password');

        $this->assertSame([], $this->entries('auth.login'));

        $entries = $this->entries('auth.login_failed');

        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]['actor_user_id'], 'nobody was signed in');
        $this->assertSame('sam@mentora.test', $entries[0]['actor_label']);
        $this->assertSame((string) $user->id, (string) $entries[0]['target_id'], 'the account that was attacked is noted');
    }

    public function testNeitherPasswordIsEverStored(): void
    {
        $this->studentWithAPassword();

        $this->signIn('sam@mentora.test', 'totally-wrong-secret-9');
        $this->signIn('sam@mentora.test');

        $dump = json_encode(db_connect()->table('audit_logs')->get()->getResultArray());

        $this->assertStringNotContainsString('totally-wrong-secret-9', $dump);
        $this->assertStringNotContainsString(self::PASSWORD, $dump);
    }

    public function testAFailureForAnUnknownAddressIsLoggedWithNoTarget(): void
    {
        $this->signIn('nobody@mentora.test', 'whatever-it-is-12');

        $entries = $this->entries('auth.login_failed');

        $this->assertCount(1, $entries);
        $this->assertSame('nobody@mentora.test', $entries[0]['actor_label']);
        $this->assertNull($entries[0]['target_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | Sign-out
    |--------------------------------------------------------------------------
    */

    public function testSigningOutIsLogged(): void
    {
        $user = $this->studentWithAPassword();
        $this->signIn('sam@mentora.test');

        auth()->logout();

        $entries = $this->entries('auth.logout');

        $this->assertCount(1, $entries);
        $this->assertSame((string) $user->id, (string) $entries[0]['actor_user_id']);
    }

    public function testSigningOutWhenNobodyIsSignedInLogsNothing(): void
    {
        auth()->logout();

        $this->assertSame([], $this->entries('auth.logout'));
    }

    /*
    |--------------------------------------------------------------------------
    | Administrators
    |--------------------------------------------------------------------------
    */

    public function testAnAdministratorsSignInIsLoggedOnlyAfterTheEmailedCode(): void
    {
        $mailer = FakeEmail::install();
        (new SystemAdminService())->create('root@mentora.test', 'root', self::PASSWORD);

        $this->signIn('root@mentora.test');
        $this->assertSame(0, $this->entryCount('auth.login'), 'a password alone is not a completed sign-in');

        $this->step('POST', 'auth/a/handle', ['email' => 'root@mentora.test']);
        $this->step('POST', 'auth/a/verify', ['token' => $mailer->lastCode()]);

        $entries = $this->entries('auth.login');

        $this->assertCount(1, $entries);
        $this->assertSame('admin', $entries[0]['acting_role']);

        Services::resetSingle('email');
    }

    /*
    |--------------------------------------------------------------------------
    | A broken audit log must not break sign-in
    |--------------------------------------------------------------------------
    */

    public function testSigningInStillWorksWhenTheAuditLogCannotBeWritten(): void
    {
        $this->studentWithAPassword();

        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                throw new RuntimeException('the audit table is unavailable');
            }
        });

        $this->signIn('sam@mentora.test');

        $this->assertTrue(auth()->loggedIn(), 'they are signed in regardless');
    }

    public function testTheListenersSwallowAWriteFailure(): void
    {
        Services::injectMock('auditLogger', new class () extends AuditLogger {
            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                throw new RuntimeException('boom');
            }
        });

        AuditAuthListener::login(CourseFactory::user('student'));
        AuditAuthListener::failedLogin(['email' => 'x@y.test']);
        AuditAuthListener::logout(CourseFactory::user('student'));

        $this->addToAssertionCount(1);
    }
}
