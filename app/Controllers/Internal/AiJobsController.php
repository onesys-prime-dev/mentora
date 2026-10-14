<?php

declare(strict_types=1);

namespace App\Controllers\Internal;

use App\Controllers\BaseController;
use App\Services\AI\AiJobService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * What the Python worker calls (see AiWorkerFilter for who may). Each call
 * names its worker; a job is only ever changed by the worker that holds it.
 */
class AiJobsController extends BaseController
{
    private AiJobService $jobs;

    public function __construct()
    {
        $this->jobs = new AiJobService();
    }

    /** POST internal/ai/claim {worker_id, version?, backend?} */
    public function claim(): ResponseInterface
    {
        $in     = $this->body();
        $worker = $this->workerId($in);

        if ($worker === null) {
            return $this->bad('worker_id is required.');
        }

        $this->jobs->workerSeen($worker, $this->str($in, 'version'), $this->str($in, 'backend'));

        // Lost jobs are swept whenever a worker asks, so no scheduler is needed for it.
        $this->jobs->requeueLost();

        $job = $this->jobs->claim($worker);

        return $this->response->setJSON(['job' => $job === null ? null : $this->forWorker($job)]);
    }

    /** POST internal/ai/heartbeat {worker_id, job_id?} */
    public function heartbeat(): ResponseInterface
    {
        $in     = $this->body();
        $worker = $this->workerId($in);

        if ($worker === null) {
            return $this->bad('worker_id is required.');
        }

        $this->jobs->workerSeen($worker, $this->str($in, 'version'), $this->str($in, 'backend'));

        $state = isset($in['job_id']) ? $this->jobs->heartbeat((int) $in['job_id'], $worker) : 'idle';

        return $this->response->setJSON(['state' => $state]);
    }

    /** POST internal/ai/progress {worker_id, job_id, stage, done, of, note?, waiting_seconds?, checkpoint?} */
    public function progress(): ResponseInterface
    {
        $in     = $this->body();
        $worker = $this->workerId($in);

        if ($worker === null || ! isset($in['job_id'])) {
            return $this->bad('worker_id and job_id are required.');
        }

        $progress = array_intersect_key($in, array_flip(['stage', 'done', 'of', 'note', 'waiting_seconds', 'checkpoint']));

        return $this->response->setJSON(['state' => $this->jobs->progress((int) $in['job_id'], $worker, $progress)]);
    }

    /** POST internal/ai/complete {worker_id, job_id, result} */
    public function complete(): ResponseInterface
    {
        $in     = $this->body();
        $worker = $this->workerId($in);

        if ($worker === null || ! isset($in['job_id']) || ! is_array($in['result'] ?? null)) {
            return $this->bad('worker_id, job_id and result are required.');
        }

        return $this->response->setJSON(['accepted' => $this->jobs->complete((int) $in['job_id'], $worker, $in['result'])]);
    }

    /** POST internal/ai/fail {worker_id, job_id, code, message?} */
    public function fail(): ResponseInterface
    {
        $in     = $this->body();
        $worker = $this->workerId($in);

        if ($worker === null || ! isset($in['job_id']) || $this->str($in, 'code') === null) {
            return $this->bad('worker_id, job_id and code are required.');
        }

        return $this->response->setJSON([
            'accepted' => $this->jobs->fail((int) $in['job_id'], $worker, (string) $this->str($in, 'code'), (string) ($this->str($in, 'message') ?? '')),
        ]);
    }

    /**
     * What a worker needs to run a job, and nothing of who asked for it.
     *
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    private function forWorker(array $job): array
    {
        return [
            'id'             => (int) $job['id'],
            'kind'           => $job['kind'],
            'institution_id' => (int) $job['institution_id'],
            'payload'        => $job['payload'] ?? [],
            'checkpoint'     => $job['checkpoint'],
            'attempt'        => (int) $job['requeues'] + 1,
        ];
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $json = $this->request->getJSON(true);

        return is_array($json) ? $json : [];
    }

    /** @param array<string, mixed> $in */
    private function workerId(array $in): ?string
    {
        $id = $this->str($in, 'worker_id');

        return $id !== null && mb_strlen($id) <= 64 ? $id : null;
    }

    /** @param array<string, mixed> $in */
    private function str(array $in, string $key): ?string
    {
        return isset($in[$key]) && is_string($in[$key]) && $in[$key] !== '' ? $in[$key] : null;
    }

    private function bad(string $message): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON(['error' => $message]);
    }
}
