<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Services\Notifications\NotificationRecipients;
use App\Services\Notifications\NotificationService;

/**
 * The hourly scheduler check (audit decisions 6.2, U2, D11).
 *
 * It can't come from the jobs themselves (a stopped cron sends nothing), so the
 * uptime workflow calls it from GitHub (POST internal/scheduler-check). Every
 * system administrator is told, urgently (so by email too), once when a job
 * stops reporting and once when it reports again. The stale periods are kept
 * in the setting `Monitoring.staleJobs` (command => its last heartbeat when it
 * went stale, 0 for never); the notifications' dedupe keys keep a repeat check
 * from telling anyone twice.
 *
 * A job that reported and stopped again between two checks starts a new
 * period ("running again", then "stopped" again). A period is only kept once
 * somebody was told, so the first administrator still hears of a job that
 * stopped before they existed. A SIS job no longer checked (SIS went out of
 * use) is forgotten without a word; if SIS comes back while it is still
 * stopped, the stop is remembered again (everyone already knows), so its
 * "running again" is still sent.
 */
final class SchedulerCheck
{
    /** @return array{stale: list<string>, alerted: int} alerted counts the notifications sent */
    public function run(int $now): array
    {
        $stale   = ScheduledJobs::stale($now);
        $periods = $this->periods();
        $admins  = (new NotificationRecipients())->administrators();
        $notices = new NotificationService();
        $alerted = 0;

        foreach (array_keys(ScheduledJobs::JOBS) as $command) {
            $last     = ScheduledJobs::lastRun($command);
            $recorded = array_key_exists($command, $periods);

            if (in_array($command, $stale, true)) {
                if ($recorded && $periods[$command] === ($last ?? 0)) {
                    continue; // the same stop, already told
                }

                // It reported since the stop it was told about, and stopped again: that is a new period.
                if ($recorded) {
                    $alerted += $this->tell($admins, $notices, 'jobs.recovered', "Scheduled job running again: {$command}", "{$command} reported again at " . date('Y-m-d H:i', (int) $last) . '.', "jobs:recovered:{$command}:{$last}");
                    unset($periods[$command]);
                }

                $since = $last === null ? 'it was installed' : date('Y-m-d H:i', $last);
                $key   = "jobs:stale:{$command}:" . ($last ?? 'never');
                $sent  = $this->tell($admins, $notices, 'jobs.stale', "Scheduled job stopped: {$command}", "{$command} has not reported since {$since}. Check the server's crontab (docs/server-setup.md, section 6).", $key);

                // Kept once somebody knows, told now or before (SIS off and on again), so an administrator
                // added later is still told, and "running again" still follows.
                if ($sent > 0 || $this->known($admins, $notices, $key)) {
                    $periods[$command] = $last ?? 0;
                }

                $alerted += $sent;
            } elseif ($recorded) {
                unset($periods[$command]);

                // Out of use (SIS turned off) is not "running again": nothing ran.
                if (ScheduledJobs::watched($command) && $last !== null) {
                    $alerted += $this->tell($admins, $notices, 'jobs.recovered', "Scheduled job running again: {$command}", "{$command} reported again at " . date('Y-m-d H:i', $last) . '.', "jobs:recovered:{$command}:{$last}");
                }
            }
        }

        setting()->set('Monitoring.staleJobs', json_encode($periods));

        return ['stale' => $stale, 'alerted' => $alerted];
    }

    /**
     * Sends one urgent notification to each administrator.
     *
     * @param list<int|object> $admins
     *
     * @return int how many were sent
     */
    private function tell(array $admins, NotificationService $notices, string $type, string $title, string $body, string $key): int
    {
        $sent = 0;

        foreach ($admins as $admin) {
            $sent += (int) $notices->send($admin, $type, $title, $body, true, $key, null, 'admin/monitoring');
        }

        return $sent;
    }

    /**
     * Whether some administrator already holds this notice.
     *
     * @param list<int|object> $admins
     */
    private function known(array $admins, NotificationService $notices, string $key): bool
    {
        foreach ($admins as $admin) {
            if ($notices->has($admin, $key)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> */
    private function periods(): array
    {
        $stored = json_decode((string) setting('Monitoring.staleJobs'), true);

        return is_array($stored) ? $stored : [];
    }
}
