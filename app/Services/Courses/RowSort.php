<?php

declare(strict_types=1);

namespace App\Services\Courses;

/**
 * Sorts the rows of a comparison table by one column. A missing value (no data yet) always goes last
 * whatever the direction, and equal values keep name order, so the order never jumps between reloads.
 * Shared by the dean's tables (Monitor Department, teaching load) so they order the same way.
 */
final class RowSort
{
    /**
     * @param list<array<string, mixed>> $rows each with a 'name' for the tie-break
     *
     * @return list<array<string, mixed>>
     */
    public static function by(array $rows, string $sort, string $dir): array
    {
        usort($rows, static function (array $a, array $b) use ($sort, $dir): int {
            $left  = $a[$sort] ?? null;
            $right = $b[$sort] ?? null;

            if ($left === null && $right !== null) {
                return 1;
            }

            if ($right === null && $left !== null) {
                return -1;
            }

            // Words compare without regard to case, so "bea" does not sort after "Cara".
            $result = is_string($left) && is_string($right) ? strcasecmp($left, $right) : ($left === $right ? 0 : ($left <=> $right));
            $result = $dir === 'desc' ? -$result : $result;

            return $result !== 0 ? $result : strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $rows;
    }
}
