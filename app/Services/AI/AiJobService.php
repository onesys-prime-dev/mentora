<?php

declare(strict_types=1);

namespace App\Services\AI;

use InvalidArgumentException;

/**
 * The question builder work queue.
 *
 * PHP owns every row. The Python worker never touches the database: it asks
 * this service (through the internal endpoints) to claim a job, to record its
 * progress and to store its result. A job is claimed once however many
 * workers ask, a job whose worker went quiet is put back at most twice, and a
 * job is only ever shown to the institution it belongs to.
 *
 * The clock is an input (`$now`, a unix time) so the timing rules can be
 * tested without waiting.
 */
final class AiJobService
{
    public const QUEUED    = 'queued';
    public const RUNNING   = 'running';
    public const COMPLETED = 'completed';
    public const FAILED    = 'failed';
    public const CANCELLED = 'cancelled';

    public const KINDS = ['generate', 'extract'];

    /** Seconds without a heartbeat before a running job counts as lost. */
    public const STALE_SECONDS = 120;

    /** A lost job is put back this many times, then fails with `worker_lost`. */
    public const MAX_REQUEUES = 2;

    /** Seconds without a worker heartbeat before the worker counts as gone. */
    public const WORKER_STALE_SECONDS = 90;

    private const CLAIM_ATTEMPTS = 3;

    /**
     * @param array{institution_id: int, kind?: string, payload?: array<string, mixed>, course_id?: int|null, assessment_id?: int|null, batch_id?: int|null, created_by?: int|null} $job
     *
     * @return int the new job id
     */
    public function enqueue(array $job, ?int $now = null): int
    {
        $institutionId = (int) ($job['institution_id'] ?? 0);
        $kind          = (string) ($job['kind'] ?? 'generate');

        if ($institutionId <= 0) {
            throw new InvalidArgumentException('A job belongs to an institution.');
        }

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown job kind: {$kind}");
        }

        $stamp = $this->stamp($now);

        db_connect()->table('ai_jobs')->insert([
            'institution_id' => $institutionId,
            'course_id'      => $job['course_id'] ?? null,
            'assessment_id'  => $job['assessment_id'] ?? null,
            'batch_id'       => $job['batch_id'] ?? null,
            'created_by'     => $job['created_by'] ?? null,
            'kind'           => $kind,
            'status'         => self::QUEUED,
            'payload'        => json_encode($job['payload'] ?? [], JSON_THROW_ON_ERROR),
            'created_at'     => $stamp,
            'updated_at'     => $stamp,
        ]);

        return (int) db_connect()->insertID();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = db_connect()->table('ai_jobs')->where('id', $id)->get()->getRowArray();

