<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\HTTP\Files\UploadedFile;

use App\Models\Assessments\AiGenerationBatchModel;
use App\Models\Assessments\AiGeneratedQuestionModel;
use App\Models\Assessments\AssessmentModel;
use App\Models\Files\FileModel;

use App\Services\AI\LocalQuestionBuilderClient;
use App\Services\AI\QuestionGeneratorClientInterface;
use App\Services\AI\QuestionGeneratorBusyException;
use App\Services\AI\QuestionGeneratorUnavailableException;
use App\Services\AI\SimilarityChecker;
use App\Services\Storage\StorageService;

class QuestionGenerationService
{
    protected AiGenerationBatchModel $batchModel;
    protected AiGeneratedQuestionModel $generatedQuestionModel;
    protected AssessmentModel $assessmentModel;
    protected FileModel $fileModel;

    protected QuestionGeneratorClientInterface $client;
    protected SimilarityChecker $similarityChecker;
    protected StorageService $storageService;
    protected LearningUnitContentExtractor $contentExtractor;

    public function __construct(?QuestionGeneratorClientInterface $client = null)
    {
        $this->batchModel = new AiGenerationBatchModel();
        $this->generatedQuestionModel = new AiGeneratedQuestionModel();
        $this->assessmentModel = new AssessmentModel();
        $this->fileModel = new FileModel();

        $this->client = $client ?? new LocalQuestionBuilderClient();
        $this->similarityChecker = new SimilarityChecker();
        $this->storageService = new StorageService();
        $this->contentExtractor = new LearningUnitContentExtractor();
    }

    /**
     * Start a new batch and generate the first chunk. The JS side calls
     * nextChunk() repeatedly afterward until requested_count is reached.
     *
     * @param array $data {
     *     assessment_id: int,
     *     course_id: int,
     *     offering_id: int,
     *     source_type: 'topic'|'text'|'file'|'learning_unit' (the quiz's own unit),
     *     topic?: string,
     *     source_text?: string,
     *     count: int,
     *     type_mix?: array,
     *     points_per_question?: float,
     * }
     */
    public function startBatch(User $user, array $data, ?UploadedFile $file = null): array
    {
        $assessment = !empty($data['assessment_id'])
            ? $this->assessmentModel->find($data['assessment_id'])
            : null;

        if (!$assessment) {
            return ['success' => false, 'message' => 'Assessment not found.'];
        }

        $sourceType = $data['source_type'] ?? 'topic';

        $sourceFileId = null;
        $sourceText = $data['source_text'] ?? null;

        if ($sourceType === 'file') {

            if (!$file || !$file->isValid()) {
                return ['success' => false, 'message' => 'A valid file is required.'];
            }

            $stored = $this->storageService->upload($file, 'ai-generation-sources');

            $this->fileModel->insert([
                'owner_user_id' => $user->id,
                'original_filename' => $stored['original_name'],
                'stored_filename' => $stored['filename'],
                'mime_type' => $stored['mime_type'],
                'file_size' => $stored['size'],
                'storage_key' => $stored['storage_key'],
                'status' => 'active',
            ]);

            $sourceFileId = $this->fileModel->getInsertID();

            try {

                $extracted = $this->client->extractText(
                    $this->storageService->path($stored['storage_key']),
                    $stored['mime_type']
                );

                $sourceText = $extracted['text'];

            } catch (QuestionGeneratorUnavailableException $e) {

                return [
                    'success' => false,
                    'message' => 'Could not read that file: ' . $e->getMessage(),
                ];

            }

        }

        $learningUnitId = null;
        $skippedSources = [];

        if ($sourceType === 'learning_unit') {

            // Always the unit this quiz sits under — never a client-supplied
            // id, so generation can't read another unit or course.
            $unit = $this->contentExtractor->unitForAssessment($assessment, $user);

            if (!$unit) {
                return ['success' => false, 'message' => "This quiz isn't in a learning unit."];
            }

            $learningUnitId = $unit['id'];

            $extracted = $this->contentExtractor->extract($learningUnitId, $user, $this->client);

            if (trim($extracted['text']) === '') {

                return [
                    'success' => false,
                    'message' => $extracted['failure']
                        ?? 'That learning unit has no readings or PDFs to generate questions from.',
                ];

            }

            $sourceText = $extracted['text'];
            $skippedSources = $extracted['skipped'];

        }

        $now = date('Y-m-d H:i:s');

        $this->batchModel->insert([

            'assessment_id' => $data['assessment_id'] ?? null,
            'course_id' => $data['course_id'],
            'source_type' => $sourceType,
            'topic' => $data['topic'] ?? null,
            'source_file_id' => $sourceFileId,
            'learning_unit_id' => $learningUnitId,
            'source_text' => $sourceText,
            'type_mix' => isset($data['type_mix']) ? json_encode($data['type_mix']) : null,
            'requested_count' => $data['count'] ?? 10,
            'generated_count' => 0,
            'points_per_question' => $data['points_per_question'] ?? null,
            'status' => 'generating',
            'created_by' => $user->id,
            'created_at' => $now,

        ]);

        $batchId = $this->batchModel->getInsertID();

        $result = $this->generateChunk($batchId);

        // One-time notice for the tray: items in the chosen unit that exist
        // but couldn't be read, so the questions won't cover them.
        $result['skipped_sources'] = $skippedSources;

        return $result;
    }

