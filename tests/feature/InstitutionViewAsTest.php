<?php

declare(strict_types=1);

use App\Filters\AccountStateFilter;
use App\Services\Admin\Impersonation;
use App\Services\Institutions\InstitutionAuditLog;
use App\Services\Audit\AuditLogFilters;
use App\Services\Users\AccountControl;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * An institution's administrator viewing as one of its own people: who may,
 * who can be viewed (their own institution's people, never an administrator,
 * never someone who is also elsewhere), that the view is read-only and
 * time-limited, how it ends and returns them, and that the record names them.
 *
 * @internal
 */
final class InstitutionViewAsTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        FakeEmail::install();

        $this->institution = PeopleFactory::institution('VWA', 'active', 'View A');
        $this->other       = PeopleFactory::institution('VWB', 'active', 'View B');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@view.example', $this->institution);
        $this->student     = PeopleFactory::person('student', 'Stu', 'Dent', 'stu@view.example', $this->institution);

        $this->signInAs($this->admin);
    }

    private function signInAs(User $user): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);
        $this->withSession();
        $_SESSION[AccountStateFilter::SIGNED_IN_AT] = time() - 60;
        $_SESSION['reauth']                         = ['user' => $user->id, 'at' => time()];
        $this->withSession();

        db_connect()->table('audit_logs')->where('id >', 0)->delete();
    }

    /** One request from a fresh authenticator, as a real request would be. */
    private function go(string $verb, string $uri, array $data = []): TestResponse
    {
        Services::resetSingle('auth');

        return $this->step($verb, $uri, $data);
    }

    private function viewAs(User $target, string $reason = 'A student says the grades page is empty'): TestResponse
    {
        return $this->go('POST', "institution/view-as/{$target->id}", ['reason' => $reason]);
    }

    private function signedInId(): int
    {
        return (int) ($_SESSION['user']['id'] ?? 0);
    }

    private function audit(string $action): ?array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->get()->getRowArray();
    }

    /** The error a refused start gives, asked for the way the dialog asks. */
    private function refusal(User $target, string $reason = 'A student says the grades page is empty'): string
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $response = $this->viewAs($target, $reason);
        $this->withHeaders(['Accept' => 'text/html']);

        $this->assertSame(422, $response->response()->getStatusCode());
        $this->assertFalse(Impersonation::active());
        $this->assertSame((int) $this->admin->id, $this->signedInId());

        $body = json_decode($response->response()->getBody(), true);

        return (string) ($body['message'] ?? '') . ' ' . implode(' ', array_map('strval', array_merge(...array_values(array_map(static fn ($e) => (array) $e, $body['errors'] ?? [])))));
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanStartIt(string $role): void
    {
        $this->signInAs(PeopleFactory::person($role, 'Some', 'One', "{$role}@view.example", $role === 'admin' ? null : $this->institution));

        $response = $this->viewAs($this->student);

        $this->assertSame(302, $response->response()->getStatusCode());
        $this->assertFalse(Impersonation::active());
        $this->assertNull($this->audit('impersonation.started'));
    }

    public function testStartingNeedsThePasswordAgain(): void
    {
        unset($_SESSION['reauth']);
        $this->withSession();

        $response = $this->viewAs($this->student);

        $this->assertStringContainsString('reauth', $response->response()->getHeaderLine('Location'));
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
        $this->assertSame($this->institution, (int) $entry['institution_id']);
        $this->assertStringContainsString('grades page is empty', (string) $entry['new_values']);
    }

    public function testTheDialogIsToldWhereToGo(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $response = $this->viewAs($this->student);
        $this->withHeaders(['Accept' => 'text/html']);

        $this->assertSame(200, $response->response()->getStatusCode());
        $this->assertSame(site_url('/'), json_decode($response->response()->getBody(), true)['redirect'] ?? null);
        $this->assertTrue(Impersonation::active());
    }

    public function testAReasonIsRequired(): void
    {
        $this->assertStringContainsString('Say why', $this->refusal($this->student, 'hi'));
        $this->assertNull($this->audit('impersonation.started'));
    }

    public function testSomeoneOfAnotherInstitutionIsAnswerAsIfTheyDoNotExist(): void
    {
        $foreign = PeopleFactory::person('student', 'Far', 'Away', 'far@view.example', $this->other);

        $this->assertStringContainsString('does not exist', $this->refusal($foreign));
    }

    public function testNeitherASystemAdministratorNorAnotherAdministratorCanBeViewed(): void
    {
        $colleague = PeopleFactory::person('institutional_admin', 'Colin', 'Colleague', 'col@view.example', $this->institution);

        $this->assertStringContainsString('administrator', $this->refusal($colleague));
        $this->assertStringContainsString('does not exist', $this->refusal(PeopleFactory::person('admin', 'Root', 'Admin', 'root@view.example')));
    }

    public function testYouCannotViewAsYourself(): void
    {
        $this->assertStringContainsString('yourself', $this->refusal($this->admin));
    }

    public function testADeactivatedPersonCannotBeViewed(): void
    {
        (new AccountControl())->deactivate((int) $this->student->id);

        $this->assertStringContainsString('deactivated', $this->refusal($this->student));
    }

    public function testTheViewIsReadOnlyAndMarked(): void
    {
        $this->viewAs($this->student);

        $page = $this->go('GET', 'student/dashboard');

        $this->assertSame(200, $page->response()->getStatusCode());
        $this->assertStringContainsString('data-impersonation-banner', $page->response()->getBody());

        $this->withHeaders(['Accept' => 'application/json']);
        $write = $this->go('POST', 'role/switch', ['role' => 'student']);
        $this->withHeaders(['Accept' => 'text/html']);

        $this->assertSame(403, $write->response()->getStatusCode());
        $this->assertStringContainsString('impersonation_view_only', $write->response()->getBody());
        $this->assertTrue(Impersonation::active());
    }

    public function testEntriesMadeWhileViewingNameTheAdministratorInTheirAuditLog(): void
    {
        $this->viewAs($this->student);

        service('auditLogger')->log('course.viewed', $this->student, ['target_type' => 'course', 'target_id' => 3]);

        $rows = (new InstitutionAuditLog($this->institution))->page(new AuditLogFilters(action: 'course.viewed'))['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('ada@view.example', $rows[0]['via']);
        $this->assertSame((int) $this->admin->id, (int) db_connect()->table('audit_logs')->where('action', 'course.viewed')->get()->getRowArray()['impersonator_user_id']);
    }

    public function testTheTimeLimitComesFromTheSetting(): void
    {
        (new App\Services\Admin\PlatformSettings())->set('impersonationMinutes', 10);

        $before = time();
        $this->viewAs($this->student);

        $this->assertEqualsWithDelta($before + 600, $_SESSION['impersonation']['expires_at'], 5);
    }

    public function testLeavingPutsTheAdministratorBackOnThePeopleList(): void
    {
        $this->viewAs($this->student);
        $this->assertSame((int) $this->student->id, $this->signedInId());

        $response = $this->go('POST', 'impersonation/stop');

        $this->assertStringContainsString('institution/people', $response->response()->getHeaderLine('Location'));
        $this->assertSame((int) $this->admin->id, $this->signedInId());
        $this->assertFalse(Impersonation::active());
        $this->assertSame('institutional_admin', $_SESSION['active_role'] ?? 'institutional_admin');

        $ended = $this->audit('impersonation.ended');

        $this->assertSame((int) $this->admin->id, (int) $ended['actor_user_id']);
        $this->assertSame($this->institution, (int) $ended['institution_id']);
    }

    public function testAnExpiredViewEndsOnTheNextRequest(): void
    {
        $this->viewAs($this->student);
        $_SESSION['impersonation']['expires_at'] = time() - 1;
        $this->withSession();

        $response = $this->go('GET', 'student/dashboard');

        $this->assertStringContainsString('institution/people', $response->response()->getHeaderLine('Location'));
        $this->assertSame((int) $this->admin->id, $this->signedInId());
    }

    public function testAReadOnlyInstitutionCanStillViewAs(): void
    {
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $this->assertSame(302, $this->viewAs($this->student)->response()->getStatusCode());
        $this->assertSame((int) $this->student->id, $this->signedInId());
    }

    public function testThePeopleListOffersItOnlyWhereItWorks(): void
    {
        $body = $this->go('GET', 'institution/people')->response()->getBody();

        $this->assertStringContainsString('/institution/view-as/' . $this->student->id . '"', $body);
        $this->assertStringNotContainsString('/institution/view-as/' . $this->admin->id . '"', $body, 'not yourself');
    }
}
