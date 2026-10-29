<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Services\AI\AiJobService;
use App\Services\AI\LocalQuestionBuilderClient;
use App\Services\Courses\Assessments\AiJobGeneration;
use Closure;
use DateTimeImmutable;
use Throwable;

/**
 * What the platform's dependencies are doing right now (SA9): database,
 * storage, disk space, the question-builder (AI) service and the scheduled
 * jobs, each `up`, `degraded` or `down` with a short reason.
 *
 * What counts as which is decided by the pure mappers below, so it can be
 * tested with plain numbers. Each probe can be replaced (the constructor takes
 * closures keyed by check name) so tests never touch a real service.
 *
 * `degraded` is something to look at (the AI service is optional; a job is
 * late); `down` is something the platform cannot work without.
 */
class SystemHealth
{
    public const UP = 'up';

    public const DEGRADED = 'degraded';

    public const DOWN = 'down';

    /** @var array<string, Closure(): array{status: string, detail: string}> */
    private array $probes;

    /**
     * @param array<string, Closure(): array{status: string, detail: string}> $probes replacements by check name
     */
    public function __construct(array $probes = [])
    {
        $this->probes = $probes + [
            'database' => fn (): array => $this->database(),
            'storage'  => fn (): array => $this->storage(),
            'disk'     => fn (): array => $this->disk(),
            'ai'       => fn (): array => $this->ai(),
            'jobs'     => fn (): array => $this->jobs(),
            'backups'  => fn (): array => $this->backups(),
        ];
    }

    public const LABELS = [
        'database' => 'Database',
        'storage'  => 'File storage',
        'disk'     => 'Disk space',
        'ai'       => 'Question-builder (AI) service',
        'jobs'     => 'Scheduled jobs',
        'backups'  => 'Backups',
    ];

    /**
     * @return array<string, array{status: string, detail: string}> by check name, in display order
     */
    public function run(): array
    {
        $results = [];

        foreach (array_keys(self::LABELS) as $name) {
            try {
                $results[$name] = ($this->probes[$name])();
            } catch (Throwable $e) {
                log_message('error', "Health check {$name} failed: " . $e->getMessage());
                $results[$name] = ['status' => self::DOWN, 'detail' => 'The check itself failed.'];
            }
        }

        return $results;
    }

    /**
     * The worst of the given results.
     *
     * @param array<string, array{status: string, detail: string}> $results
     */
    public static function overall(array $results): string
    {
        $statuses = array_column($results, 'status');

        return in_array(self::DOWN, $statuses, true) ? self::DOWN : (in_array(self::DEGRADED, $statuses, true) ? self::DEGRADED : self::UP);
    }

    /*
    |--------------------------------------------------------------------------
    | Pure mappers
    |--------------------------------------------------------------------------
    */

    /** @return array{status: string, detail: string} */
    public static function diskStatus(float $free, float $total): array
    {
        if ($total <= 0) {
            return ['status' => self::DEGRADED, 'detail' => 'Disk size could not be read.'];
        }

        $exact  = (1 - $free / $total) * 100;
        $used   = (int) round($exact);
        $detail = "{$used}% used, " . number_format($free / 1073741824, 1) . ' GB free.';

        return match (true) {
            $exact >= 97 => ['status' => self::DOWN, 'detail' => $detail],
            $exact >= 90 => ['status' => self::DEGRADED, 'detail' => $detail],
            default     => ['status' => self::UP, 'detail' => $detail],
        };
    }

    /** @return array{status: string, detail: string} */
    public static function databaseStatus(int $milliseconds): array
    {
        $detail = "Answered in {$milliseconds} ms.";

        return $milliseconds >= 1000 ? ['status' => self::DEGRADED, 'detail' => $detail . ' That is slow.'] : ['status' => self::UP, 'detail' => $detail];
    }

    /*
    |--------------------------------------------------------------------------
    | Real probes
    |--------------------------------------------------------------------------
    */

