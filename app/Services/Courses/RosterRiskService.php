<?php

namespace App\Services\Courses;

use App\Models\Courses\EnrollmentModel;
use App\Models\Learning\CourseModuleModel;

/**
 * Which enrolled students have stopped keeping up.
 *
 * Shared by the dashboard's "Needs Your Attention" widget (every section an
 * instructor teaches) and the course Overview (one section), so the two
 * can't disagree about who counts as at risk.
 *
 * Two ways to be at risk, worst first:
 *   - inactive: no learning activity for the institution's number of days
 *               (INACTIVE_DAYS unless it set its own), or none ever. Silence
 *               outranks a low percentage — a student still working is not
 *               the one who needs chasing.
 *   - behind:   active, but under the institution's percent (BEHIND_BELOW
 *               unless it set its own) of the published items.
 */
class RosterRiskService
{
    public const INACTIVE_DAYS = 14;

    public const BEHIND_BELOW = 50;

    protected EnrollmentModel $enrollmentModel;

    protected CourseModuleModel $courseModuleModel;

    public function __construct()
    {
        $this->enrollmentModel = new EnrollmentModel();
        $this->courseModuleModel = new CourseModuleModel();
    }

    /**
     * At-risk students across the given offerings, longest-silent first
     * (never-active students lead).
     *
     * @param  list<int> $offeringIds
     * @return list<array{offering_id: int, name: string, progress: int, days_since: ?int, reason: string}>
     */
    public function atRisk(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }

        // Published items per offering — the denominator for "how far along".
        $itemTotals = [];

        foreach ($this->courseModuleModel->retrieveModuleProgress($offeringIds) as $module) {

            $offeringId = (int) $module['offering_id'];

            $itemTotals[$offeringId] = ($itemTotals[$offeringId] ?? 0)
                + (int) $module['item_count'];

        }

        // "Silent" and "behind" are the institution's to define (its academic defaults).
        $thresholds = (new \App\Services\Institutions\AcademicDefaults())->thresholdsForOfferings($offeringIds);

        $students = [];

        foreach ($this->enrollmentModel->retrieveRosterProgress($offeringIds) as $row) {

            $offeringId = (int) $row['offering_id'];

            $total = $itemTotals[$offeringId] ?? 0;

            // Nothing published yet means nobody can be behind.
            if ($total === 0) {
                continue;
            }

            $progress = (int) round((int) $row['completed_count'] / $total * 100);

            $daysSince = $row['last_active'] !== null
                ? (int) ((time() - strtotime($row['last_active'])) / 86400)
                : null;

            $inactiveDays = $thresholds[$offeringId]['inactiveDays'] ?? self::INACTIVE_DAYS;
            $behindBelow  = $thresholds[$offeringId]['behindBelow'] ?? self::BEHIND_BELOW;

            if ($daysSince === null || $daysSince >= $inactiveDays) {
                $reason = 'inactive';
            } elseif ($progress < $behindBelow) {
                $reason = 'behind';
            } else {
                continue;
            }

            $students[] = [
                'offering_id' => $offeringId,
                'student_id'  => (int) $row['student_user_id'],
                'name'        => trim($row['first_name'] . ' ' . $row['last_name']),
                'progress'    => $progress,
                'days_since'  => $daysSince,
                'reason'      => $reason,
            ];

        }

        usort($students, static fn (array $a, array $b) => ($b['days_since'] ?? PHP_INT_MAX) <=> ($a['days_since'] ?? PHP_INT_MAX));

        return $students;
    }
}
