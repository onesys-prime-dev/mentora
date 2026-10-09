<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use DateTimeImmutable;

/**
 * When a deleted institution is due to be purged: a fixed number of days
 * after it was deleted (the deletedInstitutionRetentionDays setting, 90 by
 * default). Pure, with the clock passed in, so the boundaries are exact.
 */
final class PurgePolicy
{
    /** The moment from which an institution deleted at $deletedAt may be purged. */
    public static function dueAt(string $deletedAt, int $retentionDays): DateTimeImmutable
    {
        return (new DateTimeImmutable($deletedAt))->modify("+{$retentionDays} days");
    }

    /** True from the due moment onwards. */
    public static function isDue(string $deletedAt, DateTimeImmutable $now, int $retentionDays): bool
    {
        return $now >= self::dueAt($deletedAt, $retentionDays);
    }

    /**
     * Whole days left before the purge, rounded up; 0 once it is due.
     */
    public static function daysLeft(string $deletedAt, DateTimeImmutable $now, int $retentionDays): int
    {
        $due = self::dueAt($deletedAt, $retentionDays);

        if ($now >= $due) {
            return 0;
        }

        return (int) ceil(($due->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    /**
     * The newest deletion time that is already due: anything deleted at or
     * before this moment may be purged now.
     */
    public static function cutoff(DateTimeImmutable $now, int $retentionDays): DateTimeImmutable
    {
        return $now->modify("-{$retentionDays} days");
    }
}
