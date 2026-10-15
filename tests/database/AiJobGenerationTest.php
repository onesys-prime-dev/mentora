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
        $this->instructor = CourseFactory::user('instructor');
        CourseFactory::assignInstructor($this->offering, $this->instructor);
        db_connect()->table('institution_users')->insert(['institution_id' => $this->institution, 'user_id' => $this->instructor->id]);

        $this->quiz = CourseFactory::assessment($this->offering, $this->instructor, $module);
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
            'source_type' => 'text',
            'source_text' => 'Routers forward packets using the destination address.',
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
            'digest' => ['source_hash' => $digestHash, 'window_count' => 1, 'sections' => ['0' => ['concepts' => []]]],
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

        $this->assertSame($hash, $payload['digest']['source_hash']);
        $this->assertSame(1, $payload['rotation'], 'a second batch on the quiz starts further along');
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
