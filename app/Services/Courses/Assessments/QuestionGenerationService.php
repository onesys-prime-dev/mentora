<?php

namespace App\Services\Courses\Assessments;

use CodeIgniter\Shield\Entities\User;

use App\Services\Audit\AuditLogger;
use App\Services\Flags\FeatureFlags;
use App\Services\Subscriptions\PlanLimits;
use App\Models\Assessments\AiGenerationBatchModel;
use App\Models\Assessments\AiGeneratedQuestionModel;
use App\Models\Assessments\AssessmentModel;

use App\Services\AI\LocalQuestionBuilderClient;
use App\Services\AI\QuestionGeneratorClientInterface;
use App\Services\AI\QuestionGeneratorBusyException;
use App\Services\AI\QuestionGeneratorUnavailableException;
use App\Services\AI\SimilarityChecker;
use Throwable;

class QuestionGenerationService
{
    protected AiGenerationBatchModel $batchModel;
    protected AiGeneratedQuestionModel $generatedQuestionModel;
    protected AssessmentModel $assessmentModel;

    protected QuestionGeneratorClientInterface $client;
    protected SimilarityChecker $similarityChecker;
    protected LearningUnitContentExtractor $contentExtractor;
    protected AiJobGeneration $jobGeneration;

    /** When on, a batch is a background job for the worker instead of chunks in the request. */
    protected bool $useJobs;

    public function __construct(?QuestionGeneratorClientInterface $client = null, ?bool $useJobs = null)
    {
        $this->useJobs = $useJobs ?? AiJobGeneration::enabled();
        $this->jobGeneration = new AiJobGeneration();
        $this->batchModel = new AiGenerationBatchModel();
        $this->generatedQuestionModel = new AiGeneratedQuestionModel();
        $this->assessmentModel = new AssessmentModel();

        $this->client = $client ?? new LocalQuestionBuilderClient();
        $this->similarityChecker = new SimilarityChecker();
        $this->contentExtractor = new LearningUnitContentExtractor();
    }

    /** The types a generated question may have; Discussion has no builder. */
    private const TYPES = ['Multiple Choice', 'Short Answer', 'Essay', 'File Upload'];

    private const MAX_POINTS = 1000;

    private const MAX_CHOICES = 8;

    /**
     * Whether this batch is the person's own and belongs to a quiz of the
     * offering. Every action on a batch asks this first.
     */
    public function ownsBatch(User $user, int $batchId, int $offeringId): bool
    {
        $batch = $this->batchModel->find($batchId);

        if (! $batch || (int) ($batch['created_by'] ?? 0) !== (int) $user->id || empty($batch['assessment_id'])) {
            return false;
        }

        return (new AssessmentScopeService())->assessmentBelongsTo((int) $batch['assessment_id'], $offeringId);
    }

    /** Whether this generated question sits in one of the person's own batches of the offering. */
    public function ownsGeneratedQuestion(User $user, int $generatedQuestionId, int $offeringId): bool
    {
        $row = $this->generatedQuestionModel->find($generatedQuestionId);

        return $row !== null && $this->ownsBatch($user, (int) $row['batch_id'], $offeringId);
    }

    /**
     * Whether AI question generation is switched on for the person's
     * institution (the platform flag and the institution's own override).
     */
    private function generationEnabled(User $user): bool
    {
        try {
            return (new FeatureFlags())->isEnabled('ai.question_generation', (new PlanLimits())->institutionOf((int) $user->id));
        } catch (Throwable $e) {
            log_message('error', 'Could not read the AI generation switch: ' . $e->getMessage());

            // Unreadable switch: stay available rather than lock every teacher out.
            return true;
        }
    }

