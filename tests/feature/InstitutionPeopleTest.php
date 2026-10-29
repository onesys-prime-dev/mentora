<?php

declare(strict_types=1);

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;
use Tests\Support\SisFixture;

/**
 * The People page and what it posts: access, the list and its filters, the
 * invitations tab, inviting and editing through the dialog's JSON, the
 * password in front of the dangerous actions, bulk actions, the CSV, foreign
 * people answering 404, and a read-only institution.
 *
 * @internal
 */
final class InstitutionPeopleTest extends RouteTestCase
{
    private int $institution;

    private int $other;

    private User $admin;

    private FakeEmail $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('settings');
        Services::resetSingle('auditLogger');
        $this->mailer = FakeEmail::install();

        $this->institution = SisFixture::institution('PPLFA');
        $this->other       = SisFixture::institution('PPLFB');
        $this->admin       = PeopleFactory::person('institutional_admin', 'Ada', 'Admin', 'ada@pplf.example', $this->institution);

        db_connect()->table('audit_logs')->emptyTable();

        $this->signIn($this->admin);
    }

    private function signIn(User $user, bool $confirmed = true): void
    {
        auth()->logout();
        Services::resetSingle('auth');
        $this->withSession([]);
        $this->loggedInAs($user);

        if ($confirmed) {
            $_SESSION['reauth'] = ['user' => $user->id, 'at' => time()];
        } else {
            unset($_SESSION['reauth']);
        }

        $this->withSession();
    }

    private function person(string $role, string $first, ?int $institution = null): User
    {
        return PeopleFactory::person($role, $first, 'Person', strtolower($first) . '@pplf.example', $institution ?? $this->institution);
    }

    private function page(string $query = ''): string
    {
        return $this->step('GET', 'institution/people' . $query)->response()->getBody();
    }

    private function active(User $user): bool
    {
        return (int) db_connect()->table('users')->where('id', $user->id)->get()->getRowArray()['active'] === 1;
    }

    /** @return iterable<string, array{string}> */
    public static function provideOtherRoles(): iterable
    {
        foreach (['student', 'instructor', 'chairperson', 'parent', 'admin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('provideOtherRoles')]
    public function testNoOtherRoleCanUseIt(string $role): void
    {
        $ann = $this->person('student', 'Ann');
        $this->signIn(PeopleFactory::person($role, 'Some', 'One', "{$role}@pplf.example", $role === 'admin' ? null : $this->institution));

        $this->assertSame(302, $this->step('GET', 'institution/people')->response()->getStatusCode());
        $this->assertSame(302, $this->step('GET', 'institution/people/export')->response()->getStatusCode());

        $this->step('POST', "institution/people/{$ann->id}/deactivate");

        $this->assertTrue($this->active($ann));
    }

    public function testThePageListsOnlyThisInstitutionsPeopleWithTheirRoles(): void
    {
        $this->person('student', 'Ann');
        $this->person('student', 'Zed', $this->other);

        $body = $this->page();

        $this->assertStringContainsString('Ann Person', $body);
        $this->assertStringContainsString('Student', $body);
        $this->assertStringContainsString('(you)', $body);
        $this->assertStringNotContainsString('Zed Person', $body);
        $this->assertMatchesRegularExpression('/2 people\s*</', $body);
    }

    public function testTheListIsFilteredAndSearched(): void
    {
        $this->person('student', 'Ann');
        $this->person('instructor', 'Ivy');

        $students = $this->page('?role=student');
        $this->assertStringContainsString('Ann Person', $students);
        $this->assertStringNotContainsString('Ivy Person', $students);
        $this->assertMatchesRegularExpression('/1 person match/', $students);

        $this->assertStringContainsString('No one matches these filters', $this->page('?q=nobody-like-this'));
        $this->assertStringContainsString('aria-sort="descending"', $this->page('?sort=name&dir=desc'));
    }

    public function testSomeoneIsInvitedThroughTheDialogAndShownOnTheInvitationsTab(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', 'institution/people', ['first_name' => 'Ivy', 'last_name' => 'Tan', 'email' => 'ivy@school.example', 'role' => 'instructor']);

        $this->assertTrue($result->isOK());
        $this->assertStringContainsString('ivy@school.example', json_decode($result->getJSON(), true)['message']);
        $this->assertCount(1, $this->mailer->sent);

        $this->withHeaders(['Accept' => 'text/html']);
        $body = $this->page('?view=invitations');

        $this->assertStringContainsString('Ivy Tan', $body);
        $this->assertStringContainsString('Waiting', $body);
        $this->assertStringContainsString('Send again', $body);
    }

    public function testABadInvitationAnswers422WithTheField(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', 'institution/people', ['first_name' => 'Ivy', 'last_name' => 'Tan', 'email' => 'nope', 'role' => 'instructor']);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('email', json_decode($result->getJSON(), true)['errors']);
    }

    public function testNameAndEmailAreEditedThroughTheDialog(): void
    {
        $ann = $this->person('student', 'Ann');

        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', "institution/people/{$ann->id}", ['first_name' => 'Anna', 'last_name' => 'Lee', 'email' => 'anna@pplf.example']);

        $this->assertTrue($result->isOK());

        $this->withHeaders(['Accept' => 'text/html']);
        $this->assertStringContainsString('Anna Lee', $this->page());
    }

    public function testDeactivatingNeedsThePasswordAndThenWorks(): void
    {
        $ann = $this->person('student', 'Ann');
        $this->signIn($this->admin, false);

        $this->withHeaders(['Accept' => 'application/json']);
        $refused = $this->step('POST', "institution/people/{$ann->id}/deactivate");

        $this->assertSame(403, $refused->response()->getStatusCode());
        $this->assertSame('reauth_required', json_decode($refused->getJSON(), true)['error']);
        $this->assertTrue($this->active($ann), 'nothing changed');

        $this->signIn($this->admin, true);
        $this->withHeaders(['Accept' => 'application/json']);
        $done = $this->step('POST', "institution/people/{$ann->id}/deactivate");

        $this->assertTrue($done->isOK());
        $this->assertFalse($this->active($ann));
    }

    public function testRolesAreChangedThroughTheDialogAndNeedThePassword(): void
    {
        $ann = $this->person('student', 'Ann');
        $this->signIn($this->admin, false);

        $this->withHeaders(['Accept' => 'application/json']);
        $this->assertSame(403, $this->step('POST', "institution/people/{$ann->id}/roles", ['role_instructor' => '1'])->response()->getStatusCode());

        $this->signIn($this->admin, true);
        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', "institution/people/{$ann->id}/roles", ['role_instructor' => '1', 'role_parent' => '1']);

        $this->assertTrue($result->isOK());

        $groups = array_column(db_connect()->table('auth_groups_users')->where('user_id', $ann->id)->get()->getResultArray(), 'group');
        sort($groups);
        $this->assertSame(['instructor', 'parent'], $groups);
    }

    public function testNoRoleTickedAnswers422OnTheRolesField(): void
    {
        $ann = $this->person('student', 'Ann');

        $this->withHeaders(['Accept' => 'application/json']);
        $result = $this->step('POST', "institution/people/{$ann->id}/roles", []);

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertArrayHasKey('roles', json_decode($result->getJSON(), true)['errors']);
    }

    public function testTheLastAdministratorCannotBeDeactivatedFromThePage(): void
    {
        $this->withHeaders(['Accept' => 'application/json']);

        $result = $this->step('POST', "institution/people/{$this->admin->id}/deactivate");

        $this->assertSame(422, $result->response()->getStatusCode());
        $this->assertTrue($this->active($this->admin));
    }

    public function testBulkDeactivateAndReactivateReportWhoWasSkipped(): void
    {
        $ann = $this->person('student', 'Ann');
        $ben = $this->person('student', 'Ben');

        $this->step('POST', 'institution/people/bulk/deactivate', ['ids' => [(string) $ann->id, (string) $ben->id, (string) $this->admin->id]]);

        $this->assertFalse($this->active($ann));
        $this->assertFalse($this->active($ben));
        $this->assertTrue($this->active($this->admin));
        $this->assertStringContainsString('2 people were deactivated. 1 skipped.', $this->page());

        $this->step('POST', 'institution/people/bulk/reactivate', ['ids' => [(string) $ann->id]]);

        $this->assertTrue($this->active($ann));
    }

    public function testTheCsvHoldsTheFilteredPeopleAndNamesOnlyThisInstitution(): void
    {
        $this->person('student', 'Ann');
        $ivy = $this->person('instructor', 'Ivy');

        $all = $this->step('GET', 'institution/people/export')->response();

        $this->assertStringContainsString('text/csv', $all->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Ann Person', $all->getBody());
        $this->assertStringContainsString('PPLFA College', $all->getBody());
        $this->assertStringNotContainsString('PPLFB', $all->getBody());

        $students = $this->step('GET', 'institution/people/export?role=student')->response()->getBody();
        $this->assertStringContainsString('Ann Person', $students);
        $this->assertStringNotContainsString('Ivy Person', $students);

        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'users.exported')->countAllResults() >= 2 ? 1 : 0);
    }

    public function testOnlyTheTickedPeopleAreExportedAndForeignOnesAreLeftOut(): void
    {
        $ann = $this->person('student', 'Ann');
        $zed = $this->person('student', 'Zed', $this->other);

        $body = $this->step('POST', 'institution/people/export', ['ids' => [(string) $ann->id, (string) $zed->id]])->response()->getBody();

        $this->assertStringContainsString('Ann Person', $body);
        $this->assertStringNotContainsString('Zed Person', $body);
    }

    public function testAnotherInstitutionsPeopleAreNeverChanged(): void
    {
        $zed = $this->person('student', 'Zed', $this->other);

        foreach ([
            ["institution/people/{$zed->id}", ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@y.example']],
            ["institution/people/{$zed->id}/deactivate", []],
            ["institution/people/{$zed->id}/reactivate", []],
            ["institution/people/{$zed->id}/sign-out", []],
            ["institution/people/{$zed->id}/reset-password", []],
            ["institution/people/{$zed->id}/roles", ['role_instructor' => '1']],
            ["institution/people/{$zed->id}/remove", []],
        ] as [$uri, $data]) {
            try {
                $this->call('POST', $uri, $data);
                $this->fail("{$uri} must be a 404");
            } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue($this->active($zed));
    }

    public function testAReadOnlyInstitutionCanLookAndExportButNotChange(): void
    {
        $ann = $this->person('student', 'Ann');
        db_connect()->table('institutions')->where('id', $this->institution)->update(['status' => 'read_only']);

        $body = $this->page();

        $this->assertStringContainsString('Ann Person', $body);
        $this->assertStringContainsString('data-lock="read_only"', $body);
        $this->assertStringNotContainsString('Invite a person', $body);
        $this->assertStringContainsString('Ann Person', $this->step('GET', 'institution/people/export')->response()->getBody());

        $this->step('POST', "institution/people/{$ann->id}/deactivate");
        $this->assertTrue($this->active($ann));
    }

    public function testThePeopleTileOnTheHomeLeadsHere(): void
    {
        $dashboard = html_entity_decode($this->step('GET', 'institution/dashboard')->response()->getBody());

        $this->assertSame(1, preg_match('/<a\s[^>]*href="[^"]*institution\/people"[^>]*data-app-tile="people"/s', $dashboard));
    }
}
