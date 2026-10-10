<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Services\AI\LocalQuestionBuilderClient;
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

    /** Seconds without a daily job run before it is late / dead (36 hours, 72 hours). */
    private const JOB_LATE = 129600;

    private const JOB_DEAD = 259200;

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
        ];
    }

    public const LABELS = [
        'database' => 'Database',
        'storage'  => 'File storage',
        'disk'     => 'Disk space',
        'ai'       => 'Question-builder (AI) service',
        'jobs'     => 'Scheduled jobs',
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

    /**
     * @param int|null $lastRun unix time of the last run, null if it never ran
     *
     * @return array{status: string, detail: string}
     */
    public static function jobStatus(?int $lastRun, int $now): array
    {
        if ($lastRun === null) {
            return ['status' => self::DEGRADED, 'detail' => 'The daily subscription job has not reported a run yet.'];
        }

        $age    = max(0, $now - $lastRun);
        $detail = 'Daily subscription job last ran ' . date('Y-m-d H:i', $lastRun) . '.';

        return match (true) {
            $age >= self::JOB_DEAD => ['status' => self::DOWN, 'detail' => $detail . ' It has not run for more than 3 days.'],
            $age >= self::JOB_LATE => ['status' => self::DEGRADED, 'detail' => $detail . ' It is late.'],
            default                => ['status' => self::UP, 'detail' => $detail],
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
        return (new LocalQuestionBuilderClient())->isAvailable()
            ? ['status' => self::UP, 'detail' => 'The service answers.']
            : ['status' => self::DEGRADED, 'detail' => 'The service does not answer; question generation is unavailable.'];
    }

    /** @return array{status: string, detail: string} */
    private function jobs(): array
    {
        $last = setting('Monitoring.subscriptionSyncAt');

        return self::jobStatus(is_numeric($last) ? (int) $last : null, (new DateTimeImmutable())->getTimestamp());
    }
}
