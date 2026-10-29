<?php

declare(strict_types=1);

use App\Services\Admin\PlatformSettings;
use App\Services\Institutions\InvitationService;
use App\Services\Institutions\InvitationStatus;
use App\Services\Institutions\StructureException;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestLogger;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * Invitations to administer an institution: issuing and emailing a link,
 * replacing it, expiry, and what using it does. The mailer is a fake and the
 * current time is passed in, so nothing is sent and no test depends on today.
 *
 * @internal
 */
final class InvitationServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const STRONG = 'Tr4ins-Lantern-Moss-92';

    private const OTHER = 'An0ther-Str0ng-Phrase-77';

    private InvitationService $service;

    private FakeEmail $mailer;

    private User $admin;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->service = new InvitationService();
        $this->mailer  = FakeEmail::install();
        $this->admin   = CourseFactory::user('admin');
        $this->now     = new DateTimeImmutable('2026-10-09 10:00:00');
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function institution(string $code = 'ACME', string $status = 'active'): int
    {
        db_connect()->table('institutions')->insert(['name' => "Institution {$code}", 'code' => $code, 'status' => $status]);

        return (int) db_connect()->insertID();
    }

    /** @return array<string, string> */
    private function person(string $email = 'maria.santos@example.com', string $first = 'Maria', string $last = 'Santos'): array
    {
        return ['first_name' => $first, 'last_name' => $last, 'email' => $email];
    }

    /** The secret in the link of the last email sent. */
    private function lastToken(): string
    {
        $last = end($this->mailer->sent);

        $this->assertNotFalse($last, 'an email should have been sent');
        $this->assertSame(1, preg_match('#/invitation/([A-Za-z0-9_-]{43})#', $last['message'], $match), 'the email should hold the link');

        return $match[1];
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return db_connect()->table('institution_invitations')->where('id', $id)->get()->getRowArray();
    }

    private function auditCount(string $action): int
    {
        return db_connect()->table('audit_logs')->where('action', $action)->countAllResults();
    }

    /*
    |--------------------------------------------------------------------------
    | Inviting
    |--------------------------------------------------------------------------
    */

    public function testInvitingSavesAnInvitationAndEmailsTheLink(): void
    {
        $institution = $this->institution();

        $result = $this->service->invite($institution, $this->person(), $this->admin, $this->now);

        $this->assertNull($result['email_error']);
        $this->assertSame('maria.santos@example.com', $result['email']);
        $this->assertSame('2026-10-16 10:00:00', $result['expires_at'], 'a week by default');

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('maria.santos@example.com', $this->mailer->sent[0]['to']);
        $this->assertStringContainsString('Institution ACME', $this->mailer->sent[0]['subject']);
        $this->assertStringContainsString('Hello Maria', $this->mailer->sent[0]['message']);
        $this->assertStringContainsString('2026-10-16 10:00', $this->mailer->sent[0]['message']);

        $row = $this->row($result['invitation_id']);

        $this->assertSame($institution, (int) $row['institution_id']);
        $this->assertSame('institutional_admin', $row['role']);
        $this->assertSame('2026-10-09 10:00:00', $row['sent_at']);
        $this->assertNull($row['accepted_at']);
        $this->assertSame($this->admin->email ?: $this->admin->username, $row['invited_by_label']);
        $this->assertSame(1, $this->auditCount('invitation.created'));
    }

    public function testTheSecretIsNeverStored(): void
    {
        $institution = $this->institution();
        $id          = $this->service->invite($institution, $this->person(), $this->admin, $this->now)['invitation_id'];
        $token       = $this->lastToken();

        $row = $this->row($id);

        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        $this->assertStringNotContainsString($token, implode('|', array_map('strval', $row)), 'only the hash is kept');

        $audit = db_connect()->table('audit_logs')->get()->getResultArray();
        $this->assertStringNotContainsString($token, json_encode($audit), 'and it is not in the audit log either');
    }

    public function testTheLinkExpiresAfterTheConfiguredNumberOfDays(): void
    {
        (new PlatformSettings())->set('invitationValidityDays', 3);

        $result = $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now);

        $this->assertSame('2026-10-12 10:00:00', $result['expires_at']);
    }

    public function testAPendingInstitutionIsInvitedThroughOnboardingNotHere(): void
    {
        $institution = $this->institution('NEW', 'pending');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Finish onboarding');

        $this->service->invite($institution, $this->person(), $this->admin, $this->now);
    }

    public function testAnUnknownInstitutionCannotBeInvitedTo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->service->invite(999999, $this->person(), $this->admin, $this->now);
    }

    public function testADeletedInstitutionCannotBeInvitedTo(): void
    {
        $institution = $this->institution();
        db_connect()->table('institutions')->where('id', $institution)->update(['deleted_at' => '2026-10-01 00:00:00']);

        $this->expectException(InvalidArgumentException::class);

        $this->service->invite($institution, $this->person(), $this->admin, $this->now);
    }

    public function testBadDetailsAreRefusedAndNothingIsSavedOrSent(): void
    {
        $institution = $this->institution();

        try {
            $this->service->invite($institution, $this->person('not-an-email'), $this->admin, $this->now);
            $this->fail('A bad email should be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, db_connect()->table('institution_invitations')->countAllResults());
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAnAddressThatAlreadyHasAnAccountIsRefused(): void
    {
        $institution = $this->institution();
        $existing    = CourseFactory::user('student');
        db_connect()->table('auth_identities')->insert(['user_id' => $existing->id, 'type' => 'email_password', 'secret' => 'taken@example.com', 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');

        $this->service->invite($institution, $this->person('Taken@Example.com'), $this->admin, $this->now);
    }

    public function testASecondInvitationRevokesTheOpenOne(): void
    {
        $institution = $this->institution();

        $first      = $this->service->invite($institution, $this->person('typo@example.com'), $this->admin, $this->now);
        $firstToken = $this->lastToken();

        $second = $this->service->invite($institution, $this->person('right@example.com'), $this->admin, $this->now->modify('+1 hour'));

        $this->assertSame(InvitationStatus::REVOKED, $this->service->forInstitution($institution, $this->now, 5)[1]['status']);
        $this->assertSame($first['invitation_id'], (int) $this->service->forInstitution($institution, $this->now, 5)[1]['id']);
        $this->assertSame(InvitationStatus::PENDING, $this->service->forInstitution($institution, $this->now, 5)[0]['status']);
        $this->assertSame($second['invitation_id'], (int) $this->service->forInstitution($institution, $this->now, 5)[0]['id']);

        try {
            $this->service->open($firstToken, $this->now);
            $this->fail('The replaced link must not work.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(InvitationService::LINK_REPLACED, $e->getMessage());
        }
    }

    public function testAnotherInstitutionsInvitationIsNotRevoked(): void
    {
        $a = $this->institution('AAA');
        $b = $this->institution('BBB');

        $this->service->invite($a, $this->person('a@example.com'), $this->admin, $this->now);
        $tokenA = $this->lastToken();
        $this->service->invite($b, $this->person('b@example.com'), $this->admin, $this->now);

        $this->assertSame('a@example.com', $this->service->open($tokenA, $this->now)['email']);
    }

    /*
    |--------------------------------------------------------------------------
    | Other roles, and cancelling
    |--------------------------------------------------------------------------
    */

    public function testSeveralPeopleCanBeInvitedToDifferentRolesAtOnce(): void
    {
        $institution = $this->institution();

        $this->service->invite($institution, $this->person('one@example.com'), $this->admin, $this->now, 'instructor');
        $one = $this->lastToken();
        $this->service->invite($institution, $this->person('two@example.com'), $this->admin, $this->now, 'student');
        $two = $this->lastToken();

        $this->assertSame('instructor', $this->service->open($one, $this->now)['role']);
        $this->assertSame('student', $this->service->open($two, $this->now)['role']);
    }

    public function testInvitingTheSamePersonAgainReplacesTheirOpenInvitation(): void
    {
        $institution = $this->institution();

        $this->service->invite($institution, $this->person('one@example.com'), $this->admin, $this->now, 'instructor');
        $first = $this->lastToken();
        $this->service->invite($institution, $this->person('one@example.com'), $this->admin, $this->now, 'chairperson');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(InvitationService::LINK_REPLACED);

        $this->service->open($first, $this->now);
    }

    public function testAnotherRolesInvitationDoesNotReplaceTheAdministratorsAndTheOtherWayAround(): void
    {
        $institution = $this->institution();

        $this->service->invite($institution, $this->person('boss@example.com'), $this->admin, $this->now);
        $boss = $this->lastToken();
        $this->service->invite($institution, $this->person('student@example.com'), $this->admin, $this->now, 'student');
        $student = $this->lastToken();

        $this->assertSame('boss@example.com', $this->service->open($boss, $this->now)['email']);
        $this->assertSame('student@example.com', $this->service->open($student, $this->now)['email']);

        $this->service->invite($institution, $this->person('newboss@example.com'), $this->admin, $this->now);

        $this->assertSame('student@example.com', $this->service->open($student, $this->now)['email'], 'a new administrator invitation leaves the student alone');
    }

    public function testASystemAdministratorCannotBeInvited(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('php spark admin:create');

        $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now, 'admin');
    }

    public function testTheListCanBeNarrowedToOneRole(): void
    {
        $institution = $this->institution();
        $this->service->invite($institution, $this->person('boss@example.com'), $this->admin, $this->now);
        $this->service->invite($institution, $this->person('student@example.com'), $this->admin, $this->now, 'student');

        $this->assertCount(2, $this->service->forInstitution($institution, $this->now));
        $this->assertSame(['boss@example.com'], array_column($this->service->forInstitution($institution, $this->now, 20, InvitationService::ROLE), 'email'));
    }

    public function testCancellingKillsTheLinkAndIsRecorded(): void
    {
        $institution = $this->institution();
        $result      = $this->service->invite($institution, $this->person(), $this->admin, $this->now);
        $token       = $this->lastToken();

        $this->service->revoke($institution, $result['invitation_id'], $this->admin, $this->now);

        $this->assertSame(1, $this->auditCount('invitation.revoked'));
        $this->assertSame(InvitationStatus::REVOKED, $this->service->forInstitution($institution, $this->now)[0]['status']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(InvitationService::LINK_REPLACED);

        $this->service->open($token, $this->now);
    }

    public function testOnlyAnOpenInvitationCanBeCancelled(): void
    {
        $institution = $this->institution();
        $other       = $this->institution('OTHER');

        $open = $this->service->invite($institution, $this->person('open@example.com'), $this->admin, $this->now);
        $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $expired = $this->service->invite($other, $this->person('late@example.com'), $this->admin, $this->now);

        foreach ([[$institution, $open['invitation_id'], $this->now, 'no longer open'], [$other, $expired['invitation_id'], $this->now->modify('+30 days'), 'no longer open'], [$institution, 999999, $this->now, 'does not exist'], [$other, $open['invitation_id'], $this->now, 'does not exist']] as [$inst, $id, $when, $says]) {
            try {
                $this->service->revoke($inst, $id, $this->admin, $when);
                $this->fail('This should have been refused.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($says, $e->getMessage());
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | When the email does not go
    |--------------------------------------------------------------------------
    */

    public function testAFailedEmailKeepsTheInvitationAndSaysSo(): void
    {
        $this->mailer->failToSend = true;

        $result = $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now);

        $this->assertSame(InvitationService::SEND_FAILED, $result['email_error']);

        $row = $this->row($result['invitation_id']);

        $this->assertNull($row['sent_at'], 'it is not marked as sent');
        $this->assertSame(1, $this->auditCount('invitation.created'));
    }

    public function testAMailerThatThrowsIsTreatedTheSameWay(): void
    {
        $this->mailer->throwOnSend = new RuntimeException('connection refused');

        $result = $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now);

        $this->assertSame(InvitationService::SEND_FAILED, $result['email_error']);
        $this->assertNull($this->row($result['invitation_id'])['sent_at']);
    }

    public function testInDevelopmentTheLinkIsAlsoLogged(): void
    {
        $service = new class () extends InvitationService {
            protected function isDevelopment(): bool
            {
                return true;
            }
        };

        $service->invite($this->institution(), $this->person(), $this->admin, $this->now);

        $this->assertLogged('warning', 'DEVELOPMENT ONLY - invitation link for maria.santos@example.com: ' . site_url('invitation/' . $this->lastToken()));
    }

    public function testOutsideDevelopmentTheLinkIsNotLogged(): void
    {
        $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now);

        $this->assertFalse(TestLogger::didLog('warning', 'DEVELOPMENT ONLY - invitation link for maria.santos@example.com: ' . site_url('invitation/' . $this->lastToken())));
    }

    /*
    |--------------------------------------------------------------------------
    | Sending again
    |--------------------------------------------------------------------------
    */

    public function testResendingGivesANewLinkAndKillsTheOldOne(): void
    {
        $institution = $this->institution();

        $first    = $this->service->invite($institution, $this->person(), $this->admin, $this->now);
        $oldToken = $this->lastToken();

        $again = $this->service->resend($institution, $first['invitation_id'], $this->admin, $this->now->modify('+2 days'));

        $this->assertNull($again['email_error']);
        $this->assertCount(2, $this->mailer->sent);
        $this->assertSame('maria.santos@example.com', $this->mailer->sent[1]['to']);
        $this->assertSame('2026-10-18 10:00:00', $again['expires_at'], 'a fresh period from the day it was re-sent');

        $newToken = $this->lastToken();
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame('maria.santos@example.com', $this->service->open($newToken, $this->now->modify('+2 days'))['email']);
        $this->assertSame(1, $this->auditCount('invitation.resent'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(InvitationService::LINK_REPLACED);

        $this->service->open($oldToken, $this->now->modify('+2 days'));
    }

    public function testAnExpiredInvitationCanBeResent(): void
    {
        $institution = $this->institution();
        $first       = $this->service->invite($institution, $this->person(), $this->admin, $this->now);

        $later = $this->now->modify('+30 days');

        $this->service->resend($institution, $first['invitation_id'], $this->admin, $later);

        $this->assertSame('maria.santos@example.com', $this->service->open($this->lastToken(), $later)['email']);
    }

    /*
    |--------------------------------------------------------------------------
    | A parent invited with the students to link
    |--------------------------------------------------------------------------
    */

    private function student(int $institution, string $first, ?string $number = null): User
    {
        $student = PeopleFactory::person('student', $first, 'Lee', strtolower($first) . '@family.test', $institution);

        if ($number !== null) {
            db_connect()->table('user_profiles')->where('user_id', $student->id)->update(['student_number' => $number]);
        }

        return $student;
    }

    /** @return list<int> */
    private function storedStudents(int $invitationId): array
    {
        return json_decode((string) $this->row($invitationId)['student_ids'], true) ?? [];
    }

    /** Invites a parent and returns the invitation id. */
    private function inviteParent(int $institution, string $students, string $email = 'pam@family.test'): int
    {
        return $this->service->invite($institution, $this->person($email, 'Pam', 'Parent') + ['students' => $students], $this->admin, $this->now, 'parent')['invitation_id'];
    }

    private function links(int $parentId): int
    {
        return db_connect()->table('parent_student_links')->where('parent_user_id', $parentId)->countAllResults();
    }

    public function testAParentInvitationKeepsTheNamedStudentsAndStillSendsTheEmail(): void
    {
        $institution = $this->institution();
        $ann         = $this->student($institution, 'Ann', '2026-001');
        $ben         = $this->student($institution, 'Ben');

        $id = $this->inviteParent($institution, "2026-001\nben@family.test");

        $this->assertEqualsCanonicalizing([(int) $ann->id, (int) $ben->id], $this->storedStudents($id));
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame(0, db_connect()->table('parent_student_links')->countAllResults(), 'nobody is linked before the parent has an account');
    }

    public function testOneUnmatchedNameStopsTheInvitationAndNothingIsSent(): void
    {
        $institution = $this->institution();
        $this->student($institution, 'Ann', '2026-001');

        try {
            $this->inviteParent($institution, "2026-001\n2026-999\nghost@family.test");
            $this->fail('Expected the invitation to be refused.');
        } catch (StructureException $e) {
            $this->assertArrayHasKey('students', $e->errors());
            $this->assertStringContainsString('2026-999', $e->errors()['students']);
            $this->assertStringContainsString('ghost@family.test', $e->errors()['students']);
            $this->assertStringContainsString('Nothing was sent', $e->errors()['students']);
        }

        $this->assertSame(0, db_connect()->table('institution_invitations')->where('role', 'parent')->countAllResults());
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAStudentOfAnotherInstitutionOrANonStudentIsAnUnmatchedName(): void
    {
        $institution = $this->institution();
        $other       = $this->institution('OTHER');
        $this->student($other, 'Zed', '2026-777');
        PeopleFactory::person('instructor', 'Tom', 'Teacher', 'tom@family.test', $institution);

        foreach (['2026-777', 'tom@family.test'] as $name) {
            try {
                $this->inviteParent($institution, $name);
                $this->fail("{$name} should have been refused.");
            } catch (StructureException $e) {
                $this->assertArrayHasKey('students', $e->errors(), $name);
            }
        }

        $this->assertSame([], $this->mailer->sent);
    }

    public function testStudentsCanOnlyBeNamedForAParent(): void
    {
        $institution = $this->institution();
        $this->student($institution, 'Ann', '2026-001');

        $this->expectException(StructureException::class);
        $this->expectExceptionMessage('only be named for a parent');

        $this->service->invite($institution, $this->person('ivy@family.test', 'Ivy', 'Teacher') + ['students' => '2026-001'], $this->admin, $this->now, 'instructor');
    }

    public function testAParentInvitationWithoutStudentsIsAsBefore(): void
    {
        $institution = $this->institution();

        $id = $this->inviteParent($institution, '');

        $this->assertNull($this->row($id)['student_ids']);
        $this->assertCount(1, $this->mailer->sent);
    }

    public function testAcceptingMakesTheAccountAndLinksTheStudentsWithTheAdministratorAsActor(): void
    {
        $institution = $this->institution();
        $ann         = $this->student($institution, 'Ann', '2026-001');
        $ben         = $this->student($institution, 'Ben');
        $id          = $this->inviteParent($institution, "2026-001\nben@family.test");

        $parent = $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $this->assertTrue($parent->inGroup('parent'));
        $this->assertSame(2, $this->links((int) $parent->id));

        $links = db_connect()->table('parent_student_links')->where('parent_user_id', $parent->id)->get()->getResultArray();

        $this->assertEqualsCanonicalizing([(int) $ann->id, (int) $ben->id], array_map('intval', array_column($links, 'student_user_id')));
        $this->assertSame([(int) $this->admin->id], array_values(array_unique(array_map('intval', array_column($links, 'created_by_user_id')))));

        $entries = db_connect()->table('audit_logs')->where('action', 'parent.linked')->get()->getResultArray();

        $this->assertCount(2, $entries);
        $this->assertSame((int) $this->admin->id, (int) $entries[0]['actor_user_id']);
        $this->assertSame('invitation', json_decode((string) $entries[0]['new_values'], true)['via']);
        $this->assertSame($id, json_decode((string) $entries[0]['new_values'], true)['invitation_id']);

        $accepted = db_connect()->table('audit_logs')->where('action', 'invitation.accepted')->get()->getRowArray();

        $this->assertSame(2, json_decode((string) $accepted['new_values'], true)['linked_students']);
    }

    public function testAStudentWhoLeftBeforeAcceptanceIsSkippedAndTheAccountIsStillMade(): void
    {
        $institution = $this->institution();
        $ann         = $this->student($institution, 'Ann', '2026-001');
        $ben         = $this->student($institution, 'Ben');
        $this->inviteParent($institution, "2026-001\nben@family.test");

        db_connect()->table('users')->where('id', $ben->id)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $parent = $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $this->assertSame(1, $this->links((int) $parent->id));
        $this->assertSame((int) $ann->id, (int) db_connect()->table('parent_student_links')->where('parent_user_id', $parent->id)->get()->getRowArray()['student_user_id']);
        $this->assertSame(1, json_decode((string) db_connect()->table('audit_logs')->where('action', 'invitation.accepted')->get()->getRowArray()['new_values'], true)['linked_students']);
    }

    public function testAcceptingTwiceLinksOnce(): void
    {
        $institution = $this->institution();
        $this->student($institution, 'Ann', '2026-001');
        $this->inviteParent($institution, '2026-001');
        $token = $this->lastToken();

        $parent = $this->service->accept($token, self::STRONG, $this->now);

        try {
            $this->service->accept($token, self::OTHER, $this->now);
            $this->fail('The second use of the link should be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(InvitationService::LINK_USED, $e->getMessage());
        }

        $this->assertSame(1, $this->links((int) $parent->id));
    }

    public function testAResendKeepsTheStudentsAndAFreshInvitationReplacesThem(): void
    {
        $institution = $this->institution();
        $ann         = $this->student($institution, 'Ann', '2026-001');
        $ben         = $this->student($institution, 'Ben');
        $first       = $this->inviteParent($institution, '2026-001');

        $resent = $this->service->resend($institution, $first, $this->admin, $this->now)['invitation_id'];

        $this->assertSame([(int) $ann->id], $this->storedStudents($resent));

        $fresh = $this->inviteParent($institution, 'ben@family.test');

        $this->assertSame([(int) $ben->id], $this->storedStudents($fresh));

        $parent = $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $this->assertSame([(int) $ben->id], array_map('intval', array_column(db_connect()->table('parent_student_links')->where('parent_user_id', $parent->id)->get()->getResultArray(), 'student_user_id')), 'only the list sent with the current invitation');
    }

    public function testAnAcceptedParentWithoutStudentsHasNoLinks(): void
    {
        $institution = $this->institution();
        $this->student($institution, 'Ann', '2026-001');
        $this->inviteParent($institution, '');

        $parent = $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $this->assertSame(0, $this->links((int) $parent->id));
        $this->assertSame(0, json_decode((string) db_connect()->table('audit_logs')->where('action', 'invitation.accepted')->get()->getRowArray()['new_values'], true)['linked_students']);
    }

    public function testAnAcceptedInvitationCannotBeResent(): void
    {
        $institution = $this->institution();
        $first       = $this->service->invite($institution, $this->person(), $this->admin, $this->now);
        $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been accepted');

        $this->service->resend($institution, $first['invitation_id'], $this->admin, $this->now);
    }

    public function testAnotherInstitutionsInvitationCannotBeResentThroughThisOne(): void
    {
        $a     = $this->institution('AAA');
        $b     = $this->institution('BBB');
        $other = $this->service->invite($b, $this->person('b@example.com'), $this->admin, $this->now);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->service->resend($a, $other['invitation_id'], $this->admin, $this->now);
    }

    /*
    |--------------------------------------------------------------------------
    | Opening a link
    |--------------------------------------------------------------------------
    */

    public function testALinkIsGoodUpToItsLastSecond(): void
    {
        $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now);
        $token = $this->lastToken();

        $this->assertSame('Institution ACME', $this->service->open($token, new DateTimeImmutable('2026-10-16 09:59:59'))['institution_name']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(InvitationService::LINK_EXPIRED);

        $this->service->open($token, new DateTimeImmutable('2026-10-16 10:00:00'));
    }

    public function testNonsenseAndUnknownLinksAreNotValid(): void
    {
        $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now);

        foreach (['', 'abc', str_repeat('a', 43), str_repeat('A', 43) . 'x'] as $token) {
            try {
                $this->service->open($token, $this->now);
                $this->fail("'{$token}' must not open anything.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame(InvitationService::LINK_INVALID, $e->getMessage());
            }
        }
    }

    public function testTheLinkOfADeletedInstitutionDoesNotWork(): void
    {
        $institution = $this->institution();
        $this->service->invite($institution, $this->person(), $this->admin, $this->now);
        $token = $this->lastToken();

        db_connect()->table('institutions')->where('id', $institution)->update(['deleted_at' => '2026-10-09 11:00:00']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(InvitationService::LINK_INSTITUTION_GONE);

        $this->service->open($token, $this->now);
    }

    public function testTheListShowsEachInvitationsStatusNewestFirst(): void
    {
        $institution = $this->institution();
        $this->service->invite($institution, $this->person('one@example.com'), $this->admin, $this->now);
        $this->service->invite($institution, $this->person('two@example.com'), $this->admin, $this->now);

        $list = $this->service->forInstitution($institution, $this->now);

        $this->assertSame(['two@example.com', 'one@example.com'], array_column($list, 'email'));
        $this->assertSame(['pending', 'revoked'], array_column($list, 'status'));
        $this->assertSame('expired', $this->service->forInstitution($institution, $this->now->modify('+8 days'))[0]['status']);
    }

    /*
    |--------------------------------------------------------------------------
    | Using a link
    |--------------------------------------------------------------------------
    */

    public function testAcceptingMakesTheAccountInTheRightPlace(): void
    {
        $institution = $this->institution();
        $this->service->invite($institution, $this->person(), $this->admin, $this->now);

        $user = $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $this->assertTrue($user->inGroup('institutional_admin'));
        $this->assertTrue($user->active);
        $this->assertSame('maria.santos@example.com', $user->email);
        $this->assertSame('maria.santos', $user->username);

        $this->assertSame(1, db_connect()->table('institution_users')->where(['institution_id' => $institution, 'user_id' => $user->id])->countAllResults());

        $profile = db_connect()->table('user_profiles')->where('user_id', $user->id)->get()->getRowArray();
        $this->assertSame('Maria', $profile['first_name']);
        $this->assertSame('Santos', $profile['last_name']);

        $invitation = db_connect()->table('institution_invitations')->get()->getRowArray();
        $this->assertSame('2026-10-09 10:00:00', $invitation['accepted_at']);
        $this->assertSame((int) $user->id, (int) $invitation['accepted_user_id']);
    }

    public function testThePasswordTheyChoseIsTheOneThatWorks(): void
    {
        $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now);
        $user = $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $stored = model(UserModel::class)->findById($user->id);

        $this->assertTrue(service('passwords')->verify(self::STRONG, $stored->password_hash));
        $this->assertFalse(service('passwords')->verify(self::OTHER, $stored->password_hash));
        $this->assertNotSame(self::STRONG, $stored->password_hash, 'stored hashed');
    }

    public function testALinkCanOnlyBeUsedOnce(): void
    {
        $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now);
        $token = $this->lastToken();

        $this->service->accept($token, self::STRONG, $this->now);

        try {
            $this->service->accept($token, self::OTHER, $this->now);
            $this->fail('A used link must not work again.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(InvitationService::LINK_USED, $e->getMessage());
        }

        $this->assertSame(1, db_connect()->table('institution_users')->countAllResults(), 'no second account');
    }

    public function testAnExpiredLinkMakesNoAccount(): void
    {
        $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now);
        $token = $this->lastToken();

        try {
            $this->service->accept($token, self::STRONG, $this->now->modify('+8 days'));
            $this->fail('An expired link must not work.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(InvitationService::LINK_EXPIRED, $e->getMessage());
        }

        $this->assertNull(model(UserModel::class)->findByCredentials(['email' => 'maria.santos@example.com']));
    }

    public function testAWeakPasswordIsRefusedAndTheLinkStaysUsable(): void
    {
        $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now);
        $token = $this->lastToken();

        foreach (['short', 'password', 'maria.santos'] as $weak) {
            try {
                $this->service->accept($token, $weak, $this->now);
                $this->fail("'{$weak}' should be refused.");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame(InvitationService::LINK_USED, $e->getMessage());
            }
        }

        $this->assertNull(model(UserModel::class)->findByCredentials(['email' => 'maria.santos@example.com']));
        $this->assertSame('maria.santos@example.com', $this->service->open($token, $this->now)['email'], 'still open');

        $this->assertSame('maria.santos@example.com', $this->service->accept($token, self::STRONG, $this->now)->email);
    }

    public function testAnAccountMadeForTheSameAddressInTheMeantimeStopsTheLink(): void
    {
        $this->service->invite($this->institution(), $this->person(), $this->admin, $this->now);
        $token = $this->lastToken();

        // Someone registers that address between the invitation and the click.
        $existing = CourseFactory::user('student');
        db_connect()->table('auth_identities')->insert(['user_id' => $existing->id, 'type' => 'email_password', 'secret' => 'maria.santos@example.com', 'secret2' => 'x', 'created_at' => date('Y-m-d H:i:s')]);

        try {
            $this->service->accept($token, self::STRONG, $this->now);
            $this->fail('The address now belongs to another account.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->assertNull(db_connect()->table('institution_invitations')->get()->getRowArray()['accepted_at']);
    }

    public function testDeliveringAnInvitationThatDoesNotExistReportsAFailure(): void
    {
        $this->assertSame(InvitationService::SEND_FAILED, $this->service->deliver(999999, str_repeat('a', 43), $this->now));
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAcceptingIsRecordedInTheAuditLogAgainstTheNewUser(): void
    {
        $institution = $this->institution();
        $this->service->invite($institution, $this->person(), $this->admin, $this->now);
        $user = $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $entry = db_connect()->table('audit_logs')->where('action', 'invitation.accepted')->get()->getRowArray();

        $this->assertSame((int) $user->id, (int) $entry['actor_user_id']);
        $this->assertSame($institution, (int) $entry['institution_id']);
        $this->assertSame('invitation', $entry['target_type']);
        $this->assertStringNotContainsString(self::STRONG, json_encode($entry));
    }

    public function testUsernamesAreMadeUniqueAndStayWithinWhatShieldAllows(): void
    {
        $a = $this->institution('AAA');
        $b = $this->institution('BBB');

        $this->service->invite($a, $this->person('first@example.com'), $this->admin, $this->now);
        $one = $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $this->service->invite($b, $this->person('second@example.com'), $this->admin, $this->now);
        $two = $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $this->assertSame('maria.santos', $one->username);
        $this->assertSame('maria.santos1', $two->username);

        $c = $this->institution('CCC');
        $this->service->invite($c, $this->person('long@example.com', 'José Maria-Luisa', 'de la Peña y Fernández-Villanueva'), $this->admin, $this->now);
        $three = $this->service->accept($this->lastToken(), self::STRONG, $this->now);

        $this->assertMatchesRegularExpression('/^[a-z0-9.]{3,30}$/', $three->username);
    }
}
