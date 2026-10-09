<?php

declare(strict_types=1);

namespace App\Services\Flags;

use DateTimeImmutable;

/**
 * Decides whether a feature flag is on for an institution, from everything
 * that can say something about it. Pure: the rows and the date are passed in.
 *
 * In order, the first that applies wins:
 *
 *   1. the kill switch        a global row with enabled = 0: off for everyone
 *   2. the institution's own  an override for it, on or off, unless it has ended
 *   3. the plan               the flag is one of the plan's granted features: on
 *   4. the default            a global row with enabled = 1 (on for everyone),
 *                             else the default written in code
 *
 * A row that has ended (its ends_on date is before today) is ignored, so a
 * lapsed kill switch no longer kills and a lapsed override no longer overrides.
 */
final class FeatureFlagResolver
{
    public const KILL_SWITCH = 'kill_switch';

    public const OVERRIDE_ON = 'override_on';

    public const OVERRIDE_OFF = 'override_off';

    public const PLAN = 'plan';

    public const GLOBAL_ON = 'global_on';

    public const DEFAULT = 'default';

    /**
     * @param array{enabled: bool|int|string, ends_on: ?string}|null $global   the flag's global row, if any
     * @param array{enabled: bool|int|string, ends_on: ?string}|null $override the institution's row, if any
     * @param bool                                                   $default  the default written in code
     * @param bool                                                   $planGrants whether the institution's plan grants the flag
     *
     * @return array{enabled: bool, reason: string}
     */
    public static function resolve(?array $global, ?array $override, bool $default, bool $planGrants, DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');

        $global   = self::current($global, $today);
        $override = self::current($override, $today);

        if ($global !== null && ! self::on($global)) {
            return ['enabled' => false, 'reason' => self::KILL_SWITCH];
        }

        if ($override !== null) {
            return self::on($override)
                ? ['enabled' => true, 'reason' => self::OVERRIDE_ON]
                : ['enabled' => false, 'reason' => self::OVERRIDE_OFF];
        }

        if ($planGrants) {
            return ['enabled' => true, 'reason' => self::PLAN];
        }

        if ($global !== null) {
            return ['enabled' => true, 'reason' => self::GLOBAL_ON];
        }

        return ['enabled' => $default, 'reason' => self::DEFAULT];
    }

    /**
     * The row, or null if there is none or it has ended.
     *
     * @param array{enabled: bool|int|string, ends_on: ?string}|null $row
     *
     * @return array{enabled: bool|int|string, ends_on: ?string}|null
     */
    private static function current(?array $row, string $today): ?array
    {
        if ($row === null) {
            return null;
        }

        $endsOn = $row['ends_on'] ?? null;

        return $endsOn !== null && $endsOn < $today ? null : $row;
    }

    /** @param array{enabled: bool|int|string, ends_on: ?string} $row */
    private static function on(array $row): bool
    {
        return (bool) (int) $row['enabled'];
    }
}
