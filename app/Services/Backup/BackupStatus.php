<?php

declare(strict_types=1);

namespace App\Services\Backup;

use DateTimeImmutable;

/**
 * Whether the backups are in a state to worry about. Pure: the time is passed
 * in. Failed outranks stale (a failure after the last success), then stale,
 * then "never".
 */
final class BackupStatus
{
    public const FAILED = 'failed';

    public const STALE = 'stale';

    public const NONE = 'none';

    /**
     * @param string|null $lastSuccess  when the last good backup finished, null if there is none
     * @param string|null $lastFailure  when the latest failed attempt finished
     * @param string|null $failureCause what it said
     *
     * @return array{kind: string, message: string}|null
     */
    public static function assess(?string $lastSuccess, ?string $lastFailure, ?string $failureCause, DateTimeImmutable $now, int $alertHours): ?array
    {
        if ($lastFailure !== null && ($lastSuccess === null || $lastFailure > $lastSuccess)) {
            return ['kind' => self::FAILED, 'message' => 'The latest backup failed' . ($failureCause !== null && $failureCause !== '' ? ': ' . $failureCause : '.')];
        }

        if ($lastSuccess === null) {
            return ['kind' => self::NONE, 'message' => 'No backup has been taken yet.'];
        }

        $age = $now->getTimestamp() - strtotime($lastSuccess);

        if ($age > $alertHours * 3600) {
            return ['kind' => self::STALE, 'message' => 'The last good backup was ' . substr($lastSuccess, 0, 16) . ', more than ' . $alertHours . ' hours ago.'];
        }

        return null;
    }
}
