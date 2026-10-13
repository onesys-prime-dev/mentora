<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Institutions\InstitutionSecurity;
use DateTimeImmutable;

/**
 * Locks an account's sign-in after too many failed attempts in a row (SA8).
 *
 * It reads Shield's own record of attempts (auth_logins): the failures for an
 * email since that email's last success. When there are as many as the
 * setting allows, sign-in for that email is refused until the lockout length
 * has passed since the latest failure. A correct password during a lockout is
 * refused too, so guessing cannot continue behind it. Locking is per email, so
 * someone else's account can be locked by typing it wrongly: that is the
 * trade this setting makes, and 0 attempts turns it off.
 */
class LoginLockout
{
    /** When the lockout for this email ends, or null when it is not locked. */
    public function lockedUntil(string $email, ?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $now ??= new DateTimeImmutable();
        $email = strtolower(trim($email));

        // The stricter of the platform's and the account's institution's.
        $rules    = (new InstitutionSecurity())->forEmail($email);
        $attempts = (int) $rules['lockoutAttempts'];

        if ($attempts <= 0 || $email === '') {
            return null;
        }

        $db = db_connect();

        $base = static fn () => $db->table('auth_logins')->where('id_type', 'email_password')->where('identifier', $email);

        $lastSuccess = $base()->where('success', 1)->selectMax('date')->get()->getRowArray()['date'] ?? null;

        $failures = $base()->where('success', 0);

        if ($lastSuccess !== null) {
            $failures->where('date >', $lastSuccess);
        }

        $rows = $failures->orderBy('date', 'desc')->limit($attempts)->get()->getResultArray();

        if (count($rows) < $attempts) {
            return null;
        }

        $until = (new DateTimeImmutable($rows[0]['date']))->modify('+' . (int) $rules['lockoutMinutes'] . ' minutes');

        return $until > $now ? $until : null;
    }
}