    public function nextChunk(int $batchId): array
    {
        $batch = $this->batchModel->find($batchId);

        if (!$batch) {
            return ['success' => false, 'message' => 'Batch not found.'];
        }

        if (in_array($batch['status'], ['completed', 'failed'], true)) {
            return $this->batchSummary($batch);
        }

        return $this->generateChunk($batchId);
    }

    public function getBatch(int $batchId): array
    {
        $batch = $this->batchModel->find($batchId);

        if (!$batch) {
            return ['success' => false, 'message' => 'Batch not found.'];
        }

        return $this->batchSummary($batch);
    }

    public function updateGeneratedQuestion(int $id, array $data): array
    {
        $row = $this->generatedQuestionModel->find($id);

        if (!$row) {
            return ['success' => false, 'message' => 'Question not found.'];
        }

        $update = array_merge([
            'question_text' => $data['question_text'] ?? $row['question_text'],
            'question_type' => $data['question_type'] ?? $row['question_type'],
            'points' => $data['points'] ?? $row['points'],
            'is_required' => array_key_exists('is_required', $data)
                ? !empty($data['is_required'])
                : $row['is_required'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], $this->resolveMultipleAnswers($data, $row));

        if (array_key_exists('choices', $data)) {
            $update['choices'] = json_encode($data['choices']);
        }

        // Short Answer answer key; other types never keep one.
        if ($update['question_type'] === 'Short Answer') {

            if (array_key_exists('accepted_answers', $data)) {
                $update['accepted_answers'] = ShortAnswerGrader::encode($data['accepted_answers']);
            }

            if (array_key_exists('case_sensitive', $data)) {
                $update['case_sensitive'] = (int) filter_var($data['case_sensitive'], FILTER_VALIDATE_BOOLEAN);
            }

        } else {

            $update['accepted_answers'] = null;
            $update['case_sensitive'] = 0;

        }

        $this->generatedQuestionModel->update($id, $update);

        return [
            'success' => true,
            'question' => $this->generatedQuestionModel->getDecoded($id),
        ];
    }

    public function acceptGeneratedQuestion(User $user, int $id): array
    {
        $row = $this->generatedQuestionModel->getDecoded($id);

        if (!$row) {
            return ['success' => false, 'message' => 'Question not found.'];
        }

        $batch = $this->batchModel->find($row['batch_id']);

        if (empty($batch['assessment_id'])) {
            return ['success' => false, 'message' => 'This batch is not linked to an assessment.'];
        }

        $result = (new QuestionService())->createQuestion($user, $batch['assessment_id'], [
            'question_text' => $row['question_text'],
            'question_type' => $row['question_type'],
            'points' => $row['points'],
            'is_required' => $row['is_required'],
            'allow_multiple_answers' => $row['allow_multiple_answers'],
            'answers_count' => $row['answers_count'],
            'accepted_answers' => $row['accepted_answers'],
            'case_sensitive' => $row['case_sensitive'],
            'choices' => $row['choices'],
        ]);

        if ($result['success']) {

            $this->generatedQuestionModel->update($id, [
                'status' => 'accepted',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        }

        return $result;
    }

    public function discardGeneratedQuestion(int $id): array
    {
        $row = $this->generatedQuestionModel->find($id);

        if (!$row) {
            return ['success' => false, 'message' => 'Question not found.'];
        }

        $this->generatedQuestionModel->update($id, [
            'status' => 'discarded',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'message' => 'Discarded.'];
    }

    public function acceptAll(User $user, int $batchId): array
    {
        $batch = $this->batchModel->find($batchId);

        if (!$batch) {
            return ['success' => false, 'message' => 'Batch not found.'];
        }

        if (empty($batch['assessment_id'])) {
            return ['success' => false, 'message' => 'This batch is not linked to an assessment.'];
        }

        $questionService = new QuestionService();

        $acceptedIds = [];
        $createdQuestions = [];

        foreach ($this->generatedQuestionModel->listForBatch($batchId) as $row) {

            $result = $questionService->createQuestion($user, $batch['assessment_id'], [
                'question_text' => $row['question_text'],
                'question_type' => $row['question_type'],
                'points' => $row['points'],
                'is_required' => $row['is_required'],
                'allow_multiple_answers' => $row['allow_multiple_answers'],
                'answers_count' => $row['answers_count'],
                'accepted_answers' => $row['accepted_answers'],
                'case_sensitive' => $row['case_sensitive'],
                'choices' => $row['choices'],
            ]);

            if ($result['success']) {
                $acceptedIds[] = $row['id'];
                $createdQuestions[] = $result['question'];
            }

        }

        if (!empty($acceptedIds)) {

            $this->generatedQuestionModel
                ->whereIn('id', $acceptedIds)
                ->set(['status' => 'accepted', 'updated_at' => date('Y-m-d H:i:s')])
                ->update();

        }

        return [
            'success' => true,
            'message' => count($acceptedIds) . ' question(s) accepted.',
            'accepted_count' => count($acceptedIds),
            // Lets the frontend drop the new cards straight into the live
            // canvas instead of reloading the whole builder.
            'questions' => $createdQuestions,
        ];
    }

    private function generateChunk(int $batchId): array
    {
        $batch = $this->batchModel->find($batchId);

        $remaining = $batch['requested_count'] - $batch['generated_count'];

        if ($remaining <= 0) {

            $this->batchModel->update($batchId, ['status' => 'completed']);

            return $this->batchSummary($this->batchModel->find($batchId));

        }

        $recentQuestions = array_column(
            $this->generatedQuestionModel
                ->where('batch_id', $batchId)
                ->where('status !=', 'discarded')
                ->orderBy('id', 'DESC')
                ->limit(30)
                ->findAll(),
            'question_text'
        );

        try {

            $result = $this->client->generate([
                'topic' => $batch['topic'],
                'source_text' => $batch['source_text'],
                'count' => $remaining,
                'type_mix' => $batch['type_mix'] ? json_decode($batch['type_mix'], true) : null,
                'points_per_question' => $batch['points_per_question'],
                'recent_questions' => $recentQuestions,
            ]);

        } catch (QuestionGeneratorBusyException $e) {

            return ['success' => false, 'busy' => true, 'message' => $e->getMessage()];

        } catch (QuestionGeneratorUnavailableException $e) {

            $this->batchModel->update($batchId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];

        }

        $now = date('Y-m-d H:i:s');

        $insertedCount = 0;

        foreach ($result['questions'] as $question) {

            $choiceTexts = array_map(
                static fn (array $choice) => $choice['text'] ?? '',
                $question['choices'] ?? []
            );

            $similarity = $this->similarityChecker->score(
                $question['question_text'],
                $choiceTexts,
                $batch['source_text']
            );

            $this->generatedQuestionModel->insert([

                'batch_id' => $batchId,
                'question_text' => $question['question_text'],
                'question_type' => $question['question_type'],
                'points' => $question['points'],
                'is_required' => $question['is_required'] ?? true,
                'choices' => json_encode($question['choices'] ?? []),
                // The generator's answer key for Short Answer (main answer
                // first); the instructor can edit it in the review tray.
                'accepted_answers' => ($question['question_type'] ?? '') === 'Short Answer'
                    ? ShortAnswerGrader::encode($question['accepted_answers'] ?? [])
                    : null,
                'similarity_score' => $similarity,
                // Computed by the Python service's deterministic sympy
                // check — never trust the model's own claim of correctness.
                'math_check' => $question['math_check'] ?? 'not_applicable',
                'status' => 'pending',
                'created_at' => $now,

            ]);

            $insertedCount++;

        }

        $newGeneratedCount = $batch['generated_count'] + $insertedCount;

        $this->batchModel->update($batchId, [
            'generated_count' => $newGeneratedCount,
            'status' => $newGeneratedCount >= $batch['requested_count']
                ? 'completed'
                : ($insertedCount === 0 ? 'completed' : 'generating'),
        ]);

        return $this->batchSummary($this->batchModel->find($batchId));
    }

    private function batchSummary(array $batch): array
    {
        return [
            'success' => true,
            'batch' => $batch,
            // Derived, not stored — a batch marked "completed" short of its
            // requested_count means the model ran out of genuinely distinct
            // content for this topic/type mix rather than the batch actually
            // failing (see generateChunk(): a chunk returning 0 new questions
            // ends the batch early, on purpose, rather than looping forever
            // or shipping duplicates).
            'stopped_early' => $batch['status'] === 'completed'
                && (int) $batch['generated_count'] < (int) $batch['requested_count'],
            'questions' => $this->generatedQuestionModel->listForBatch($batch['id']),
        ];
    }

    /**
     * answers_count only means anything when allow_multiple_answers is
     * true — mirrors QuestionService::resolveMultipleAnswers() so a
     * staged question's settings behave identically once accepted.
     */
    private function resolveMultipleAnswers(array $data, array $existing = []): array
    {
        $allowMultiple = array_key_exists('allow_multiple_answers', $data)
            ? !empty($data['allow_multiple_answers'])
            : !empty($existing['allow_multiple_answers'] ?? false);

        if (!$allowMultiple) {
            return ['allow_multiple_answers' => false, 'answers_count' => null];
        }

        $answersCount = array_key_exists('answers_count', $data)
            ? (int) $data['answers_count']
            : (int) ($existing['answers_count'] ?? 2);

        // "Multiple" answers means at least 2 — a 1-answer "multiple
        // answers" question is a single-answer question in disguise.
        return ['allow_multiple_answers' => true, 'answers_count' => max(2, $answersCount)];
    }
}
