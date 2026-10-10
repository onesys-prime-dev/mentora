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
| Question-builder (AI) service | answers | does not answer (optional feature) | |
| Scheduled jobs | `subscriptions:sync` ran in the last 36 h | late (36 h to 72 h) or never reported | silent for 72 h or more |

The overall badge is the worst of them. A check that throws is shown as down
with a generic message (the detail goes to the log).

The jobs check relies on the daily `subscriptions:sync` run recording its time
(`Monitoring.subscriptionSyncAt`). On a new server it reads "never reported"
until the scheduler's first run.

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
