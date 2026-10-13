<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * The users pages as a system administrator uses them, and as everyone else is
 * refused: list, filters, search, sort and paging, export, bulk changes,
 * inviting, and each action on one person.
 *
 * @internal
 */
final class AdminUsersPageTest extends RouteTestCase
{
    private User $admin;

    private FakeEmail $mailer;

    private int $acme;

    private int $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->mailer = FakeEmail::install();
        $this->admin  = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        $this->acme = PeopleFactory::institution('ACME', 'active', 'Acme University');
        $this->beta = PeopleFactory::institution('BETA', 'active', 'Beta College');

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        service('superglobals')->setGetArray([]);

        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function body(string $uri): string
    {
        return $this->step('GET', $uri)->response()->getBody();
    }

    /** @param array<string, mixed> $data */
    private function submit(string $uri, array $data = []): TestResponse
    {
        return $this->step('POST', $uri, $data);
    }

    private function location(TestResponse $response): string
    {
        return $response->response()->getHeaderLine('Location');
    }

    private function confirmedPassword(): void
    {
        $_SESSION['reauth'] = ['user' => $this->admin->id, 'at' => time()];
        $this->withSession();
    }

    private function auditCount(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    private function active(User $user): int
    {
        return (int) db_connect()->table('users')->where('id', $user->id)->get()->getRowArray()['active'];
    }

    /** @return array<string, mixed> */
    private function directoryRow(User $user): array
    {
        return (new App\Services\Users\UserDirectory())->find((int) $user->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Who may use it
    |--------------------------------------------------------------------------
    */

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'institutional_admin', 'parent'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNobodyElseCanSeeOrChangeUsers(string $role): void
    {
        $target = PeopleFactory::person('student', 'Target', 'Student', 'target@example.com', $this->acme);

        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        foreach (['admin/users', 'admin/users/new', 'admin/users/export', "admin/users/{$target->id}"] as $uri) {
            $this->assertSame(302, $this->step('GET', $uri)->response()->getStatusCode(), "{$role} must not open {$uri}");
        }

        $this->submit('admin/users', ['institution_id' => $this->acme, 'role' => 'student', 'first_name' => 'Sneaky', 'last_name' => 'Person', 'email' => 'sneaky@example.com']);
        $this->submit("admin/users/{$target->id}", ['first_name' => 'Hacked', 'last_name' => 'Name', 'email' => 'hacked@example.com']);
        $this->submit("admin/users/{$target->id}/deactivate");
        $this->submit("admin/users/{$target->id}/roles", ['roles' => ['chairperson']]);
        $this->submit('admin/users/bulk', ['action' => 'deactivate', 'ids' => [(string) $target->id]]);
        $this->submit('admin/users/export', ['ids' => [(string) $target->id]]);

        $this->assertSame(0, db_connect()->table('institution_invitations')->countAllResults());
        $this->assertSame(1, $this->active($target));
        $this->assertSame('target@example.com', $this->directoryRow($target)['email']);
        $this->assertSame(['student'], $this->directoryRow($target)['roles']);
        $this->assertSame(0, $this->auditCount('users.exported'));
    }

    /*
    |--------------------------------------------------------------------------
    | The list
    |--------------------------------------------------------------------------
    */

    public function testTheListShowsEveryonesNameRolesInstitutionsAndStatus(): void
    {
        $maria = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme, true, '2026-10-09 08:30:00');
        $juan  = PeopleFactory::person('instructor', 'Juan', 'Cruz', 'juan@example.com', $this->beta);
        (new App\Services\Users\AccountControl())->deactivate((int) $juan->id);

        $body = $this->body('admin/users');

        $this->assertStringContainsString('Maria Santos', $body);
        $this->assertStringContainsString('maria@example.com', $body);
        $this->assertStringContainsString('Student', $body);
        $this->assertStringContainsString('Acme University', $body);
        $this->assertStringContainsString('2026-10-09 08:30', $body);
        $this->assertStringContainsString('href="' . site_url('admin/users/' . $maria->id) . '"', $body);
        $this->assertMatchesRegularExpression('/data-user="' . $juan->id . '" data-status="deactivated"/', $body);
        $this->assertMatchesRegularExpression('/data-user="' . $maria->id . '" data-status="active"/', $body);
    }

    public function testTheListMarksTheAdministratorLookingAtItAsYou(): void
    {
        $this->assertStringContainsString('(you)', $this->body('admin/users'));
    }

    public function testTheListCarriesTheCheckboxesAndBulkButtonsTheScriptNeeds(): void
    {
        PeopleFactory::person('student', 'Maria', 'Santos', null, $this->acme);

        $body = $this->body('admin/users');

        $this->assertStringContainsString('data-users-bulk', $body);
        $this->assertStringContainsString('data-select-all', $body);
        $this->assertStringContainsString('data-selected-count', $body);
        $this->assertSame(3, preg_match_all('/data-bulk-action/', $body), 'deactivate, reactivate and export');
        $this->assertStringContainsString('data-page="admin-users"', $body);
    }

    public function testFilteringByInstitutionRoleStatusAndSearch(): void
    {
        PeopleFactory::person('student', 'Ana', 'Acme', 'ana@example.com', $this->acme);
        PeopleFactory::person('instructor', 'Ivo', 'Acme', 'ivo@example.com', $this->acme);
        PeopleFactory::person('student', 'Ben', 'Beta', 'ben@example.com', $this->beta);
        $off = PeopleFactory::person('student', 'Off', 'Beta', 'off@example.com', $this->beta);
        (new App\Services\Users\AccountControl())->deactivate((int) $off->id);

        $byInstitution = $this->body('admin/users?institution=' . $this->acme);
        $this->assertStringContainsString('ana@example.com', $byInstitution);
        $this->assertStringContainsString('ivo@example.com', $byInstitution);
        $this->assertStringNotContainsString('ben@example.com', $byInstitution);

        $byRole = $this->body('admin/users?role=instructor');
        $this->assertStringContainsString('ivo@example.com', $byRole);
        $this->assertStringNotContainsString('ana@example.com', $byRole);

        $byStatus = $this->body('admin/users?status=deactivated');
        $this->assertStringContainsString('off@example.com', $byStatus);
        $this->assertStringNotContainsString('ana@example.com', $byStatus);

        $bySearch = $this->body('admin/users?q=' . urlencode('ben beta'));
        $this->assertStringContainsString('ben@example.com', $bySearch);
        $this->assertStringNotContainsString('ana@example.com', $bySearch);
        $this->assertStringContainsString('1 person match', $bySearch);
    }

    public function testAFilteredListOffersToClearTheFilters(): void
    {
        PeopleFactory::person('student', 'Ana', 'Acme', null, $this->acme);

        $this->assertStringNotContainsString('>Clear<', $this->body('admin/users'));
        $this->assertStringContainsString('>Clear<', $this->body('admin/users?role=student'));
    }

    public function testNoMatchesSaysSo(): void
    {
        $this->assertStringContainsString('matches these filters', $this->body('admin/users?q=nobody-at-all'));
    }

    public function testTheListIsSortedAndPaged(): void
    {
        foreach (['Cara' => 'Zed', 'Abe' => 'Yan', 'Bea' => 'Xu'] as $first => $last) {
            PeopleFactory::person('student', $first, $last, strtolower($first) . '@example.com', $this->acme);
        }

        $asc = $this->body('admin/users?role=student&sort=name');

        $this->assertLessThan(strpos($asc, 'abe@example.com'), strpos($asc, 'bea@example.com'), 'by last name: Xu, Yan, Zed');
        $this->assertLessThan(strpos($asc, 'cara@example.com'), strpos($asc, 'abe@example.com'));

        $paged = $this->body('admin/users?role=student&per_page=2');

        $this->assertStringContainsString('Page 1 of 2', $paged);
        $this->assertStringContainsString('>Next<', $paged);
        $this->assertStringNotContainsString('>Previous<', $paged);

        $this->assertStringContainsString('>Previous<', $this->body('admin/users?role=student&per_page=2&page=2'));
    }

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    public function testExportGivesTheFilteredPeopleAsACsvAndIsRecorded(): void
    {
        PeopleFactory::person('student', 'Ana', 'Acme', 'ana@example.com', $this->acme);
        PeopleFactory::person('student', 'Ben', 'Beta', 'ben@example.com', $this->beta);

        $response = $this->step('GET', 'admin/users/export?institution=' . $this->acme);
        $body     = $response->response()->getBody();

        $this->assertStringStartsWith('text/csv', $response->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="users-', $response->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('ID,Name,Email', $body);
        $this->assertStringContainsString('ana@example.com', $body);
        $this->assertStringNotContainsString('ben@example.com', $body);

        $entry = db_connect()->table('audit_logs')->where('action', 'users.exported')->get()->getRowArray();

        $this->assertSame((int) $this->admin->id, (int) $entry['actor_user_id']);
        $this->assertStringContainsString('"rows":1', (string) $entry['new_values']);
    }

    public function testExportDefusesNamesThatWouldRunAsFormulas(): void
    {
        PeopleFactory::person('student', '=HYPERLINK("http://evil","click")', 'Person', 'evil@example.com', $this->acme);

        $body = $this->step('GET', 'admin/users/export')->response()->getBody();

        $this->assertStringNotContainsString(',"=HYPERLINK', $body);
        $this->assertStringContainsString("'=HYPERLINK", $body);
    }

    public function testExportingJustTheTickedPeople(): void
    {
        $ana = PeopleFactory::person('student', 'Ana', 'Acme', 'ana@example.com', $this->acme);
        PeopleFactory::person('student', 'Ben', 'Beta', 'ben@example.com', $this->beta);

        $response = $this->submit('admin/users/export', ['ids' => [(string) $ana->id, 'not-a-number', '0']]);
        $body     = $response->response()->getBody();

        $this->assertStringContainsString('ana@example.com', $body);
        $this->assertStringNotContainsString('ben@example.com', $body);
        $this->assertSame(1, $this->auditCount('users.exported'));
    }

    public function testExportingNobodyIsRefused(): void
    {
        $response = $this->submit('admin/users/export', []);

        $this->assertStringEndsWith('admin/users', $this->location($response));
        $this->assertStringContainsString('at least one person', $this->body('admin/users'));
        $this->assertSame(0, $this->auditCount('users.exported'));
    }

    /*
    |--------------------------------------------------------------------------
    | Inviting
    |--------------------------------------------------------------------------
    */

    public function testTheInviteFormOffersOnboardedInstitutionsAndRolesButNotAdministrators(): void
    {
        PeopleFactory::institution('NEWCO', 'pending', 'New Company College');

        $body = $this->body('admin/users/new');

        $this->assertStringContainsString('Acme University', $body);
        $this->assertStringNotContainsString('New Company College', $body);

        foreach (['Student', 'Instructor', 'Chairperson', 'Parent', 'Institutional administrator'] as $role) {
            $this->assertStringContainsString(">{$role}<", $body);
        }

        $this->assertStringNotContainsString('value="admin"', $body);
    }

    public function testInvitingSendsTheEmailAndGoesBackToTheList(): void
    {
        $response = $this->submit('admin/users', ['institution_id' => (string) $this->acme, 'role' => 'instructor', 'first_name' => 'Ivo', 'last_name' => 'Teacher', 'email' => 'ivo@example.com']);

        $this->assertStringEndsWith('admin/users', $this->location($response));
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('ivo@example.com', $this->mailer->sent[0]['to']);
        $this->assertSame('instructor', db_connect()->table('institution_invitations')->get()->getRowArray()['role']);
        $this->assertStringContainsString('An invitation was emailed to ivo@example.com', $this->body('admin/users'));
    }

    public function testBadInvitationDetailsGoBackWithTheReasonAndTheInput(): void
    {
        $response = $this->submit('admin/users', ['institution_id' => (string) $this->acme, 'role' => 'student', 'first_name' => 'Maria José', 'last_name' => 'Santos', 'email' => 'nope']);

        $this->assertStringEndsWith('admin/users/new', $this->location($response));

        $body = $this->body('admin/users/new');

        $this->assertStringContainsString('valid email address', $body);
        $this->assertStringContainsString('value="Maria José"', $body);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testNobodyIsInvitedAsASystemAdministrator(): void
    {
        $this->submit('admin/users', ['institution_id' => (string) $this->acme, 'role' => 'admin', 'first_name' => 'Evil', 'last_name' => 'Admin', 'email' => 'evil@example.com']);

        $this->assertSame(0, db_connect()->table('institution_invitations')->countAllResults());
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAFullInstitutionRefusesAnotherStudent(): void
    {
        PeopleFactory::subscribe($this->acme, PeopleFactory::plan('Tiny', 1));
        PeopleFactory::person('student', 'Only', 'Seat', null, $this->acme);

        $this->submit('admin/users', ['institution_id' => (string) $this->acme, 'role' => 'student', 'first_name' => 'Extra', 'last_name' => 'Student', 'email' => 'extra@example.com']);

        $this->assertStringContainsString('seats', $this->body('admin/users/new'));
        $this->assertSame(0, db_connect()->table('institution_invitations')->countAllResults());
    }

    /*
    |--------------------------------------------------------------------------
    | One person
    |--------------------------------------------------------------------------
    */

    public function testThePersonPageShowsDetailsRolesAndInstitutions(): void
    {
        $user = PeopleFactory::person('instructor', 'Ivo', 'Teacher', 'ivo@example.com', $this->acme);

        $body = $this->body("admin/users/{$user->id}");

        $this->assertStringContainsString('Ivo Teacher', $body);
        $this->assertStringContainsString('value="ivo@example.com"', $body);
        $this->assertMatchesRegularExpression('/name="roles\[\]" value="instructor" checked/', $body);
        $this->assertDoesNotMatchRegularExpression('/name="roles\[\]" value="student" checked/', $body);
        $this->assertStringNotContainsString('value="admin"', $body);
        $this->assertStringContainsString('Acme University', $body);
        $this->assertStringContainsString('data-account-status="active"', $body);
    }

    public function testAnAdministratorsPageHasNoRoleEditor(): void
    {
        $other = PeopleFactory::person('admin', 'Other', 'Admin', 'other.admin@example.com');

        $body = $this->body("admin/users/{$other->id}");

        $this->assertStringContainsString('php spark', $body);
        $this->assertStringNotContainsString('name="roles[]"', $body);
        $this->assertStringContainsString('belong to no institution', $body);
    }

    public function testYourOwnPageHasNoWayToDeactivateYou(): void
    {
        $body = $this->body("admin/users/{$this->admin->id}");

        $this->assertStringContainsString('(this is you)', $body);
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>Deactivate<\/button>/', $body);
    }

    public function testAnUnknownPersonIsNotFound(): void
    {
        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->step('GET', 'admin/users/999999');
    }

    /*
    |--------------------------------------------------------------------------
    | Actions on one person
    |--------------------------------------------------------------------------
    */

    public function testSavingDetailsNeedsThePasswordAgain(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme);

        $response = $this->submit("admin/users/{$user->id}", ['first_name' => 'Marie', 'last_name' => 'Santos', 'email' => 'marie@example.com']);

        $this->assertStringContainsString('admin/reauth', $this->location($response));
        $this->assertSame('maria@example.com', $this->directoryRow($user)['email']);
    }

    public function testSavingDetailsOnceThePasswordIsConfirmed(): void
    {
        $this->confirmedPassword();
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme);

        $response = $this->submit("admin/users/{$user->id}", ['first_name' => 'Marie', 'last_name' => 'Santos', 'email' => 'marie@example.com']);

        $this->assertStringEndsWith("admin/users/{$user->id}", $this->location($response));
        $this->assertSame('marie@example.com', $this->directoryRow($user)['email']);
        $this->assertSame('Marie', $this->directoryRow($user)['first_name']);
        $this->assertSame(1, $this->auditCount('user.updated'));
    }

    public function testARefusedSaveKeepsWhatWasTyped(): void
    {
        $this->confirmedPassword();
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme);

        $this->submit("admin/users/{$user->id}", ['first_name' => 'Marie', 'last_name' => 'Santos', 'email' => 'not-an-email']);

        $body = $this->body("admin/users/{$user->id}");

        $this->assertStringContainsString('valid email address', $body);
        $this->assertStringContainsString('value="not-an-email"', $body);
        $this->assertSame('maria@example.com', $this->directoryRow($user)['email']);
    }

    public function testDeactivatingAndReactivatingFromThePersonPage(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme);

        $this->submit("admin/users/{$user->id}/deactivate");

        $this->assertSame(0, $this->active($user));
        $this->assertStringContainsString('data-account-status="deactivated"', $this->body("admin/users/{$user->id}"));

        $this->submit("admin/users/{$user->id}/reactivate");

        $this->assertSame(1, $this->active($user));
        $this->assertStringContainsString('data-account-status="active"', $this->body("admin/users/{$user->id}"));
    }

    public function testYouCannotDeactivateYourselfEvenByPostingTheForm(): void
    {
        $this->submit("admin/users/{$this->admin->id}/deactivate");

        $this->assertSame(1, $this->active($this->admin));
        $this->assertStringContainsString('your own account', $this->body("admin/users/{$this->admin->id}"));
    }

    public function testTheLastAdministratorCannotBeDeactivated(): void
    {
        $other = PeopleFactory::person('admin', 'Other', 'Admin', 'other.admin@example.com');
        db_connect()->table('users')->where('id', $this->admin->id)->update(['active' => 0]);

        $this->submit("admin/users/{$other->id}/deactivate");

        $this->assertSame(1, $this->active($other));
        $this->assertStringContainsString('last active system administrator', $this->body("admin/users/{$other->id}"));
    }

    public function testSigningSomeoneOutEverywhere(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', null, $this->acme);

        $this->submit("admin/users/{$user->id}/sign-out");

        $this->assertNotNull(db_connect()->table('user_account_controls')->where('user_id', $user->id)->get()->getRowArray()['sessions_valid_from']);
        $this->assertStringContainsString('Every session', $this->body("admin/users/{$user->id}"));
    }

    public function testSendingAPasswordResetNeedsThePasswordAndEmailsTheLink(): void
    {
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme);

        $first = $this->submit("admin/users/{$user->id}/reset-password");
        $this->assertStringContainsString('admin/reauth', $this->location($first));
        $this->assertSame([], $this->mailer->sent);

        $this->confirmedPassword();
        $this->submit("admin/users/{$user->id}/reset-password");

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('maria@example.com', $this->mailer->sent[0]['to']);
        $this->assertMatchesRegularExpression('#/reset-password/[A-Za-z0-9_-]{43}#', $this->mailer->sent[0]['message']);
        $this->assertStringContainsString('emailed to maria@example.com', $this->body("admin/users/{$user->id}"));
    }

