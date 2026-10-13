<?php

declare(strict_types=1);

namespace App\Services\Institutions;

/**
 * The pure rules about academic terms: no database, no clock.
 */
final class TermRules
{
    /** A term longer than this is almost certainly a typo in a date. */
    public const MAX_DAYS = 800;

    /**
     * Whether two date ranges share a day. Ranges are inclusive, in
     * Y-m-d form (which compares correctly as text).
     */
    public static function overlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA <= $endB && $startB <= $endA;
    }

    /**
     * The academic year as written, normalised, or null when it is not one:
     * "2026-2027" (the second year follows the first) or a single "2026".
     */
    public static function academicYear(string $value): ?string
    {
        $value = trim(str_replace(['–', '—', '/'], '-', $value));

        if (preg_match('/^(\d{4})$/', $value, $m) === 1) {
            return $value;
        }

        if (preg_match('/^(\d{4})-(\d{4})$/', $value, $m) === 1 && (int) $m[2] === (int) $m[1] + 1 && (int) $m[1] >= 1990 && (int) $m[1] <= 2200) {
            return $value;
        }

        return null;
    }

    /**
     * A valid Y-m-d date, or null.
     */
    public static function date(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $value;
    }

    /** Days from start to end, both included. */
    public static function days(string $start, string $end): int
    {
        return (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;
    }
}
