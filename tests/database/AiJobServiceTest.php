<?php

declare(strict_types=1);

use App\Models\Assessments\AiJobModel;
use App\Models\Assessments\AiSourceDigestModel;
use App\Models\Assessments\AiWorkerHeartbeatModel;
use App\Services\AI\AiJobService;
use App\Services\Monitoring\SystemHealth;
use Tests\Support\DatabaseTestCase;

/**
 * The question builder work queue: a job is claimed once, a quiet worker's job
 * is put back at most twice, a cancelled job stops its worker, and a job is
 * only visible to its own institution. Time is passed in, never waited for.
 *
 * Tables: ai_jobs, ai_source_digests, ai_worker_heartbeats.
 *
 * @internal
 */
final class AiJobServiceTest extends DatabaseTestCase
{
    protected bool $isolateTests = true;

    private const T0 = 1_800_000_000;

    private AiJobService $jobs;
    private int $institution;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobs        = new AiJobService();
        $this->institution = $this->institution('AIJ1');
        $this->other       = $this->institution('AIJ2');
    }

    private function institution(string $code): int
    {
        db_connect()->table('institutions')->insert(['name' => "Institution {$code}", 'code' => $code]);

        return (int) db_connect()->insertID();
    }

    private function enqueue(?int $institution = null, int $at = self::T0): int
    {
        return $this->jobs->enqueue([
            'institution_id' => $institution ?? $this->institution,
            'payload'        => ['count' => 10, 'topic' => 'Networking'],
        ], $at);
    }

    public function testAJobIsQueuedWithItsPayload(): void
    {
        $id  = $this->enqueue();
        $job = $this->jobs->find($id);

        $this->assertSame(AiJobService::QUEUED, $job['status']);
        $this->assertSame('generate', $job['kind']);
        $this->assertSame(['count' => 10, 'topic' => 'Networking'], $job['payload']);
        $this->assertSame($id, (int) (new AiJobModel())->find($id)['id']);
    }

    public function testAJobNeedsAnInstitutionAndAKnownKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->jobs->enqueue(['institution_id' => 0]);
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->jobs->enqueue(['institution_id' => $this->institution, 'kind' => 'translate']);
    }

    public function testTheOldestJobIsClaimedFirstAndOnlyOnce(): void
    {
        $first  = $this->enqueue();
        $second = $this->enqueue();

        $a = $this->jobs->claim('worker-a', self::T0 + 1);
        $b = $this->jobs->claim('worker-b', self::T0 + 1);
        $c = $this->jobs->claim('worker-c', self::T0 + 1);

        $this->assertSame($first, (int) $a['id']);
        $this->assertSame('worker-a', $a['claimed_by']);
        $this->assertSame($second, (int) $b['id']);
        $this->assertNull($c, 'there was nothing left to claim');
    }

    public function testNothingToClaimIsNull(): void
    {
        $this->assertNull($this->jobs->claim('worker-a'));
    }

    public function testOnlyTheHoldingWorkerCanReportOnOrFinishAJob(): void
    {
        $id = $this->enqueue();
        $this->jobs->claim('worker-a', self::T0);

        $this->assertSame('unknown', $this->jobs->progress($id, 'worker-b', ['stage' => 'digest']));
        $this->assertFalse($this->jobs->complete($id, 'worker-b', ['questions' => []]));
        $this->assertFalse($this->jobs->fail($id, 'worker-b', 'provider_error', 'x'));

        $this->assertSame('running', $this->jobs->progress($id, 'worker-a', [
            'stage' => 'write', 'done' => 3, 'of' => 10, 'note' => 'Writing questions', 'checkpoint' => ['batches' => 1],
        ], self::T0 + 10));

        $job = $this->jobs->find($id);
        $this->assertSame('write', $job['stage']);
        $this->assertSame(3, (int) $job['progress_done']);
        $this->assertSame(10, (int) $job['progress_of']);
        $this->assertSame(['batches' => 1], $job['checkpoint']);
    }

    public function testACompletedJobKeepsItsResult(): void
    {
        $id = $this->enqueue();
        $this->jobs->claim('worker-a', self::T0);

        $this->assertTrue($this->jobs->complete($id, 'worker-a', ['questions' => [['question_text' => 'Q?']]], self::T0 + 5));

        $job = $this->jobs->find($id);
        $this->assertSame(AiJobService::COMPLETED, $job['status']);
        $this->assertSame('Q?', $job['result']['questions'][0]['question_text']);
        $this->assertFalse($this->jobs->complete($id, 'worker-a', ['questions' => []]), 'a finished job cannot be finished again');
    }

    public function testAFailedJobKeepsItsCode(): void
    {
        $id = $this->enqueue();
        $this->jobs->claim('worker-a', self::T0);

        $this->assertTrue($this->jobs->fail($id, 'worker-a', 'free_quota', 'The free allowance is used up.', self::T0 + 5));

        $job = $this->jobs->find($id);
        $this->assertSame(AiJobService::FAILED, $job['status']);
        $this->assertSame('free_quota', $job['error_code']);
    }

    public function testCancellingStopsTheWorkerAndIgnoresItsResult(): void
    {
        $id = $this->enqueue();
        $this->jobs->claim('worker-a', self::T0);

        $this->assertTrue($this->jobs->cancel($this->institution, $id, self::T0 + 2));
        $this->assertSame('cancelled', $this->jobs->heartbeat($id, 'worker-a', self::T0 + 3));
        $this->assertFalse($this->jobs->complete($id, 'worker-a', ['questions' => []]));
        $this->assertSame(AiJobService::CANCELLED, $this->jobs->find($id)['status']);
    }

    public function testAQueuedJobCanBeCancelledAndIsThenNeverClaimed(): void
    {
        $id = $this->enqueue();

        $this->assertTrue($this->jobs->cancel($this->institution, $id));
        $this->assertNull($this->jobs->claim('worker-a'));
    }

    public function testAnotherInstitutionCannotCancelOrSeeAJob(): void
    {
        $id = $this->enqueue();

        $this->assertNull($this->jobs->findForInstitution($this->other, $id));
        $this->assertFalse($this->jobs->cancel($this->other, $id));
        $this->assertSame(AiJobService::QUEUED, $this->jobs->find($id)['status']);
        $this->assertNotNull($this->jobs->findForInstitution($this->institution, $id));
    }

    public function testALostJobIsPutBackFromItsCheckpointAtMostTwice(): void
    {
        $id = $this->enqueue();
        $t  = self::T0;

        for ($round = 1; $round <= AiJobService::MAX_REQUEUES; $round++) {
            $this->jobs->claim('worker-a', $t);
            $this->jobs->progress($id, 'worker-a', ['stage' => 'write', 'checkpoint' => ['round' => $round]], $t + 1);

            $t += AiJobService::STALE_SECONDS + 10;

            $this->assertSame(['requeued' => 1, 'failed' => 0], $this->jobs->requeueLost($t));

            $job = $this->jobs->find($id);
            $this->assertSame(AiJobService::QUEUED, $job['status']);
            $this->assertSame($round, (int) $job['requeues']);
            $this->assertSame(['round' => $round], $job['checkpoint'], 'work already done is kept');
        }

        $this->jobs->claim('worker-a', $t);
        $t += AiJobService::STALE_SECONDS + 10;

        $this->assertSame(['requeued' => 0, 'failed' => 1], $this->jobs->requeueLost($t));
        $this->assertSame('worker_lost', $this->jobs->find($id)['error_code']);
    }

    public function testAJobStillReportingIsNotConsideredLost(): void
    {
        $id = $this->enqueue();
        $this->jobs->claim('worker-a', self::T0);
        $this->jobs->heartbeat($id, 'worker-a', self::T0 + AiJobService::STALE_SECONDS - 1);

        $this->assertSame(['requeued' => 0, 'failed' => 0], $this->jobs->requeueLost(self::T0 + AiJobService::STALE_SECONDS + 5));
        $this->assertSame(AiJobService::RUNNING, $this->jobs->find($id)['status']);
    }

    public function testTheWorkerHeartbeatIsKeptOnePerWorkerAndReadByHealth(): void
    {
        $this->assertNull($this->jobs->lastWorkerSeen());

        $this->jobs->workerSeen('worker-a', '1.0', 'groq', self::T0);
        $this->jobs->workerSeen('worker-a', '1.0', 'groq', self::T0 + 30);

        $this->assertSame(1, (new AiWorkerHeartbeatModel())->countAllResults());
        $this->assertSame(self::T0 + 30, $this->jobs->lastWorkerSeen());

        $this->assertSame('up', AiJobService::workerStatus(self::T0 + 30, self::T0 + 40)['status']);
        $this->assertSame('degraded', AiJobService::workerStatus(self::T0 + 30, self::T0 + 400)['status']);
        $this->assertSame('degraded', AiJobService::workerStatus(null, self::T0)['status']);
        $this->assertSame(SystemHealth::UP, AiJobService::workerStatus(self::T0, self::T0)['status']);
    }

    public function testADigestIsKeptPerInstitutionAndNeverSharedAcrossThem(): void
    {
        $digests = new AiSourceDigestModel();
        $hash    = str_repeat('a', 64);

        $digests->insert(['institution_id' => $this->institution, 'source_hash' => $hash, 'digest' => '{}', 'token_estimate' => 10]);
        $digests->insert(['institution_id' => $this->other, 'source_hash' => $hash, 'digest' => '{}', 'token_estimate' => 10]);

        $this->assertSame(2, $digests->where('source_hash', $hash)->countAllResults());

        // The same institution cannot hold the same source twice: the insert is refused.
        try {
            $digests->insert(['institution_id' => $this->institution, 'source_hash' => $hash, 'digest' => '{}']);
        } catch (\Throwable) {
            // Some configurations throw instead of returning false; either way nothing was added.
        }

        $this->assertSame(2, $digests->where('source_hash', $hash)->countAllResults());
    }
}
