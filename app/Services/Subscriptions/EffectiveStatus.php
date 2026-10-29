<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use DateTimeImmutable;

/**
 * The status an institution has right now: the worse of its stored status and
 * what its subscription dates say (audit decision 6.1).
 *
 * The nightly `subscriptions:sync` still moves the stored status and writes the
 * audit entry, but access never waits for it: an institution whose subscription
 * ran out is read-only (then past) on the dates the timeline gives, whether or
 * not the job ran. Like the job, dates only ever make it worse: a manual
 * suspension (stored `past`) is never lifted, a renewal lifts it through
 * SubscriptionLifecycle::statusAfterRenewal(), a `pending` institution and one
 * with no subscription keep their stored status.
 */
final class EffectiveStatus
{
    /** One of pending, active, read_only, past. */
    public static function combine(string $stored, ?string $endDate, int $institutionId, DateTimeImmutable $today): string
    {
        if ($endDate === null || $endDate === '' || ! isset(SubscriptionLifecycle::RANK[$stored])) {
            return $stored;
        }

        $byDates = (new SubscriptionLifecycle())->statusOf($endDate, $institutionId, $today)->institutionStatus();

        return SubscriptionLifecycle::RANK[$byDates] > SubscriptionLifecycle::RANK[$stored] ? $byDates : $stored;
    }

    /** The institution's status now, from its row and its subscription; its stored status when it has none. */
    public static function of(int $institutionId, ?DateTimeImmutable $today = null): string
    {
        $row = db_connect()->table('institutions i')
            ->select('i.status, s.end_date')
            ->join('subscriptions s', 's.institution_id = i.id', 'left')
            ->where('i.id', $institutionId)
            ->get()->getRowArray();

        if ($row === null) {
            return '';
        }

        return self::combine((string) $row['status'], $row['end_date'], $institutionId, $today ?? new DateTimeImmutable());
    }
}
