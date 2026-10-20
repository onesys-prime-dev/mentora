<?php

namespace App\Services\Courses\Grades;

use CodeIgniter\Shield\Entities\User;

use App\Models\Grades\GradingSchemeModel;
use App\Models\Grades\GradingPeriodModel;
use App\Models\Grades\GradeCategoryModel;
use App\Models\Grades\GradeItemModel;
use App\Models\Grades\GradebookItemScoreModel;
use App\Models\Grades\GradeScaleModel;
use App\Models\Grades\GradeScaleItemModel;

/**
 * A course offering's grading scheme: its (optionally periodized) categories,
 * weights, grade items, passing mark and grade scale.
 *
 * Every offering resolves to exactly one scheme — the first time one is
 * needed it's copied from the system default template (forOffering), so the
 * default is data, not code, and editing an offering's copy never touches it.
 *
 * Changes that alter how grades are computed (weights, categories, periods,
 * what counts, passing mark, scale) mark the scheme `needs_recalculation`;
 * final grades only change when the instructor runs Recalculate. Turning
 * grading periods on/off is the one exception — see updateScheme().
 */
class GradingSchemeService
{
    protected GradingSchemeModel $schemeModel;
    protected GradingPeriodModel $periodModel;
    protected GradeCategoryModel $categoryModel;
    protected GradeItemModel $itemModel;
    protected GradebookItemScoreModel $scoreModel;
    protected GradeScaleModel $scaleModel;
    protected GradeScaleItemModel $scaleItemModel;

    public function __construct()
    {
        $this->schemeModel = new GradingSchemeModel();
        $this->periodModel = new GradingPeriodModel();
        $this->categoryModel = new GradeCategoryModel();
        $this->itemModel = new GradeItemModel();
        $this->scoreModel = new GradebookItemScoreModel();
        $this->scaleModel = new GradeScaleModel();
        $this->scaleItemModel = new GradeScaleItemModel();
    }

    /*
    |--------------------------------------------------------------------------
    | Resolving an offering's scheme
    |--------------------------------------------------------------------------
    */

    /**
     * The offering's scheme, created from the system default the first time.
     * A new scheme picks up the offering's already-published assessments and
     * computes everyone's grade once, so it's usable immediately.
     */
    public function forOffering(int $offeringId): array
    {
        $scheme = $this->schemeModel->findForOffering($offeringId);

        if ($scheme) {
            return $scheme;
        }

        $scheme = $this->copyTemplate($offeringId);

        (new GradeItemSyncService())->syncOffering($offeringId);

        (new GradingEngineService())->recalculateOffering($offeringId);

        return $this->schemeModel->find($scheme['id']);
    }

