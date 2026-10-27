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
            $last = ScheduledJobs::lastRun($command);

            if (in_array($command, $stale, true)) {
                if (array_key_exists($command, $periods)) {
                    continue;
                }

                $periods[$command] = $last ?? 0;
                $since             = $last === null ? 'it was installed' : date('Y-m-d H:i', $last);

                foreach ($admins as $admin) {
                    $alerted += (int) $notices->send($admin, 'jobs.stale', "Scheduled job stopped: {$command}", "{$command} has not reported since {$since}. Check the server's crontab (docs/server-setup.md, section 6).", true, "jobs:stale:{$command}:" . ($last ?? 'never'), null, 'admin/monitoring');
                }
            } elseif (array_key_exists($command, $periods)) {
                unset($periods[$command]);

                foreach ($admins as $admin) {
                    $alerted += (int) $notices->send($admin, 'jobs.recovered', "Scheduled job running again: {$command}", "{$command} reported again at " . date('Y-m-d H:i', (int) $last) . '.', true, "jobs:recovered:{$command}:{$last}", null, 'admin/monitoring');
                }
            }
        }

        setting()->set('Monitoring.staleJobs', json_encode($periods));

        return ['stale' => $stale, 'alerted' => $alerted];
    }

    /** @return array<string, int> */
    private function periods(): array
    {
        $stored = json_decode((string) setting('Monitoring.staleJobs'), true);

        return is_array($stored) ? $stored : [];
    }
}
