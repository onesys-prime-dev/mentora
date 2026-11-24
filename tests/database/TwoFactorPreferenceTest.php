<?php

declare(strict_types=1);

use App\Auth\Actions\AdminEmail2FA;
use App\Services\Institutions\InstitutionSecurity;
use App\Services\Profiles\ProfileEditException;
use App\Services\Profiles\TwoFactorPreference;
use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\DatabaseTestCase;
use Tests\Support\FakeEmail;
use Tests\Support\PeopleFactory;

/**
 * Turning emailed two-step sign-in on and off from Settings (D14): a school requirement cannot be turned off,
 * turning it off needs the password (throttled), and each change is audited without values and emailed (D16).
 *
 * @internal
 */
final class TwoFactorPreferenceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const PASSWORD = 'Tr4ins-Lantern-Moss-92';

    private TwoFactorPreference $service;

    private FakeEmail $mailer;

    private int $institution;

    private User $ivo;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        Services::resetSingle('throttler');

        $this->service     = new TwoFactorPreference();
        $this->mailer      = FakeEmail::install();
        $this->institution = PeopleFactory::institution('TFA');
        $this->ivo         = $this->withPassword(PeopleFactory::person('instructor', 'Ivo', 'Teacher', 'maria@school.edu', $this->institution));
    }

    protected function tearDown(): void
    {
        Services::resetSingle('email');
        Services::resetSingle('auditLogger');
        Services::resetSingle('settings');
        Services::resetSingle('throttler');

        parent::tearDown();
    }

    private function withPassword(User $user): User
    {
        db_connect()->table('auth_identities')->where(['user_id' => $user->id, 'type' => 'email_password'])->update(['secret2' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);

        return model(UserModel::class)->findById($user->id);
    }

    private function requireForInstructors(): void
    {
        (new InstitutionSecurity())->save($this->institution, ['twoFactorRoles' => ['instructor']]);
    }

    private function refused(callable $call, string $mentions): void
    {
        try {
            $call();
            $this->fail('This should have been refused.');
        } catch (ProfileEditException $e) {
            $this->assertSame('two_factor_password', $e->field);
            $this->assertStringContainsString($mentions, $e->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    private function audits(string $action): array
    {
        return db_connect()->table('audit_logs')->where('action', $action)->get()->getResultArray();
    }

    public function testStatusWhenOff(): void
    {
        $this->assertSame(['on' => false, 'required' => false, 'email_masked' => 'm•••@school.edu'], $this->service->status($this->ivo));
        $this->assertFalse((new AdminEmail2FA())->appliesTo($this->ivo));
    }

    public function testOptingInTurnsTheCodeStepOnAuditsAndEmails(): void
    {
        $this->service->optIn($this->ivo);

        $this->assertTrue($this->service->status($this->ivo)['on']);
        $this->assertFalse($this->service->status($this->ivo)['required']);
        $this->assertTrue((new AdminEmail2FA())->appliesTo($this->ivo));

        $entries = $this->audits('profile.two_factor_on');
        $this->assertCount(1, $entries);
        $this->assertSame((int) $this->ivo->id, (int) $entries[0]['actor_user_id']);
        $this->assertStringNotContainsString('two_factor_opt_in', json_encode($entries[0]), 'no old/new values');

        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('maria@school.edu', $this->mailer->sent[0]['to']);
        $this->assertStringContainsString('turned on', $this->mailer->sent[0]['subject']);
    }

    public function testOptingInTwiceChangesNothingTheSecondTime(): void
    {
        $this->service->optIn($this->ivo);
        $this->service->optIn($this->ivo);

        $this->assertCount(1, $this->audits('profile.two_factor_on'));
        $this->assertCount(1, $this->mailer->sent);
    }

    public function testOptingOutWithThePasswordTurnsItOff(): void
    {
        $this->service->optIn($this->ivo);
        $this->service->optOut($this->ivo, self::PASSWORD);

        $this->assertFalse($this->service->status($this->ivo)['on']);
        $this->assertFalse((new AdminEmail2FA())->appliesTo($this->ivo));

        $entries = $this->audits('profile.two_factor_off');
        $this->assertCount(1, $entries);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($entries[0]));
        $this->assertCount(2, $this->mailer->sent);
        $this->assertStringContainsString('turned off', $this->mailer->sent[1]['subject']);
        $this->assertStringNotContainsString(self::PASSWORD, $this->mailer->sent[1]['message']);
    }

    public function testAWrongPasswordIsRefusedAndTheSixthAttemptIsThrottled(): void
    {
        $this->service->optIn($this->ivo);

        for ($i = 0; $i < 5; $i++) {
            $this->refused(fn () => $this->service->optOut($this->ivo, 'wrong-' . $i), 'password is not right');
        }

        $this->refused(fn () => $this->service->optOut($this->ivo, self::PASSWORD), 'Too many attempts. Try again in a few minutes.');

        $this->assertTrue($this->service->status($this->ivo)['on'], 'still on');
        $this->assertSame([], $this->audits('profile.two_factor_off'));
    }

    public function testARequiredUserCannotOptOut(): void
    {
        $this->requireForInstructors();

        $this->assertSame(['on' => true, 'required' => true, 'email_masked' => 'm•••@school.edu'], $this->service->status($this->ivo));

        $this->refused(fn () => $this->service->optOut($this->ivo, self::PASSWORD), 'Your school requires two-step sign-in for your role.');

        $this->assertTrue((new AdminEmail2FA())->appliesTo($this->ivo));
        $this->assertSame([], $this->audits('profile.two_factor_off'));
        $this->assertSame([], $this->mailer->sent);
    }

    public function testOptedInThenRequiredStaysOnAndCannotBeTurnedOff(): void
    {
        $this->service->optIn($this->ivo);
        $this->requireForInstructors();

        $this->refused(fn () => $this->service->optOut($this->ivo, self::PASSWORD), 'Your school requires');
        $this->assertTrue((new AdminEmail2FA())->appliesTo($this->ivo));
    }
    public function testOptingInWhenTheSchoolAlreadyRequiresItChangesNothing(): void
    {
        $this->requireForInstructors();

        $this->service->optIn($this->ivo);

        $this->assertSame(0, (int) db_connect()->table('user_profiles')->where('user_id', $this->ivo->id)->get()->getRow('two_factor_opt_in'));
        $this->assertSame([], $this->audits('profile.two_factor_on'));
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAnAccountWithNoProfileIsRefusedClearlyAndNothingIsLogged(): void
    {
        db_connect()->table('user_profiles')->where('user_id', $this->ivo->id)->delete();

        $this->refused(fn () => $this->service->optIn($this->ivo), 'Ask your school to finish setting up your account.');

        $this->assertSame([], $this->audits('profile.two_factor_on'));
        $this->assertSame([], $this->mailer->sent);
    }

    public function testTheRightPasswordDoesNotAlsoUnlockAdministratorPages(): void
    {
        $this->service->optIn($this->ivo);
        $this->service->optOut($this->ivo, self::PASSWORD);

        $this->assertNull(session('reauth'), 'only a password check, not a re-authentication');
    }
}
