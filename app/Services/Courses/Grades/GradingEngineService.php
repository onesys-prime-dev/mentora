<?php

namespace App\Services\Courses\Grades;

use CodeIgniter\Shield\Entities\User;

use App\Models\Assessments\GradebookRecordModel;
use App\Models\Grades\GradingSchemeModel;
use App\Models\Grades\GradingPeriodModel;
use App\Models\Grades\GradeCategoryModel;
use App\Models\Grades\GradeItemModel;
use App\Models\Grades\GradebookItemScoreModel;
use App\Models\Grades\GradeScaleItemModel;
use App\Models\Grades\GradeHistoryModel;

/**
 * Computes final grades from the gradebook and the offering's scheme, and
 * caches them in gradebook_records. The one place course grades are
 * calculated — the Grades pages and the grade-sheet export all read it.
 *
 * Rule (weighted categories, optionally grouped into weighted periods):
 *   category % = points earned ÷ points possible over its GRADED items
 *                (not-yet-graded items are left out, not counted as zero)
 *   [with periods] period % = Σ(category % × weight) ÷ Σ(weight), over that
 *                period's categories that have graded work
 *   final %    = Σ(entry % × weight) ÷ Σ(weight), over periods (if the
 *                scheme uses them) or else categories directly — entries
 *                with no data are left out, so an empty one never drags the
 *                grade down
 * Uncategorized items/categories don't count. The final % maps to the
 * scheme's grade scale; remarks come from its passing mark.
 */
class GradingEngineService
{
    protected GradingSchemeModel $schemeModel;
    protected GradingPeriodModel $periodModel;
    protected GradeCategoryModel $categoryModel;
    protected GradeItemModel $itemModel;
    protected GradebookItemScoreModel $scoreModel;
    protected GradeScaleItemModel $scaleItemModel;
    protected GradebookRecordModel $recordModel;
    protected GradeHistoryModel $historyModel;

    public function __construct()
    {
        $this->schemeModel = new GradingSchemeModel();
        $this->periodModel = new GradingPeriodModel();
        $this->categoryModel = new GradeCategoryModel();
        $this->itemModel = new GradeItemModel();
        $this->scoreModel = new GradebookItemScoreModel();
        $this->scaleItemModel = new GradeScaleItemModel();
        $this->recordModel = new GradebookRecordModel();
        $this->historyModel = new GradeHistoryModel();
    }

    /*
    |--------------------------------------------------------------------------
    | Recalculation
    |--------------------------------------------------------------------------
    */

    /**
     * Full recompute for every enrolled student; clears needs_recalculation.
     * Returns how many students were recalculated.
     */
    public function recalculateOffering(int $offeringId): int
    {
        $context = $this->context($offeringId);

        if (!$context) {
            return 0;
        }

        foreach ($context['studentIds'] as $studentId) {
            $this->store($context, $studentId, $this->compute($context, $studentId));
        }

        $this->schemeModel->update($context['scheme']['id'], [
            'needs_recalculation' => 0,
            'last_recalculated_at' => date('Y-m-d H:i:s'),
        ]);

        return count($context['studentIds']);
    }

