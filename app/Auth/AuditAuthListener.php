<?php

declare(strict_types=1);

namespace App\Auth;

use App\Filters\AccountStateFilter;
use App\Services\Audit\AuditLogger;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Throwable;

/**
 * Turns Shield's sign-in events into audit entries (registered in
 * Config\Events). Writing the audit entry must never get in the way of
 * signing in or out, so a failure is logged to the file log and swallowed.
 *
 * `login` fires only when sign-in is complete, so for an administrator it
 * comes after the emailed code, not after the password.
 */
class AuditAuthListener
{
    public static function login(User $user): void
    {
        // When this session began: "sign out everywhere" ends every session that began before it.
        session()->set(AccountStateFilter::SIGNED_IN_AT, time());

        self::record(static fn (AuditLogger $audit) => $audit->log('auth.login', $user, [
            'target_type' => 'user',
            'target_id'   => $user->id,
        ]));
    }

    /**
     * @param array<string, mixed> $credentials the attempt; the password is already removed by Shield and is never read here
     */
    public static function failedLogin(array $credentials): void
    {
        $email = isset($credentials['email']) ? strtolower(trim((string) $credentials['email'])) : null;

        self::record(static function (AuditLogger $audit) use ($email): void {
            $known = $email === null ? null : model(UserModel::class)->findByCredentials(['email' => $email]);

            $audit->log('auth.login_failed', null, [
                'actor_label' => $email,
                'target_type' => $known !== null ? 'user' : null,
                'target_id'   => $known?->id,
            ]);
        });
    }

    public static function logout(?User $user): void
    {
        if ($user === null) {
            return;
        }

        self::record(static fn (AuditLogger $audit) => $audit->log('auth.logout', $user, [
            'target_type' => 'user',
            'target_id'   => $user->id,
        ]));
    }

    /** @param callable(AuditLogger): mixed $write */
    private static function record(callable $write): void
    {
        try {
            $write(service('auditLogger'));
        } catch (Throwable $e) {
            log_message('error', 'Audit log write failed: ' . $e->getMessage());
        }
    }
}
