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

    /** Similarity to the source at or above which a question needs a look before it is accepted in bulk. */
    private const SOURCE_SIMILARITY_HARD = 0.6;

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
            'type_mix' => ($mix = GenerationOptions::typeMix($data['type_mix'] ?? null)) !== null ? json_encode($mix) : null,
            'options' => json_encode(GenerationOptions::normalise($data['options'] ?? null)),
            'diagnostics' => ! empty($extracted['truncated']) ? json_encode(['truncated' => true]) : null,
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

    /**
     * Throws away every question still waiting in a set (and stops it if it
     * is still being written). What was already accepted stays.
     *
     * @return array{success: bool, discarded: int, message: string}
     */
    public function discardAll(User $user, int $batchId): array
    {
        $batch = $this->batchModel->find($batchId);

        if (! $batch) {
            return ['success' => false, 'discarded' => 0, 'message' => 'Batch not found.'];
        }

        if ($batch['status'] === 'generating') {
            $this->jobGeneration->cancel($batch, (int) $user->id);
        }

        $count = $this->generatedQuestionModel->where('batch_id', $batchId)->where('status', 'pending')->countAllResults();

        $this->generatedQuestionModel->where('batch_id', $batchId)->where('status', 'pending')
            ->set(['status' => 'discarded', 'updated_at' => date('Y-m-d H:i:s')])->update();

        $this->audit($user, 'ai.batch.discarded', $batchId, ['discarded' => $count]);

        return [
            'success'   => true,
            'discarded' => $count,
            'message'   => $count === 1 ? '1 question was discarded.' : "{$count} questions were discarded.",
        ];
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
        // A replacement still being written is finished first, so its question is in the tray.
        foreach ($this->batchModel->where('assessment_id', $assessmentId)->where('created_by', (int) $user->id)
            ->where('replaces_question_id IS NOT NULL', null, false)->where('status', 'generating')->findAll() as $replacement) {
            $this->jobGeneration->sync((int) $replacement['id']);
        }

        foreach ($this->batchModel->where('assessment_id', $assessmentId)->where('created_by', (int) $user->id)
            ->where('extends_batch_id IS NOT NULL', null, false)->where('status', 'generating')->findAll() as $extension) {
            $this->jobGeneration->sync((int) $extension['id']);
        }

        $batch = $this->jobGeneration->pendingBatch((int) $user->id, $assessmentId);

        if ($batch === null) {
            return null;
        }

        return $this->getBatch((int) $batch['id']) + [
            'other_sets' => $this->jobGeneration->otherWaiting((int) $user->id, $assessmentId, (int) $batch['id']),
        ];
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

    /** What each replacement preset asks the writer for. */
    private const REPLACE_PRESETS = [
        'different' => 'Write a different question about another part of the material. Do not rephrase the one being replaced.',
        'easier'    => 'Make it easier: ask for plain recall of one clear fact, with obviously distinct choices.',
        'harder'    => 'Make it harder: ask the student to apply or compare ideas rather than recall one sentence, with plausible close choices.',
        'type'      => 'Ask about the same idea in the requested question type.',
    ];

    private const REPLACE_TYPE_KEYS = [
        'Multiple Choice' => 'multiple_choice',
        'Short Answer'    => 'short_answer',
        'Essay'           => 'essay',
        'File Upload'     => 'file_upload',
    ];

    /**
     * Replaces one staged question with a freshly written one: a one-question
     * batch from the same material, a short instruction, and the replaced
     * question left in place until the new one has arrived.
     *
     * @param string $mode       different | easier | harder | type
     * @param string $instruction an optional note from the teacher (up to 200 characters)
     * @param string|null $type  the question type wanted, for mode "type"
     *
     * @return array<string, mixed>
     */
    public function replaceQuestion(User $user, int $generatedQuestionId, string $mode, string $instruction = '', ?string $type = null): array
    {
        $row = $this->generatedQuestionModel->find($generatedQuestionId);

        if (! $row || $row['status'] !== 'pending') {
            return ['success' => false, 'message' => 'That question is no longer waiting for review.'];
        }

        if (! isset(self::REPLACE_PRESETS[$mode])) {
            return ['success' => false, 'message' => 'Choose how the replacement should differ.'];
        }

        $instruction = trim((string) preg_replace('/\s+/u', ' ', $instruction));

        if (mb_strlen($instruction) > 200) {
            return ['success' => false, 'message' => 'Keep the instruction under 200 characters.'];
        }

        if (! $this->generationEnabled($user)) {
            return ['success' => false, 'code' => 'disabled', 'message' => 'AI question generation is turned off for your institution.'];
        }

        $blocked = (new PlanLimits())->generationBlock($user, 1);

        if ($blocked !== null) {
            return ['success' => false, 'message' => $blocked];
        }

        $wanted = $mode === 'type' && isset(self::REPLACE_TYPE_KEYS[(string) $type]) ? (string) $type : (string) $row['question_type'];
        $original = $this->batchModel->find((int) $row['batch_id']);

        if (! $original) {
            return ['success' => false, 'message' => 'Batch not found.'];
        }

        $text = self::REPLACE_PRESETS[$mode];

        if ($mode === 'type') {
            $text .= ' The type is: ' . $wanted . '.';
        }

        if ($instruction !== '') {
            $text .= ' The teacher asks: ' . $instruction;
        }

        $this->batchModel->insert([
            'assessment_id'        => $original['assessment_id'],
            'course_id'            => $original['course_id'],
            'source_type'          => 'learning_unit',
            'learning_unit_id'     => $original['learning_unit_id'],
            'source_text'          => $original['source_text'],
            'source_items'         => $original['source_items'],
            'type_mix'             => json_encode([$this->replaceKey($row, $wanted, $mode) => 1]),
            'requested_count'      => 1,
            'generated_count'      => 0,
            'points_per_question'  => $row['points'],
            'options'              => $original['options'] ?? null,
            'status'               => 'generating',
            'created_by'           => $user->id,
            'created_at'           => date('Y-m-d H:i:s'),
            'replaces_question_id' => $generatedQuestionId,
            'instruction'          => mb_substr($text, 0, 300),
        ]);

        $newId = (int) $this->batchModel->getInsertID();

        $this->audit($user, 'ai.question.replace_requested', $newId, ['replaces' => $generatedQuestionId, 'mode' => $mode]);

        if ($this->useJobs) {
            $this->jobGeneration->enqueue($this->batchModel->find($newId), (int) $user->id);
        } else {
            $this->generateChunk($newId);
            $done = $this->batchModel->find($newId);
            $this->jobGeneration->settleReplacement($done, (int) $done['generated_count']);
        }

        return $this->getBatch($newId) + ['replaces' => $generatedQuestionId, 'batch_id' => $newId];
    }

    /**
     * The type key a replacement is written as: a true/false or a
     * several-answers question is replaced by the same kind.
     *
     * @param array<string, mixed> $row
     */
    private function replaceKey(array $row, string $wantedType, string $mode): string
    {
        if ($mode === 'type' || $wantedType !== 'Multiple Choice') {
            return self::REPLACE_TYPE_KEYS[$wantedType];
        }

        if (! empty($row['allow_multiple_answers'])) {
            return 'multiple_answer';
        }

        $texts = array_map(
            static fn (array $c): string => strtolower(trim((string) ($c['text'] ?? ''))),
            (array) (json_decode((string) ($row['choices'] ?? ''), true) ?: [])
        );
        sort($texts);

        return $texts === ['false', 'true'] ? 'true_false' : 'multiple_choice';
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

    /**
     * Whether a staged question should be looked at before it goes in:
     * the answer may be wrong, was not confirmed, repeats a question already
     * in the quiz or copies the source too closely.
     *
     * @param array<string, mixed> $question as listed (decoded), with duplicate_of
     */
    private function needsLook(array $question): bool
    {
        $verification = is_array($question['verification'] ?? null) ? $question['verification'] : [];

        return ($question['math_check'] ?? '') === 'mismatch'
            || ($verification['solver_agrees'] ?? null) === false
            || ($verification['grounded'] ?? null) === false
            || ! empty($question['duplicate_of'])
            || (float) ($question['similarity_score'] ?? 0) >= self::SOURCE_SIMILARITY_HARD;
    }

    /**
     * The staged questions of a batch, each marked with the quiz question it
     * repeats (if any) and whether it needs a look.
     *
     * @return list<array<string, mixed>>
     */
    private function reviewList(array $batch): array
    {
        $live = [];

        if (! empty($batch['assessment_id'])) {
            $live = array_map(
                static fn (array $q): string => (string) $q['question_text'],
                db_connect()->table('assessment_questions')->select('question_text')
                    ->where('assessment_id', (int) $batch['assessment_id'])->get()->getResultArray()
            );
        }

        $list = [];

        foreach ($this->generatedQuestionModel->listForBatch((int) $batch['id']) as $question) {
            $question['duplicate_of'] = QuestionTwins::find((string) $question['question_text'], $live);
            $question['needs_look'] = $this->needsLook($question);
            $list[] = $question;
        }

        return $list;
    }

    /**
     * @param bool $onlyClear leave out the ones that need a look (and say how many)
     */
    public function acceptAll(User $user, int $batchId, bool $onlyClear = false): array
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

        $skipped = 0;

        foreach ($this->reviewList($batch) as $row) {

            if ($onlyClear && $row['needs_look']) {
                $skipped++;

                continue;
            }

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
            'skipped_count' => $skipped,
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
                'instruction' => $batch['instruction'] ?? null,
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
            'questions' => $this->reviewList($batch),
        ] + $this->shortfallView($batch);
    }

    /** What each worker drop code means to a teacher. */
    private const DROP_WORDS = [
        'solver_disagrees' => 'a second check did not agree with the marked answer',
        'ambiguous'        => 'more than one choice could be right',
        'no_answer'        => 'a second check could not answer it',
        'math_mismatch'    => 'the answer did not match the calculation',
        'duplicate'        => 'it repeated another question',
        'ungrounded'       => 'it was not found in the material',
        'wrong_fact'       => 'it was not about the idea it was meant for',
        'wrong_concept'    => 'it was not about the idea it was meant for',
        'regeneration_failed_gates' => 'its second attempt did not meet the question rules',
    ];

    /**
     * Coverage per item and, when fewer came back than were asked for, why
     * and whether more can be asked for.
     *
     * @param array<string, mixed> $batch
     *
     * @return array<string, mixed>
     */
    private function shortfallView(array $batch): array
    {
        $diagnostics = json_decode((string) ($batch['diagnostics'] ?? ''), true);
        $diagnostics = is_array($diagnostics) ? $diagnostics : [];

        $missing = max(0, (int) $batch['requested_count'] - (int) $batch['generated_count']);
        $reasons = [];

        foreach ((array) ($diagnostics['dropped'] ?? []) as $code => $count) {
            if ((int) $count < 1) {
                continue;
            }

            $word = self::DROP_WORDS[$code] ?? (str_starts_with((string) $code, 'mc_') || str_starts_with((string) $code, 'sa_') || str_starts_with((string) $code, 'tf_') || str_starts_with((string) $code, 'ma_')
                ? 'it did not meet the question rules' : 'it did not pass the checks');
            $reasons[$word] = ($reasons[$word] ?? 0) + (int) $count;
        }

        $why = [];

        foreach ($reasons as $word => $count) {
            $why[] = ['count' => $count, 'text' => $word];
        }

        $exhausted = ($diagnostics['stop_reason'] ?? '') === 'exhausted_topic';

        return [
            'coverage' => array_values((array) ($diagnostics['coverage'] ?? [])),
            'truncated' => ! empty($diagnostics['truncated']),
            'topped_up' => (int) ($diagnostics['topped_up'] ?? 0),
            'shortfall' => [
                'missing' => $batch['status'] === 'completed' ? $missing : 0,
                'reasons' => $why,
                'not_enough_material' => $exhausted,
            ],
        ];
    }

    /**
     * Asks for more questions to add to a finished batch: a new job from the
     * same material and settings, whose questions land in the same tray. The
     * material that was already used is avoided, so it reaches other parts.
     *
     * @return array<string, mixed>
     */
    public function topUp(User $user, int $batchId, int $count): array
    {
        $original = $this->batchModel->find($batchId);

        if (! $original || $original['status'] !== 'completed') {
            return ['success' => false, 'message' => 'That set is not ready for more questions.'];
        }

        $missing = (int) $original['requested_count'] - (int) $original['generated_count'];

        if ($count < 1) {
            return ['success' => false, 'message' => 'Choose how many more questions to write.'];
        }

        $count = min($count, 100);

        if (! $this->generationEnabled($user)) {
            return ['success' => false, 'code' => 'disabled', 'message' => 'AI question generation is turned off for your institution.'];
        }

        $blocked = (new PlanLimits())->generationBlock($user, $count);

        if ($blocked !== null) {
            return ['success' => false, 'message' => $blocked];
        }

        $this->batchModel->insert([
            'assessment_id'       => $original['assessment_id'],
            'course_id'           => $original['course_id'],
            'source_type'         => 'learning_unit',
            'learning_unit_id'    => $original['learning_unit_id'],
            'source_text'         => $original['source_text'],
            'source_items'        => $original['source_items'],
            'type_mix'            => $original['type_mix'],
            'options'             => $original['options'],
            'requested_count'     => $count,
            'generated_count'     => 0,
            'points_per_question' => $original['points_per_question'],
            'status'              => 'generating',
            'created_by'          => $user->id,
            'created_at'          => date('Y-m-d H:i:s'),
            'extends_batch_id'    => $batchId,
        ]);

        $newId = (int) $this->batchModel->getInsertID();

        $this->audit($user, 'ai.batch.topped_up', $newId, ['extends' => $batchId, 'requested' => $count, 'missing' => $missing]);

        if ($this->useJobs) {
            $this->jobGeneration->enqueue($this->batchModel->find($newId), (int) $user->id);
        } else {
            $this->generateChunk($newId);
            $done = $this->batchModel->find($newId);
            $this->jobGeneration->settleReplacement($done, (int) $done['generated_count']);
        }

        return $this->getBatch($newId) + ['extends' => $batchId, 'batch_id' => $newId];
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
