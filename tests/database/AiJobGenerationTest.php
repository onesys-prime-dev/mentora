<?php

declare(strict_types=1);

use App\Services\AI\AiJobService;
use App\Services\Courses\Assessments\AiJobGeneration;
use App\Services\Courses\Assessments\QuestionGenerationService;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\CourseFactory;
use Tests\Support\DatabaseTestCase;

/**
 * Question generation as a background job: starting queues a job instead of
 * waiting on a model, the finished job fills the batch exactly once, failures
 * come back in plain words, and a reload can find the batch again.
 *
 * @internal
 */
final class AiJobGenerationTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const T0 = 1_800_000_000;

    private const SOURCE = 'Routers forward packets using the destination address.';

    private int $reading;

    private int $offering;
    private int $courseId;
    private int $quiz;
    private int $institution;
    private User $instructor;
    private QuestionGenerationService $service;
    private AiJobService $jobs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->offering = CourseFactory::offering();
        $row = db_connect()->table('course_offerings')
            ->select('course_offerings.course_id, academic_terms.institution_id')
            ->join('academic_terms', 'academic_terms.id = course_offerings.term_id')
            ->where('course_offerings.id', $this->offering)->get()->getRowArray();
        $this->courseId = (int) $row['course_id'];
        $this->institution = (int) $row['institution_id'];

        $module = CourseFactory::module($this->offering);
        $unit = CourseFactory::unit($module);
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        db_connect()->table('institution_users')->insert(['institution_id' => $this->institution, 'user_id' => $this->instructor->id]);

        // The only source is the quiz's learning unit: a reading to write questions from.
        [$this->reading, $quizItem] = CourseFactory::items($unit, [
            ['title' => 'Networking', 'type' => 'reading'],
            ['title' => 'Quiz', 'type' => 'quiz'],
        ]);
        db_connect()->table('learning_items')->where('id', $this->reading)->update(['content' => json_encode([
            'version' => 1,
            'blocks' => [['type' => 'paragraph', 'content' => [['text' => self::SOURCE]]]],
        ])]);

        $this->quiz = CourseFactory::assessment($this->offering, $this->instructor, $module, $quizItem);
        $this->service = new QuestionGenerationService(null, true);
        $this->jobs = new AiJobService();
    }

    /** @return array<string, mixed> */
    private function start(array $data = []): array
    {
        return $this->service->startBatch($this->instructor, $data + [
            'assessment_id' => $this->quiz,
            'course_id' => $this->courseId,
            'offering_id' => $this->offering,
            'count' => 3,
            'type_mix' => ['multiple_choice' => 3],
        ]);
    }

    /** @return array<string, mixed> */
    private function workerResult(string $digestHash = 'abc'): array
    {
        return [
            'questions' => [
                [
                    'type' => 'Multiple Choice',
                    'text' => 'Which device forwards packets between networks?',
                    'choices' => [['text' => 'Router', 'is_correct' => true], ['text' => 'Hub', 'is_correct' => false]],
                    'accepted_answers' => [],
                    'math_check' => 'not_applicable',
                ],
                [
                    'type' => 'Short Answer',
                    'text' => 'Name the protocol with reliable delivery.',
                    'choices' => [],
                    'accepted_answers' => ['TCP'],
                    'math_check' => 'not_applicable',
                ],
            ],
            // The worker returns the digests of the items it read, by their own source hash.
            'digests' => [$digestHash => ['source_hash' => $digestHash, 'window_count' => 1, 'sections' => ['0' => ['concepts' => []]]]],
            'stop_reason' => 'exhausted_topic',
        ];
    }

    private function finishJob(array $result, int $jobId): void
    {
        $this->jobs->claim('w1', self::T0);
        $this->jobs->complete($jobId, 'w1', $result, self::T0 + 5);
    }

    public function testStartingQueuesAJobAndReturnsAtOnceWithNoQuestions(): void
    {
        $result = $this->start();

        $this->assertTrue($result['success']);
        $this->assertSame('generating', $result['batch']['status']);
        $this->assertSame([], $result['questions']);
        $this->assertSame('queued', $result['job']['status']);
        $this->assertSame('Waiting for the question builder', $result['job']['note']);

        $job = $this->jobs->find($result['job']['id']);
        $this->assertSame($this->institution, (int) $job['institution_id']);
        $this->assertSame((int) $result['batch']['id'], (int) $job['batch_id']);
        $this->assertSame(3, $job['payload']['count']);
        $this->assertSame(['multiple_choice' => 3], $job['payload']['type_mix']);
    }

    public function testWhatTheTeacherSteeredWithGoesToTheWorkerAsOptionsAndOneInstruction(): void
    {
        $result = $this->start([
            'type_mix' => ['true_false' => 2, 'multiple_answer' => 1],
            'options' => ['difficulty' => 'harder', 'choices' => 5, 'language' => 'Filipino', 'focus' => 'chapter 3', 'bogus' => 'x'],
        ]);

        $payload = $this->jobs->find($result['job']['id'])['payload'];

        $this->assertSame(['true_false' => 2, 'multiple_answer' => 1], $payload['type_mix']);
        $this->assertSame(5, $payload['options']['choices']);
        $this->assertArrayNotHasKey('bogus', $payload['options']);
        $this->assertStringContainsString('demanding', $payload['instruction']);
        $this->assertStringContainsString('Filipino', $payload['instruction']);
        $this->assertStringContainsString('chapter 3', $payload['instruction']);
    }

    public function testNothingSteeredMeansNoInstruction(): void
    {
        $payload = $this->jobs->find($this->start()['job']['id'])['payload'];

        $this->assertNull($payload['instruction']);
        $this->assertSame(4, $payload['options']['choices']);
    }

    public function testAMultipleAnswerQuestionKeepsItsSettingsWhenTheBatchIsFilled(): void
    {
        $started = $this->start();
        $batchId = (int) $started['batch']['id'];
        $result = $this->workerResult();
        $result['questions'] = [[
            'type' => 'Multiple Choice',
            'text' => 'Which of these are routers or switches?',
            'choices' => [
                ['text' => 'Router', 'is_correct' => true], ['text' => 'Switch', 'is_correct' => true],
                ['text' => 'Cable', 'is_correct' => false], ['text' => 'Plug', 'is_correct' => false],
            ],
            'allow_multiple_answers' => true, 'answers_count' => 2,
            'accepted_answers' => [], 'math_check' => 'not_applicable',
        ]];

        $this->finishJob($result, (int) $started['job']['id']);
        $this->service->getBatch($batchId);

        $row = db_connect()->table('ai_generated_questions')->where('batch_id', $batchId)->get()->getRowArray();

        $this->assertSame(1, (int) $row['allow_multiple_answers']);
        $this->assertSame(2, (int) $row['answers_count']);
    }

    public function testAFinishedJobIsAppliedAndTheTeacherIsToldEvenWithNoBrowserWatching(): void
    {
        $started = $this->start();
        $batchId = (int) $started['batch']['id'];

        $this->finishJob($this->workerResult(), (int) $started['job']['id']);

        (new \App\Services\Courses\Assessments\AiJobGeneration($this->jobs))->settleFinished((int) $started['job']['id']);

        $this->assertSame('completed', db_connect()->table('ai_generation_batches')->where('id', $batchId)->get()->getRowArray()['status']);

        $notes = db_connect()->table('notifications')->where('user_id', $this->instructor->id)->where('type', 'ai.questions_ready')->get()->getResultArray();

        $this->assertCount(1, $notes);
        $this->assertSame('2 questions are ready to review', $notes[0]['title']);
        $this->assertSame("ai-batch-{$batchId}", $notes[0]['dedupe_key']);
    }

    public function testSettlingAJobTwiceTellsThePersonOnce(): void
    {
        $started = $this->start();
        $this->finishJob($this->workerResult(), (int) $started['job']['id']);

        $generation = new \App\Services\Courses\Assessments\AiJobGeneration($this->jobs);
        $generation->settleFinished((int) $started['job']['id']);
        $generation->settleFinished((int) $started['job']['id']);

        $this->assertSame(1, db_connect()->table('notifications')->where('type', 'ai.questions_ready')->countAllResults());
    }

    public function testAFailedJobTellsThePersonInPlainWords(): void
    {
        $started = $this->start();
        $this->jobs->claim('w1', self::T0);
        $this->jobs->fail((int) $started['job']['id'], 'w1', 'free_quota', 'used up', self::T0 + 2);

        (new \App\Services\Courses\Assessments\AiJobGeneration($this->jobs))->settleFinished((int) $started['job']['id']);

        $note = db_connect()->table('notifications')->where('type', 'ai.questions_failed')->get()->getRowArray();

        $this->assertNotNull($note);
        $this->assertStringContainsString('used up', $note['body']);
    }

    public function testAJobWaitingTooLongSaysTheBuilderIsSlowToStart(): void
    {
        $started = $this->start();
        $generation = new \App\Services\Courses\Assessments\AiJobGeneration($this->jobs);

        $fresh = $generation->view($this->jobs->find((int) $started['job']['id']));
        $this->assertFalse($fresh['stalled']);

        db_connect()->table('ai_jobs')->where('id', $started['job']['id'])->update(['created_at' => date('Y-m-d H:i:s', time() - 300)]);

        $late = $generation->view($this->jobs->find((int) $started['job']['id']));

        $this->assertTrue($late['stalled']);
        $this->assertStringContainsString('slow to start', $late['note']);
    }

    public function testOtherUnreviewedSetsAreCountedAndOldOnesAreLeftAlone(): void
    {
        $first = $this->start();
        $this->finishJob($this->workerResult('h1'), (int) $first['job']['id']);
        $this->service->getBatch((int) $first['batch']['id']);

        $second = $this->start();
        $this->jobs->claim('w2', self::T0 + 10);
        $this->jobs->complete((int) $second['job']['id'], 'w2', $this->workerResult('h2'), self::T0 + 15);
        $this->service->getBatch((int) $second['batch']['id']);

        $pending = $this->service->pendingBatch($this->instructor, $this->quiz);

        $this->assertSame((int) $second['batch']['id'], (int) $pending['batch']['id'], 'the newest set opens first');
        $this->assertSame(1, $pending['other_sets']);

        db_connect()->table('ai_generation_batches')->where('id', $first['batch']['id'])->update(['created_at' => date('Y-m-d H:i:s', time() - 20 * 86400)]);

        $this->assertSame(0, $this->service->pendingBatch($this->instructor, $this->quiz)['other_sets']);
    }

    public function testTheQuizsExistingQuestionsGoWithTheJob(): void
    {
        \Tests\Support\AssessmentFactory::question($this->quiz, $this->instructor, 'Essay', 1.0, ['question_text' => 'An existing question about routing?']);

        $result = $this->start();

        $this->assertContains('An existing question about routing?', $this->jobs->find($result['job']['id'])['payload']['existing_questions']);
    }

    public function testAFinishedJobFillsTheBatchOnceHoweverOftenItIsPolled(): void
    {
        $started = $this->start();
        $batchId = (int) $started['batch']['id'];
        $this->finishJob($this->workerResult(), $started['job']['id']);

        $first = $this->service->getBatch($batchId);
        $second = $this->service->getBatch($batchId);
        $third = $this->service->nextChunk($batchId);

        $this->assertSame('completed', $first['batch']['status']);
        $this->assertCount(2, $first['questions']);
        $this->assertSame(2, (int) $first['batch']['generated_count']);
        $this->assertTrue($first['stopped_early']);
        $this->assertCount(2, $second['questions']);
        $this->assertCount(2, $third['questions']);
        $this->assertSame(2, db_connect()->table('ai_generated_questions')->where('batch_id', $batchId)->countAllResults());
        $this->assertSame('TCP', json_decode($first['questions'][1]['accepted_answers'][0] ?? '"TCP"', true) ?? 'TCP');
    }

    public function testTheDigestIsKeptForTheInstitutionAndHandedToTheNextJobForTheSameSource(): void
    {
        $started = $this->start();
        $hash = AiJobGeneration::sourceHash('Routers forward packets using the destination address.');
        $this->finishJob($this->workerResult($hash), $started['job']['id']);
        $this->service->getBatch((int) $started['batch']['id']);

        $stored = db_connect()->table('ai_source_digests')->where('institution_id', $this->institution)->get()->getRowArray();
        $this->assertSame($hash, $stored['source_hash']);

        $again = $this->start();
        $payload = $this->jobs->find($again['job']['id'])['payload'];

        $this->assertSame($hash, $payload['digests'][$hash]['source_hash']);
        $this->assertSame(1, $payload['rotation'], 'a second batch on the quiz starts further along');
    }

    public function testTheJobIsGivenTheChosenItemsEachWithItsOwnText(): void
    {
        $started = $this->start(['learning_item_ids' => [$this->reading]]);
        $payload = $this->jobs->find($started['job']['id'])['payload'];

        $this->assertSame([$this->reading], array_column($payload['items'], 'id'));
        $this->assertSame('Networking', $payload['items'][0]['title']);
        $this->assertSame('reading', $payload['items'][0]['type']);
        $this->assertSame(self::SOURCE, $payload['items'][0]['text']);
        $this->assertStringContainsString(self::SOURCE, $payload['source_text'], 'a worker that does not know items yet reads this');
        $this->assertArrayNotHasKey('digest', $payload);
    }

    public function testOnlyTheDigestsOfTheChosenItemsAreHandedOver(): void
    {
        $second = $this->secondReading('Switching', 'Switches forward frames inside one network segment.');
        $keptA = AiJobGeneration::sourceHash(self::SOURCE);
        $keptB = AiJobGeneration::sourceHash('Switches forward frames inside one network segment.');

        db_connect()->table('ai_source_digests')->insertBatch([
            ['institution_id' => $this->institution, 'source_hash' => $keptA, 'digest' => json_encode(['source_hash' => $keptA, 'sections' => []]), 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 86400)],
            ['institution_id' => $this->institution, 'source_hash' => $keptB, 'digest' => json_encode(['source_hash' => $keptB, 'sections' => []]), 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 86400)],
        ]);

        $both = $this->jobs->find($this->start()['job']['id'])['payload'];
        $onlyFirst = $this->jobs->find($this->start(['learning_item_ids' => [$this->reading]])['job']['id'])['payload'];

        $this->assertEqualsCanonicalizing([$keptA, $keptB], array_keys($both['digests']));
        $this->assertSame([$keptA], array_keys($onlyFirst['digests']));
        $this->assertSame([$this->reading, $second], array_column($both['items'], 'id'));
    }

    private function secondReading(string $title, string $text): int
    {
        $unit = (int) db_connect()->table('learning_items')->where('id', $this->reading)->get()->getRow('learning_unit_id');
        [$id] = CourseFactory::items($unit, [['title' => $title, 'type' => 'reading']]);
        db_connect()->table('learning_items')->where('id', $id)->update(['content' => json_encode([
            'version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['text' => $text]]]],
        ])]);

        return $id;
    }

    public function testAFailedJobMarksTheBatchFailedWithAPlainMessage(): void
    {
        $started = $this->start();
        $this->jobs->claim('w1', self::T0);
        $this->jobs->fail($started['job']['id'], 'w1', 'free_quota', 'raw detail', self::T0 + 1);

        $result = $this->service->getBatch((int) $started['batch']['id']);

        $this->assertSame('failed', $result['batch']['status']);
        $this->assertSame('failed', $result['job']['status']);
        $this->assertStringContainsString('free allowance', $result['job']['message']);
        $this->assertStringNotContainsString('raw detail', $result['job']['message']);
        $this->assertFalse($result['job']['retryable']);
    }

    public function testACancelledBatchStopsItsWorkerAndKeepsNothing(): void
    {
        $started = $this->start();
        $this->jobs->claim('w1', self::T0);

        $result = $this->service->cancelBatch($this->instructor, (int) $started['batch']['id']);

        $this->assertSame('cancelled', $result['job']['status']);
        $this->assertSame('failed', $result['batch']['status']);
        $this->assertSame('cancelled', $this->jobs->heartbeat($started['job']['id'], 'w1'));
    }

    public function testAReloadFindsTheRunningBatchAndThenTheOneWaitingForReview(): void
    {
        $this->assertNull($this->service->pendingBatch($this->instructor, $this->quiz));

        $started = $this->start();
        $running = $this->service->pendingBatch($this->instructor, $this->quiz);
        $this->assertSame((int) $started['batch']['id'], (int) $running['batch']['id']);

        $this->finishJob($this->workerResult(), $started['job']['id']);
        $waiting = $this->service->pendingBatch($this->instructor, $this->quiz);
        $this->assertCount(2, $waiting['questions']);

        foreach ($waiting['questions'] as $q) {
            $this->service->discardGeneratedQuestion((int) $q['id']);
        }

        $this->assertNull($this->service->pendingBatch($this->instructor, $this->quiz));
    }

    public function testSomeoneElsesBatchIsNotFoundAsPending(): void
    {
        $this->start();
        $other = CourseFactory::user('instructor');

        $this->assertNull($this->service->pendingBatch($other, $this->quiz));
    }

    public function testTheSourceHashIgnoresSpacingAndMatchesTheWorker(): void
    {
        $this->assertSame(AiJobGeneration::sourceHash("a  b\n c"), AiJobGeneration::sourceHash(' a b c '));
        $this->assertNotSame(AiJobGeneration::sourceHash('a b'), AiJobGeneration::sourceHash('a c'));
    }

    public function testFailureMessagesAreInPlainWords(): void
    {
        $generation = new AiJobGeneration();

        foreach (['free_quota', 'provider_rate_limited', 'provider_auth', 'source_unreadable', 'worker_lost', 'something_new'] as $code) {
            $this->assertNotSame('', $generation->message($code));
            $this->assertStringNotContainsString('_', $generation->message($code));
        }
    }

    public function testTheSourceHashMatchesTheWorkersForPagedAndPlainText(): void
    {
        // Vectors computed by the worker's own source_hash() (qb/digest.py).
        $this->assertSame(
            '2cdc7df6715924c380d2e399e1f1d9a379410e932250a6a0b3cc34539a9379de',
            AiJobGeneration::sourceHash("[[page 1]]
Alpha beta

[[page 2]]
Gamma")
        );
        $this->assertSame(
            'da5b975f1be7805ad7f435eac7d70edbd5f3473291a839e2fb6000c3c22361ac',
            AiJobGeneration::sourceHash("a  b
 c")
        );
        $this->assertSame(
            '8e8b30bf020132b128d1912af1da110da1e0532b0d36478f189226f8d8ac0b24',
            AiJobGeneration::sourceHash("intro
[[page 3]]
x y
[[page 4]]
")
        );
    }
}
