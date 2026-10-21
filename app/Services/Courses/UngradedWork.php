<?php

declare(strict_types=1);

namespace App\Services\Courses;

/**
 * Work that has been handed in and is still waiting to be graded, counted per offering. The one
 * definition behind the dean's teaching-load page and dashboard, so the two never disagree: a
 * submission with a hand-in time and not graded. Optionally only work handed in before a moment,
 * which is what "overdue" means (the institution says how many days).
 */
final class UngradedWork
{
    /**
     * @param list<int> $offeringIds
     *
     * @return array<int, int> offering id => how many pieces of work wait
     */
    public static function countByOffering(array $offeringIds, ?string $handedInBefore = null): array
    {
        if ($offeringIds === []) {
            return [];
        }

        $builder = db_connect()->table('assessment_submissions s')
            ->select(['cm.offering_id', 'COUNT(*) AS waiting'], false)
            ->join('assessments a', 'a.id = s.assessment_id')
            ->join('course_modules cm', 'cm.id = a.module_id')
            ->whereIn('cm.offering_id', $offeringIds)
            ->where('s.submitted_at IS NOT NULL', null, false)
            ->where('s.is_graded', 0);

        if ($handedInBefore !== null) {
            $builder->where('s.submitted_at <', $handedInBefore);
        }

        $counts = [];

        foreach ($builder->groupBy('cm.offering_id')->get()->getResultArray() as $row) {
            $counts[(int) $row['offering_id']] = (int) $row['waiting'];
        }

        return $counts;
    }
}
