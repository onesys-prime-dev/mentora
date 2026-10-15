<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

use App\Models\Assessments\AiGeneratedQuestionModel;
use App\Models\Assessments\AiGenerationBatchModel;
use App\Services\AI\AiJobService;
use App\Services\AI\SimilarityChecker;
use App\Services\Subscriptions\PlanLimits;

/**
 * Question generation as a background job: a batch is created, a job is queued
 * for the worker, and the batch is filled when the job reports back. The
 * browser polls the batch; nothing waits on a model.
 *
 * Applying a finished job is idempotent: whichever poll sees it first claims
 * the batch (a conditional update) and inserts the questions once.
 */
class AiJobGeneration
{
    /** How long a stored digest is kept before it is read again. */
    public const DIGEST_DAYS = 30;

    private const EXISTING_QUESTIONS = 30;

    private const MESSAGES = [
        'free_quota'             => "Today's free allowance for AI questions is used up. It resets tomorrow, and nothing already made is lost.",
        'provider_rate_limited'  => 'The AI service is busy right now. Please try again in a minute.',
        'provider_auth'          => 'The AI service is not set up correctly. Please ask your administrator.',
        'provider_model_missing' => 'The AI service did not answer. Please try again.',
        'provider_error'         => 'The AI service did not answer. Please try again.',
        'provider_refused'       => 'The AI service declined to write questions from that material.',
        'source_unreadable'      => 'That material could not be read. Check the file and try again.',
        'worker_lost'            => 'The question builder stopped responding. Please try again.',
        'invalid_request'        => 'That request could not be processed.',
    ];

    private AiJobService $jobs;
    private AiGenerationBatchModel $batches;
    private AiGeneratedQuestionModel $questions;

    public function __construct(?AiJobService $jobs = null)
    {
        $this->jobs      = $jobs ?? new AiJobService();
        $this->batches   = new AiGenerationBatchModel();
        $this->questions = new AiGeneratedQuestionModel();
    }

