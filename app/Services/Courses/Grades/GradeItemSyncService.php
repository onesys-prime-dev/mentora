<?php

namespace App\Services\Courses\Grades;

use CodeIgniter\Shield\Entities\User;

use App\Models\Assessments\AssessmentModel;
use App\Models\Grades\GradingSchemeModel;
use App\Models\Grades\GradeCategoryModel;
use App\Models\Grades\GradeItemModel;

/**
 * Keeps grade items in step with assessments.
 *
 * Publishing an assessment makes it a grade item (eager sync), filed under
 * the category matching its type (Quiz → "Quizzes"). The item's max_points
 * is a snapshot: editing the quiz later flags the item out of sync instead of
 * silently reweighting it; Resync accepts the new total.
 */
class GradeItemSyncService
{
    /** Assessment statuses that count toward the grade. */
    public const COUNTED_STATUSES = ['Published', 'Closed'];

    protected AssessmentModel $assessmentModel;
    protected GradingSchemeModel $schemeModel;
    protected GradeCategoryModel $categoryModel;
    protected GradeItemModel $itemModel;

    public function __construct()
    {
        $this->assessmentModel = new AssessmentModel();
        $this->schemeModel = new GradingSchemeModel();
        $this->categoryModel = new GradeCategoryModel();
        $this->itemModel = new GradeItemModel();
    }

    /**
     * Makes a published assessment a grade item (once). An existing item —
     * including one the instructor excluded — is left as it is.
     */
    public function syncFromAssessment(int $assessmentId): void
    {
        $assessment = $this->assessmentModel->find($assessmentId);

        if (!$assessment || !in_array($assessment['status'], self::COUNTED_STATUSES, true)) {
            return;
        }

        if ($this->itemModel->findForAssessment($assessmentId)) {
            return;
        }

        $offeringId = $this->offeringOf($assessment);

        if (!$offeringId) {
            return;
        }

        $scheme = (new GradingSchemeService())->forOffering($offeringId);

        // forOffering() may have just created the scheme — and this item with it.
        if ($this->itemModel->findForAssessment($assessmentId)) {
            return;
        }

        $this->createItem($scheme, $assessment);

        // Already-graded work (e.g. published again after edits) counts now;
        // only then does anyone's grade change.
        if ((new GradebookSyncService())->syncAssessmentScores($assessmentId) > 0) {
            $this->schemeModel->markNeedsRecalculation((int) $scheme['id']);
        }
    }

    /**
     * Every counted assessment of the offering → grade items, with their
     * current scores. Used when an offering's scheme is first created.
     */
    public function syncOffering(int $offeringId): void
    {
        $scheme = $this->schemeModel->findForOffering($offeringId);

        if (!$scheme) {
            return;
        }

        $assessments = $this->assessmentModel
            ->select('assessments.*')
            ->join('course_modules cm', 'cm.id = assessments.module_id')
            ->where('cm.offering_id', $offeringId)
            ->whereIn('assessments.status', self::COUNTED_STATUSES)
            ->orderBy('assessments.due_date', 'ASC')
            ->orderBy('assessments.id', 'ASC')
            ->findAll();

        $scores = new GradebookSyncService();

        foreach ($assessments as $assessment) {

            if ($this->itemModel->findForAssessment((int) $assessment['id'])) {
                continue;
            }

            $this->createItem($scheme, $assessment);

            $scores->syncAssessmentScores((int) $assessment['id']);

        }
    }

    /**
     * The assessment is being deleted: its item stops counting but is kept
     * (archived), with its scores, for history.
     */
    public function archiveForAssessment(int $assessmentId): void
    {
        $item = $this->itemModel->findForAssessment($assessmentId);

        if (!$item || $item['status'] === 'Archived') {
            return;
        }

        $this->itemModel->update($item['id'], [
            'status' => 'Archived',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->schemeModel->markNeedsRecalculation((int) $item['grading_scheme_id']);
    }

    /**
     * The assessment's points changed since the snapshot was taken.
     *
     * @param array $item a grade item row carrying current_points (GradeItemModel::listForScheme)
     */
    public function isOutOfSync(array $item): bool
    {
        if (empty($item['assessment_id']) || !array_key_exists('current_points', $item) || $item['current_points'] === null) {
            return false;
        }

        return abs((float) $item['current_points'] - (float) $item['max_points']) > 0.001;
    }

    /**
     * Accept the assessment's current total as the item's max points.
     */
    public function resync(User $user, int $itemId): array
    {
        $item = $this->itemModel->find($itemId);

        if (!$item || empty($item['assessment_id'])) {
            return ['success' => false, 'message' => 'Only quiz and assignment items can be resynced.'];
        }

        $assessment = $this->assessmentModel->find((int) $item['assessment_id']);

        if (!$assessment) {
            return ['success' => false, 'message' => 'The assessment no longer exists.'];
        }

        $this->itemModel->update($itemId, [
            'max_points' => (float) $assessment['total_points'],
            'updated_by' => $user->id,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->schemeModel->markNeedsRecalculation((int) $item['grading_scheme_id']);

        return ['success' => true, 'message' => 'Points updated — recalculate to apply.', 'item' => $this->itemModel->find($itemId)];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createItem(array $scheme, array $assessment): void
    {
        $last = $this->itemModel->where('grading_scheme_id', $scheme['id'])->orderBy('sort_order', 'DESC')->first();

        $now = date('Y-m-d H:i:s');

        $this->itemModel->insert([
            'grading_scheme_id' => $scheme['id'],
            'category_id' => $this->categoryFor((int) $scheme['id'], (int) $assessment['assessment_type_id']),
            'assessment_id' => $assessment['id'],
            'name' => $assessment['title'],
            'max_points' => (float) $assessment['total_points'],
            'sort_order' => $last ? (int) $last['sort_order'] + 1 : 1,
            'created_at' => $now,
            'updated_at' => $now,
            'status' => 'Active',
        ]);
    }

    /**
     * The category named after the assessment's type ("Quiz" → "Quizzes"),
     * or null — left Uncategorized for the instructor rather than guessed.
     */
    private function categoryFor(int $schemeId, int $assessmentTypeId): ?int
    {
        $type = db_connect()->table('assessment_types')->select('name')->where('id', $assessmentTypeId)->get()->getRowArray();

        if (!$type) {
            return null;
        }

        $name = strtolower(trim($type['name']));

        $candidates = [$name, $name . 's', $name . 'es', $name . 'zes'];

        foreach ($this->categoryModel->listForScheme($schemeId) as $category) {
            if (in_array(strtolower(trim($category['name'])), $candidates, true)) {
                return (int) $category['id'];
            }
        }

        return null;
    }

    public function offeringOf(array $assessment): ?int
    {
        if (empty($assessment['module_id'])) {
            return null;
        }

        $row = db_connect()->table('course_modules')->select('offering_id')->where('id', $assessment['module_id'])->get()->getRowArray();

        return $row ? (int) $row['offering_id'] : null;
    }
}