    private function audit(User $user, string $action, int $batchId, array $new): void
    {
        try {
            (new AuditLogger())->log($action, $user, [
                'target_type' => 'ai_generation_batch',
                'target_id'   => $batchId,
                'new'         => $new,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'AI generation audit entry failed: ' . $e->getMessage());
        }
    }

    /**
     * Start a new batch. The only source is the learning unit the quiz sits
     * under: the person chooses which of its readings and PDFs to use, and
     * only those are read. The unit is always derived from the assessment,
     * never taken from the request.
     *
     * @param array $data {
     *     assessment_id: int,
     *     course_id: int,
     *     offering_id: int,
     *     learning_item_ids?: list<int>|null (null: every reading and PDF),
     *     count: int,
     *     type_mix?: array,
     *     points_per_question?: float,
     * }
     */
    public function startBatch(User $user, array $data): array
    {
        $assessment = !empty($data['assessment_id'])
            ? $this->assessmentModel->find($data['assessment_id'])
            : null;

        if (!$assessment) {
            return ['success' => false, 'message' => 'Assessment not found.'];
        }

        if (! $this->generationEnabled($user)) {
            return ['success' => false, 'code' => 'disabled', 'message' => 'AI question generation is turned off for your institution.'];
        }

        // The institution's plan caps AI question generations per term. Checked before anything is
        // read or started, so a refused request leaves nothing behind.
        $blocked = (new PlanLimits())->generationBlock($user, (int) ($data['count'] ?? 10));

        if ($blocked !== null) {
            return ['success' => false, 'message' => $blocked];
        }

        $unit = $this->contentExtractor->unitForAssessment($assessment, $user);

        if (!$unit) {
            return [
                'success' => false,
                'message' => "This quiz isn't in a learning unit. Place it in a learning unit to generate questions from its readings and PDFs.",
            ];
        }

        $selection = $this->contentExtractor->selection($unit, $data['learning_item_ids'] ?? null);

        if ($selection === []) {
            return [
                'success' => false,
                'message' => $unit['items'] === []
                    ? 'That learning unit has no readings or PDFs yet.'
                    : 'Choose at least one reading or PDF to generate questions from.',
            ];
        }

        $extracted = $this->contentExtractor->extract($unit['id'], $user, $this->client, $selection);

        if (trim($extracted['text']) === '') {

            return [
                'success' => false,
                'message' => $extracted['failure']
                    ?? 'The items you chose have no text to generate questions from.',
            ];

        }

        $now = date('Y-m-d H:i:s');

        $this->batchModel->insert([

            'assessment_id' => $data['assessment_id'],
            'course_id' => $data['course_id'],
            'source_type' => 'learning_unit',
            'learning_unit_id' => $unit['id'],
            'source_text' => $extracted['text'],
            'source_items' => json_encode($extracted['items'], JSON_UNESCAPED_UNICODE),
            'type_mix' => isset($data['type_mix']) ? json_encode($data['type_mix']) : null,
            'requested_count' => $data['count'] ?? 10,
            'generated_count' => 0,
            'points_per_question' => $data['points_per_question'] ?? null,
            'status' => 'generating',
            'created_by' => $user->id,
            'created_at' => $now,

        ]);

        $batchId = $this->batchModel->getInsertID();

        $this->audit($user, 'ai.batch.started', (int) $batchId, [
            'assessment_id' => (int) $data['assessment_id'],
            'requested'     => (int) ($data['count'] ?? 10),
            'items'         => count($extracted['items']),
        ]);

        if ($this->useJobs) {
            $this->jobGeneration->enqueue($this->batchModel->find($batchId), (int) $user->id);

            $result = $this->getBatch($batchId);
        } else {
            $result = $this->generateChunk($batchId);
        }

        // One-time notice for the tray: chosen items that exist but couldn't
        // be read, so the questions won't cover them.
        $result['skipped_sources'] = $extracted['skipped'];

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

        // A job-backed batch is filled by the worker; asking again only looks.
        if ($this->jobGeneration->sync($batchId) !== null) {
            return $this->getBatch($batchId);
        }

        return $this->generateChunk($batchId);
    }

    public function getBatch(int $batchId): array
    {
        $batch = $this->batchModel->find($batchId);

        if (!$batch) {
            return ['success' => false, 'message' => 'Batch not found.'];
        }

        // For a job-backed batch this also applies a finished job's questions.
        $job = $this->jobGeneration->sync($batchId);

        if ($job !== null) {
            $batch = $this->batchModel->find($batchId);
        }

        return $this->batchSummary($batch) + ($job !== null ? ['job' => $job] : []);
    }

    /** Stops a background batch. */
    public function cancelBatch(User $user, int $batchId): array
    {
        $batch = $this->batchModel->find($batchId);

        if (!$batch) {
            return ['success' => false, 'message' => 'Batch not found.'];
        }

        $this->jobGeneration->cancel($batch, (int) $user->id);

        $this->audit($user, 'ai.batch.cancelled', $batchId, ['generated' => (int) $batch['generated_count']]);

        return $this->getBatch($batchId);
    }

    /** The person's unfinished or unreviewed batch for a quiz, so a reload can reopen the tray. */
    public function pendingBatch(User $user, int $assessmentId): ?array
    {
        $batch = $this->jobGeneration->pendingBatch((int) $user->id, $assessmentId);

        return $batch === null ? null : $this->getBatch((int) $batch['id']);
    }

    public function updateGeneratedQuestion(int $id, array $data): array
    {
        $row = $this->generatedQuestionModel->find($id);

        if (!$row) {
            return ['success' => false, 'message' => 'Question not found.'];
        }

        $invalid = $this->editProblem($data);

        if ($invalid !== null) {
            return ['success' => false, 'message' => $invalid];
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

    /**
     * What is wrong with an edit of a staged question, or null when it is fine.
     * The same limits the quiz builder itself keeps, so nothing odd is staged
     * only to be refused at accept time.
     *
     * @param array<string, mixed> $data
     */
    private function editProblem(array $data): ?string
    {
        if (array_key_exists('question_text', $data) && (! is_string($data['question_text']) || trim($data['question_text']) === '' || mb_strlen($data['question_text']) > 5000)) {
            return 'The question needs text of up to 5,000 characters.';
        }

        if (array_key_exists('question_type', $data) && ! in_array($data['question_type'], self::TYPES, true)) {
            return 'That question type is not available.';
        }

        if (array_key_exists('points', $data) && $data['points'] !== null
            && (! is_numeric($data['points']) || (float) $data['points'] < 0 || (float) $data['points'] > self::MAX_POINTS)) {
            return 'Points must be a number from 0 to ' . self::MAX_POINTS . '.';
        }

        if (array_key_exists('choices', $data)) {
            if (! is_array($data['choices']) || count($data['choices']) > self::MAX_CHOICES) {
                return 'A question can have up to ' . self::MAX_CHOICES . ' choices.';
            }

            foreach ($data['choices'] as $choice) {
                if (! is_array($choice) || ! is_string($choice['text'] ?? null) || mb_strlen($choice['text']) > 1000) {
                    return 'Each choice needs text of up to 1,000 characters.';
                }
            }
        }

        return null;
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

        // Only a question still waiting in the tray can be added: accepting
        // twice would add it twice, and a discarded one was turned down.
        if ($row['status'] !== 'pending') {
            return ['success' => false, 'message' => 'This question has already been ' . $row['status'] . '.'];
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

        if ($acceptedIds !== []) {
            $this->audit($user, 'ai.batch.accepted', $batchId, ['accepted' => count($acceptedIds)]);
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
