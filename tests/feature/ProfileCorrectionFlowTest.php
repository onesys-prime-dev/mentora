<?php

declare(strict_types=1);

use App\Services\Profiles\CorrectionRequestService;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;
use Tests\Support\RouteTestCase;

/**
 * W4 through HTTP (FR7, FR9, D17, D22): the owner asks for a correction on /profile, an administrator decides it on
 * People → Requests (or, for platform-routed requests, on the Users screen), and an approved email waits for the
 * link sent to the new address. A system administrator edits their own official details directly (FR7.6).
 *
 * @internal
 */
final class ProfileCorrectionFlowTest extends RouteTestCase
{
    private FakeEmail $mailer;

    private int $school;

    private User $admin;

    private User $ann;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->mailer = FakeEmail::install();
        $this->school = PeopleFactory::institution('CFL');
        $this->admin  = PeopleFactory::person('institutional_admin', 'Ida', 'Admin', 'ida@example.com', $this->school);
        $this->ann    = PeopleFactory::person('student', 'Ann', 'Lee', 'ann@example.com', $this->school);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function body(string $uri): string
    {
        $result = $this->call('GET', $uri);
        $result->assertOK();

        return (string) $result->response()->getBody();
    }

    private function profile(User $user, string $column): mixed
    {
        return db_connect()->table('user_profiles')->where('user_id', $user->id)->get()->getRow($column);
    }

    private function email(User $user): string
    {
        return (string) db_connect()->table('auth_identities')->where(['user_id' => $user->id, 'type' => 'email_password'])->get()->getRow('secret');
    }

    /** @return array<string, mixed>|null */
    private function request(int $id): ?array
    {
        return db_connect()->table('profile_correction_requests')->where('id', $id)->get()->getRowArray();
    }

    private function submitAsAnn(string $field = 'last_name', string $value = 'Santos'): int
    {
        return (new CorrectionRequestService())->submit($this->ann, $field, $value, 'Married name.');
    }

    private function linkTo(string $address): string
    {
        $mail = array_values(array_filter($this->mailer->sent, static fn (array $m): bool => $m['to'] === $address));
        $this->assertNotEmpty($mail, "a mail to {$address}");
        $this->assertSame(1, preg_match('#email-change/([0-9a-f]{64})#', end($mail)['message'], $m));

        return $m[1];
    }

