# Uptime check (runbook)

A scheduled GitHub Actions workflow, `.github/workflows/uptime.yml`, checks the
staging site and the production site once an hour. It runs the same smoke test
as the end of every deploy (`.github/scripts/smoke-test.php`, 11 checks) against
each, and tells you when either is down or broken, without anyone having to look.
The two are checked independently (two jobs of one run), so one being down never
hides the other.

## How it works

1. Every hour (at minute 17 UTC) GitHub starts the workflow.
2. It runs the smoke test against `https://mentora.staging.srv1404227.hstgr.cloud`
   and `https://mentora.onesyssolutions.com`: `/health`, the login page, the built
   CSS and JS, and that anonymous visitors are sent to login. Production's
   certificate is verified; staging's self-signed one is not (see "Settings").
3. A failed check is retried twice, 30 seconds apart, so one network blip does
   not raise an alert. Three failures in a row mean the site is really in trouble.
4. Then it **opens a GitHub issue** for that site, titled "Staging is down or
   broken" or "Production is down or broken", labelled `uptime` and
   `uptime-staging` / `uptime-production`, assigned to `waway647`, with a link to
   the failed run. GitHub emails the assignee. The failed run also emails whoever
   last edited the schedule.
5. While a site keeps failing, each hourly run adds a comment to its issue (no
   duplicates, and one issue per site). When that site passes, its issue
   **closes itself** with a comment. Production being down is the urgent one.

You can also start it by hand: Actions tab, "Uptime check", "Run workflow".

## When you get the alert

1. Open the issue, then the failed run. The `FAIL` lines say what is wrong.
2. Find the cause in this table, and also see the failure table in `docs/deploy.md`.

| What the run says | Usually means | First thing to do |
|---|---|---|
| `Could not reach ...` | The server, nginx, PHP-FPM or DNS is down | Check the VPS is running in the Hostinger panel; on the server restart `php8.5-fpm` and nginx (CloudPanel) |
| `GET /health is ok` fails, HTTP 503 | The app cannot reach its database | Is MySQL running? Disk full? Check the `.env` database settings |
| `GET /login answers 200` fails, HTTP 500 | PHP error | Read `writable/logs/` and `~/logs/php/error.log`. Was there a deploy in the last hour? |
| Debug toolbar or error output on the login page | `CI_ENVIRONMENT` is wrong on the server | Fix the server `.env` (it must not be `development`) |
| No built stylesheet or script, or an asset is 404 | `public/build` did not deploy | Re-run the Deploy workflow |
| An anonymous page does not go to login | **A security problem** | Treat as urgent: take the site offline or revert the last deploy first, investigate after |

3. If a deploy caused it, revert the merged PR (see "Rolling back code" in
   `docs/deploy.md`). The database backup from that deploy is in `~/backups`.
4. Add a comment to the issue with the cause (one line). The history is useful.
5. Once fixed, wait for the next hourly run or start it by hand. It closes the
   issue.

Planned work (a server reboot, a migration) will open an issue. It closes
itself afterwards; or disable the workflow while you work (Actions tab,
"Uptime check", "..." menu, "Disable workflow") and enable it again when done.

## Cost (read before making it more frequent)

On a private repository, every job is billed at least one Actions minute, and
the free plan includes 2,000 minutes a month, shared with CI and Deploy. Each
run now has two jobs (staging and production), so at the hourly schedule this
check uses about **1,440 minutes a month** (24 runs a day times 30 days times
two sites, about a minute each; a failing job takes about 3 minutes).

| Schedule | Minutes a month (both sites) | Share of the free 2,000 |
|---|---|---|
| Every 4 hours | about 360 | 18% |
| Every 2 hours | about 720 | 36% |
| **Hourly (now)** | **about 1,440** | **72%, heavy next to CI** |
| Every 30 minutes | about 2,880 | over the whole allowance |

If the allowance is tight, check production hourly and staging less often, or
move to an external uptime service for production (see below).

Check what the repository really uses under Settings, Billing and plans,
Usage. To change the schedule edit the `cron:` line in `uptime.yml`; GitHub's
minimum is every 5 minutes. If the repository becomes public or moves to a
paid plan the minutes stop mattering and every 15 minutes is reasonable.

## What it cannot do

- It checks hourly, so an outage shorter than that can pass unseen, and you may
  learn about one up to an hour late.
- GitHub can delay scheduled runs by many minutes under load, and occasionally
  skips one. That is why this is a safety net, not a guarantee.
- It sees only what an anonymous visitor sees. It cannot tell that a page is slow,
  or that something behind login is broken.
- It runs on GitHub, so it works when your server is down, but it shares GitHub's
  own outages.
- Scheduled workflows run only from the default branch (`main`). If the
  repository ever becomes public, GitHub switches them off after 60 days with no
  repository activity; re-enable under the Actions tab.

When these limits start to matter (a real certificate, a production site, more
users), add an external uptime service as the primary monitor and keep this as
the backup.

## Settings and upkeep

- **Who is alerted:** change `ALERT_USER` in `uptime.yml`. Several people can be
  listed as `user1,user2`. Also check Settings, Notifications on GitHub, that
  "Actions" notifications and issue emails are on.
- **Issues must be enabled** for the repository, because that is how the alert
  is delivered.
- **Certificate:** staging uses a self-signed certificate, so its entry in the
  `matrix` of `uptime.yml` has `insecure: true`. Set it to `false` once staging has
  a real certificate (and `TLS_INSECURE` in the staging environment, see
  `docs/github-environments.md`). Production is always verified.
- **Addresses:** the two sites' URLs are in the `matrix` at the top of the job in
  `uptime.yml`. Change them there if a site moves; keep them equal to each
  environment's `SITE_URL` in GitHub.