    private function copyTemplate(int $offeringId): array
    {
        // The institution's own defaults where it set them, else the platform's template.
        $template = (new \App\Services\Institutions\AcademicDefaults())->templateForOffering($offeringId);

        $now = date('Y-m-d H:i:s');

        try {

            $this->schemeModel->insert([
                'course_offering_id' => $offeringId,
                'name' => 'Grading Scheme',
                'is_system_default' => 0,
                'passing_percentage' => $template['passing'],
                'grade_scale_id' => $template['scaleId'],
                // The template is valid as-is, so the copy is live straight away.
                'status' => 'Published',
                'needs_recalculation' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        } catch (\Throwable $e) {

            // Another request created it first (unique per offering).
            $existing = $this->schemeModel->findForOffering($offeringId);

            if ($existing) {
                return $existing;
            }

            throw $e;

        }

        $schemeId = (int) $this->schemeModel->getInsertID();

        foreach ($template['categories'] as $category) {
            $this->categoryModel->insert([
                'grading_scheme_id' => $schemeId,
                'name' => $category['name'],
                'weight_percentage' => $category['weight'],
                'sort_order' => $category['sort'],
                'created_at' => $now,
                'updated_at' => $now,
                'status' => 'Active',
            ]);
        }

        return $this->schemeModel->find($schemeId);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading (editor + Grades page)
    |--------------------------------------------------------------------------
    */

    /**
     * Everything the scheme editor shows: categories with their items (under
     * their period, when the scheme uses periods), the weight total(s),
     * out-of-sync items and the available grade scales.
     */
    public function describe(int $offeringId): array
    {
        $scheme = $this->forOffering($offeringId);

        $usesPeriods = !empty($scheme['uses_periods']);

        $categories = $this->categoryModel->listForScheme((int) $scheme['id']);

        $sync = new GradeItemSyncService();

        $items = array_map(static function (array $item) use ($sync) {
            $item['is_manual'] = empty($item['assessment_id']);
            $item['display_name'] = $item['assessment_title'] ?? $item['name'];
            $item['is_out_of_sync'] = $sync->isOutOfSync($item);
            return $item;
        }, $this->itemModel->listForScheme((int) $scheme['id']));

        $categoryIds = array_map('intval', array_column($categories, 'id'));

        foreach ($categories as &$category) {
            $category['items'] = array_values(array_filter(
                $items,
                static fn (array $item) => (int) $item['category_id'] === (int) $category['id']
            ));
        }
        unset($category);

        $uncategorized = array_values(array_filter(
            $items,
            static fn (array $item) => !in_array((int) $item['category_id'], $categoryIds, true)
        ));

        $result = [
            'scheme' => $scheme,
            'uses_periods' => $usesPeriods,
            // Every active category regardless of period — always present,
            // whichever mode the scheme is in.
            'categories' => $categories,
            'uncategorized' => $uncategorized,
            'out_of_sync_count' => count(array_filter($items, static fn ($item) => $item['is_out_of_sync'])),
            'finalization' => (new GradeFinalizationService())->current($offeringId),
            'scales' => $this->scaleModel->where('status', 'Active')->orderBy('id')->findAll(),
            'scale_items' => !empty($scheme['grade_scale_id'])
                ? $this->scaleItemModel->listForScale((int) $scheme['grade_scale_id'])
                : [],
        ];

        if (!$usesPeriods) {
            $result['periods'] = [];
            $result['unassigned_categories'] = [];
            $result['total_weight'] = round(array_sum(array_map(static fn ($c) => (float) $c['weight_percentage'], $categories)), 2);
            return $result;
        }

        $periods = $this->periodModel->listForScheme((int) $scheme['id']);

        $periodIds = array_map('intval', array_column($periods, 'id'));

        foreach ($periods as &$period) {

            $period['categories'] = array_values(array_filter(
                $categories,
                static fn (array $category) => (int) ($category['period_id'] ?? 0) === (int) $period['id']
            ));

            $period['weight_total'] = round(array_sum(array_map(
                static fn ($c) => (float) $c['weight_percentage'],
                $period['categories']
            )), 2);

        }
        unset($period);

        $result['periods'] = $periods;
        $result['unassigned_categories'] = array_values(array_filter(
            $categories,
            static fn (array $category) => !in_array((int) ($category['period_id'] ?? 0), $periodIds, true)
        ));
        $result['total_weight'] = round(array_sum(array_map(static fn ($p) => (float) $p['weight_percentage'], $periods)), 2);

        return $result;
    }

    /**
     * The gradebook grid: every enrolled student, their manual-item scores
     * (the only ones editable here — assessment-backed scores come from
     * Grade Work / quiz grading), a read-only category/period breakdown so
     * "why is this student at X%" doesn't require leaving the grid, and
     * their final grade, with override state.
     */
    public function roster(int $offeringId): array
    {
        $scheme = $this->forOffering($offeringId);

        $engine = new GradingEngineService();

        $context = $engine->context($offeringId);

        $usesPeriods = !empty($scheme['uses_periods']) && ($context['periods'] ?? []) !== [];

        $breakdownGroups = $usesPeriods ? $context['periods'] : $context['categories'];

        $manualItems = array_values(array_filter(
            $this->itemModel->listForScheme((int) $scheme['id']),
            static fn (array $item) => empty($item['assessment_id'])
        ));

        $manualItemIds = array_map('intval', array_column($manualItems, 'id'));

        $scores = $this->scoreModel->mapForItems($manualItemIds, $context['studentIds'] ?? []);

        $recordModel = new \App\Models\Assessments\GradebookRecordModel();

        $students = [];

        foreach (\App\Services\Courses\Grades\GradeSheetExportService::roster($offeringId) as $row) {

            $studentId = (int) $row['student_id'];

            $record = $recordModel->findForStudent($offeringId, $studentId);

            $itemScores = [];

            foreach ($manualItemIds as $itemId) {
                $cell = $scores[$itemId][$studentId] ?? null;
                $itemScores[$itemId] = $cell && $cell['raw_score'] !== null ? (float) $cell['raw_score'] : null;
            }

            $result = $engine->compute($context, $studentId);

            $breakdown = [];

            foreach (($usesPeriods ? ($result['periods'] ?? []) : ($result['categories'] ?? [])) as $group) {
                $breakdown[(int) $group['id']] = $group['percentage'];
            }

            $students[] = $row + [
                'scores' => $itemScores,
                'breakdown' => $breakdown,
                'percentage' => \App\Models\Assessments\GradebookRecordModel::effectivePercentage($record),
                'calculated_percentage' => $record['calculated_percentage'] ?? null,
                'is_override' => !empty($record['is_override']),
                'override_reason' => $record['override_reason'] ?? null,
                'remarks' => $record['remarks'] ?? 'Incomplete',
                'is_final' => !empty($record['finalized_at']),
                'tag' => GradingEngineService::displayTag($record['remarks'] ?? 'Incomplete', !empty($record['finalized_at'])),
                'inc_reason' => $record['inc_reason'] ?? null,
                'inc_until' => $record['inc_until'] ?? null,
            ];

        }

        return [
            'scheme' => $scheme,
            'items' => $manualItems,
            'uses_periods' => $usesPeriods,
            'breakdown_groups' => $breakdownGroups,
            'students' => $students,
        ];
    }

    /**
     * An instructor typing a manual-item score directly (as opposed to the
     * offline import). Same live-update rule as grading a submission: the
     * final grade refreshes only while the scheme is published and not
     * waiting on a Recalculate.
     */
    public function updateManualScore(User $user, int $offeringId, int $itemId, int $studentId, ?float $score): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        $item = $this->ownedItem($scheme, $itemId);

        if (!$item || !empty($item['assessment_id'])) {
            return ['success' => false, 'message' => 'Only manual items can be scored here.'];
        }

        if ($score !== null && ($score < 0 || $score > (float) $item['max_points'])) {
            return ['success' => false, 'message' => 'Score must be between 0 and ' . GradeSheetLayout::number((float) $item['max_points']) . '.'];
        }

        $enrolled = db_connect()->table('enrollments')
            ->where('offering_id', $offeringId)
            ->where('student_user_id', $studentId)
            ->whereIn('status', ['enrolled', 'completed'])
            ->countAllResults() > 0;

        if (!$enrolled) {
            return ['success' => false, 'message' => 'Student is not enrolled in this section.'];
        }

        $before = (new \App\Models\Grades\GradebookItemScoreModel())
            ->where('grade_item_id', $itemId)
            ->where('student_id', $studentId)
            ->first();

        (new GradebookSyncService())->setManualScore($offeringId, $itemId, $studentId, $score, $user->id);

        $previous = $before === null || $before['raw_score'] === null ? null : (float) $before['raw_score'];

        if ($previous !== $score) {
            GradeAudit::record('grades.manual_score', $offeringId, [
                'old' => ['item_id' => $itemId, 'item' => $item['name'], 'student_id' => $studentId, 'score' => $previous],
                'new' => ['item_id' => $itemId, 'item' => $item['name'], 'student_id' => $studentId, 'score' => $score],
            ], $user);
        }

        $refreshed = $scheme['status'] === 'Published';

        if ($refreshed) {
            (new GradingEngineService())->recalculateStudent($offeringId, $studentId);
        }

        $recordModel = new \App\Models\Assessments\GradebookRecordModel();

        return [
            'success' => true,
            'message' => $refreshed ? 'Score saved.' : 'Score saved. Final grades update once the grading is published.',
            'percentage' => \App\Models\Assessments\GradebookRecordModel::effectivePercentage($recordModel->findForStudent($offeringId, $studentId)),
        ];
    }

    /**
     * Sets (percentage given) or clears (null) an instructor's override of
     * a student's final grade. Every change is recorded (GradeHistoryModel).
     */
    /** Marks the student's grade Incomplete (INC), or takes it off, with the reason and finish-by date. */
    public function setIncomplete(User $user, int $offeringId, int $studentId, bool $incomplete, string $reason = '', ?string $until = null): array
    {
        $engine = new GradingEngineService();

        return $incomplete
            ? $engine->markIncomplete($user, $offeringId, $studentId, $reason, $until)
            : $engine->clearIncomplete($user, $offeringId, $studentId);
    }

    public function overrideGrade(User $user, int $offeringId, int $studentId, ?float $percentage, string $reason): array
    {
        return (new GradingEngineService())->overrideFinalGrade($user, $offeringId, $studentId, $percentage, $reason);
    }

    /*
    |--------------------------------------------------------------------------
    | Scheme settings + publishing
    |--------------------------------------------------------------------------
    */

    public function updateScheme(User $user, int $offeringId, array $data): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        $update = [];
        $affectsGrades = false;

        if (array_key_exists('name', $data) && trim((string) $data['name']) !== '') {
            $update['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('passing_percentage', $data)) {

            $passing = (float) $data['passing_percentage'];

            if ($passing < 0 || $passing > 100) {
                return ['success' => false, 'message' => 'The passing mark must be between 0 and 100.'];
            }

            $affectsGrades = $affectsGrades || $passing !== (float) $scheme['passing_percentage'];
            $update['passing_percentage'] = $passing;

        }

        if (array_key_exists('grade_scale_id', $data)) {

            $scaleId = $data['grade_scale_id'] ? (int) $data['grade_scale_id'] : null;

            if ($scaleId && !$this->scaleModel->find($scaleId)) {
                return ['success' => false, 'message' => 'Grade scale not found.'];
            }

            $affectsGrades = $affectsGrades || $scaleId !== ($scheme['grade_scale_id'] ? (int) $scheme['grade_scale_id'] : null);
            $update['grade_scale_id'] = $scaleId;

        }

        if (array_key_exists('status', $data)) {

            $status = $data['status'] === 'Published' ? 'Published' : 'Draft';

            if ($status === 'Published') {

                $problem = $this->publishProblem((int) $scheme['id']);

                if ($problem !== null) {
                    return ['success' => false, 'message' => $problem];
                }

            }

            $update['status'] = $status;

        }

        if (array_key_exists('uses_periods', $data)) {

            $usesPeriods = filter_var($data['uses_periods'], FILTER_VALIDATE_BOOLEAN);

            // Turning periods on for the first time: wrap the existing
            // categories into one "Full Term" period at 100% so this alone
            // never changes a single number — only weight edits made
            // afterward flag needs_recalculation, same as any other edit.
            if ($usesPeriods && empty($scheme['uses_periods']) && $this->periodModel->listForScheme((int) $scheme['id']) === []) {
                $this->wrapCategoriesIntoFullTermPeriod((int) $scheme['id']);
            }

            // Turning it off leaves categories' period_id links in place —
            // the engine simply ignores them while the flag is off — so
            // this is fully reversible either way.
            $update['uses_periods'] = $usesPeriods ? 1 : 0;

        }

        if ($affectsGrades) {
            $update['needs_recalculation'] = 1;
        }

        if ($update !== []) {
            $this->schemeModel->update($scheme['id'], $update + [
                'updated_by' => $user->id,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // Publishing is itself the explicit action: grades are computed now.
        if (($update['status'] ?? null) === 'Published' && $scheme['status'] !== 'Published') {
            (new GradingEngineService())->recalculateOffering($offeringId);
        }

        return ['success' => true, 'message' => 'Grading scheme updated.', 'scheme' => $this->schemeModel->find($scheme['id'])];
    }

    /**
     * Why the scheme can't be published yet, or null when it can. When the
     * scheme uses periods this checks both levels: the periods themselves
     * must total 100%, and so must each period's own categories.
     */
    public function publishProblem(int $schemeId): ?string
    {
        $scheme = $this->schemeModel->find($schemeId);

        if (empty($scheme['uses_periods'])) {

            $categories = $this->categoryModel->listForScheme($schemeId);

            if ($categories === []) {
                return 'Add at least one category before publishing.';
            }

            $total = round(array_sum(array_map(static fn ($c) => (float) $c['weight_percentage'], $categories)), 2);

            if (abs($total - 100) > 0.001) {
                return "Category weights add up to {$total}% — they must total 100%.";
            }

            return null;

        }

        $periods = $this->periodModel->listForScheme($schemeId);

        if ($periods === []) {
            return 'Add at least one grading period before publishing.';
        }

        $periodTotal = round(array_sum(array_map(static fn ($p) => (float) $p['weight_percentage'], $periods)), 2);

        if (abs($periodTotal - 100) > 0.001) {
            return "Period weights add up to {$periodTotal}% — they must total 100%.";
        }

        foreach ($periods as $period) {

            $categories = $this->categoryModel->listForPeriod((int) $period['id']);

            if ($categories === []) {
                return "Add at least one category to \"{$period['name']}\" before publishing.";
            }

            $total = round(array_sum(array_map(static fn ($c) => (float) $c['weight_percentage'], $categories)), 2);

            if (abs($total - 100) > 0.001) {
                return "\"{$period['name']}\"'s category weights add up to {$total}% — they must total 100%.";
            }

        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    public function createCategory(int $offeringId, array $data): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            return ['success' => false, 'message' => 'Category name is required.'];
        }

        $weight = (float) ($data['weight_percentage'] ?? 0);

        if ($weight < 0 || $weight > 100) {
            return ['success' => false, 'message' => 'Weight must be between 0 and 100.'];
        }

        $periodId = null;

        if (!empty($data['period_id'])) {

            $periodId = (int) $data['period_id'];

            if (!$this->ownedPeriod($scheme, $periodId)) {
                return ['success' => false, 'message' => 'Grading period not found.'];
            }

        }

        $last = $this->categoryModel->where('grading_scheme_id', $scheme['id'])->orderBy('sort_order', 'DESC')->first();

        $now = date('Y-m-d H:i:s');

        $this->categoryModel->insert([
            'grading_scheme_id' => $scheme['id'],
            'period_id' => $periodId,
            'name' => $name,
            'weight_percentage' => $weight,
            'sort_order' => $last ? (int) $last['sort_order'] + 1 : 1,
            'created_at' => $now,
            'updated_at' => $now,
            'status' => 'Active',
        ]);

        $this->schemeModel->markNeedsRecalculation((int) $scheme['id']);

        return ['success' => true, 'message' => 'Category added.', 'category' => $this->categoryModel->find($this->categoryModel->getInsertID())];
    }

    public function updateCategory(int $offeringId, int $categoryId, array $data): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        $category = $this->ownedCategory($scheme, $categoryId);

        if (!$category) {
            return ['success' => false, 'message' => 'Category not found.'];
        }

        $update = [];

        if (array_key_exists('name', $data)) {

            $name = trim((string) $data['name']);

            if ($name === '') {
                return ['success' => false, 'message' => 'Category name is required.'];
            }

            $update['name'] = $name;

        }

        if (array_key_exists('weight_percentage', $data)) {

            $weight = (float) $data['weight_percentage'];

            if ($weight < 0 || $weight > 100) {
                return ['success' => false, 'message' => 'Weight must be between 0 and 100.'];
            }

            $update['weight_percentage'] = $weight;

        }

        if (array_key_exists('period_id', $data)) {

            $periodId = !empty($data['period_id']) ? (int) $data['period_id'] : null;

            if ($periodId !== null && !$this->ownedPeriod($scheme, $periodId)) {
                return ['success' => false, 'message' => 'Grading period not found.'];
            }

            $update['period_id'] = $periodId;

        }

        if ($update === []) {
            return ['success' => true, 'message' => 'Nothing to update.', 'category' => $category];
        }

        $this->categoryModel->update($categoryId, $update + ['updated_at' => date('Y-m-d H:i:s')]);

        // A rename doesn't change anyone's grade; a weight or period move does.
        $weightChanged = array_key_exists('weight_percentage', $update)
            && (float) $update['weight_percentage'] !== (float) $category['weight_percentage'];

        $periodChanged = array_key_exists('period_id', $update)
            && $update['period_id'] !== ($category['period_id'] !== null ? (int) $category['period_id'] : null);

        if ($weightChanged || $periodChanged) {
            $this->schemeModel->markNeedsRecalculation((int) $scheme['id']);
        }

        return ['success' => true, 'message' => 'Category updated.', 'category' => $this->categoryModel->find($categoryId)];
    }

    /*
    |--------------------------------------------------------------------------
    | Grading periods
    |--------------------------------------------------------------------------
    */

    public function createPeriod(int $offeringId, array $data): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            return ['success' => false, 'message' => 'Period name is required.'];
        }

        $weight = (float) ($data['weight_percentage'] ?? 0);

        if ($weight < 0 || $weight > 100) {
            return ['success' => false, 'message' => 'Weight must be between 0 and 100.'];
        }

        $last = $this->periodModel->where('grading_scheme_id', $scheme['id'])->orderBy('sort_order', 'DESC')->first();

        $now = date('Y-m-d H:i:s');

        $this->periodModel->insert([
            'grading_scheme_id' => $scheme['id'],
            'name' => $name,
            'weight_percentage' => $weight,
            'sort_order' => $last ? (int) $last['sort_order'] + 1 : 1,
            'created_at' => $now,
            'updated_at' => $now,
            'status' => 'Active',
        ]);

        $this->schemeModel->markNeedsRecalculation((int) $scheme['id']);

        return ['success' => true, 'message' => 'Period added.', 'period' => $this->periodModel->find($this->periodModel->getInsertID())];
    }

    public function updatePeriod(int $offeringId, int $periodId, array $data): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        $period = $this->ownedPeriod($scheme, $periodId);

        if (!$period) {
            return ['success' => false, 'message' => 'Grading period not found.'];
        }

        $update = [];

        if (array_key_exists('name', $data)) {

            $name = trim((string) $data['name']);

            if ($name === '') {
                return ['success' => false, 'message' => 'Period name is required.'];
            }

            $update['name'] = $name;

        }

        if (array_key_exists('weight_percentage', $data)) {

            $weight = (float) $data['weight_percentage'];

            if ($weight < 0 || $weight > 100) {
                return ['success' => false, 'message' => 'Weight must be between 0 and 100.'];
            }

            $update['weight_percentage'] = $weight;

        }

        if ($update === []) {
            return ['success' => true, 'message' => 'Nothing to update.', 'period' => $period];
        }

        $this->periodModel->update($periodId, $update + ['updated_at' => date('Y-m-d H:i:s')]);

        if (array_key_exists('weight_percentage', $update) && (float) $update['weight_percentage'] !== (float) $period['weight_percentage']) {
            $this->schemeModel->markNeedsRecalculation((int) $scheme['id']);
        }

        return ['success' => true, 'message' => 'Period updated.', 'period' => $this->periodModel->find($periodId)];
    }

    /**
     * Archives the period and its categories (their items become
     * Uncategorized, same as deleteCategory) so nothing is left silently
     * uncounted.
     */
    public function deletePeriod(int $offeringId, int $periodId): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        $period = $this->ownedPeriod($scheme, $periodId);

        if (!$period) {
            return ['success' => false, 'message' => 'Grading period not found.'];
        }

        foreach ($this->categoryModel->listForPeriod($periodId) as $category) {
            $this->deleteCategory($offeringId, (int) $category['id']);
        }

        $this->periodModel->update($periodId, ['status' => 'Archived', 'updated_at' => date('Y-m-d H:i:s')]);

        $this->schemeModel->markNeedsRecalculation((int) $scheme['id']);

        return ['success' => true, 'message' => 'Period removed.'];
    }