    /** A request the viewer may not reach answers not found. */
    private function notFound(string $uri, array $data): void
    {
        try {
            $this->call('POST', $uri, $data);
            $this->fail("{$uri} should be not found");
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string, string> */
    private function json(): array
    {
        return ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
    }

    public function testTheOwnerAsksAndTheAdminApprovesFromTheRequestsTab(): void
    {
        $this->loggedInAs($this->ann);
        $page = $this->body('profile');
        $this->assertStringContainsString('data-dialog-open="profile-correction"', $page);
        $this->assertStringContainsString('id="profile-correction"', $page);
        $this->assertStringContainsString('value="student_number"', $page, 'a student may correct their number');

        $this->step('POST', 'profile/corrections', ['field' => 'last_name', 'new_value' => 'Santos', 'reason' => 'Married name.'])->assertRedirectTo(site_url('profile'));
        $this->assertSame('Request sent', session('message'));

        $page = $this->body('profile');
        $this->assertStringContainsString('Waiting for your school', $page);
        $this->assertMatchesRegularExpression('#<option value="last_name"[^>]*disabled#', $page, 'the field with an open request cannot be asked again');

        $id = (int) db_connect()->table('profile_correction_requests')->where('user_id', $this->ann->id)->get()->getRow('id');

        $this->loggedInAs($this->admin);
        $this->assertMatchesRegularExpression('#Requests\s*<span[^>]*>1</span>#', $this->body('institution/people'), 'the tab badge counts the open requests');

        $queue = $this->body('institution/people/requests');
        $this->assertStringContainsString('Ann Lee', $queue);
        $this->assertStringContainsString('Santos', $queue);
        $this->assertStringContainsString('Change Ann Lee&#039;s last name?', $queue);
        $this->assertStringContainsString(site_url("institution/people/requests/{$id}/approve"), $queue);

        $this->step('POST', "institution/people/requests/{$id}/approve", ['note' => ''])->assertRedirectTo(site_url('institution/people/requests'));
        $this->assertSame('Change approved', session('message'));
        $this->assertSame('Santos', $this->profile($this->ann, 'last_name'));

        $bell = db_connect()->table('notifications')->where('user_id', $this->ann->id)->get()->getRowArray();
        $this->assertSame('profile', $bell['link'] ?? null, 'the requester is pointed to their profile');

        $this->assertStringContainsString('No requests waiting', $this->body('institution/people/requests'));
    }

    public function testAnApprovedEmailChangesOnlyOnceTheLinkIsConfirmed(): void
    {
        $this->loggedInAs($this->ann);
        $page = $this->body('profile');
        $this->assertStringContainsString('email a confirmation link to the new address', $page);

        $this->step('POST', 'profile/corrections', ['field' => 'email', 'new_value' => 'ann.new@example.com', 'reason' => 'New address.']);
        $id = (int) db_connect()->table('profile_correction_requests')->where('user_id', $this->ann->id)->get()->getRow('id');

        $this->loggedInAs($this->admin);
        $this->step('POST', "institution/people/requests/{$id}/approve", ['note' => '']);
        $this->assertSame('ann@example.com', $this->email($this->ann), 'approval alone changes nothing');

        $token = $this->linkTo('ann.new@example.com');
        auth()->logout();
        $this->mailer->sent = [];

        $shown = $this->call('GET', "email-change/{$token}");
        $shown->assertOK();
        $this->assertStringContainsString('Confirm new email', (string) $shown->response()->getBody());
        $this->assertSame('ann@example.com', $this->email($this->ann), 'opening the link does not change it');

        $done = $this->step('POST', "email-change/{$token}");
        $done->assertOK();
        $this->assertStringContainsString('Your email address is now ann.new@example.com', (string) $done->response()->getBody());
        $this->assertStringContainsString('Sign in', (string) $done->response()->getBody());
        $this->assertSame('ann.new@example.com', $this->email($this->ann));
        $this->assertContains('ann@example.com', array_column($this->mailer->sent, 'to'), 'the old address is told');

        $again = $this->step('POST', "email-change/{$token}");
        $this->assertStringContainsString('This link has expired or was already used.', (string) $again->response()->getBody());
    }

    public function testAnExpiredOrSomeoneElsesLinkShowsTheFriendlyPage(): void
    {
        (new \App\Services\Profiles\EmailChangeService())->start((int) $this->ann->id, 'ann.new@example.com', null);
        $token = $this->linkTo('ann.new@example.com');

        $this->loggedInAs($this->admin);
        $this->assertStringContainsString('This link is for another account. Sign out, then open it again.', (string) $this->step('POST', "email-change/{$token}")->response()->getBody());
        $this->assertSame('ann@example.com', $this->email($this->ann), 'a different signed-in user cannot use it');

        db_connect()->table('pending_email_changes')->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        auth()->logout();
        $this->assertStringContainsString('This link has expired or was already used.', (string) $this->step('POST', "email-change/{$token}")->response()->getBody());
        $this->assertStringContainsString('This link has expired or was already used.', (string) $this->call('GET', 'email-change/nonsense')->response()->getBody());
        $this->assertSame('ann@example.com', $this->email($this->ann));
    }

    public function testARequestRoutedToThePlatformSaysSo(): void
    {
        $loner = PeopleFactory::person('student', 'Lone', 'Student', null, PeopleFactory::institution('CFL2'));
        (new CorrectionRequestService())->submit($loner, 'first_name', 'Lonny', 'Nickname is official now.');

        $this->loggedInAs($loner);
        $this->assertStringContainsString('Waiting for an administrator', $this->body('profile'));
    }

    public function testAReadOnlyInstitutionRefusesSubmitsAndDecisions(): void
    {
        $id = $this->submitAsAnn();
        db_connect()->table('institutions')->where('id', $this->school)->update(['status' => 'read_only']);

        $this->loggedInAs($this->ann);
        $this->step('POST', 'profile/corrections', ['field' => 'first_name', 'new_value' => 'Anne', 'reason' => 'Spelling.']);
        $this->assertStringContainsString('read-only', (string) session('error'));
        $this->assertSame(1, db_connect()->table('profile_correction_requests')->countAllResults());

        $this->loggedInAs($this->admin);
        $this->step('POST', "institution/people/requests/{$id}/approve", ['note' => '']);
        $this->step('POST', "institution/people/requests/{$id}/reject", ['note' => 'No.']);
        $this->assertSame('pending', $this->request($id)['status']);
        $this->assertSame('Lee', $this->profile($this->ann, 'last_name'));
    }

    public function testAnotherInstitutionsRequestIsNotFound(): void
    {
        $other = PeopleFactory::institution('CFL3');
        PeopleFactory::person('institutional_admin', 'Oz', 'Admin', null, $other);
        $bob = PeopleFactory::person('student', 'Bob', 'Far', null, $other);
        $id  = (new CorrectionRequestService())->submit($bob, 'last_name', 'Near', 'Typo.');

        $this->loggedInAs($this->admin);
        $this->assertStringNotContainsString('Bob Far', $this->body('institution/people/requests'));
        $this->notFound("institution/people/requests/{$id}/approve", ['note' => '']);
        $this->notFound("institution/people/requests/{$id}/reject", ['note' => 'No.']);
        $this->assertSame('pending', $this->request($id)['status']);
    }

    public function testAnAdminNeitherSeesNorDecidesTheirOwnRequest(): void
    {
        PeopleFactory::person('institutional_admin', 'Second', 'Admin', null, $this->school);
        $id = (new CorrectionRequestService())->submit($this->admin, 'first_name', 'Idaa', 'Spelling.');

        $this->loggedInAs($this->admin);
        $this->assertStringContainsString('No requests waiting', $this->body('institution/people/requests'));
        $this->notFound("institution/people/requests/{$id}/approve", ['note' => '']);
        $this->assertSame('pending', $this->request($id)['status']);
    }

    public function testRejectingNeedsANoteAndADecidedRequestSaysSo(): void
    {
        $id = $this->submitAsAnn();
        $this->loggedInAs($this->admin);

        $queue = $this->body('institution/people/requests');
        $this->assertDoesNotMatchRegularExpression('#<textarea[^>]*name="note"[^>]*required#', $queue, 'approving needs no note');
        $this->assertStringContainsString('(needed if you reject)', $queue);
        $this->assertMatchesRegularExpression('#<button[^>]*data-requires="note"[^>]*>Reject</button>#', $queue, 'the script asks for the note on Reject');

        $bad = $this->withHeaders($this->json())->step('POST', "institution/people/requests/{$id}/reject", ['note' => '  ']);
        $bad->assertStatus(422);
        $this->assertArrayHasKey('note', json_decode($bad->getJSON(), true)['errors']);
        $this->assertSame('pending', $this->request($id)['status']);

        $this->withHeaders([]);
        $this->step('POST', "institution/people/requests/{$id}/reject", ['note' => 'Bring your certificate.'])->assertRedirectTo(site_url('institution/people/requests'));
        $this->assertSame('Request rejected', session('message'));
        $this->assertSame('rejected', $this->request($id)['status']);

        $this->step('POST', "institution/people/requests/{$id}/approve", ['note' => '']);
        $this->assertSame(CorrectionRequestService::ALREADY_DECIDED, session('error'));
    }

    public function testTheSisWarningShowsForASyncedPerson(): void
    {
        $this->submitAsAnn();
        $db = db_connect();
        $db->table('sis_connections')->insert(['institution_id' => $this->school, 'enabled' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('sis_links')->insert(['institution_id' => $this->school, 'entity' => 'person', 'external_id' => 'S-1', 'local_id' => $this->ann->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->loggedInAs($this->admin);
        $queue = $this->body('institution/people/requests');
        $this->assertStringContainsString('From SIS', $queue);
        $this->assertStringContainsString('The next sync will undo this change unless it is fixed in the SIS too.', $queue);
    }

    public function testASystemAdminSeesOnlyPlatformRequestsOnUsers(): void
    {
        $sys   = PeopleFactory::person('admin', 'Sys', 'Admin', 'sys@example.com');
        $loner = PeopleFactory::person('student', 'Lone', 'Student', null, PeopleFactory::institution('CFL4'));
        $mine  = (new CorrectionRequestService())->submit($loner, 'first_name', 'Lonny', 'Nickname.');
        $school = $this->submitAsAnn();

        $this->loggedInAs($sys);
        $users = $this->body('admin/users');
        $this->assertStringContainsString(site_url("admin/users/requests/{$mine}/approve"), $users);
        $this->assertStringNotContainsString("requests/{$school}/approve", $users, 'a school request stays with the school');

        $this->step('POST', "admin/users/requests/{$mine}/approve", ['note' => '']);
        $this->assertSame('Lone', $this->profile($loner, 'first_name'), 'approving a name change asks for the password first');

        $_SESSION['reauth'] = ['user' => $sys->id, 'at' => time()];
        $this->withSession();
        $this->notFound("admin/users/requests/{$school}/approve", ['note' => '']);
        $this->notFound("admin/users/requests/{$school}/reject", ['note' => 'No.']);
        $this->step('POST', "admin/users/requests/{$mine}/approve", ['note' => ''])->assertRedirectTo(site_url('admin/users'));
        $this->assertSame('Change approved', session('message'));
        $this->assertSame('Lonny', $this->profile($loner, 'first_name'));
    }

    public function testASystemAdminEditsTheirOwnOfficialDetails(): void
    {
        $sys = PeopleFactory::person('admin', 'Sys', 'Admin', 'sys@example.com');
        $this->loggedInAs($sys);

        $page = $this->body('profile');
        $this->assertStringContainsString('data-dialog-open="profile-official"', $page);
        $this->assertStringNotContainsString('profile-correction', $page, 'they edit directly instead');

        $this->step('POST', 'profile/identity-official', ['first_name' => 'Sylvia', 'middle_name' => '', 'last_name' => 'Admin', 'suffix' => '', 'email' => 'sylvia@example.com'])->assertRedirectTo(site_url('profile'));
        $this->assertSame('Profile updated', session('message'));

        $this->assertSame('Sylvia', $this->profile($sys, 'first_name'));
        $this->assertSame('sys@example.com', $this->email($sys), 'the email waits for its link');
        $this->assertSame(1, db_connect()->table('pending_email_changes')->where('user_id', $sys->id)->countAllResults());

        $log = db_connect()->table('audit_logs')->where('action', 'profile.official_updated')->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertStringContainsString('first_name', (string) $log['new_values']);
        $this->assertStringNotContainsString('Sylvia', (string) $log['new_values'], 'field names, not values');

        $this->assertContains('sys@example.com', array_column($this->mailer->sent, 'to'), 'they are told by email');
    }

    public function testNoOtherRoleMayEditOfficialDetailsDirectly(): void
    {
        $this->loggedInAs($this->admin);
        $this->notFound('profile/identity-official', ['first_name' => 'Hacked', 'last_name' => 'Admin']);

        $this->loggedInAs($this->ann);
        $this->notFound('profile/identity-official', ['first_name' => 'Hacked', 'last_name' => 'Lee']);

        $this->assertSame('Ann', $this->profile($this->ann, 'first_name'));
        $this->assertSame('Ida', $this->profile($this->admin, 'first_name'));
    }

    public function testPostsWithoutTheCsrfTokenAreRefused(): void
    {
        $id = $this->submitAsAnn('first_name', 'Anne');

        $this->loggedInAs($this->ann);
        $this->withoutCsrfToken()->call('POST', 'profile/corrections', ['field' => 'last_name', 'new_value' => 'Santos', 'reason' => 'Married name.']);
        $this->assertSame(1, db_connect()->table('profile_correction_requests')->countAllResults());

        $this->loggedInAs($this->admin);
        $this->call('POST', "institution/people/requests/{$id}/approve", ['note' => '']);
        $this->assertSame('pending', $this->request($id)['status']);
    }

    public function testASystemAdminWithAProfileRowGetsNoSelfServiceEditing(): void
    {
        $sys = PeopleFactory::person('admin', 'Sys', 'Admin', 'sys@example.com');
        db_connect()->table('user_profiles')->insert(['user_id' => $sys->id, 'first_name' => 'Sys', 'last_name' => 'Admin', 'created_at' => date('Y-m-d H:i:s')]);

        $this->loggedInAs($sys);
        $page = $this->body('profile');
        foreach (['data-profile-checklist', 'profile-photo', 'profile-edit-identity', 'profile-edit-about', 'profile-edit-staff'] as $marker) {
            $this->assertStringNotContainsString($marker, $page, $marker);
        }
        $this->assertStringContainsString('data-dialog-open="profile-official"', $page);

        foreach (['profile/identity' => ['preferred_first_name' => 'Syl'], 'profile/about' => ['bio' => 'Hi'], 'profile/staff' => ['office_location' => 'X'], 'profile/photo' => [], 'profile/photo/remove' => [], 'profile/checklist/dismiss' => []] as $uri => $data) {
            $this->notFound($uri, $data);
        }
        $this->assertNull($this->profile($sys, 'bio'));
        $this->assertNull($this->profile($sys, 'checklist_dismissed_at'));
    }

    public function testTheBadgeCountMatchesTheQueue(): void
    {
        $this->submitAsAnn();
        $this->submitAsAnn('first_name', 'Anne');
        $service = new CorrectionRequestService();

        $this->assertSame(2, $service->countFor($this->admin));
        $this->assertSame(count($service->pendingFor($this->admin)), $service->countFor($this->admin));
        $this->assertSame(0, $service->countFor($this->ann), 'not an admin: nothing to decide');
    }

    public function testAReadOnlyInstitutionHidesTheCorrectionButton(): void
    {
        db_connect()->table('institutions')->where('id', $this->school)->update(['status' => 'read_only']);

        $this->loggedInAs($this->ann);
        $page = $this->body('profile');
        $this->assertStringNotContainsString('data-dialog-open="profile-correction"', $page);
        $this->assertStringContainsString('This institution is read-only', $page);
    }

    public function testWhenEveryDetailIsWaitingTheButtonSaysSo(): void
    {
        $service = new CorrectionRequestService();
        foreach (['first_name' => 'Anne', 'middle_name' => 'Mae', 'last_name' => 'Santos', 'suffix' => 'Jr.', 'email' => 'ann2@example.com', 'student_number' => '2026-1'] as $field => $value) {
            $service->submit($this->ann, $field, $value, 'Fix.');
        }

        $this->loggedInAs($this->ann);
        $page = $this->body('profile');
        $this->assertMatchesRegularExpression('#<button[^>]*disabled[^>]*>All your requests are waiting</button>#', $page);
        $this->assertStringNotContainsString('id="profile-correction"', $page);
    }

    public function testTheOfficialDialogShowsAnErrorBesideItsField(): void
    {
        $sys = PeopleFactory::person('admin', 'Sys', 'Admin', 'sys@example.com');
        $this->loggedInAs($sys);

        $bad = $this->withHeaders($this->json())->step('POST', 'profile/identity-official', ['first_name' => 'S', 'last_name' => 'Admin', 'email' => 'sys@example.com']);
        $bad->assertStatus(422);
        $this->assertArrayHasKey('first_name', json_decode($bad->getJSON(), true)['errors']);

        $bad = $this->withHeaders($this->json())->step('POST', 'profile/identity-official', ['first_name' => 'Sys', 'last_name' => 'Admin', 'email' => 'nope']);
        $this->assertArrayHasKey('email', json_decode($bad->getJSON(), true)['errors']);
    }
}