    public static function enabled(): bool
    {
        return filter_var(env('questionBuilder.useJobs', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The same hash the worker computes for the same text: pages are the
     * `[[page N]]` sections (or one page when there are none), each reduced
     * to its words, so a repeat of the same source finds its stored digest.
     */
    public static function sourceHash(string $text): string
    {
        $pages = [];
        $parts = preg_split('/^\[\[page (\d+)\]\]$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        if (count($parts) === 1) {
            if (trim($text) !== '') {
                $pages[] = [1, trim($text)];
            }
        } else {
            // parts[0] is whatever came before the first marker, which is not a page.
            for ($i = 1; $i + 1 < count($parts); $i += 2) {
                $pages[] = [(int) $parts[$i], trim($parts[$i + 1])];
            }
        }

        $lines = [];

        foreach ($pages as [$number, $body]) {
            $words   = preg_split('/\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $lines[] = $number . "\x1f" . implode(' ', $words);
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * Queues the job for a batch that was just created.
     *
     * @param array<string, mixed> $batch the ai_generation_batches row
     */
    public function enqueue(array $batch, int $userId, ?int $now = null): int
    {
        $institutionId = (new PlanLimits())->institutionOf($userId);

        if ($institutionId === null) {
            throw new \RuntimeException('The person belongs to no institution.');
        }

        $sourceText = (string) ($batch['source_text'] ?? '');
        $hash       = $sourceText !== '' ? self::sourceHash($sourceText) : null;
        $items      = json_decode((string) ($batch['source_items'] ?? ''), true);
        $items      = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];

        $payload = [
            'topic'               => $batch['topic'],
            'count'               => (int) $batch['requested_count'],
            'type_mix'            => $batch['type_mix'] ? json_decode((string) $batch['type_mix'], true) : null,
            'points_per_question' => $batch['points_per_question'],
            'existing_questions'  => $this->existingQuestions($batch),
            'instruction'         => $this->instructionFor($batch),
            'options'             => GenerationOptions::normalise(json_decode((string) ($batch['options'] ?? ''), true)),
            'rotation'            => $this->priorBatches($batch),
            'seed'                => (int) $batch['id'],
        ];

        if ($items !== []) {
            // The selected learning items, each with the digest already kept
            // for its own text: the worker reads only what is new.
            $digests = [];

            foreach ($items as $item) {
                $itemHash = self::sourceHash((string) ($item['text'] ?? ''));
                $kept     = $this->storedDigest($institutionId, $itemHash, $now);

                if ($kept !== null) {
                    $digests[$itemHash] = $kept;
                }
            }

            $payload['items']   = $items;
            $payload['digests'] = (object) $digests;

            // The combined text goes too: a worker that does not know `items` yet
            // (not redeployed) reads that instead, so the two can ship in either order.
            $payload['source_text'] = $sourceText !== '' ? $sourceText : null;
        } else {
            // A batch started before items were kept: the single combined source.
            $payload['source_text'] = $sourceText !== '' ? $sourceText : null;
            $payload['digest']      = $hash === null ? null : $this->storedDigest($institutionId, $hash, $now);
        }

        return $this->jobs->enqueue([
            'institution_id' => $institutionId,
            'course_id'      => (int) $batch['course_id'],
            'assessment_id'  => $batch['assessment_id'] !== null ? (int) $batch['assessment_id'] : null,
            'batch_id'       => (int) $batch['id'],
            'created_by'     => $userId,
            'kind'           => 'generate',
            'payload'        => $payload,
        ], $now);
    }

    /**
     * What the tray needs to show about a batch's job, after applying the
     * job's outcome to the batch if it has finished.
     *
     * @return array<string, mixed>|null null when the batch has no job
     */
    public function sync(int $batchId, ?int $now = null): ?array
    {
        $db  = db_connect();
        $row = $db->table('ai_jobs')->where('batch_id', $batchId)->orderBy('id', 'DESC')->get(1)->getRowArray();

        if ($row === null) {
            return null;
        }

        $job = $this->jobs->find((int) $row['id']);

        if ($job['status'] === AiJobService::COMPLETED) {
            $this->apply($job, $now);
        } elseif ($job['status'] === AiJobService::FAILED) {
            $this->batches->where('id', $batchId)->where('status', 'generating')->set([
                'status' => 'failed', 'error_message' => $this->message((string) $job['error_code']),
            ])->update();
        } elseif ($job['status'] === AiJobService::CANCELLED) {
            $this->batches->where('id', $batchId)->where('status', 'generating')->set([
                'status' => 'failed', 'error_message' => 'Cancelled.',
            ])->update();
        }

        return $this->view($job);
    }

    /** Stops a batch's job; what was already accepted stays. */
    public function cancel(array $batch, int $userId): bool
    {
        $institutionId = (new PlanLimits())->institutionOf($userId);
        $row           = db_connect()->table('ai_jobs')->where('batch_id', (int) $batch['id'])->orderBy('id', 'DESC')->get(1)->getRowArray();

        if ($institutionId === null || $row === null) {
            return false;
        }

        return $this->jobs->cancel($institutionId, (int) $row['id']);
    }

    /**
     * The person's most recent batch for an assessment that still has work
     * in it: generating, or finished with questions waiting for review.
     *
     * @return array<string, mixed>|null
     */
    public function pendingBatch(int $userId, int $assessmentId): ?array
    {
        $db = db_connect();

        $batch = $db->table('ai_generation_batches')
            ->where('assessment_id', $assessmentId)
            ->where('created_by', $userId)
            ->where('replaces_question_id IS NULL', null, false)
            ->whereIn('status', ['generating', 'completed'])
            ->orderBy('id', 'DESC')
            ->get(5)->getResultArray();

        foreach ($batch as $row) {
            if ($row['status'] === 'generating') {
                return $row;
            }

            if ($this->questions->where('batch_id', (int) $row['id'])->where('status', 'pending')->countAllResults() > 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    public function view(array $job): array
    {
        $status = (string) $job['status'];
        $code   = $job['error_code'] ?? null;

        return [
            'id'              => (int) $job['id'],
            'status'          => $status,
            'stage'           => $job['stage'],
            'done'            => (int) $job['progress_done'],
            'of'              => (int) $job['progress_of'],
            'note'            => $status === AiJobService::QUEUED ? 'Waiting for the question builder' : $job['progress_note'],
            'waiting_seconds' => $job['waiting_seconds'] !== null ? (int) $job['waiting_seconds'] : null,
            'error_code'      => $code,
            'message'         => $status === AiJobService::FAILED ? $this->message((string) $code) : null,
            'retryable'       => in_array($code, ['provider_rate_limited', 'provider_error', 'provider_model_missing', 'worker_lost'], true),
        ];
    }

    public function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Something went wrong while writing the questions. Please try again.';
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** @param array<string, mixed> $job */
    private function apply(array $job, ?int $now): void
    {
        $batchId = (int) $job['batch_id'];
        $result  = is_array($job['result']) ? $job['result'] : [];
        $db      = db_connect();

        $db->transStart();

        // Whoever flips the batch from generating wins; everyone else does nothing.
        $this->batches->where('id', $batchId)->where('status', 'generating')->set(['status' => 'completed'])->update();

        if ($db->affectedRows() !== 1) {
            $db->transComplete();

            return;
        }

        $batch  = $this->batches->find($batchId);
        $stamp  = date('Y-m-d H:i:s', $now ?? time());
        $checker = new SimilarityChecker();
        $count  = 0;

        foreach ((array) ($result['questions'] ?? []) as $q) {
            $choices = array_map(
                static fn (array $c): array => ['text' => (string) ($c['text'] ?? ''), 'is_correct' => ! empty($c['is_correct'])],
                (array) ($q['choices'] ?? [])
            );

            $this->questions->insert([
                'batch_id'         => $batchId,
                'question_text'    => (string) $q['text'],
                'question_type'    => (string) $q['type'],
                'points'           => $batch['points_per_question'] ?? 1,
                'is_required'      => true,
                'choices'          => json_encode($choices),
                'accepted_answers' => $q['type'] === 'Short Answer' ? ShortAnswerGrader::encode($q['accepted_answers'] ?? []) : null,
                'similarity_score' => $checker->score((string) $q['text'], array_column($choices, 'text'), $batch['source_text']),
                'math_check'       => in_array($q['math_check'] ?? '', ['verified', 'mismatch', 'not_applicable'], true) ? $q['math_check'] : 'not_applicable',
                'allow_multiple_answers' => ! empty($q['allow_multiple_answers']),
                'answers_count'    => ! empty($q['allow_multiple_answers']) && is_numeric($q['answers_count'] ?? null) ? (int) $q['answers_count'] : null,
                'explanation'      => isset($q['explanation']) ? mb_substr((string) $q['explanation'], 0, 2000) : null,
                'evidence_quote'   => isset($q['evidence_quote']) ? mb_substr((string) $q['evidence_quote'], 0, 2000) : null,
                'source_title'     => isset($q['source_title']) ? mb_substr((string) $q['source_title'], 0, 255) : null,
                'source_page'      => isset($q['page']) && is_numeric($q['page']) ? (int) $q['page'] : null,
                'verification'     => isset($q['verification']) && is_array($q['verification']) ? json_encode($q['verification']) : null,
                'flags'            => isset($q['flags']) && is_array($q['flags']) ? json_encode(array_values(array_map('strval', $q['flags']))) : null,
                'status'           => 'pending',
                'created_at'       => $stamp,
            ]);
            $count++;
        }

        $this->batches->update($batchId, ['generated_count' => $count]);

        $this->settleReplacement($batch, $count);

        $this->storeDigest((int) $job['institution_id'], $result['digest'] ?? null, $now);

        // Per-item digests: only the new or updated ones come back.
        foreach ((array) ($result['digests'] ?? []) as $digest) {
            $this->storeDigest((int) $job['institution_id'], $digest, $now);
        }

        $db->transComplete();
    }

    /**
     * A one-question batch that stands in for a staged question: its question
     * moves into the original batch (so the tray shows it there) and the one
     * it replaces is discarded. Nothing is discarded when no question came.
     *
     * @param array<string, mixed> $batch
     */
    public function settleReplacement(array $batch, int $inserted): void
    {
        if (empty($batch['replaces_question_id']) || $inserted < 1) {
            return;
        }

        $old = $this->questions->find((int) $batch['replaces_question_id']);

        if ($old === null) {
            return;
        }

        $this->questions->where('batch_id', (int) $batch['id'])->set(['batch_id' => (int) $old['batch_id']])->update();

        if ($old['status'] === 'pending') {
            $this->questions->update((int) $old['id'], ['status' => 'discarded', 'updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    /** @return array<string, mixed>|null */
    private function storedDigest(int $institutionId, string $hash, ?int $now): ?array
    {
        $row = db_connect()->table('ai_source_digests')
            ->where('institution_id', $institutionId)
            ->where('source_hash', $hash)
            ->where('expires_at >', date('Y-m-d H:i:s', $now ?? time()))
            ->get()->getRowArray();

        return $row === null ? null : (json_decode((string) $row['digest'], true) ?: null);
    }

    private function storeDigest(int $institutionId, mixed $digest, ?int $now): void
    {
        if (! is_array($digest) || empty($digest['source_hash'])) {
            return;
        }

        $db      = db_connect();
        $stamp   = date('Y-m-d H:i:s', $now ?? time());
        $expires = date('Y-m-d H:i:s', ($now ?? time()) + self::DIGEST_DAYS * 86400);
        $set     = ['digest' => json_encode($digest), 'last_used_at' => $stamp, 'expires_at' => $expires];
        $where   = ['institution_id' => $institutionId, 'source_hash' => (string) $digest['source_hash']];

        $db->table('ai_source_digests')->where($where)->update($set);

        if ($db->affectedRows() === 0 && $db->table('ai_source_digests')->where($where)->countAllResults() === 0) {
            $db->table('ai_source_digests')->insert($where + $set + ['created_at' => $stamp, 'token_estimate' => 0]);
        }
    }

    /**
     * The quiz's own questions and earlier batches, as short titles.
     *
     * @param array<string, mixed> $batch
     *
     * @return list<string>
     */
    private function existingQuestions(array $batch): array
    {
        if ($batch['assessment_id'] === null) {
            return [];
        }

        $db   = db_connect();
        $live = $db->table('assessment_questions')->select('question_text')
            ->where('assessment_id', (int) $batch['assessment_id'])
            ->orderBy('id', 'DESC')->get(self::EXISTING_QUESTIONS)->getResultArray();

        $staged = $db->table('ai_generated_questions q')->select('q.question_text')
            ->join('ai_generation_batches b', 'b.id = q.batch_id')
            ->where('b.assessment_id', (int) $batch['assessment_id'])
            ->where('q.status !=', 'discarded')
            ->where('b.id !=', (int) $batch['id'])
            ->orderBy('q.id', 'DESC')->get(self::EXISTING_QUESTIONS)->getResultArray();

        $replaced = [];

        if (! empty($batch['replaces_question_id'])) {
            $old = $this->questions->find((int) $batch['replaces_question_id']);

            if ($old !== null) {
                $replaced[] = ['question_text' => $old['question_text']];
            }
        }

        $texts = array_map(static fn (array $r): string => mb_substr((string) $r['question_text'], 0, 160), array_merge($replaced, $live, $staged));

        return array_values(array_unique($texts));
    }

    /**
     * What the writer is told about how to write: the teacher's steering
     * (difficulty, level, language, focus) and, for a replacement, what they
     * asked for.
     *
     * @param array<string, mixed> $batch
     */
    private function instructionFor(array $batch): ?string
    {
        $steering = GenerationOptions::instruction(
            GenerationOptions::normalise(json_decode((string) ($batch['options'] ?? ''), true))
        );

        $text = trim(((string) ($batch['instruction'] ?? '')) . ' ' . $steering);

        return $text === '' ? null : $text;
    }

    /** @param array<string, mixed> $batch */
    private function priorBatches(array $batch): int
    {
        return (int) db_connect()->table('ai_generation_batches')
            ->where('course_id', (int) $batch['course_id'])
            ->where('assessment_id', $batch['assessment_id'])
            ->where('id <', (int) $batch['id'])
            ->countAllResults();
    }
}
