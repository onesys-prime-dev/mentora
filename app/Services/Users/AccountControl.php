<?php

declare(strict_types=1);

namespace App\Services\Users;

use DateTimeImmutable;

/**
 * Switching accounts off, and ending sessions, in a way that takes effect on
 * the very next click (SA5).
 *
 * Shield checks whether an account is banned only while it signs in, and never
 * looks again once someone is in, so neither "deactivate" nor "sign out
 * everywhere" would reach a person who already has a session. The two facts
 * kept here (see the user_account_controls table) are checked on every
 * request by the `accountstate` filter instead.
 *
 * `users.active` is kept in step because the seat count reads it: a
 * deactivated student frees a seat.
 *
 * Nothing here decides whether an action is allowed (who may deactivate whom
 * is UserAdminService's job); it only does it and tells the filter.
 */
class AccountControl
{
    public const REASON_DEACTIVATED = 'deactivated';

    public const REASON_SIGNED_OUT = 'signed_out';

    /** Switches an account off and ends its sessions. */
    public function deactivate(int $userId, ?DateTimeImmutable $now = null): void
    {
        $stamp = $this->stamp($now);

        db_connect()->table('users')->where('id', $userId)->update(['active' => 0]);

        $this->write($userId, ['deactivated_at' => $stamp, 'sessions_valid_from' => $stamp], $stamp);
    }

    /** Switches an account back on. Sessions ended earlier stay ended. */
    public function reactivate(int $userId, ?DateTimeImmutable $now = null): void
    {
        db_connect()->table('users')->where('id', $userId)->update(['active' => 1]);

        $this->write($userId, ['deactivated_at' => null], $this->stamp($now));
    }

    /** Ends every session the account has, now. */
    public function signOutEverywhere(int $userId, ?DateTimeImmutable $now = null): void
    {
        $stamp = $this->stamp($now);

        $this->write($userId, ['sessions_valid_from' => $stamp], $stamp);
    }

    /** Whether the account has been switched off by an administrator. */
    public function isDeactivated(int $userId): bool
    {
        return $this->row($userId)['deactivated_at'] !== null;
    }

    /**
     * Why a request from this session must be refused, or null when it may go
     * ahead.
     *
     * @param int|null $signedInAt when the session's sign-in happened (unix time), null if not recorded
     *
     * @return self::REASON_*|null
     */
    public function blockReason(int $userId, ?int $signedInAt): ?string
    {
        $row = $this->row($userId);

        if ($row['deactivated_at'] !== null) {
            return self::REASON_DEACTIVATED;
        }

        return self::sessionEnded($row['sessions_valid_from'], $signedInAt) ? self::REASON_SIGNED_OUT : null;
    }

    /**
     * Whether a session that signed in at $signedInAt has been ended by a
     * sign-out made at $validFrom. A session that signed in at the same second
     * is ended too (the sign-out is taken to have come after), and one with no
     * recorded sign-in time cannot be shown to be newer, so it is ended.
     */
    public static function sessionEnded(?string $validFrom, ?int $signedInAt): bool
    {
        if ($validFrom === null) {
            return false;
        }

        return $signedInAt === null || $signedInAt <= strtotime($validFrom);
    }

    /** @return array{deactivated_at: ?string, sessions_valid_from: ?string} */
    private function row(int $userId): array
    {
        $row = db_connect()->table('user_account_controls')
            ->select('deactivated_at, sessions_valid_from')
            ->where('user_id', $userId)
            ->get()->getRowArray();

        return $row ?? ['deactivated_at' => null, 'sessions_valid_from' => null];
    }

    /** @param array<string, ?string> $values */
    private function write(int $userId, array $values, string $stamp): void
    {
        $table = db_connect()->table('user_account_controls');

        if ($table->where('user_id', $userId)->countAllResults() > 0) {
            db_connect()->table('user_account_controls')->where('user_id', $userId)->update($values + ['updated_at' => $stamp]);

            return;
        }

        db_connect()->table('user_account_controls')->insert($values + ['user_id' => $userId, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    private function stamp(?DateTimeImmutable $now): string
    {
        return ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
