<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Services\Concerns\InTransaction;
use App\Services\Security\PasswordPolicy;
use App\Services\Users\AccountControl;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use RuntimeException;
use Throwable;

/**
 * Changing your own password from Settings (D13), for every role.
 *
 * Every attempt counts against a limit of ATTEMPTS per WINDOW seconds per person, so the current-password check
 * cannot be used to guess. A change ends every session the account has (AccountControl); the caller keeps its own
 * session alive afterwards. It is audit-logged without any password and the owner is emailed a notice.
 *
 * A refusal is a ProfileEditException whose message is fit to show and whose field names the form input.
 */
class PasswordChangeService
{
    use InTransaction;

    public const ATTEMPTS = 5;

    public const WINDOW = 900;

    public const TOO_MANY = 'Too many attempts. Try again in a few minutes.';

    /**
     * @throws ProfileEditException
     * @throws Throwable           whatever stopped the save or the audit entry; nothing is changed then
     */
    public function change(User $user, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new, #[\SensitiveParameter] string $confirm): void
    {
        if (! service('throttler')->check('password-change-' . $user->id, self::ATTEMPTS, self::WINDOW)) {
            throw new ProfileEditException(self::TOO_MANY, 'current_password');
        }

        if ($current === '' || ! auth('session')->check(['email' => (string) $user->email, 'password' => $current])->isOK()) {
            throw new ProfileEditException('Your current password is not right.', 'current_password');
        }

        if ($new !== $confirm) {
            throw new ProfileEditException('The two new passwords do not match.', 'password_confirm');
        }

        if ($new === $current) {
            throw new ProfileEditException('Choose a password different from your current one.', 'password');
        }

        // The same rules as the reset link: the minimum in force, then Shield's checks.
        $problem = (new PasswordPolicy())->problem($new, $user);

        if ($problem !== null) {
            throw new ProfileEditException($problem, 'password');
        }

        $this->inTransaction(static function () use ($user, $new): void {
            $user->password = $new;

            if (! model(UserModel::class)->save($user)) {
                throw new RuntimeException('The password could not be saved.');
            }

            (new AccountControl())->signOutEverywhere((int) $user->id);

            service('auditLogger')->log('profile.password_changed', $user, ['target_type' => 'user', 'target_id' => (int) $user->id]);
        });

        $this->notify($user);
    }

    /** Tells the owner by email (D16). */
    private function notify(User $user): void
    {
        SecurityNotice::send(
            $user,
            'Your Mentora password was changed',
            "The password for your Mentora account ({$user->email}) was changed on " . date('M j, Y \a\t g:i A') . ". Every other device signed in to your account was signed out.\n\n" . SecurityNotice::ifNotYou(),
        );
    }
}