    /**
     * Wraps every existing Active category into one new "Full Term" period
     * at 100% — called once, the moment periods are switched on, so the
     * grade a student had a second before stays exactly the same after.
     */
    private function wrapCategoriesIntoFullTermPeriod(int $schemeId): void
    {
        $now = date('Y-m-d H:i:s');

        $this->periodModel->insert([
            'grading_scheme_id' => $schemeId,
            'name' => 'Full Term',
            'weight_percentage' => 100,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'status' => 'Active',
        ]);

        $periodId = (int) $this->periodModel->getInsertID();

        $this->categoryModel
            ->where('grading_scheme_id', $schemeId)
            ->where('status', 'Active')
            ->set(['period_id' => $periodId])
            ->update();
    }

    /**
     * Archives the category; its items become Uncategorized (and stop
     * counting) rather than disappearing.
     */
    public function deleteCategory(int $offeringId, int $categoryId): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        $category = $this->ownedCategory($scheme, $categoryId);

        if (!$category) {
            return ['success' => false, 'message' => 'Category not found.'];
        }

        $this->categoryModel->update($categoryId, ['status' => 'Archived', 'updated_at' => date('Y-m-d H:i:s')]);

        $this->itemModel->where('category_id', $categoryId)->set(['category_id' => null])->update();