    public function testNoResetIsSentToADeactivatedAccount(): void
    {
        $this->confirmedPassword();
        $user = PeopleFactory::person('student', 'Maria', 'Santos', 'maria@example.com', $this->acme);
        (new App\Services\Users\AccountControl())->deactivate((int) $user->id);

        $this->submit("admin/users/{$user->id}/reset-password");

        $this->assertSame([], $this->mailer->sent);
        $this->assertStringContainsString('deactivated', $this->body("admin/users/{$user->id}"));
    }

    public function testChangingRolesNeedsThePasswordAndIsSaved(): void
    {
        $user = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->acme);

        $first = $this->submit("admin/users/{$user->id}/roles", ['roles' => ['instructor', 'chairperson']]);
        $this->assertStringContainsString('admin/reauth', $this->location($first));
        $this->assertSame(['instructor'], $this->directoryRow($user)['roles']);

        $this->confirmedPassword();
        $this->submit("admin/users/{$user->id}/roles", ['roles' => ['instructor', 'chairperson']]);

        $this->assertSame(['chairperson', 'instructor'], $this->directoryRow($user)['roles']);
        $this->assertSame(1, $this->auditCount('user.roles_changed'));
    }

    public function testNobodyBecomesAnAdministratorByPostingTheRole(): void
    {
        $this->confirmedPassword();
        $user = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->acme);

        $this->submit("admin/users/{$user->id}/roles", ['roles' => ['instructor', 'admin']]);

        $this->assertSame(['instructor'], $this->directoryRow($user)['roles']);
        $this->assertStringContainsString('php spark admin:create', $this->body("admin/users/{$user->id}"));
    }

    public function testClearingEveryRoleIsRefused(): void
    {
        $this->confirmedPassword();
        $user = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->acme);

        $this->submit("admin/users/{$user->id}/roles", []);

        $this->assertSame(['instructor'], $this->directoryRow($user)['roles']);
        $this->assertStringContainsString('at least one role', $this->body("admin/users/{$user->id}"));
    }

    public function testAddingAndRemovingAnInstitution(): void
    {
        $this->confirmedPassword();
        $user = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->acme);

        $this->submit("admin/users/{$user->id}/memberships", ['institution_id' => (string) $this->beta]);

        $this->assertSame(['Acme University', 'Beta College'], array_column($this->directoryRow($user)['institutions'], 'name'));

        $this->submit("admin/users/{$user->id}/memberships/{$this->acme}/remove");

        $this->assertSame(['Beta College'], array_column($this->directoryRow($user)['institutions'], 'name'));
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk
    |--------------------------------------------------------------------------
    */

    public function testBulkDeactivatingTheTickedPeople(): void
    {
        $a    = PeopleFactory::person('student', 'Ann', 'One', null, $this->acme);
        $b    = PeopleFactory::person('student', 'Ben', 'Two', null, $this->acme);
        $left = PeopleFactory::person('student', 'Cy', 'Three', null, $this->acme);

        $response = $this->submit('admin/users/bulk', ['action' => 'deactivate', 'ids' => [(string) $a->id, (string) $b->id]]);

        $this->assertStringEndsWith('admin/users', $this->location($response));
        $this->assertSame(0, $this->active($a));
        $this->assertSame(0, $this->active($b));
        $this->assertSame(1, $this->active($left));
        $this->assertStringContainsString('2 people were deactivated', $this->body('admin/users'));
    }

    public function testBulkSaysWhoCouldNotBeDone(): void
    {
        $a = PeopleFactory::person('student', 'Ann', 'One', null, $this->acme);

        $this->submit('admin/users/bulk', ['action' => 'deactivate', 'ids' => [(string) $a->id, (string) $this->admin->id]]);

        $body = $this->body('admin/users');

        $this->assertSame(0, $this->active($a));
        $this->assertSame(1, $this->active($this->admin));
        $this->assertStringContainsString('1 person was deactivated', $body);
        $this->assertStringContainsString('1 could not be deactivated', $body);
        $this->assertStringContainsString('your own account', $body);
    }

    public function testBulkReactivating(): void
    {
        $a = PeopleFactory::person('student', 'Ann', 'One', null, $this->acme);
        (new App\Services\Users\AccountControl())->deactivate((int) $a->id);

        $this->submit('admin/users/bulk', ['action' => 'reactivate', 'ids' => [(string) $a->id]]);

        $this->assertSame(1, $this->active($a));
    }

    public function testBulkNeedsPeopleAndARealAction(): void
    {
        $a = PeopleFactory::person('student', 'Ann', 'One', null, $this->acme);

        $this->submit('admin/users/bulk', ['action' => 'deactivate']);
        $this->assertStringContainsString('at least one person', $this->body('admin/users'));

        $this->submit('admin/users/bulk', ['action' => 'delete', 'ids' => [(string) $a->id]]);
        $this->assertStringContainsString('several people at once', $this->body('admin/users'));

        $this->submit('admin/users/bulk', ['action' => 'roles', 'ids' => [(string) $a->id]]);
        $this->assertSame(['student'], $this->directoryRow($a)['roles'], 'there is no bulk role change');
        $this->assertSame(1, $this->active($a));
    }
}
