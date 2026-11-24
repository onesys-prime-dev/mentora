<?php

declare(strict_types=1);

use App\Services\Institutions\InstitutionSecurity;
use App\Services\Profiles\PasswordChangeService;
use App\Services\Profiles\ProfileEditException;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * Changing your own password from Settings (D13): what is refused and why, the attempt limit, and what a
 * change does (the hash, the other sessions, the audit entry, the email notice).
 *
 * @internal
 */
final class PasswordChangeServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const CURRENT = 'Tr4ins-Lantern-Moss-92';

    private const NEW = 'An0ther-Str0ng-Phrase-77';

    private PasswordChangeService $service;

    private FakeEmail $mailer;

    private int $institution;

    private User $ivo;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        Services::resetSingle('throttler');

        $this->service     = new PasswordChangeService();
        $this->mailer      = FakeEmail::install();
        $this->institution = PeopleFactory::institution('PWD');
        $this->ivo         = $this->withPassword(PeopleFactory::person('instructor', 'Ivo', 'Teacher', 'ivo@example.com', $this->institution));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        Services::resetSingle('throttler');

        parent::tearDown();
    }

    private function withPassword(User $user, string $password = self::CURRENT): User
    {
        db_connect()->table('auth_identities')->where(['user_id' => $user->id, 'type' => 'email_password'])->update(['secret2' => password_hash($password, PASSWORD_DEFAULT)]);

        return model(UserModel::class)->findById($user->id);
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

    private function hash(User $user): string
    {
        return (string) db_connect()->table('auth_identities')->where(['user_id' => $user->id, 'type' => 'email_password'])->get()->getRow('secret2');
    }

    public function testAWrongCurrentPasswordIsRefused(): void
    {
        $before = $this->hash($this->ivo);

        $this->refused(fn () => $this->service->change($this->ivo, 'not-my-password', self::NEW, self::NEW), 'current_password', 'current password');

        $this->assertSame($before, $this->hash($this->ivo), 'nothing changed');
        $this->assertSame([], $this->mailer->sent);
    }

    public function testTheTwoNewPasswordsMustMatch(): void
    {
        $this->refused(fn () => $this->service->change($this->ivo, self::CURRENT, self::NEW, self::NEW . 'x'), 'password_confirm', 'do not match');
    }

    public function testTheNewPasswordMustDifferFromTheCurrentOne(): void
    {
        $this->refused(fn () => $this->service->change($this->ivo, self::CURRENT, self::CURRENT, self::CURRENT), 'password', 'different');
    }

    public function testTheInstitutionsMinimumLengthApplies(): void
    {
        (new InstitutionSecurity())->save($this->institution, ['passwordMinLength' => '12']);

        $this->refused(fn () => $this->service->change($this->ivo, self::CURRENT, 'Qz7#vLp2wX', 'Qz7#vLp2wX'), 'password', 'at least 12 characters');
    }

    public function testASystemAdministratorGetsThePlatformMinimum(): void
    {
        (new InstitutionSecurity())->save($this->institution, ['passwordMinLength' => '12']);
        $admin = $this->withPassword(PeopleFactory::person('admin', 'Sys', 'Admin', 'sys@mentora.example'));

        $this->refused(fn () => $this->service->change($admin, self::CURRENT, 'Qz7#vLp', 'Qz7#vLp'), 'password', 'at least 8 characters');

        $this->service->change($admin, self::CURRENT, 'Qz7#vLp2wX', 'Qz7#vLp2wX');

        $this->assertTrue(password_verify('Qz7#vLp2wX', $this->hash($admin)), 'ten characters are enough without an institution');
    }

    public function testTheSixthAttemptIsRefusedEvenWithTheRightPassword(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->refused(fn () => $this->service->change($this->ivo, 'wrong-' . $i, self::NEW, self::NEW), 'current_password', 'current password');
        }

        $this->refused(fn () => $this->service->change($this->ivo, self::CURRENT, self::NEW, self::NEW), 'current_password', 'Too many attempts. Try again in 15 minutes.');

        $this->assertTrue(password_verify(self::CURRENT, $this->hash($this->ivo)), 'still the old password');
    }

    public function testTheLimitIsPerPerson(): void
    {
        for ($i = 0; $i < 6; $i++) {
            try {
                $this->service->change($this->ivo, 'wrong', self::NEW, self::NEW);
            } catch (ProfileEditException) {
            }
        }

        $ann = $this->withPassword(PeopleFactory::person('student', 'Ann', 'Lee', 'ann@example.com', $this->institution));
        $this->service->change($ann, self::CURRENT, self::NEW, self::NEW);

        $this->assertTrue(password_verify(self::NEW, $this->hash($ann)));
    }

    public function testASuccessfulChangeSetsThePasswordEndsSessionsLogsAndEmails(): void
    {
        $this->service->change($this->ivo, self::CURRENT, self::NEW, self::NEW);

        $hash = $this->hash($this->ivo);
        $this->assertTrue(password_verify(self::NEW, $hash));
        $this->assertFalse(password_verify(self::CURRENT, $hash));
        $this->assertTrue(auth('session')->check(['email' => 'ivo@example.com', 'password' => self::NEW])->isOK());

        $validFrom = db_connect()->table('user_account_controls')->where('user_id', $this->ivo->id)->get()->getRow('sessions_valid_from');
        $this->assertNotNull($validFrom, 'every session is ended');
        $this->assertLessThanOrEqual(2, abs(time() - strtotime((string) $validFrom)));

        $entry = db_connect()->table('audit_logs')->where('action', 'profile.password_changed')->get()->getRowArray();
        $this->assertNotNull($entry);
        $this->assertSame((int) $this->ivo->id, (int) $entry['actor_user_id']);
        $this->assertSame((int) $this->ivo->id, (int) $entry['target_id']);
        foreach ([self::NEW, self::CURRENT, '$2y$', 'argon'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($entry), 'no password in the audit entry');
        }

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('ivo@example.com', $this->mailer->sent[0]['to']);
        $this->assertSame('Your Mentora password was changed', $this->mailer->sent[0]['subject']);
        $this->assertStringContainsString("If this wasn't you, reset your password", $this->mailer->sent[0]['message']);
        $this->assertStringNotContainsString(self::NEW, $this->mailer->sent[0]['message']);
    }

    public function testAFailedNoticeDoesNotUndoTheChange(): void
    {
        $this->mailer->failToSend = true;

        $this->service->change($this->ivo, self::CURRENT, self::NEW, self::NEW);

        $this->assertTrue(password_verify(self::NEW, $this->hash($this->ivo)));
    }
}