    /** @return array{status: string, detail: string} */
    private function database(): array
    {
        $start = microtime(true);

        try {
            db_connect()->query('SELECT 1');
        } catch (Throwable $e) {
            log_message('error', 'Health: database unreachable: ' . $e->getMessage());

            return ['status' => self::DOWN, 'detail' => 'The database does not answer.'];
        }

        return self::databaseStatus((int) round((microtime(true) - $start) * 1000));
    }

    /** @return array{status: string, detail: string} */
    private function storage(): array
    {
        $paths = [WRITEPATH . 'uploads', WRITEPATH . 'cache', WRITEPATH . 'logs', WRITEPATH . 'session'];

        foreach ($paths as $path) {
            if (is_dir($path) && ! is_writable($path)) {
                return ['status' => self::DOWN, 'detail' => 'The folder ' . basename($path) . ' is not writable.'];
            }
        }

        return ['status' => self::UP, 'detail' => 'The writable folders can be written to.'];
    }

    /** @return array{status: string, detail: string} */
    private function disk(): array
    {
        $free  = @disk_free_space(WRITEPATH);
        $total = @disk_total_space(WRITEPATH);

        return self::diskStatus($free === false ? 0.0 : (float) $free, $total === false ? 0.0 : (float) $total);
    }

    /** @return array{status: string, detail: string} */
    private function ai(): array
    {
        $lastSeen = (new AiJobService())->lastWorkerSeen();
        $now      = (new DateTimeImmutable())->getTimestamp();

        return self::aiStatus(
            AiJobGeneration::enabled(),
            $lastSeen,
            $now,
            static fn (): bool => (new LocalQuestionBuilderClient())->isAvailable(),
        );
    }

    /**
     * With background jobs on, only the worker's heartbeat counts: the
     * original HTTP service is not what generation talks to, so its silence
     * must not read as "up" and its absence must not hide a stopped worker.
     * With jobs off, a worker that is reporting is still the answer, and
     * otherwise the original service is.
     *
     * @param callable(): bool $serviceAnswers only called when it can matter
     *
     * @return array{status: string, detail: string}
     */
    public static function aiStatus(bool $useJobs, ?int $lastSeen, int $now, callable $serviceAnswers): array
    {
        if ($useJobs || ($lastSeen !== null && ($now - $lastSeen) <= AiJobService::WORKER_STALE_SECONDS)) {
            return AiJobService::workerStatus($lastSeen, $now);
        }

        return $serviceAnswers()
            ? ['status' => self::UP, 'detail' => 'The service answers.']
            : ['status' => self::DEGRADED, 'detail' => 'The service does not answer; question generation is unavailable.'];
    }

    /** @return array{status: string, detail: string} */
    private function backups(): array
    {
        $alert = (new \App\Services\Backup\BackupService())->alert();

        return $alert === null
            ? ['status' => self::UP, 'detail' => 'A recent backup succeeded.']
            : ['status' => self::DEGRADED, 'detail' => $alert['message']];
    }

    /**
     * All seven scheduled jobs (6.2): degraded while any has missed its window, down once one has been silent
     * for three times its allowed gap.
     *
     * @return array{status: string, detail: string}
     */
    private function jobs(): array
    {
        $now   = (new DateTimeImmutable())->getTimestamp();
        $stale = ScheduledJobs::stale($now);

        if ($stale === []) {
            return ['status' => self::UP, 'detail' => 'All seven scheduled jobs reported on time.'];
        }

        $dead = array_filter($stale, static fn (string $command): bool => ($last = ScheduledJobs::lastRun($command)) !== null && $now - $last >= 3 * ScheduledJobs::JOBS[$command]['gap']);

        return ['status' => $dead === [] ? self::DEGRADED : self::DOWN, 'detail' => 'Not reporting: ' . implode(', ', $stale) . '.'];
    }
}
