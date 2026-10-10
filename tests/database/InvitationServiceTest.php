<?php

declare(strict_types=1);

use App\Services\Admin\PlatformSettings;
use App\Services\Institutions\InvitationService;
use App\Services\Institutions\InvitationStatus;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\TestLogger;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;

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
