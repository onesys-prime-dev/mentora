<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use App\Services\Institutions\InstitutionSecurity;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\CourseFactory;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * Settings: the Two-step sign-in and Signed-in devices cards (D14, D21). Turning two-step sign-in on and off,
 * a school requirement, signing out every other browser while keeping this one, a read-only institution, CSRF
 * and an administrator's view-as session.
 *
 * @internal
 */
final class SettingsSecurityTest extends RouteTestCase
{
    private const PASSWORD = 'Tr4ins-Lantern-Moss-92';

    private int $institution;

    private User $ivo;

    private FakeEmail $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        Services::resetSingle('throttler');
        InstitutionSecurity::forget();

        $this->mailer      = FakeEmail::install();
        $this->institution = PeopleFactory::institution('SEC');
        $this->ivo         = PeopleFactory::person('instructor', 'Ivo', 'Teacher', 'ivo@example.com', $this->institution);

        db_connect()->table('auth_identities')->where(['user_id' => $this->ivo->id, 'type' => 'email_password'])->update(['secret2' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
        $this->ivo = model(UserModel::class)->findById($this->ivo->id);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        Services::resetSingle('throttler');
        InstitutionSecurity::forget();

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

    private function optedIn(): bool
    {
        return (int) db_connect()->table('user_profiles')->where('user_id', $this->ivo->id)->get()->getRow('two_factor_opt_in') === 1;
    }

    private function audits(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    private function page(): string
    {
        return (string) $this->step('GET', 'settings')->response()->getBody();
    }

    public function testThePageShowsBothCardsInOrder(): void
    {
        $this->browserSignedInSince($this->ivo, time() - 60);

        $body = $this->page();

        $this->assertStringContainsString('href="#two-step"', $body);
        $this->assertStringContainsString('href="#devices"', $body);
        $this->assertLessThan(strpos($body, 'id="two-step"'), strpos($body, 'id="password"'));
        $this->assertLessThan(strpos($body, 'id="devices"'), strpos($body, 'id="two-step"'));
        $this->assertMatchesRegularExpression('#role="switch"[^>]*aria-checked="false"#', $body);
        $this->assertStringContainsString('Sign out everywhere except this browser.', $body);
        $this->assertStringContainsString('Sign out of all other devices', $body);
    }

    public function testTurningItOnAndOff(): void
    {
        $this->browserSignedInSince($this->ivo, time() - 60);

        $this->step('POST', 'settings/two-factor', ['action' => 'on']);
        $this->assertSame('Two-step sign-in turned on', session('message'));
        $this->assertTrue($this->optedIn());
        $this->assertStringContainsString('On — we email a code to i•••@example.com when you sign in', $this->page());

        $this->step('POST', 'settings/two-factor', ['action' => 'off', 'password' => 'wrong']);
        $this->assertTrue($this->optedIn(), 'a wrong password changes nothing');
        $this->assertSame('Your password is not right.', session('errors')['two_factor_password'] ?? null);
        $this->assertStringNotContainsString('value="wrong"', $this->page(), 'a typed password is never echoed back');

        $this->step('POST', 'settings/two-factor', ['action' => 'off', 'password' => self::PASSWORD]);
        $this->assertSame('Two-step sign-in turned off', session('message'));
        $this->assertFalse($this->optedIn());

        $this->assertSame(1, $this->audits('profile.two_factor_on'));
        $this->assertSame(1, $this->audits('profile.two_factor_off'));
        $this->assertCount(2, $this->mailer->sent);
    }

    public function testARequiredSwitchIsOnAndDisabled(): void
    {
        (new InstitutionSecurity())->save($this->institution, ['twoFactorRoles' => ['instructor']]);
        $this->browserSignedInSince($this->ivo, time() - 60);

        $body = $this->page();
        $this->assertStringContainsString('Required by your school', $body);
        $this->assertMatchesRegularExpression('#role="switch"[^>]*aria-checked="true"[^>]*disabled#', $body);

        $this->step('POST', 'settings/two-factor', ['action' => 'off', 'password' => self::PASSWORD]);
        $this->assertSame('Your school requires two-step sign-in for your role.', session('errors')['two_factor_password'] ?? null);
        $this->assertSame(0, $this->audits('profile.two_factor_off'));
    }

    public function testSigningOutOthersKeepsThisBrowserAndEndsTheOther(): void
    {
        // Browser A asks for it.
        $this->browserSignedInSince($this->ivo, time() - 3600);
        $this->step('POST', 'settings/sign-out-others');

        $this->assertSame('Signed out of all other devices', session('message'));
        $this->assertSame(200, $this->step('GET', 'settings')->response()->getStatusCode(), 'browser A stays signed in');
        $this->assertTrue(auth()->loggedIn());

        $this->assertSame(1, $this->audits('profile.signed_out_others'));
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('ivo@example.com', $this->mailer->sent[0]['to']);

        // Browser B signed in before it: its next request signs it out.
        $this->browserSignedInSince($this->ivo, time() - 3600);
        $other = $this->step('GET', 'settings');

        $this->assertStringEndsWith('login', $other->response()->getHeaderLine('Location'));
        $this->assertSame('You were signed out. Please sign in again.', session('error'));
        $this->assertFalse(auth()->loggedIn());
    }

    public function testAReadOnlyInstitutionStillAllowsBoth(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'past']);
        $this->browserSignedInSince($this->ivo, time() - 60);

        $this->step('POST', 'settings/two-factor', ['action' => 'on']);
        $this->assertTrue($this->optedIn());

        $this->step('POST', 'settings/sign-out-others');
        $this->assertSame('Signed out of all other devices', session('message'));
    }

    public function testWithoutACsrfTokenNothingChanges(): void
    {
        $this->browserSignedInSince($this->ivo, time() - 60);
        $this->withoutCsrfToken();

        $this->step('POST', 'settings/two-factor', ['action' => 'on']);
        $this->step('POST', 'settings/sign-out-others');

        $this->assertFalse($this->optedIn());
        $this->assertSame(0, $this->audits('profile.signed_out_others'));
        $this->assertSame(App\Filters\CsrfFilter::MESSAGE, session('error'));
    }

    public function testAnAdministratorViewingAsSomeoneCannotChangeEither(): void
    {
        $admin    = CourseFactory::user('admin');
        $_SESSION = [];
        Services::resetSingle('auth');
        $this->loggedInAs($admin);
        $this->withSession();
        $_SESSION[AccountStateFilter::SIGNED_IN_AT] = time() - 60;
        $_SESSION['reauth']                          = ['user' => $admin->id, 'at' => time()];
        $this->withSession();
        Services::resetSingle('auth');
        $this->step('POST', "admin/users/{$this->ivo->id}/impersonate", ['reason' => 'Support ticket 1234']);
        Services::resetSingle('auth');

        $this->assertSame((int) $this->ivo->id, (int) ($_SESSION['user']['id'] ?? 0), 'the view of Ivo must be on');

        $this->step('POST', 'settings/two-factor', ['action' => 'on']);
        $this->step('POST', 'settings/sign-out-others');

        $this->assertFalse($this->optedIn());
        $this->assertSame(0, $this->audits('profile.two_factor_on'));
        $this->assertSame(0, $this->audits('profile.signed_out_others'));
        $this->assertSame([], $this->mailer->sent);
    }
    public function testAnAccountWithNoProfileIsToldWhyInsteadOfAnError(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->ivo->id)->delete();
        $this->browserSignedInSince($this->ivo, time() - 60);

        $this->step('POST', 'settings/two-factor', ['action' => 'on']);

        $this->assertStringContainsString('Ask your school to finish setting up your account.', $this->page());
        $this->assertSame(0, $this->audits('profile.two_factor_on'));
    }

