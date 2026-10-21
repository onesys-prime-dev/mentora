<?php

namespace App\Services\Courses\Grades;

/**
 * The figures that describe a class's grades. One place, so the gradebook,
 * the reports and anything else that summarizes a class mean the same thing
 * by "average" and "passing": only students who have a grade count, and the
 * number graded is always given beside the average.
 */
final class GradeStatistics
{
    /**
     * @param list<float|int|string|null> $percentages one per student; students with no grade yet are null
     *
     * @return array{students: int, graded: int, mean: ?float, median: ?float, high: ?float, low: ?float, at_or_above_passing: int, passing_mark: float}
     */
    public static function of(array $percentages, float $passing): array
    {
        $graded = array_values(array_map(
            static fn ($p): float => (float) $p,
            array_filter($percentages, static fn ($p): bool => $p !== null && $p !== '')
        ));

        sort($graded);

        $count = count($graded);

        $median = null;

        if ($count > 0) {
            $median = $count % 2 === 1
                ? $graded[intdiv($count, 2)]
                : ($graded[$count / 2 - 1] + $graded[$count / 2]) / 2;
        }

        return [
            'students' => count($percentages),
            'graded' => $count,
            'mean' => $count > 0 ? round(array_sum($graded) / $count, 2) : null,
            'median' => $median !== null ? round($median, 2) : null,
            'high' => $count > 0 ? max($graded) : null,
            'low' => $count > 0 ? min($graded) : null,
            'at_or_above_passing' => count(array_filter($graded, static fn (float $p): bool => $p >= $passing)),
            'passing_mark' => $passing,
        ];
    }
}
