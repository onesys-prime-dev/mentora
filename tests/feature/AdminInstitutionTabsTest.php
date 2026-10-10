<?php

declare(strict_types=1);

use App\Services\Institutions\InvitationService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourseFactory;
use Tests\Support\FakeEmail;
use Tests\Support\RouteTestCase;

/**
 * An institution's pages as a system administrator uses them: the profile, the
 * tabs that join its pages together, the people and invitations page, and the
 * audit tab.
 *
 * @internal
 */
final class AdminInstitutionTabsTest extends RouteTestCase
{
    private const STRONG = 'Tr4ins-Lantern-Moss-92';

    private User $admin;

    private FakeEmail $mailer;

    private int $institution;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->mailer = FakeEmail::install();
        $this->admin  = model(UserModel::class)->findById(CourseFactory::user('admin')->id);

        $this->loggedInAs($this->admin);
        $this->withSession();

        $this->institution = $this->seeded('ACME', 'active', 'Acme University');

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function seeded(string $code, string $status = 'active', ?string $name = null): int
    {
        db_connect()->table('institutions')->insert(['name' => $name ?? "Institution {$code}", 'code' => $code, 'status' => $status, 'created_at' => date('Y-m-d H:i:s')]);

        return (int) db_connect()->insertID();
    }

    private function body(string $uri): string
    {
        return $this->step('GET', $uri)->response()->getBody();
    }

    /** @param array<string, string> $data */
    private function submit(string $uri, array $data = []): TestResponse
    {
        return $this->step('POST', $uri, $data);
    }

    /** @return array<string, string> */
    private function person(array $override = []): array
    {
        return array_merge(['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria.santos@example.com'], $override);
    }

