<?php

declare(strict_types=1);

use App\Services\Users\AccountControl;
use App\Services\Users\PasswordResetService;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * Password resets an administrator starts: the emailed link, its expiry and
 * single use, what using it does (the password, the sessions, the record), and
 * what is refused. The mailer is a fake and the time is passed in.
 *
 * @internal
 */
final class PasswordResetServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const STRONG = 'Tr4ins-Lantern-Moss-92';

    private const OTHER = 'An0ther-Str0ng-Phrase-77';

    private PasswordResetService $service;

    private FakeEmail $mailer;

    private User $admin;

    private User $person;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        $this->service = new PasswordResetService();
        $this->mailer  = FakeEmail::install();
        $this->admin   = PeopleFactory::person('admin', 'Ada', 'Admin', 'ada.admin@example.com');
        $this->person  = PeopleFactory::person('instructor', 'Ivo', 'Teacher', 'ivo@example.com', PeopleFactory::institution('ACME'));
        $this->now     = new DateTimeImmutable('2026-10-09 10:00:00');

        db_connect()->table('audit_logs')->emptyTable();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');

        parent::tearDown();
    }

    private function lastToken(): string
    {
        $last = end($this->mailer->sent);

        $this->assertNotFalse($last, 'an email should have been sent');
        $this->assertSame(1, preg_match('#/reset-password/([A-Za-z0-9_-]{43})#', $last['message'], $match));

        return $match[1];
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return db_connect()->table('password_resets')->orderBy('id', 'desc')->get()->getRowArray();
    }

    private function refused(callable $call, string $mentions): void
    {
        try {
            $call();
            $this->fail('This should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($mentions, $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    public function testSendingEmailsALinkAndRecordsIt(): void
    {
        $result = $this->service->send((int) $this->person->id, $this->admin, $this->now);

        $this->assertNull($result['email_error']);
        $this->assertSame('ivo@example.com', $result['email']);
        $this->assertSame('2026-10-10 10:00:00', $result['expires_at'], 'a day by default');

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('ivo@example.com', $this->mailer->sent[0]['to']);

        $row = $this->row();

        $this->assertSame((int) $this->person->id, (int) $row['user_id']);
        $this->assertSame('2026-10-09 10:00:00', $row['sent_at']);
        $this->assertNull($row['used_at']);

        $entry = db_connect()->table('audit_logs')->where('action', 'user.password_reset_sent')->get()->getRowArray();

        $this->assertSame((int) $this->admin->id, (int) $entry['actor_user_id']);
        $this->assertSame((int) $this->person->id, (int) $entry['target_id']);
    }

    public function testOnlyTheHashOfTheSecretIsStoredAndItIsNotInTheAuditLog(): void
    {
        $this->service->send((int) $this->person->id, $this->admin, $this->now);
        $token = $this->lastToken();

        $this->assertSame(hash('sha256', $token), $this->row()['token_hash']);
        $this->assertStringNotContainsString($token, json_encode(db_connect()->table('audit_logs')->get()->getResultArray()));
        $this->assertStringNotContainsString($token, implode('|', array_map('strval', $this->row())));
    }

    public function testTheLinkLastsAsLongAsTheSettingSays(): void
    {
        (new App\Services\Admin\PlatformSettings())->set('passwordResetValidityHours', 2);

        $result = $this->service->send((int) $this->person->id, $this->admin, $this->now);

        $this->assertSame('2026-10-09 12:00:00', $result['expires_at']);
    }

    public function testASecondLinkCancelsTheFirst(): void
    {
        $this->service->send((int) $this->person->id, $this->admin, $this->now);
        $first = $this->lastToken();

        $this->service->send((int) $this->person->id, $this->admin, $this->now->modify('+1 hour'));

        $this->refused(fn () => $this->service->open($first, $this->now), PasswordResetService::LINK_REPLACED);
        $this->assertSame('ivo@example.com', $this->service->open($this->lastToken(), $this->now)['email']);
    }

    public function testAFailedEmailIsReportedAndTheLinkCanBeSentAgain(): void
    {
        $this->mailer->failToSend = true;

        $result = $this->service->send((int) $this->person->id, $this->admin, $this->now);

        $this->assertSame(PasswordResetService::SEND_FAILED, $result['email_error']);
        $this->assertNull($this->row()['sent_at']);
    }

    public function testADeactivatedAccountCannotBeSentOne(): void
    {
        (new AccountControl())->deactivate((int) $this->person->id, $this->now);

        $this->refused(fn () => $this->service->send((int) $this->person->id, $this->admin, $this->now), 'deactivated');
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAnUnknownAccountCannotBeSentOne(): void
    {
        $this->refused(fn () => $this->service->send(999999, $this->admin, $this->now), 'does not exist');
    }

    public function testAnAccountWithNoEmailCannotBeSentOne(): void
    {
        db_connect()->table('auth_identities')->where('user_id', $this->person->id)->delete();

        $this->refused(fn () => $this->service->send((int) $this->person->id, $this->admin, $this->now), 'no email address');
    }

    /*
    |--------------------------------------------------------------------------
    | Opening the link
    |--------------------------------------------------------------------------
    */

    public function testTheLinkWorksUpToItsLastSecond(): void
    {
        $this->service->send((int) $this->person->id, $this->admin, $this->now);
        $token = $this->lastToken();

        $this->assertSame('ivo@example.com', $this->service->open($token, new DateTimeImmutable('2026-10-10 09:59:59'))['email']);

        $this->refused(fn () => $this->service->open($token, new DateTimeImmutable('2026-10-10 10:00:00')), PasswordResetService::LINK_EXPIRED);
    }

    public function testNonsenseAndUnknownLinksAreNotValid(): void
    {
        $this->service->send((int) $this->person->id, $this->admin, $this->now);

        foreach (['', 'abc', str_repeat('a', 43), str_repeat('z', 44)] as $token) {
            $this->refused(fn () => $this->service->open($token, $this->now), PasswordResetService::LINK_INVALID);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Using it
    |--------------------------------------------------------------------------
    */

    public function testCompletingSetsThePasswordTheyChose(): void
    {
        $this->service->send((int) $this->person->id, $this->admin, $this->now);

        $this->service->complete($this->lastToken(), self::STRONG, $this->now->modify('+1 hour'));

        $stored = model(UserModel::class)->findById($this->person->id);

        $this->assertTrue(service('passwords')->verify(self::STRONG, $stored->password_hash));
        $this->assertSame('2026-10-09 11:00:00', $this->row()['used_at']);
    }

    public function testCompletingEndsEveryOtherSessionTheyHad(): void
    {
        $this->service->send((int) $this->person->id, $this->admin, $this->now);

        $this->service->complete($this->lastToken(), self::STRONG, $this->now->modify('+1 hour'));

        $control = new AccountControl();

        $this->assertSame(AccountControl::REASON_SIGNED_OUT, $control->blockReason((int) $this->person->id, strtotime('2026-10-09 09:00:00')));
        $this->assertNull($control->blockReason((int) $this->person->id, strtotime('2026-10-09 11:00:01')), 'but signing in again works');
    }

    public function testTheLinkWorksOnce(): void
    {
        $this->service->send((int) $this->person->id, $this->admin, $this->now);
        $token = $this->lastToken();

        $this->service->complete($token, self::STRONG, $this->now);

        $this->refused(fn () => $this->service->complete($token, self::OTHER, $this->now), PasswordResetService::LINK_USED);
    }

    public function testAnExpiredLinkChangesNothing(): void
    {
        $this->service->send((int) $this->person->id, $this->admin, $this->now);
        $before = model(UserModel::class)->findById($this->person->id)->password_hash;

        $this->refused(fn () => $this->service->complete($this->lastToken(), self::STRONG, $this->now->modify('+2 days')), PasswordResetService::LINK_EXPIRED);

        $this->assertSame($before, model(UserModel::class)->findById($this->person->id)->password_hash);
    }

    public function testAWeakPasswordIsRefusedAndTheLinkStaysUsable(): void
    {
        $this->service->send((int) $this->person->id, $this->admin, $this->now);
        $token = $this->lastToken();

        foreach (['short', 'password', 'ivo@example.com'] as $weak) {
            try {
                $this->service->complete($token, $weak, $this->now);
                $this->fail("'{$weak}' should be refused.");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame(PasswordResetService::LINK_USED, $e->getMessage());
            }
        }

        $this->assertNull($this->row()['used_at']);
        $this->assertSame('ivo@example.com', $this->service->open($token, $this->now)['email']);
    }

    public function testUsingTheLinkIsRecordedAgainstThePerson(): void
    {
        $this->service->send((int) $this->person->id, $this->admin, $this->now);
        $this->service->complete($this->lastToken(), self::STRONG, $this->now);

        $entry = db_connect()->table('audit_logs')->where('action', 'user.password_reset')->get()->getRowArray();

        $this->assertSame((int) $this->person->id, (int) $entry['actor_user_id']);
        $this->assertSame((int) $this->person->id, (int) $entry['target_id']);
        $this->assertStringNotContainsString(self::STRONG, json_encode($entry));
    }

    public function testInDevelopmentTheLinkIsAlsoLogged(): void
    {
        $service = new class () extends PasswordResetService {
            protected function isDevelopment(): bool
            {
                return true;
            }
        };

        $service->send((int) $this->person->id, $this->admin, $this->now);

        $this->assertLogged('warning', 'DEVELOPMENT ONLY - password reset link for ivo@example.com: ' . site_url('reset-password/' . $this->lastToken()));
    }
}