    public function testTheSignOutAndItsAuditEntryAreWrittenInOneTransaction(): void
    {
        $this->browserSignedInSince($this->ivo, time() - 3600);
        $depth = static fn (): int => (int) (new ReflectionProperty(db_connect(), 'transDepth'))->getValue(db_connect());
        $outer = $depth();
        $seen  = new ArrayObject();

        // The test's own transaction is outermost, so a rollback cannot be watched here: check the audit entry is
        // written inside a transaction of the action's own, after the sign-out, and a failure stops the notice.
        Services::injectMock('auditLogger', new class ($depth, $seen, (int) $this->ivo->id) extends App\Services\Audit\AuditLogger {
            public function __construct(private Closure $depth, private ArrayObject $seen, private int $owner)
            {
            }

            public function log(string $action, ?User $actor = null, array $details = []): int
            {
                $this->seen['depth']     = ($this->depth)();
                $this->seen['signedOut'] = db_connect()->table('user_account_controls')->where('user_id', $this->owner)->get()->getRow('sessions_valid_from') !== null;

                throw new RuntimeException('audit store down');
            }
        });

        try {
            $this->step('POST', 'settings/sign-out-others');
            $this->fail('the failure should reach the error page');
        } catch (RuntimeException $e) {
            $this->assertSame('audit store down', $e->getMessage());
        }

        $this->assertSame(['depth' => $outer + 1, 'signedOut' => true], $seen->getArrayCopy());
        $this->assertSame($outer, $depth(), 'rolled back');
        $this->assertSame([], $this->mailer->sent);
    }
}
