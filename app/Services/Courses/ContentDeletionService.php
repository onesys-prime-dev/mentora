<?php

namespace App\Services\Courses;

use App\Services\Storage\StorageService;

/**
 * Deletes course content — a module, a learning unit, a learning item or
 * an assessment — and everything hanging off it.
 *
 * Foreign keys cascade module → units → items → resources/completions/
 * prerequisites, but assessments only get SET NULL, so the quizzes and
 * assignments linked to the deleted items are deleted here explicitly
 * (their own keys cascade to questions, submissions, rubrics and AI
 * batches). Uploaded files are rows other rows point at, so nothing
 * cascades to them; they're purged from storage once the transaction has
 * committed. Siblings are renumbered so "Module 3" / "Unit 2.1" stay
 * contiguous.
 */
class ContentDeletionService
{
    protected StorageService $storageService;

    public function __construct()
    {
        $this->storageService = new StorageService();
    }

    public function deleteModule(int $moduleId): bool
    {
        $db = db_connect();

        $module = $db->table('course_modules')->where('id', $moduleId)->get()->getRowArray();

        if (! $module) {
            return false;
        }

        $itemIds = $this->column(
            $db->table('learning_items')
                ->select('learning_items.id')
                ->join('learning_units', 'learning_units.id = learning_items.learning_unit_id')
                ->where('learning_units.module_id', $moduleId),
            'id'
        );

        return $this->run(
            $itemIds,
            [],
            fn () => $db->table('course_modules')->where('id', $moduleId)->delete(),
            fn () => $this->renumber('course_modules', 'offering_id', (int) $module['offering_id'])
        );
    }

    public function deleteLearningUnit(int $learningUnitId): bool
    {
        $db = db_connect();

        $unit = $db->table('learning_units')->where('id', $learningUnitId)->get()->getRowArray();

        if (! $unit) {
            return false;
        }

        $itemIds = $this->column(
            $db->table('learning_items')->select('id')->where('learning_unit_id', $learningUnitId),
            'id'
        );

        return $this->run(
            $itemIds,
            [],
            fn () => $db->table('learning_units')->where('id', $learningUnitId)->delete(),
            fn () => $this->renumber('learning_units', 'module_id', (int) $unit['module_id'])
        );
    }

    public function deleteLearningItem(int $learningItemId): bool
    {
        $db = db_connect();

        $item = $db->table('learning_items')->where('id', $learningItemId)->get()->getRowArray();

        if (! $item) {
            return false;
        }

        return $this->run(
            [$learningItemId],
            [],
            fn () => $db->table('learning_items')->where('id', $learningItemId)->delete(),
            fn () => $this->afterItemRemoved((int) $item['learning_unit_id'])
        );
    }

    /**
     * Deleting an assessment also deletes its learning item, so Modules
     * never shows a quiz/assignment with nothing behind it.
     */
    public function deleteAssessment(int $assessmentId): bool
    {
        $db = db_connect();

        $assessment = $db->table('assessments')->where('id', $assessmentId)->get()->getRowArray();

        if (! $assessment) {
            return false;
        }

        $itemId = (int) ($assessment['learning_item_id'] ?? 0);

        $item = $itemId
            ? $db->table('learning_items')->where('id', $itemId)->get()->getRowArray()
            : null;

        return $this->run(
            $item ? [$itemId] : [],
            [$assessmentId],
            fn () => $item ? $db->table('learning_items')->where('id', $itemId)->delete() : true,
            fn () => $item ? $this->afterItemRemoved((int) $item['learning_unit_id']) : null
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<int>  $itemIds       learning items being removed (with the root)
     * @param list<int>  $assessmentIds assessments to remove besides the items' own
     * @param callable   $deleteRoot    deletes the module/unit/item itself
     * @param callable   $afterCommit   bookkeeping once the rows are gone
     */
    private function run(array $itemIds, array $assessmentIds, callable $deleteRoot, callable $afterCommit): bool
    {
        $db = db_connect();

        $itemIds = array_map('intval', $itemIds);

        if ($itemIds !== []) {
            $assessmentIds = array_merge(
                $assessmentIds,
                $this->column($db->table('assessments')->select('id')->whereIn('learning_item_id', $itemIds), 'id')
            );
        }

        $assessmentIds = array_values(array_unique(array_map('intval', $assessmentIds)));

        $fileIds = $this->filesOwnedBy($itemIds, $assessmentIds);

        $db->transStart();

        if ($assessmentIds !== []) {
            $db->table('assessments')->whereIn('id', $assessmentIds)->delete();
        }

        $deleteRoot();

        $db->transComplete();

        if ($db->transStatus() === false) {
            return false;
        }

        $afterCommit();

        $this->storageService->purgeFiles($fileIds);

        return true;
    }

    /**
     * Every uploaded file the given items and assessments point at:
     * lesson videos/PDFs, submitted assignment files, quiz file answers
     * and AI generation sources.
     *
     * @return list<int>
     */
    private function filesOwnedBy(array $itemIds, array $assessmentIds): array
    {
        $db = db_connect();

        $fileIds = [];

        if ($itemIds !== []) {
            $fileIds = $this->column(
                $db->table('learning_resources')->select('file_id')->whereIn('learning_item_id', $itemIds)->where('file_id IS NOT NULL'),
                'file_id'
            );
        }

        if ($assessmentIds !== []) {

            $fileIds = array_merge(

                $fileIds,

                $this->column(
                    $db->table('assessment_submissions')->select('submission_file_id')
                        ->whereIn('assessment_id', $assessmentIds)->where('submission_file_id IS NOT NULL'),
                    'submission_file_id'
                ),

                $this->column(
                    $db->table('assessment_question_responses')->select('assessment_question_responses.uploaded_file_id')
                        ->join('assessment_submissions', 'assessment_submissions.id = assessment_question_responses.submission_id')
                        ->whereIn('assessment_submissions.assessment_id', $assessmentIds)
                        ->where('assessment_question_responses.uploaded_file_id IS NOT NULL'),
                    'uploaded_file_id'
                ),

                $this->column(
                    $db->table('ai_generation_batches')->select('source_file_id')
                        ->whereIn('assessment_id', $assessmentIds)->where('source_file_id IS NOT NULL'),
                    'source_file_id'
                ),

            );

        }

        return array_values(array_unique(array_map('intval', $fileIds)));
    }

    /**
     * Removing an item can leave a custom prerequisite list empty; such
     * items fall back to no dependency. Then close the gap in ordering.
     */
    private function afterItemRemoved(int $learningUnitId): void
    {
        (new LearningProgressService())->pruneDependencies($learningUnitId);

        $this->renumber('learning_items', 'learning_unit_id', $learningUnitId);
    }

    private function renumber(string $table, string $parentColumn, int $parentId): void
    {
        $db = db_connect();

        $ids = $this->column(
            $db->table($table)->select('id')->where($parentColumn, $parentId)->orderBy('order_index', 'ASC')->orderBy('id', 'ASC'),
            'id'
        );

        foreach ($ids as $index => $id) {
            $db->table($table)->where('id', $id)->update(['order_index' => $index + 1]);
        }
    }

    /**
     * @return list<int>
     */
    private function column($builder, string $column): array
    {
        return array_map(
            static fn (array $row) => (int) $row[$column],
            $builder->get()->getResultArray()
        );
    }
}
