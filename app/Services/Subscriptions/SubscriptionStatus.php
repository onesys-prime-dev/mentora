<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use DateTimeImmutable;

/**
 * Where a subscription is on its way from running to expired (SA13), worked
 * out from its end date, today's date and the timeline (days of warning
 * before the end, days of grace and days of read-only after it).
 *
 *   active     more than `warning` days before the end
 *   expiring   within `warning` days of the end, up to and including the end date
 *   grace      after the end date, for `grace` days: full access
 *   read_only  then for `readOnly` days: sign in, view and export only
 *   expired    after that: the institution becomes past
 *
 * Pure: it takes the date as an argument, so every boundary is testable.
 */
final class SubscriptionStatus
{
    public const ACTIVE = 'active';

    public const EXPIRING = 'expiring';

    public const GRACE = 'grace';

    public const READ_ONLY = 'read_only';

    public const EXPIRED = 'expired';

    private function __construct(
        public readonly string $state,
        public readonly string $endDate,
        /** Last day of full access (end date + grace). */
        public readonly string $graceEnds,
        /** Last day of read-only access (grace + read-only period). */
        public readonly string $readOnlyEnds,
        /** Whole days from today to the end date; negative once it has passed. */
        public readonly int $daysToEnd,
    ) {
    }

    public static function at(string $endDate, DateTimeImmutable $today, int $warningDays, int $graceDays, int $readOnlyDays): self
    {
        $end      = new DateTimeImmutable($endDate);
        $day      = new DateTimeImmutable($today->format('Y-m-d'));
        $graceEnd = $end->modify("+{$graceDays} days");
        $roEnd    = $graceEnd->modify("+{$readOnlyDays} days");

        $daysToEnd = (int) $day->diff($end)->format('%r%a');

        if ($day > $roEnd) {
            $state = self::EXPIRED;
        } elseif ($day > $graceEnd) {
            $state = self::READ_ONLY;
        } elseif ($day > $end) {
            $state = self::GRACE;
        } elseif ($warningDays > 0 && $day >= $end->modify("-{$warningDays} days")) {
            $state = self::EXPIRING;
        } else {
            $state = self::ACTIVE;
        }

        return new self($state, $end->format('Y-m-d'), $graceEnd->format('Y-m-d'), $roEnd->format('Y-m-d'), $daysToEnd);
    }

    /** The status the institution should have: active (through grace), read_only, or past. */
    public function institutionStatus(): string
    {
        return match ($this->state) {
            self::READ_ONLY => 'read_only',
            self::EXPIRED   => 'past',
            default         => 'active',
        };
    }
}
