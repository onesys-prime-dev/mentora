<?php

declare(strict_types=1);

use App\Services\AI\AiJobService;
use Tests\Support\RouteTestCase;

/**
 * The worker's side of the queue over HTTP: no session, only loopback and the
 * shared secret. Without the secret configured nothing is reachable.
 *
 * @internal
 */
final class AiWorkerEndpointsTest extends RouteTestCase
{
    private const SECRET = 'worker-secret-for-tests';

    private int $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['questionBuilder.workerSecret'] = self::SECRET;
        $_SERVER['REMOTE_ADDR']                  = '127.0.0.1';

        db_connect()->table('institutions')->insert(['name' => 'Worker Institution', 'code' => 'WRK1']);
        $this->institution = (int) db_connect()->insertID();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['questionBuilder.workerSecret'], $_SERVER['REMOTE_ADDR']);

        parent::tearDown();
    }

    /** @param array<string, mixed> $body */
    private function worker(string $path, array $body, ?string $secret = self::SECRET): \CodeIgniter\Test\TestResponse
    {
        $headers = $secret === null ? [] : ['X-Worker-Secret' => $secret];

        return $this->withHeaders($headers)->withBodyFormat('json')->call('post', "internal/ai/{$path}", $body);
    }

    public function testWithoutTheSecretConfiguredTheEndpointsAreUnavailable(): void
    {
        $_SERVER['questionBuilder.workerSecret'] = '';

        $this->worker('claim', ['worker_id' => 'w1'])->assertStatus(503);
    }

    public function testAWrongOrMissingSecretIsRefused(): void
    {
        $this->worker('claim', ['worker_id' => 'w1'], 'nope')->assertStatus(401);
        $this->worker('claim', ['worker_id' => 'w1'], null)->assertStatus(401);
    }

    public function testACallerFromOutsideThisMachineIsRefusedEvenWithTheSecret(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        $this->worker('claim', ['worker_id' => 'w1'])->assertStatus(403);
    }

    public function testAWorkerClaimsReportsAndCompletesAJob(): void
    {
        $id = (new AiJobService())->enqueue(['institution_id' => $this->institution, 'payload' => ['count' => 5]]);

        $claim = $this->worker('claim', ['worker_id' => 'w1', 'version' => '1.0', 'backend' => 'fake']);
        $claim->assertOK();
        $job = json_decode($claim->getJSON(), true)['job'];

        $this->assertSame($id, $job['id']);
        $this->assertSame(['count' => 5], $job['payload']);
        $this->assertArrayNotHasKey('created_by', $job, 'a worker is told what to do, not who asked');

        $this->assertSame('running', json_decode($this->worker('progress', [
            'worker_id' => 'w1', 'job_id' => $id, 'stage' => 'write', 'done' => 2, 'of' => 5,
        ])->getJSON(), true)['state']);

        $this->assertTrue(json_decode($this->worker('complete', [
            'worker_id' => 'w1', 'job_id' => $id, 'result' => ['questions' => []],
        ])->getJSON(), true)['accepted']);

        $this->assertSame('completed', (new AiJobService())->find($id)['status']);
        $this->assertNotNull((new AiJobService())->lastWorkerSeen());
    }

    public function testAnotherWorkerCannotCompleteSomeoneElsesJob(): void
    {
        $id = (new AiJobService())->enqueue(['institution_id' => $this->institution]);
        $this->worker('claim', ['worker_id' => 'w1']);

        $this->assertFalse(json_decode($this->worker('complete', [
            'worker_id' => 'w2', 'job_id' => $id, 'result' => ['questions' => []],
        ])->getJSON(), true)['accepted']);
    }

    public function testAClaimWithNothingQueuedReturnsNoJob(): void
    {
        $this->assertNull(json_decode($this->worker('claim', ['worker_id' => 'w1'])->getJSON(), true)['job']);
    }

    public function testAFailureIsRecordedWithItsCode(): void
    {
        $id = (new AiJobService())->enqueue(['institution_id' => $this->institution]);
        $this->worker('claim', ['worker_id' => 'w1']);

        $this->worker('fail', ['worker_id' => 'w1', 'job_id' => $id, 'code' => 'provider_rate_limited', 'message' => 'Slow down'])->assertOK();

        $this->assertSame('provider_rate_limited', (new AiJobService())->find($id)['error_code']);
    }

    public function testABadRequestIsRejected(): void
    {
        $this->worker('claim', [])->assertStatus(422);
        $this->worker('complete', ['worker_id' => 'w1'])->assertStatus(422);
    }
}
