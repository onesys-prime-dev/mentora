<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

/**
 * How full a plan limit is: unlimited, fine, nearly full (90% or more) or
 * full. A limit that is empty (null) is unlimited. Pure arithmetic.
 */
final class PlanLimitState
{
    public const UNLIMITED = 'unlimited';

    public const OK = 'ok';

    public const WARNING = 'warning';

    public const FULL = 'full';

    /** From this percent of the limit, a warning. */
    public const WARN_AT_PERCENT = 90;

    public static function of(int $used, ?int $limit): string
    {
        if ($limit === null) {
            return self::UNLIMITED;
        }

        if ($used >= $limit) {
            return self::FULL;
        }

        return self::percent($used, $limit) >= self::WARN_AT_PERCENT ? self::WARNING : self::OK;
    }

    /** Whole percent used, rounded down; may exceed 100. Null when unlimited. */
    public static function percent(int $used, ?int $limit): ?int
    {
        if ($limit === null) {
            return null;
        }

        return $limit > 0 ? intdiv($used * 100, $limit) : 100;
    }

    /** True when adding $adding more would go beyond the limit. */
    public static function wouldExceed(int $used, int $adding, ?int $limit): bool
    {
        return $limit !== null && $used + $adding > $limit;
    }
}