        $this->schemeModel->markNeedsRecalculation((int) $scheme['id']);

        return ['success' => true, 'message' => 'Category removed.'];
    }

    /*
    |--------------------------------------------------------------------------
    | Grade items
    |--------------------------------------------------------------------------
    */

    /**
     * A manual item (Attendance, Participation…) — no assessment behind it.
     */
    public function createManualItem(User $user, int $offeringId, int $categoryId, array $data): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        if (!$this->ownedCategory($scheme, $categoryId)) {
            return ['success' => false, 'message' => 'Category not found.'];
        }

        $name = trim((string) ($data['name'] ?? ''));
        $maxPoints = (float) ($data['max_points'] ?? 0);

        if ($name === '') {
            return ['success' => false, 'message' => 'Item name is required.'];
        }

        if ($maxPoints <= 0) {
            return ['success' => false, 'message' => 'Max points must be more than 0.'];
        }

        $last = $this->itemModel->where('grading_scheme_id', $scheme['id'])->orderBy('sort_order', 'DESC')->first();

        $now = date('Y-m-d H:i:s');

        // No scores yet, so nobody's grade changes — no recalculation needed.
        $this->itemModel->insert([
            'grading_scheme_id' => $scheme['id'],
            'category_id' => $categoryId,
            'assessment_id' => null,
            'name' => $name,
            'max_points' => $maxPoints,
            'sort_order' => $last ? (int) $last['sort_order'] + 1 : 1,
            'created_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
            'status' => 'Active',
        ]);

        return ['success' => true, 'message' => 'Item added.', 'item' => $this->itemModel->find($this->itemModel->getInsertID())];
    }

    /**
     * Move an item to another category, or rename / re-point a manual item.
     * An assessment-backed item's points change only through Resync.
     */
    public function updateItem(User $user, int $offeringId, int $itemId, array $data): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        $item = $this->ownedItem($scheme, $itemId);

        if (!$item) {
            return ['success' => false, 'message' => 'Item not found.'];
        }

        $isManual = empty($item['assessment_id']);

        $update = [];
        $affectsGrades = false;

        if (array_key_exists('category_id', $data)) {

            $categoryId = $data['category_id'] ? (int) $data['category_id'] : null;

            if ($categoryId !== null && !$this->ownedCategory($scheme, $categoryId)) {
                return ['success' => false, 'message' => 'Category not found.'];
            }

            $affectsGrades = $affectsGrades || $categoryId !== ($item['category_id'] ? (int) $item['category_id'] : null);
            $update['category_id'] = $categoryId;

        }

        if ($isManual && array_key_exists('name', $data)) {

            $name = trim((string) $data['name']);

            if ($name === '') {
                return ['success' => false, 'message' => 'Item name is required.'];
            }

            $update['name'] = $name;

        }

        if ($isManual && array_key_exists('max_points', $data)) {

            $maxPoints = (float) $data['max_points'];

            if ($maxPoints <= 0) {
                return ['success' => false, 'message' => 'Max points must be more than 0.'];
            }

            $affectsGrades = $affectsGrades || $maxPoints !== (float) $item['max_points'];
            $update['max_points'] = $maxPoints;

        }

        if ($update !== []) {
            $this->itemModel->update($itemId, $update + ['updated_by' => $user->id, 'updated_at' => date('Y-m-d H:i:s')]);
        }

        if ($affectsGrades && $this->hasScores($itemId)) {
            $this->schemeModel->markNeedsRecalculation((int) $scheme['id']);
        }

        return ['success' => true, 'message' => 'Item updated.', 'item' => $this->itemModel->find($itemId)];
    }

    /**
     * Stops an item counting. Archived, not deleted — its scores stay for
     * history. An excluded assessment stays excluded (re-publishing it
     * doesn't bring it back).
     */
    public function deleteItem(User $user, int $offeringId, int $itemId): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        $item = $this->ownedItem($scheme, $itemId);

        if (!$item) {
            return ['success' => false, 'message' => 'Item not found.'];
        }

        $this->itemModel->update($itemId, [
            'status' => 'Archived',
            'updated_by' => $user->id,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($this->hasScores($itemId)) {
            $this->schemeModel->markNeedsRecalculation((int) $scheme['id']);
        }

        return ['success' => true, 'message' => 'Item removed from the gradebook.'];
    }

    public function resyncItem(User $user, int $offeringId, int $itemId): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        if (!$this->ownedItem($scheme, $itemId)) {
            return ['success' => false, 'message' => 'Item not found.'];
        }

        return (new GradeItemSyncService())->resync($user, $itemId);
    }

    public function recalculate(int $offeringId): array
    {
        if ($locked = (new GradeFinalizationService())->lockRefusal($offeringId)) {
            return $locked;
        }

        $scheme = $this->forOffering($offeringId);

        $problem = $this->publishProblem((int) $scheme['id']);

        if ($problem !== null) {
            return ['success' => false, 'message' => $problem];
        }

        $count = (new GradingEngineService())->recalculateOffering($offeringId);

        GradeAudit::record('grades.recalculated', $offeringId, ['new' => ['students' => $count]]);

        return ['success' => true, 'message' => "Recalculated {$count} " . ($count === 1 ? 'student' : 'students') . '.'];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function ownedCategory(array $scheme, int $categoryId): ?array
    {
        $category = $this->categoryModel->find($categoryId);

        return $category
            && (int) $category['grading_scheme_id'] === (int) $scheme['id']
            && $category['status'] === 'Active'
                ? $category
                : null;
    }

    private function ownedPeriod(array $scheme, int $periodId): ?array
    {
        $period = $this->periodModel->find($periodId);

        return $period
            && (int) $period['grading_scheme_id'] === (int) $scheme['id']
            && $period['status'] === 'Active'
                ? $period
                : null;
    }

    private function ownedItem(array $scheme, int $itemId): ?array
    {
        $item = $this->itemModel->find($itemId);

        return $item
            && (int) $item['grading_scheme_id'] === (int) $scheme['id']
            && $item['status'] === 'Active'
                ? $item
                : null;
    }

    private function hasScores(int $itemId): bool
    {
        return $this->scoreModel->where('grade_item_id', $itemId)->countAllResults() > 0;
    }
}