        return $row === null ? null : $this->decode($row);
    }

    /**
     * A job as its own institution sees it: someone else's job does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function findForInstitution(int $institutionId, int $id): ?array
    {
        $row = db_connect()->table('ai_jobs')
            ->where('id', $id)
            ->where('institution_id', $institutionId)
            ->get()->getRowArray();

        return $row === null ? null : $this->decode($row);
    }

    /**
     * Hands the oldest queued job to a worker. The update only succeeds while
     * the row is still queued, so two workers asking at once get one job each,
     * never the same one.
     *
     * @return array<string, mixed>|null
     */
    public function claim(string $workerId, ?int $now = null): ?array
    {
        $db    = db_connect();
        $stamp = $this->stamp($now);

        for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
            $next = $db->table('ai_jobs')->select('id')->where('status', self::QUEUED)->orderBy('id', 'ASC')->get(1)->getRowArray();

            if ($next === null) {
                return null;
            }

            $db->table('ai_jobs')
                ->where('id', (int) $next['id'])
                ->where('status', self::QUEUED)
                ->update([
                    'status'       => self::RUNNING,
                    'claimed_by'   => $workerId,
                    'claimed_at'   => $stamp,
                    'heartbeat_at' => $stamp,
                    'updated_at'   => $stamp,
                ]);

            if ($db->affectedRows() === 1) {
                return $this->find((int) $next['id']);
            }
        }

        return null;
    }

    /**
     * Records that the worker is alive on a job.
     *
     * @return string `running`, `cancelled` (stop work) or `unknown` (not yours any more)
     */
    public function heartbeat(int $id, string $workerId, ?int $now = null): string
    {
        return $this->touch($id, $workerId, [], $now);
    }

    /**
     * @param array{stage?: string, done?: int, of?: int, note?: string|null, waiting_seconds?: int|null, checkpoint?: array<string, mixed>|null} $progress
     *
     * @return string as heartbeat()
     */
    public function progress(int $id, string $workerId, array $progress, ?int $now = null): string
    {
        $set = [];

        foreach (['stage' => 'stage', 'done' => 'progress_done', 'of' => 'progress_of', 'note' => 'progress_note', 'waiting_seconds' => 'waiting_seconds'] as $in => $column) {
            if (array_key_exists($in, $progress)) {
                $set[$column] = $in === 'note' && $progress[$in] !== null ? mb_substr((string) $progress[$in], 0, 255) : $progress[$in];
            }
        }

        if (array_key_exists('checkpoint', $progress)) {
            $set['checkpoint'] = $progress['checkpoint'] === null ? null : json_encode($progress['checkpoint'], JSON_THROW_ON_ERROR);
        }

        return $this->touch($id, $workerId, $set, $now);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return bool false when the job is no longer this worker's (cancelled, re-queued)
     */
    public function complete(int $id, string $workerId, array $result, ?int $now = null): bool
    {
        return $this->finish($id, $workerId, [
            'status' => self::COMPLETED,
            'result' => json_encode($result, JSON_THROW_ON_ERROR),
        ], $now);
    }

    public function fail(int $id, string $workerId, string $code, string $message, ?int $now = null): bool
    {
        return $this->finish($id, $workerId, [
            'status'        => self::FAILED,
            'error_code'    => mb_substr($code, 0, 40),
            'error_message' => mb_substr($message, 0, 500),
        ], $now);
    }

    /** Stops a queued or running job of this institution. */
    public function cancel(int $institutionId, int $id, ?int $now = null): bool
    {
        $stamp = $this->stamp($now);
        $db    = db_connect();

        $db->table('ai_jobs')
            ->where('id', $id)
            ->where('institution_id', $institutionId)
            ->whereIn('status', [self::QUEUED, self::RUNNING])
            ->update(['status' => self::CANCELLED, 'finished_at' => $stamp, 'updated_at' => $stamp]);

        return $db->affectedRows() === 1;
    }

    /**
     * Puts back running jobs whose worker stopped reporting, from their last
     * checkpoint; after MAX_REQUEUES they fail with `worker_lost`.
     *
     * @return array{requeued: int, failed: int}
     */
    public function requeueLost(?int $now = null): array
    {
        $now    = $now ?? time();
        $stamp  = $this->stamp($now);
        $cutoff = $this->stamp($now - self::STALE_SECONDS);
        $db     = db_connect();

        $lost = $db->table('ai_jobs')
            ->select('id, requeues')
            ->where('status', self::RUNNING)
            ->where('heartbeat_at <', $cutoff)
            ->get()->getResultArray();

        $out = ['requeued' => 0, 'failed' => 0];

        foreach ($lost as $row) {
            if ((int) $row['requeues'] < self::MAX_REQUEUES) {
                $db->table('ai_jobs')->where('id', (int) $row['id'])->where('status', self::RUNNING)->update([
                    'status'       => self::QUEUED,
                    'requeues'     => (int) $row['requeues'] + 1,
                    'claimed_by'   => null,
                    'claimed_at'   => null,
                    'heartbeat_at' => null,
                    'updated_at'   => $stamp,
                ]);
                $out['requeued'] += $db->affectedRows() === 1 ? 1 : 0;

                continue;
            }

            $db->table('ai_jobs')->where('id', (int) $row['id'])->where('status', self::RUNNING)->update([
                'status'        => self::FAILED,
                'error_code'    => 'worker_lost',
                'error_message' => 'The question builder stopped responding.',
                'finished_at'   => $stamp,
                'updated_at'    => $stamp,
            ]);
            $out['failed'] += $db->affectedRows() === 1 ? 1 : 0;
        }

        return $out;
    }

    public function workerSeen(string $workerId, ?string $version = null, ?string $backend = null, ?int $now = null): void
    {
        $stamp = $this->stamp($now);
        $db    = db_connect();
        $set   = ['version' => $version, 'backend' => $backend, 'last_seen_at' => $stamp];

        $db->table('ai_worker_heartbeats')->where('worker_id', $workerId)->update($set);

        if ($db->affectedRows() === 0 && $db->table('ai_worker_heartbeats')->where('worker_id', $workerId)->countAllResults() === 0) {
            $db->table('ai_worker_heartbeats')->insert(['worker_id' => $workerId] + $set);
        }
    }

    /** Unix time of the most recent worker heartbeat, or null if none ever reported. */
    public function lastWorkerSeen(): ?int
    {
        $row = db_connect()->table('ai_worker_heartbeats')->selectMax('last_seen_at', 'seen')->get()->getRowArray();

        return ($row['seen'] ?? null) === null ? null : (int) strtotime((string) $row['seen']);
    }

    /**
     * Plain words for the health page.
     *
     * @return array{status: string, detail: string}
     */
    public static function workerStatus(?int $lastSeen, int $now): array
    {
        if ($lastSeen === null) {
            return ['status' => 'degraded', 'detail' => 'The question-builder worker has not reported yet.'];
        }

        $age = max(0, $now - $lastSeen);

        return $age <= self::WORKER_STALE_SECONDS
            ? ['status' => 'up', 'detail' => "The question-builder worker was last seen {$age} s ago."]
            : ['status' => 'degraded', 'detail' => "The question-builder worker was last seen {$age} s ago; question generation is waiting."];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $set
     */
    private function touch(int $id, string $workerId, array $set, ?int $now): string
    {
        $job = db_connect()->table('ai_jobs')->select('status, claimed_by')->where('id', $id)->get()->getRowArray();

        if ($job === null) {
            return 'unknown';
        }

        if ($job['status'] === self::CANCELLED) {
            return 'cancelled';
        }

        if ($job['status'] !== self::RUNNING || $job['claimed_by'] !== $workerId) {
            return 'unknown';
        }

        $stamp = $this->stamp($now);

        db_connect()->table('ai_jobs')
            ->where('id', $id)
            ->where('status', self::RUNNING)
            ->where('claimed_by', $workerId)
            ->update($set + ['heartbeat_at' => $stamp, 'updated_at' => $stamp]);

        return 'running';
    }

    /**
     * @param array<string, mixed> $set
     */
    private function finish(int $id, string $workerId, array $set, ?int $now): bool
    {
        $stamp = $this->stamp($now);
        $db    = db_connect();

        $db->table('ai_jobs')
            ->where('id', $id)
            ->where('status', self::RUNNING)
            ->where('claimed_by', $workerId)
            ->update($set + ['finished_at' => $stamp, 'heartbeat_at' => $stamp, 'updated_at' => $stamp]);

        return $db->affectedRows() === 1;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        foreach (['payload', 'result', 'checkpoint'] as $column) {
            $row[$column] = ($row[$column] ?? null) === null ? null : json_decode((string) $row[$column], true);
        }

        return $row;
    }

    private function stamp(?int $now): string
    {
        return date('Y-m-d H:i:s', $now ?? time());
    }
}
