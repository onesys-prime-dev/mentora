# System status

`/admin/monitoring` (permission `monitoring.view`) is a read-only page for
system administrators. It refreshes itself every minute and changes nothing.
The public `/health` endpoint is separate and unchanged (database up or down,
nothing else).

## Health

| Check | Up | Degraded | Down |
|---|---|---|---|
| Database | answers in under 1 s | answers slowly | does not answer |
| File storage | writable folders can be written | | a writable folder is not writable |
| Disk space | under 90% used | 90% or more | 97% or more |
| Question-builder (AI) worker | the worker reported in the last 90 s | never reported, or silent for more than 90 s (generation waits in the queue) | |
| Scheduled jobs | all seven reported within their allowed gap | one or more late, or never reported | one silent for three times its gap |

The question-builder check reads the worker's heartbeat (the newest
`ai_worker_heartbeats.last_seen_at`; the worker reports every 15 s while idle).
With `questionBuilder.useJobs = true`, which both servers use, **only** that
heartbeat counts: the old HTTP service is not consulted, so a stopped worker
shows as degraded ("last seen N s ago; question generation is waiting") instead
of being hidden. With `useJobs = false` a reporting worker still wins, and
otherwise the original service at `questionBuilder.serviceUrl` is asked. After
a restart the heartbeat is back within about 15 s; a worker that never
reports is the first thing to check after a deploy (`journalctl -u
mentora-qb-worker-<env>`, see `deploy.md`).

The overall badge is the worst of them. A check that throws is shown as down
with a generic message (the detail goes to the log).

The jobs check (audit 6.2) reads each scheduled job's heartbeat: every command
records when it last ran to the end, a run with nothing to do included (a dry
run does not count). `ScheduledJobs` holds the list:

| Command | Heartbeat setting | Allowed gap |
|---|---|---|
| `backup:run` | `Monitoring.backupRunAt` | 15 min |
| `parents:notify` | `Monitoring.parentsNotifyAt` | 45 min |
| `exports:run` | `Monitoring.exportsRunAt` | 15 min |
| `subscriptions:sync` | `Monitoring.subscriptionSyncAt` | 26 h |
| `institutions:purge-expired` | `Monitoring.purgeExpiredAt` | 26 h |
| `sis:sync` | `Monitoring.sisSyncAt` | 26 h (only while some institution has SIS enabled) |
| `sis:grades` | `Monitoring.sisGradesAt` | 26 h (likewise) |

The check names the late ones ("Not reporting: backup:run, parents:notify."), and
the admin home shows the same warning. On a new server every job reads "never
reported" until the scheduler's first runs. The system administrators are also
emailed once when a job stops and once when it is back, by the hourly scheduler
check (`uptime.md`). Three cases it handles on purpose:

- **A second stall.** A job that reported and stopped again between two checks
  is a new period: "running again", then "stopped" again, each told once.
- **No administrator yet.** A stop is only remembered once somebody was told, so
  the first system administrator still hears of a job that stopped before they
  existed.
- **SIS turned off.** A SIS job that had stopped and is no longer checked (no
  institution has SIS enabled now) is forgotten without a word: nothing ran, so
  nothing is "running again".

## Also on the page
- Platform counts: institutions, active users, active today, live sessions,
  failed sign-ins in the last 24 hours and 7 days.
- Subscription watch: institutions in the warning window, grace or read-only.
- Usage against plans: per institution, active users and seats, storage and AI
  against the plan's limits.

## Not here yet
Response time, error rate and slowest pages (24 h / 7 d) need request metrics
the app does not record yet; adding per-request logging is its own decision.
Backups and SIS runs get their own checks with their branches.
