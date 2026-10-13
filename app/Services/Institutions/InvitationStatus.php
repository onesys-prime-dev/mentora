<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use DateTimeImmutable;

/**
 * Where an invitation stands. Pure: the current time is passed in, never read
 * here, so tests can set the day.
 */
final class InvitationStatus
{
    /** Can still be accepted. */
    public const PENDING = 'pending';

    /** Used: the person has set their password. */
    public const ACCEPTED = 'accepted';

    /** Replaced by a newer invitation to the same place. */
    public const REVOKED = 'revoked';

    /** Not used in time. */
    public const EXPIRED = 'expired';

    /**
     * @param array{accepted_at: ?string, revoked_at: ?string, expires_at: string} $invitation
     *
     * @return self::PENDING|self::ACCEPTED|self::REVOKED|self::EXPIRED
     */
    public static function of(array $invitation, DateTimeImmutable $now): string
    {
        if ($invitation['accepted_at'] !== null) {
            return self::ACCEPTED;
        }

        if ($invitation['revoked_at'] !== null) {
            return self::REVOKED;
        }

        // The link works up to, but not including, the expiry moment.
        return $invitation['expires_at'] <= $now->format('Y-m-d H:i:s') ? self::EXPIRED : self::PENDING;
    }
}