    /** Adds a member with a name, an email and a role. */
    private function member(string $role, string $first, string $last, string $email, int $institution): User
    {
        $user = CourseFactory::user($role);

        db_connect()->table('auth_identities')->insert(['user_id' => $user->id, 'type' => 'email_password', 'secret' => $email, 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('user_profiles')->insert(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last, 'created_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('institution_users')->insert(['institution_id' => $institution, 'user_id' => $user->id, 'created_at' => date('Y-m-d H:i:s')]);

        return $user;
    }

    private function auditCount(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
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
    public function testNobodyElseCanSeeOrUseTheseInstitutionPages(string $role): void
    {
        auth()->logout();
        $this->withSession([]);
        $this->loggedInAs(CourseFactory::user($role));
        $this->withSession();

        $id = $this->institution;

        foreach (["admin/institutions/{$id}", "admin/institutions/{$id}/users", "admin/institutions/{$id}/audit"] as $uri) {
            $this->assertSame(302, $this->step('GET', $uri)->response()->getStatusCode(), "{$role} must not open {$uri}");
        }

        $this->submit("admin/institutions/{$id}/invitations", $this->person());

        $this->assertSame(0, db_connect()->table('institution_invitations')->countAllResults());
        $this->assertSame([], $this->mailer->sent);
    }

    /*
    |--------------------------------------------------------------------------
    | The profile and the tabs
    |--------------------------------------------------------------------------
    */

    public function testTheProfileShowsWhoTheInstitutionIsAndLinksToItsOtherPages(): void
    {
        $body = $this->body("admin/institutions/{$this->institution}");

        $this->assertStringContainsString('Acme University', $body);
        $this->assertStringContainsString('ACME', $body);
        $this->assertStringContainsString('data-institution-status="active"', $body);

        foreach (['', '/subscription', '/users', '/flags', '/audit'] as $suffix) {
            $this->assertStringContainsString('href="' . site_url("admin/institutions/{$this->institution}{$suffix}") . '"', $body, "a tab to {$suffix}");
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideTabbedPages(): iterable
    {
        yield 'profile' => ['', 'profile'];
        yield 'subscription' => ['/subscription', 'subscription'];
        yield 'users' => ['/users', 'users'];
        yield 'features' => ['/flags', 'flags'];
        yield 'audit' => ['/audit', 'audit'];
    }

    #[DataProvider('provideTabbedPages')]
    public function testEveryInstitutionPageShowsTheTabsWithItsOwnMarkedAsCurrent(string $suffix, string $tab): void
    {
        $body = $this->body("admin/institutions/{$this->institution}{$suffix}");

        $this->assertStringContainsString('data-institution-tabs', $body);
        preg_match('#<nav[^>]*data-institution-tabs.*?</nav>#s', $body, $tabs);

        $this->assertSame(1, preg_match_all('/aria-current="page"/', $tabs[0] ?? ''), 'exactly one tab is current');
        $this->assertMatchesRegularExpression('/data-tab="' . $tab . '"\s+aria-current="page"/', $body);
    }

    public function testAPendingInstitutionsProfilePointsToOnboarding(): void
    {
        $pending = $this->seeded('NEWCO', 'pending', 'New Company College');

        $body = $this->body("admin/institutions/{$pending}");

        $this->assertStringContainsString('data-onboarding-pending', $body);
        $this->assertStringContainsString('href="' . site_url("admin/institutions/{$pending}/onboarding") . '"', $body);
        $this->assertStringContainsString('No administrator has been invited yet', $body);
    }

    public function testAnOnboardedInstitutionsProfileHasNoOnboardingCallToAction(): void
    {
        $this->assertStringNotContainsString('data-onboarding-pending', $this->body("admin/institutions/{$this->institution}"));
    }

    public function testTheProfileShowsTheSubscriptionAndTheAdministratorInvitation(): void
    {
        db_connect()->table('plans')->insert(['name' => 'Standard', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('subscriptions')->insert(['institution_id' => $this->institution, 'plan_id' => db_connect()->insertID(), 'start_date' => '2026-01-01', 'end_date' => date('Y-m-d', strtotime('+200 days')), 'created_at' => date('Y-m-d H:i:s')]);
        (new InvitationService())->invite($this->institution, $this->person(), $this->admin);

        $body = $this->body("admin/institutions/{$this->institution}");

        $this->assertStringContainsString('data-profile-subscription', $body);
        $this->assertStringContainsString('<strong>Standard</strong>', $body);
        $this->assertStringContainsString('Maria Santos', $body);
        $this->assertStringContainsString('data-invitation-status="pending"', $body);
    }

    public function testAnUnknownOrDeletedInstitutionHasNoProfile(): void
    {
        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->step('GET', 'admin/institutions/999999');
    }

    public function testTheListLinksEachNameToItsProfile(): void
    {
        $body = $this->body('admin/institutions');

        $this->assertStringContainsString('href="' . site_url("admin/institutions/{$this->institution}") . '"', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | People and invitations
    |--------------------------------------------------------------------------
    */

    public function testThePeoplePageListsMembersWithTheirRolesAndCountsEachRole(): void
    {
        $this->member('institutional_admin', 'Maria', 'Santos', 'maria@example.com', $this->institution);
        $this->member('student', 'Juan', 'Dela Cruz', 'juan@example.com', $this->institution);
        $this->member('student', 'Pedro', 'Reyes', 'pedro@example.com', $this->institution);
        $other = $this->seeded('OTHER', 'active', 'Other College');
        $this->member('student', 'Elsewhere', 'Person', 'elsewhere@example.com', $other);

        $body = $this->body("admin/institutions/{$this->institution}/users");

        $this->assertStringContainsString('3 people', $body);
        $this->assertStringContainsString('2 student', $body);
        $this->assertStringContainsString('1 institutional administrator', $body);
        $this->assertStringContainsString('Juan Dela Cruz', $body);
        $this->assertStringContainsString('pedro@example.com', $body);
        $this->assertStringNotContainsString('Elsewhere', $body, 'another institution\'s people are not shown');
    }

    public function testAnEmptyInstitutionSaysSo(): void
    {
        $body = $this->body("admin/institutions/{$this->institution}/users");

        $this->assertStringContainsString('Nobody belongs to this institution yet', $body);
        $this->assertStringContainsString('No invitations yet', $body);
    }

    public function testInvitingSendsTheEmailAndShowsTheInvitation(): void
    {
        $response = $this->submit("admin/institutions/{$this->institution}/invitations", $this->person());

        $this->assertStringEndsWith("admin/institutions/{$this->institution}/users", $response->response()->getHeaderLine('Location'));
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('maria.santos@example.com', $this->mailer->sent[0]['to']);
        $this->assertSame(1, $this->auditCount('invitation.created'));

        $body = $this->body("admin/institutions/{$this->institution}/users");

        $this->assertStringContainsString('An invitation was emailed to maria.santos@example.com', $body);
        $this->assertStringContainsString('data-invitation="pending"', $body);
        $this->assertStringContainsString('Resend invitation', $body);
    }

    public function testBadInvitationDetailsGoBackWithTheReasonAndTheInput(): void
    {
        $this->submit("admin/institutions/{$this->institution}/invitations", $this->person(['email' => 'nope', 'first_name' => 'Maria José']));

        $body = $this->body("admin/institutions/{$this->institution}/users");

        $this->assertStringContainsString('valid email address', $body);
        $this->assertStringContainsString('value="Maria José"', $body);
        $this->assertSame(0, db_connect()->table('institution_invitations')->countAllResults());
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAPendingInstitutionIsInvitedThroughOnboardingNotFromThisPage(): void
    {
        $pending = $this->seeded('NEWCO', 'pending');

        $body = $this->body("admin/institutions/{$pending}/users");

        $this->assertStringNotContainsString('Send invitation', $body);
        $this->assertStringContainsString('Continue onboarding', $body);

        $this->submit("admin/institutions/{$pending}/invitations", $this->person());

        $this->assertSame(0, db_connect()->table('institution_invitations')->countAllResults());
    }

    public function testResendingGivesANewEmailAndRetiresTheOldLink(): void
    {
        $this->submit("admin/institutions/{$this->institution}/invitations", $this->person());
        $invitation = (int) db_connect()->table('institution_invitations')->get()->getRowArray()['id'];

        $response = $this->submit("admin/institutions/{$this->institution}/invitations/{$invitation}/resend");

        $this->assertStringEndsWith("admin/institutions/{$this->institution}/users", $response->response()->getHeaderLine('Location'));
        $this->assertCount(2, $this->mailer->sent);
        $this->assertSame(1, $this->auditCount('invitation.resent'));

        $statuses = array_column(db_connect()->table('institution_invitations')->orderBy('id')->get()->getResultArray(), 'revoked_at');
        $this->assertNotNull($statuses[0], 'the first link is revoked');
        $this->assertNull($statuses[1], 'the new one is open');
    }

    public function testCancellingAnInvitationKillsItsLink(): void
    {
        $this->submit("admin/institutions/{$this->institution}/invitations", $this->person());
        $invitation = (int) db_connect()->table('institution_invitations')->get()->getRowArray()['id'];
        preg_match('#/invitation/([A-Za-z0-9_-]{43})#', $this->mailer->sent[0]['message'], $match);

        $this->assertStringContainsString('Cancel', $this->body("admin/institutions/{$this->institution}/users"));

        $this->submit("admin/institutions/{$this->institution}/invitations/{$invitation}/revoke");

        $this->assertStringContainsString('invitation was cancelled', $this->body("admin/institutions/{$this->institution}/users"));
        $this->assertSame(410, $this->step('GET', "invitation/{$match[1]}")->response()->getStatusCode());
        $this->assertSame(1, $this->auditCount('invitation.revoked'));
    }

    public function testAnotherInstitutionsInvitationCannotBeCancelledFromHere(): void
    {
        $other = $this->seeded('OTHER', 'active');
        (new InvitationService())->invite($other, $this->person(['email' => 'other@example.com']), $this->admin);
        $theirs = (int) db_connect()->table('institution_invitations')->get()->getRowArray()['id'];

        $this->submit("admin/institutions/{$this->institution}/invitations/{$theirs}/revoke");

        $this->assertNull(db_connect()->table('institution_invitations')->where('id', $theirs)->get()->getRowArray()['revoked_at']);
    }

    public function testTheProfilesAdministratorBoxIgnoresInvitationsToOtherRoles(): void
    {
        (new InvitationService())->invite($this->institution, $this->person(['email' => 'student@example.com', 'first_name' => 'Stu', 'last_name' => 'Dent']), $this->admin, null, 'student');

        $body = $this->body("admin/institutions/{$this->institution}");

        $this->assertStringContainsString('No administrator has been invited yet', $body);
        $this->assertStringNotContainsString('Stu Dent', $body);

        $this->assertStringContainsString('>Student<', $this->body("admin/institutions/{$this->institution}/users"));
    }

    public function testAnInvitationOfAnotherInstitutionCannotBeResentFromHere(): void
    {
        $other = $this->seeded('OTHER', 'active');
        (new InvitationService())->invite($other, $this->person(['email' => 'other@example.com']), $this->admin);
        $theirs = (int) db_connect()->table('institution_invitations')->get()->getRowArray()['id'];
        $sent   = count($this->mailer->sent);

        $this->submit("admin/institutions/{$this->institution}/invitations/{$theirs}/resend");

        $this->assertCount($sent, $this->mailer->sent, 'nothing was sent');
        $this->assertStringContainsString('does not exist', $this->body("admin/institutions/{$this->institution}/users"));
    }

    public function testAFailedEmailIsShownAsNotSentWithAWayToSendAgain(): void
    {
        $this->mailer->failToSend = true;

        $this->submit("admin/institutions/{$this->institution}/invitations", $this->person());

        $body = $this->body("admin/institutions/{$this->institution}/users");

        $this->assertStringContainsString('email could not be sent', $body);
        $this->assertStringContainsString('Not sent', $body);
        $this->assertStringContainsString('Resend invitation', $body);
    }

    public function testAnAcceptedInvitationCannotBeResentAndShowsAsAccepted(): void
    {
        $this->submit("admin/institutions/{$this->institution}/invitations", $this->person());
        preg_match('#/invitation/([A-Za-z0-9_-]{43})#', $this->mailer->sent[0]['message'], $match);
        (new InvitationService())->accept($match[1], self::STRONG);

        $body = $this->body("admin/institutions/{$this->institution}/users");

        $this->assertStringContainsString('data-invitation="accepted"', $body);
        $this->assertStringNotContainsString('Resend invitation', $body);
        $this->assertStringContainsString('1 person', $body);
        $this->assertStringContainsString('maria.santos@example.com', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | The audit tab
    |--------------------------------------------------------------------------
    */

    public function testTheAuditTabShowsThisInstitutionsEntriesOnly(): void
    {
        $other = $this->seeded('OTHER', 'active', 'Other College');
        service('auditLogger')->log('institution.updated', $this->admin, ['institution_id' => $this->institution, 'target_type' => 'institution', 'target_id' => $this->institution]);
        service('auditLogger')->log('institution.suspended', $this->admin, ['institution_id' => $other, 'target_type' => 'institution', 'target_id' => $other]);

        $body = $this->body("admin/institutions/{$this->institution}/audit");

        $this->assertStringContainsString('institution.updated', $body);
        $this->assertStringNotContainsString('institution.suspended', $body);
        $this->assertStringContainsString('audit-log?institution=' . $this->institution, $body, 'with a way into the full log');
    }

    public function testTheAuditTabSaysSoWhenNothingWasRecorded(): void
    {
        $this->assertStringContainsString('Nothing has been recorded', $this->body("admin/institutions/{$this->institution}/audit"));
    }

    public function testTheAuditTabEscapesWhatItShows(): void
    {
        service('auditLogger')->log('institution.updated', $this->admin, ['institution_id' => $this->institution, 'actor_label' => '<b>Mallory</b>']);

        $body = $this->body("admin/institutions/{$this->institution}/audit");

        $this->assertStringNotContainsString('<b>Mallory</b>', $body);
        $this->assertStringContainsString('&lt;b&gt;Mallory', $body);
    }
}
