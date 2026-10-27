<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

/**
 * The scheduled jobs and their heartbeats (audit decisions 6.2, 6.4).
 *
 * Each of the seven commands records when it last ran to the end (a setting,
 * `Monitoring.<job>At`), a run with nothing to do included; a dry run does not
 * count. A job is stale once it has not reported for longer than its allowed
 * gap, or never has. The SIS jobs count only while some institution has an
 * enabled SIS connection; they still run, and report, everywhere else.
 *
 * The admin home and the monitoring page read this (SystemHealth), and so does
 * the hourly scheduler check that emails the system administrators.
 */
final class ScheduledJobs
{
    /** @var array<string, array{setting: string, gap: int, sisOnly: bool}> by command, in display order */
    public const JOBS = [
        'backup:run'                 => ['setting' => 'Monitoring.backupRunAt', 'gap' => 15 * 60, 'sisOnly' => false],
        'parents:notify'             => ['setting' => 'Monitoring.parentsNotifyAt', 'gap' => 45 * 60, 'sisOnly' => false],
        'exports:run'                => ['setting' => 'Monitoring.exportsRunAt', 'gap' => 15 * 60, 'sisOnly' => false],
        'subscriptions:sync'         => ['setting' => 'Monitoring.subscriptionSyncAt', 'gap' => 26 * 3600, 'sisOnly' => false],
        'institutions:purge-expired' => ['setting' => 'Monitoring.purgeExpiredAt', 'gap' => 26 * 3600, 'sisOnly' => false],
        'sis:sync'                   => ['setting' => 'Monitoring.sisSyncAt', 'gap' => 26 * 3600, 'sisOnly' => true],
        'sis:grades'                 => ['setting' => 'Monitoring.sisGradesAt', 'gap' => 26 * 3600, 'sisOnly' => true],
    ];

    public static function heartbeat(string $command, ?int $now = null): void
    {
        setting()->set(self::JOBS[$command]['setting'], $now ?? time());
    }

    /** Unix time of the last run, null when it never reported. */
    public static function lastRun(string $command): ?int
    {
        $value = setting(self::JOBS[$command]['setting']);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The commands that have missed their window (or never reported), in display order.
     *
     * @return list<string>
     */
    public static function stale(int $now): array
    {
        $sisInUse = db_connect()->table('sis_connections')->where('enabled', 1)->countAllResults() > 0;
        $stale    = [];

        foreach (self::JOBS as $command => $job) {
            if ($job['sisOnly'] && ! $sisInUse) {
                continue;
            }

            $last = self::lastRun($command);

            if ($last === null || $now - $last > $job['gap']) {
                $stale[] = $command;
            }
        }

        return $stale;
    }

    /** @return array{status: string, detail: string} */
    public static function status(string $command, int $now): array
    {
        $last = self::lastRun($command);

        if ($last === null) {
            return ['status' => SystemHealth::DEGRADED, 'detail' => "{$command} has not reported a run yet."];
        }

        $detail = "{$command} last ran " . date('Y-m-d H:i', $last) . '.';

        return $now - $last > self::JOBS[$command]['gap']
            ? ['status' => SystemHealth::DEGRADED, 'detail' => $detail . ' It is late.']
            : ['status' => SystemHealth::UP, 'detail' => $detail];
    }
}
