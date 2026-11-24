<?php

declare(strict_types=1);

use App\Services\Profiles\CorrectionRequestService;
use App\Services\Profiles\ProfileEditException;
use App\Services\Sis\StudentNumbers;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * Correction requests for official identity fields (FR7, D17): what may be asked, where it goes, who may decide,
 * and what a decision writes. Nobody decides their own request; an approved email waits for its link.
 *
 * @internal
 */
final class CorrectionRequestServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private CorrectionRequestService $service;

    private FakeEmail $mailer;

    private int $school;

    private User $admin;

    private User $ann;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');

        $this->service = new CorrectionRequestService();
        $this->mailer  = FakeEmail::install();
        $this->school  = PeopleFactory::institution('COR');
        $this->admin   = PeopleFactory::person('institutional_admin', 'Ida', 'Admin', 'ida@example.com', $this->school);
        $this->ann     = PeopleFactory::person('student', 'Ann', 'Lee', 'ann@example.com', $this->school);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');

        parent::tearDown();
    }

    private function refused(callable $call, string $field, string $mentions): void
    {
        try {
            $call();
            $this->fail('This should have been refused.');
        } catch (ProfileEditException $e) {
            $this->assertSame($field, $e->field);
            $this->assertStringContainsString($mentions, $e->getMessage());
        }
    }

    private function notFound(callable $call): void
    {
        try {
            $call();
            $this->fail('This should have answered not found.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string, mixed> */
    private function request(int $id): array
    {
        return db_connect()->table('profile_correction_requests')->where('id', $id)->get()->getRowArray();
    }

    private function profile(User $user, string $column): mixed
    {
        return db_connect()->table('user_profiles')->where('user_id', $user->id)->get()->getRow($column);
    }

    private function email(User $user): string
    {
        return (string) db_connect()->table('auth_identities')->where(['user_id' => $user->id, 'type' => 'email_password'])->get()->getRow('secret');
    }

    private function bells(User $user): int
    {
        return db_connect()->table('notifications')->where('user_id', $user->id)->countAllResults();
    }

    private function giveNumber(User $user, string $number): void
    {
        db_connect()->table('user_profiles')->where('user_id', $user->id)->update(['student_number' => $number, 'student_number_institution_id' => $this->school]);
    }

    public function testASubmissionIsStoredWithTheOldValueAndRoutedToTheInstitution(): void
    {
        $id  = $this->service->submit($this->ann, 'last_name', '  Lee-Santos ', 'Married name.');
        $row = $this->request($id);

        $this->assertSame('pending', $row['status']);
        $this->assertSame('Lee', $row['old_value']);
        $this->assertSame('Lee-Santos', $row['new_value']);
        $this->assertSame('institution', $row['routed_to']);
        $this->assertSame($this->school, (int) $row['institution_id']);
    }

    public function testOnlyOneOpenRequestPerField(): void
    {
        $this->service->submit($this->ann, 'last_name', 'Santos', 'Married name.');

        $this->refused(fn () => $this->service->submit($this->ann, 'last_name', 'Cruz', 'Changed my mind.'), 'field', 'already');

        $this->assertGreaterThan(0, $this->service->submit($this->ann, 'first_name', 'Anne', 'Spelling.'), 'another field is fine');
    }

    public function testAnUnchangedValueIsRefused(): void
    {
        $this->refused(fn () => $this->service->submit($this->ann, 'first_name', 'Ann', 'Same.'), 'new_value', 'already');
        $this->refused(fn () => $this->service->submit($this->ann, 'email', 'ANN@example.com', 'Same.'), 'new_value', 'already');

        db_connect()->table('auth_identities')->where(['user_id' => $this->ann->id, 'type' => 'email_password'])->update(['secret' => 'Ann@Example.com']);
        $this->refused(fn () => $this->service->submit($this->ann, 'email', 'ann@example.com', 'Same.'), 'new_value', 'already');
    }

    public function testTheReasonIsRequiredAndAtMost500Characters(): void
    {
        $this->refused(fn () => $this->service->submit($this->ann, 'first_name', 'Anne', '   '), 'reason', 'reason');
        $this->refused(fn () => $this->service->submit($this->ann, 'first_name', 'Anne', str_repeat('a', 501)), 'reason', '500');

        $this->assertGreaterThan(0, $this->service->submit($this->ann, 'first_name', 'Anne', str_repeat('a', 500)));
    }

    public function testValuesAreChecked(): void
    {
        $this->refused(fn () => $this->service->submit($this->ann, 'password', 'x', 'Why not.'), 'field', 'cannot');
        $this->refused(fn () => $this->service->submit($this->ann, 'first_name', "An\u{200B}n", 'Typo.'), 'new_value', 'not allowed');
        $this->refused(fn () => $this->service->submit($this->ann, 'first_name', "An\nn", 'Typo.'), 'new_value', 'not allowed');
        $this->refused(fn () => $this->service->submit($this->ann, 'last_name', str_repeat('a', 101), 'Typo.'), 'new_value', '100');
        $this->refused(fn () => $this->service->submit($this->ann, 'last_name', '', 'Typo.'), 'new_value', 'characters');
        $this->refused(fn () => $this->service->submit($this->ann, 'email', 'not-an-email', 'Typo.'), 'new_value', 'valid email');
        $this->refused(fn () => $this->service->submit($this->ann, 'email', 'ida@example.com', 'Mine.'), 'new_value', 'already exists');
    }

    public function testAnEmptyMiddleNameOrSuffixClearsIt(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->ann->id)->update(['middle_name' => 'Marie']);

        $id = $this->service->submit($this->ann, 'middle_name', '', 'I have no middle name.');
        $this->service->approve($id, $this->admin, null);

        $this->assertNull($this->profile($this->ann, 'middle_name'));
    }

    public function testOnlyAStudentMayRequestAStudentNumber(): void
    {
        $ivo = PeopleFactory::person('instructor', 'Ivo', 'Teacher', null, $this->school);

        $this->refused(fn () => $this->service->submit($ivo, 'student_number', '2026-0001', 'Mine.'), 'field', 'student');
    }

    public function testATakenStudentNumberIsRefusedAtSubmitAndAtApproval(): void
    {
        $ben = PeopleFactory::person('student', 'Ben', 'Cruz', null, $this->school);
        $this->giveNumber($ben, '2026-0001');

        $this->assertTrue(StudentNumbers::taken('2026-0001', $this->school, (int) $this->ann->id));
        $this->refused(fn () => $this->service->submit($this->ann, 'student_number', '2026-0001', 'Mine.'), 'new_value', 'someone else');

        $id = $this->service->submit($this->ann, 'student_number', '2026-0002', 'Mine.');
        $this->giveNumber($ben, '2026-0002'); // someone got it while the request waited

        $this->refused(fn () => $this->service->approve($id, $this->admin, null), 'new_value', 'someone else');
        $this->assertSame('pending', $this->request($id)['status']);
        $this->assertNull($this->profile($this->ann, 'student_number'));
    }

    public function testApprovalWritesNamesAndStudentNumber(): void
    {
        $name   = $this->service->submit($this->ann, 'last_name', 'Santos', 'Married name.');
        $number = $this->service->submit($this->ann, 'student_number', '2026-0009', 'Typo in my number.');

        $this->service->approve($name, $this->admin, 'Checked the certificate.');
        $this->service->approve($number, $this->admin, null);

        $this->assertSame('Santos', $this->profile($this->ann, 'last_name'));
        $this->assertSame('2026-0009', $this->profile($this->ann, 'student_number'));
        $this->assertSame($this->school, (int) $this->profile($this->ann, 'student_number_institution_id'));

        $row = $this->request($name);
        $this->assertSame('approved', $row['status']);
        $this->assertSame((int) $this->admin->id, (int) $row['decided_by']);
        $this->assertSame('Checked the certificate.', $row['decision_note']);
        $this->assertNotNull($row['decided_at']);
    }

    public function testAnApprovedEmailDoesNotChangeTheEmailButStartsAPendingChange(): void
    {
        $id = $this->service->submit($this->ann, 'email', 'ann.lee@school.example', 'New school address.');

        $this->service->approve($id, $this->admin, null);

        $this->assertSame('ann@example.com', $this->email($this->ann), 'unchanged until the link is opened');
        $pending = db_connect()->table('pending_email_changes')->where('user_id', $this->ann->id)->get()->getRowArray();
        $this->assertSame('ann.lee@school.example', $pending['new_email']);
        $this->assertSame($id, (int) $pending['request_id']);
        $this->assertContains('ann.lee@school.example', array_column($this->mailer->sent, 'to'), 'the link goes to the new address');
    }

    public function testRejectNeedsANoteAndTellsTheRequesterWhy(): void
    {
        $id = $this->service->submit($this->ann, 'first_name', 'Anne', 'Spelling.');

        $this->refused(fn () => $this->service->reject($id, $this->admin, '  '), 'note', 'reason');
        $this->refused(fn () => $this->service->reject($id, $this->admin, str_repeat('n', 501)), 'note', '500');

        $this->mailer->sent = [];
        $this->service->reject($id, $this->admin, 'Your birth certificate says Ann.');

        $this->assertSame('rejected', $this->request($id)['status']);
        $this->assertSame('Ann', $this->profile($this->ann, 'first_name'));

        $bell = db_connect()->table('notifications')->where('user_id', $this->ann->id)->get()->getRowArray();
        $this->assertStringContainsString('Your birth certificate says Ann.', $bell['body']);
        $this->assertSame(['ann@example.com'], array_column($this->mailer->sent, 'to'), 'and by email');
    }

    public function testTheRequesterIsToldByBellAndEmailOnApproval(): void
    {
        $id = $this->service->submit($this->ann, 'first_name', 'Anne', 'Spelling.');
        $this->mailer->sent = [];

        $this->service->approve($id, $this->admin, null);

        $this->assertSame(1, $this->bells($this->ann));
        $this->assertSame(['ann@example.com'], array_column($this->mailer->sent, 'to'));
    }

    public function testEveryAdminWhoDecidesGetsABellOnSubmit(): void
    {
        $other = PeopleFactory::person('institutional_admin', 'Oli', 'Admin', null, $this->school);
        $sys   = PeopleFactory::person('admin', 'Sys', 'Admin');

        $this->service->submit($this->ann, 'first_name', 'Anne', 'Spelling.');

        $this->assertSame(1, $this->bells($this->admin));
        $this->assertSame(1, $this->bells($other));
        $this->assertSame(0, $this->bells($sys), 'the platform is not told about a request it does not decide');
        $this->assertSame(0, $this->bells($this->ann));
    }

    public function testAnInstitutionalAdminsOwnRequestGoesToAnotherAdminAndIsHiddenFromThem(): void
    {
        $other = PeopleFactory::person('institutional_admin', 'Oli', 'Admin', null, $this->school);

        $this->assertSame('institution', $this->service->routeOf($this->admin));
        $id = $this->service->submit($this->admin, 'last_name', 'Adminson', 'Typo.');

        $this->assertSame([], $this->service->pendingFor($this->admin));
        $this->assertSame([$id], array_column($this->service->pendingFor($other), 'id'));
        $this->assertSame(1, $this->bells($other));
        $this->assertSame(0, $this->bells($this->admin));

        $this->notFound(fn () => $this->service->approve($id, $this->admin, null));
        $this->notFound(fn () => $this->service->reject($id, $this->admin, 'No.'));
        $this->assertSame('Admin', $this->profile($this->admin, 'last_name'));
    }

    public function testTheSoleInstitutionalAdminsRequestGoesToThePlatform(): void
    {
        $sys = PeopleFactory::person('admin', 'Sys', 'Admin');

        $this->assertSame('platform', $this->service->routeOf($this->admin));
        $id = $this->service->submit($this->admin, 'last_name', 'Adminson', 'Typo.');

        $this->assertSame('platform', $this->request($id)['routed_to']);
        $this->assertSame([$id], array_column($this->service->pendingFor($sys), 'id'));
        $this->assertSame(1, $this->bells($sys));

        $this->service->approve($id, $sys, null);
        $this->assertSame('Adminson', $this->profile($this->admin, 'last_name'));
    }

    public function testAnInstitutionWithNoActiveInstitutionalAdminGoesToThePlatform(): void
    {
        db_connect()->table('users')->where('id', $this->admin->id)->update(['active' => 0]);

        $this->assertSame('platform', $this->service->routeOf($this->ann));
    }

    public function testSomeoneWithNoInstitutionGoesToThePlatform(): void
    {
        $loner = PeopleFactory::person('parent', 'Pat', 'Parent');

        $this->assertSame('platform', $this->service->routeOf($loner));
        $id = $this->service->submit($loner, 'first_name', 'Patricia', 'Full name.');
        $this->assertNull($this->request($id)['institution_id']);
    }

    public function testAnotherInstitutionsAdminOrTheRequesterCannotDecide(): void
    {
        $elsewhere = PeopleFactory::person('institutional_admin', 'Eli', 'Else', null, PeopleFactory::institution('ELS'));
        $sys       = PeopleFactory::person('admin', 'Sys', 'Admin');
        $id        = $this->service->submit($this->ann, 'first_name', 'Anne', 'Spelling.');

        $this->notFound(fn () => $this->service->approve($id, $elsewhere, null));
        $this->notFound(fn () => $this->service->approve($id, $sys, null)); // the institution has an admin: not escalated
        $this->notFound(fn () => $this->service->approve($id, $this->ann, null));
        $this->notFound(fn () => $this->service->approve(999999, $this->admin, null));
        $this->assertSame([], $this->service->pendingFor($elsewhere));
        $this->assertSame([], $this->service->pendingFor($sys));

        $this->service->approve($id, $this->admin, null);
    }

    public function testADecidedRequestCannotBeDecidedAgain(): void
    {
        $id = $this->service->submit($this->ann, 'first_name', 'Anne', 'Spelling.');
        $this->service->approve($id, $this->admin, null);

        $this->refused(fn () => $this->service->approve($id, $this->admin, null), 'status', 'already decided');
        $this->refused(fn () => $this->service->reject($id, $this->admin, 'No.'), 'status', 'already decided');
        $this->assertSame('approved', $this->request($id)['status']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'profile.correction_approved')->countAllResults());
    }

    public function testRealNamesWithTypographicApostrophesCombiningMarksAndOrdinalsAreAccepted(): void
    {
        $last   = $this->service->submit($this->ann, 'last_name', "O\u{2019}Brien", 'Spelling.');
        $first  = $this->service->submit($this->ann, 'first_name', "Jose\u{0301}", 'Accent.');
        $suffix = $this->service->submit($this->ann, 'suffix', '3rd', 'Missing.');

        foreach ([$last, $first, $suffix] as $id) {
            $this->service->approve($id, $this->admin, null);
        }

        $this->assertSame(["Jose\u{0301}", "O\u{2019}Brien", '3rd'], [$this->profile($this->ann, 'first_name'), $this->profile($this->ann, 'last_name'), $this->profile($this->ann, 'suffix')]);
    }

    public function testADeactivatedOrDeletedAdminCannotDecide(): void
    {
        $id = $this->service->submit($this->ann, 'first_name', 'Anne', 'Spelling.');
        $db = db_connect();

        $db->table('user_account_controls')->insert(['user_id' => $this->admin->id, 'deactivated_at' => date('Y-m-d H:i:s')]);
        $this->assertSame([], $this->service->pendingFor($this->admin));
        $this->notFound(fn () => $this->service->approve($id, $this->admin, null));

        $db->table('user_account_controls')->where('user_id', $this->admin->id)->delete();
        $db->table('users')->where('id', $this->admin->id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->notFound(fn () => $this->service->reject($id, $this->admin, 'No.'));

        $this->assertSame('pending', $this->request($id)['status']);
    }

    public function testApprovingForSomeoneWithNoProfileRowIsRefusedAndStaysPending(): void
    {
        $id = $this->service->submit($this->ann, 'last_name', 'Santos', 'Married name.');
        db_connect()->table('user_profiles')->where('user_id', $this->ann->id)->delete();

        $this->refused(fn () => $this->service->approve($id, $this->admin, null), 'new_value', 'no profile');

        $this->assertSame('pending', $this->request($id)['status']);
        $this->assertSame(0, db_connect()->table('audit_logs')->where('action', 'profile.correction_approved')->countAllResults());
        $this->assertSame(0, $this->bells($this->ann));
    }

    public function testDecisionsAreAuditedWithTheActorAndNoValues(): void
    {
        $id = $this->service->submit($this->ann, 'last_name', 'Santos', 'Married name.');
        $this->service->approve($id, $this->admin, null);
        $other = $this->service->submit($this->ann, 'first_name', 'Anne', 'Spelling.');
        $this->service->reject($other, $this->admin, 'No proof.');

        $logs = db_connect()->table('audit_logs')->like('action', 'profile.correction_', 'after')->orderBy('id')->get()->getResultArray();

        $this->assertSame(['profile.correction_requested', 'profile.correction_approved', 'profile.correction_requested', 'profile.correction_rejected'], array_column($logs, 'action'));
        $this->assertSame([(int) $this->ann->id, (int) $this->admin->id, (int) $this->ann->id, (int) $this->admin->id], array_map('intval', array_column($logs, 'actor_user_id')));

        foreach ($logs as $log) {
            $this->assertStringNotContainsString('Santos', (string) $log['new_values']);
            $this->assertStringNotContainsString('Anne', (string) $log['new_values']);
        }
    }

    public function testPendingRowsCarryWhatTheAdminNeedsAndTheSisFlag(): void
    {
        $id = $this->service->submit($this->ann, 'last_name', 'Santos', 'Married name.');

        $row = $this->service->pendingFor($this->admin)[0];
        $this->assertSame($id, $row['id']);
        $this->assertSame('Ann Lee', $row['requester']);
        $this->assertSame(['last_name', 'Lee', 'Santos', 'Married name.', false], [$row['field'], $row['old_value'], $row['new_value'], $row['reason'], $row['sis_linked']]);
        $this->assertNotEmpty($row['created_at']);

        $db = db_connect();
        $db->table('sis_connections')->insert(['institution_id' => $this->school, 'enabled' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('sis_links')->insert(['institution_id' => $this->school, 'entity' => 'person', 'external_id' => 'S-1', 'local_id' => $this->ann->id, 'created_at' => date('Y-m-d H:i:s')]);

        $this->assertTrue($this->service->pendingFor($this->admin)[0]['sis_linked']);
    }
}