    /**
     * One student, after their work was graded. Leaves needs_recalculation
     * alone — that flag is about the scheme's config, not this student.
     */
    public function recalculateStudent(int $offeringId, int $studentId): void
    {
        $context = $this->context($offeringId, [$studentId]);

        if ($context && in_array($studentId, $context['studentIds'], true)) {
            $this->store($context, $studentId, $this->compute($context, $studentId));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Calculation (read-only — also used for previews and the CSV export)
    |--------------------------------------------------------------------------
    */

    /**
     * Everything a calculation needs for an offering, loaded once. Null when
     * the offering has no scheme yet.
     *
     * @param list<int>|null $onlyStudentIds limit scores to these students
     */
    public function context(int $offeringId, ?array $onlyStudentIds = null): ?array
    {
        $scheme = $this->schemeModel->findForOffering($offeringId);

        if (!$scheme) {
            return null;
        }

        $studentIds = array_map('intval', array_column(
            db_connect()->table('enrollments')
                ->select('student_user_id')
                ->where('offering_id', $offeringId)
                ->whereIn('status', ['enrolled', 'completed'])
                ->get()
                ->getResultArray(),
            'student_user_id'
        ));

        if ($onlyStudentIds !== null) {
            $studentIds = array_values(array_intersect($studentIds, array_map('intval', $onlyStudentIds)));
        }

        $categories = $this->categoryModel->listForScheme((int) $scheme['id']);

        $items = $this->itemModel->listForScheme((int) $scheme['id'], true);

        return [
            'offeringId' => $offeringId,
            'scheme' => $scheme,
            'periods' => !empty($scheme['uses_periods']) ? $this->periodModel->listForScheme((int) $scheme['id']) : [],
            'categories' => $categories,
            'items' => $items,
            'studentIds' => $studentIds,
            'scores' => $this->scoreModel->mapForItems(array_map('intval', array_column($items, 'id')), $studentIds),
            'bands' => !empty($scheme['grade_scale_id'])
                ? $this->scaleItemModel->listForScale((int) $scheme['grade_scale_id'])
                : [],
        ];
    }

    /**
     * One student's breakdown and final grade.
     *
     * @return array{percentage: ?float, points_earned: float, points_possible: float,
     *               categories: list<array>, periods: list<array>, band: ?array, remarks: string}
     */
    public function compute(array $context, int $studentId, array $overrideScores = []): array
    {
        $categories = [];
        $pointsEarned = 0.0;
        $pointsPossible = 0.0;

        foreach ($context['categories'] as $category) {

            $earned = 0.0;
            $possible = 0.0;
            $graded = 0;

            foreach ($context['items'] as $item) {

                if ((int) $item['category_id'] !== (int) $category['id']) {
                    continue;
                }

                $score = $this->scoreFor($context, $overrideScores, (int) $item['id'], $studentId);

                if ($score === null) {
                    continue;
                }

                $earned += $score;
                $possible += (float) $item['max_points'];
                $graded++;

            }

            $percentage = $possible > 0 ? $earned / $possible * 100 : null;

            $pointsEarned += $earned;
            $pointsPossible += $possible;

            $categories[] = [
                'id' => (int) $category['id'],
                'period_id' => $category['period_id'] !== null ? (int) $category['period_id'] : null,
                'name' => $category['name'],
                'weight' => (float) $category['weight_percentage'],
                'earned' => $earned,
                'possible' => $possible,
                'graded_count' => $graded,
                'percentage' => $percentage === null ? null : round($percentage, 2),
            ];

        }

        $periods = [];

        if ($context['periods'] !== []) {

            foreach ($context['periods'] as $period) {

                $periodCategories = array_values(array_filter(
                    $categories,
                    static fn (array $category) => $category['period_id'] === (int) $period['id']
                ));

                $percentage = $this->rollup(array_map(
                    static fn (array $category) => [$category['percentage'], $category['weight']],
                    $periodCategories
                ));

                $periods[] = [
                    'id' => (int) $period['id'],
                    'name' => $period['name'],
                    'weight' => (float) $period['weight_percentage'],
                    'percentage' => $percentage,
                    'categories' => $periodCategories,
                ];

            }

            // A category with no period doesn't count (mirrors an
            // uncategorized item), same as it wouldn't in flat mode either.
            $final = $this->rollup(array_map(
                static fn (array $period) => [$period['percentage'], $period['weight']],
                $periods
            ));

        } else {

            $final = $this->rollup(array_map(
                static fn (array $category) => [$category['percentage'], $category['weight']],
                $categories
            ));

        }

        return [
            'percentage' => $final,
            'points_earned' => round($pointsEarned, 2),
            'points_possible' => round($pointsPossible, 2),
            'categories' => $categories,
            'periods' => $periods,
            'band' => $this->bandFor($context['bands'], $final),
            'remarks' => $this->remarksFor($final, (float) $context['scheme']['passing_percentage']),
        ];
    }

    /**
     * Weighted average over entries that have data; entries with no
     * percentage are left out entirely rather than counted as zero, so one
     * empty category/period never drags the rest of the grade down.
     *
     * @param list<array{0: ?float, 1: float}> $entries [percentage, weight]
     */
    private function rollup(array $entries): ?float
    {
        $weighted = 0.0;
        $weightWithData = 0.0;

        foreach ($entries as [$percentage, $weight]) {

            if ($percentage !== null && $weight > 0) {
                $weighted += $percentage * $weight;
                $weightWithData += $weight;
            }

        }

        return $weightWithData > 0 ? round($weighted / $weightWithData, 2) : null;
    }

    /**
     * A student's score on an item: an override (import preview) first, else
     * the gradebook, else null (not graded — left out of the calculation).
     */
    private function scoreFor(array $context, array $overrideScores, int $itemId, int $studentId): ?float
    {
        if (array_key_exists($itemId, $overrideScores)) {
            return $overrideScores[$itemId] === null ? null : (float) $overrideScores[$itemId];
        }

        $row = $context['scores'][$itemId][$studentId] ?? null;

        if (!$row || $row['raw_score'] === null || !empty($row['is_missing'])) {
            return null;
        }

        return (float) $row['raw_score'];
    }

    /**
     * The grade-scale band containing the percentage (bands highest first).
     */
    public function bandFor(array $bands, ?float $percentage): ?array
    {
        if ($percentage === null) {
            return null;
        }

        foreach ($bands as $band) {
            if ($percentage >= (float) $band['minimum_percentage']) {
                return $band;
            }
        }

        return $bands[count($bands) - 1] ?? null;
    }

    public function remarksFor(?float $percentage, float $passing): string
    {
        return match (true) {
            $percentage === null => 'Incomplete',
            $percentage >= $passing => 'Passed',
            default => 'Failed',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Storage + overrides
    |--------------------------------------------------------------------------
    */

    /**
     * Writes the calculated grade. An instructor's override is kept; remarks
     * always follow whichever percentage counts.
     */
    private function store(array $context, int $studentId, array $result): void
    {
        $now = date('Y-m-d H:i:s');

        $record = $this->recordModel->findForStudent($context['offeringId'], $studentId);

        $effective = !empty($record['is_override'])
            ? ($record['overridden_percentage'] === null ? null : (float) $record['overridden_percentage'])
            : $result['percentage'];

        $values = [
            'grading_scheme_id' => $context['scheme']['id'],
            'calculated_percentage' => $result['percentage'],
            'calculated_grade_scale_item_id' => $result['band']['id'] ?? null,
            'remarks' => $this->remarksFor($effective, (float) $context['scheme']['passing_percentage']),
            'last_calculated_at' => $now,
            'updated_at' => $now,
        ];

        if ($record) {
            $this->recordModel->update($record['id'], $values);
            return;
        }

        $this->recordModel->insert($values + [
            'course_offering_id' => $context['offeringId'],
            'student_id' => $studentId,
            'is_override' => 0,
            'created_at' => $now,
        ]);
    }

    /**
     * Sets (or, with null, clears) an instructor's override of a student's
     * final grade. Every change is written to grade_history.
     */
    public function overrideFinalGrade(User $user, int $offeringId, int $studentId, ?float $percentage, string $reason): array
    {
        if ($percentage !== null && ($percentage < 0 || $percentage > 100)) {
            return ['success' => false, 'message' => 'The grade must be between 0 and 100.'];
        }

        if ($percentage !== null && trim($reason) === '') {
            return ['success' => false, 'message' => 'Give a reason for the override.'];
        }

        $record = $this->recordModel->findForStudent($offeringId, $studentId);

        if (!$record) {
            $this->recalculateStudent($offeringId, $studentId);
            $record = $this->recordModel->findForStudent($offeringId, $studentId);
        }

        if (!$record) {
            return ['success' => false, 'message' => 'Student is not enrolled in this section.'];
        }

        $context = $this->context($offeringId, [$studentId]);

        $previous = GradebookRecordModel::effectivePercentage($record);

        $isOverride = $percentage !== null;

        $effective = $isOverride
            ? $percentage
            : ($record['calculated_percentage'] === null ? null : (float) $record['calculated_percentage']);

        $now = date('Y-m-d H:i:s');

        $this->recordModel->update($record['id'], [
            'is_override' => $isOverride ? 1 : 0,
            'overridden_percentage' => $percentage,
            'overridden_grade_scale_item_id' => $isOverride ? ($this->bandFor($context['bands'], $percentage)['id'] ?? null) : null,
            'override_reason' => $isOverride ? trim($reason) : null,
            'overridden_by' => $isOverride ? $user->id : null,
            'remarks' => $this->remarksFor($effective, (float) $context['scheme']['passing_percentage']),
            'updated_at' => $now,
        ]);

        $this->historyModel->insert([
            'gradebook_record_id' => $record['id'],
            'previous_percentage' => $previous,
            'new_percentage' => $effective,
            'reason' => $isOverride ? trim($reason) : (trim($reason) !== '' ? trim($reason) : 'Override removed'),
            'changed_by' => $user->id,
            'changed_at' => $now,
        ]);

        return ['success' => true, 'message' => $isOverride ? 'Final grade overridden.' : 'Override removed.'];
    }
}
