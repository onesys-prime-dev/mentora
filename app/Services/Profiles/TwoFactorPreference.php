<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Services\Concerns\InTransaction;
use App\Services\Institutions\InstitutionSecurity;
use CodeIgniter\Shield\Entities\User;

/**
 * Emailed two-step sign-in from Settings (D14). It is on when the school requires it for one of the person's
 * roles (InstitutionSecurity) or when they opted in (user_profiles.two_factor_opt_in); AdminEmail2FA asks for the
 * code whenever it is on. A requirement cannot be turned off, and turning an opt-in off needs the password,
 * with the same attempt limit as a password change. Each change is audit-logged without values and emailed (D16).
 *
 * A refusal is a ProfileEditException on the `two_factor_password` field.
 */
class TwoFactorPreference
{
    use InTransaction;

    public const FIELD = 'two_factor_password';

    /** @return array{on: bool, required: bool, email_masked: string} */
    public function status(User $user): array
    {
        $required = $this->required($user);

        return [
            'on'           => $required || $this->optedIn($user),
            'required'     => $required,
            'email_masked' => self::mask((string) $user->email),
        ];
    }

    /** Whether a sign-in code is asked for: the requirement first, so the flag is read only when it matters. */
    public function applies(User $user): bool
    {
        return $this->required($user) || $this->optedIn($user);
    }

    /** @throws ProfileEditException when the account has no profile row to keep the choice on */
    public function optIn(User $user): void
    {
        if ($this->required($user) || $this->optedIn($user)) {
            return;
        }

        $this->save($user, 1, 'profile.two_factor_on');
        $this->notify($user, 'Two-step sign-in was turned on for your Mentora account', 'turned on. From now on, after your password we email you a code to finish signing in');
    }

    /** @throws ProfileEditException */
    public function optOut(User $user, #[\SensitiveParameter] string $password): void
    {
        if ($this->required($user)) {
            throw new ProfileEditException('Your school requires two-step sign-in for your role.', self::FIELD);
        }

        if (! service('throttler')->check('two-factor-off-' . $user->id, PasswordChangeService::ATTEMPTS, PasswordChangeService::WINDOW)) {
            throw new ProfileEditException(PasswordChangeService::TOO_MANY, self::FIELD);
        }

        // Only a password check, like a password change: a re-authentication would also unlock administrator pages.
        if ($password === '' || ! auth('session')->check(['email' => (string) $user->email, 'password' => $password])->isOK()) {
            throw new ProfileEditException('Your password is not right.', self::FIELD);
        }

        if (! $this->optedIn($user)) {
            return;
        }

        $this->save($user, 0, 'profile.two_factor_off');
        $this->notify($user, 'Two-step sign-in was turned off for your Mentora account', 'turned off. Signing in now needs your password only');
    }

    /** "maria@school.edu" becomes "m•••@school.edu". */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false || $at === 0 ? '' : mb_substr($email, 0, 1) . '•••' . substr($email, $at);
    }

    private function required(User $user): bool
    {
        return $user->inGroup(...(new InstitutionSecurity())->forUser($user)['twoFactorRoles']);
    }

    private function optedIn(User $user): bool
    {
        return (int) db_connect()->table('user_profiles')->select('two_factor_opt_in')->where('user_id', $user->id)->get()->getRow('two_factor_opt_in') === 1;
    }

    private function save(User $user, int $value, string $action): void
    {
        // The choice and its audit entry are saved together or not at all.
        $this->inTransaction(static function () use ($user, $value, $action): void {
            $db = db_connect();
            $db->table('user_profiles')->where('user_id', $user->id)->update(['two_factor_opt_in' => $value, 'updated_at' => date('Y-m-d H:i:s')]);

            if ($db->affectedRows() < 1) {
                throw new ProfileEditException('We could not save this choice. Ask your school to finish setting up your account.', self::FIELD);
            }

            service('auditLogger')->log($action, $user, ['target_type' => 'user', 'target_id' => (int) $user->id]);
        });
    }

    /** Tells the owner by email (D16). */
    private function notify(User $user, string $subject, string $what): void
    {
        SecurityNotice::send($user, $subject, "Two-step sign-in for your Mentora account ({$user->email}) was {$what}. This happened on " . date('M j, Y \a\t g:i A') . ".\n\n" . SecurityNotice::ifNotYou());
    }
}
