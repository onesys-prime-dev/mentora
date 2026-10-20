# Deploying (staging and production)

Merging a pull request into `main` runs `.github/workflows/deploy-staging.yml`, which
first runs the full CI workflow on that commit and deploys to the **staging**
environment only if it passes (you can also start it by hand from the Actions
tab; CI still runs first). **Production** is deployed by pushing a version tag
(`v1.2.3`) with `.github/workflows/deploy-production.yml`: it checks the tag is
on `main`, re-runs CI, then waits for an approval before it touches the server.
Both use the same steps (`deploy-reusable.yml`) against a GitHub Environment of
their own; how to create the environments, their secrets and the approval rule
is in `github-environments.md`. This page explains what a deploy does, what it
needs, and what to do when it fails. No secrets belong in this file. Staging and
production live on the **same VPS**, in separate site users; the table below
lists both.

## Where it goes

Both environments are on one Hostinger VPS (Ubuntu 24.04, managed with CloudPanel; other sites share it). Nobody has root through the deploy users, and neither has `sudo` except `clpctlWrapper`.

| | Staging | Production |
|---|---|---|
| Site user | `mentora-staging` | `mentora-production` |
| Live folder (`DEPLOY_PATH`) | `/home/mentora-staging/htdocs/mentora.staging.srv1404227.hstgr.cloud` | `/home/mentora-production/htdocs/mentora.onesyssolutions.com` |
| URL (`SITE_URL`) | https://mentora.staging.srv1404227.hstgr.cloud | https://mentora.onesyssolutions.com |
| Certificate (`TLS_INSECURE`) | self-signed, so `true` | real, so `false` |
| Worker code (`QB_PATH`) | `/opt/mentora-qb-staging` | `/opt/mentora-qb-production` |
| Worker unit (`QB_SERVICE`) | `mentora-qb-worker-staging` | `mentora-qb-worker-production` |
| Worker secrets file | `/etc/mentora/qb-worker-staging.env` | `/etc/mentora/qb-worker-production.env` |
| Worker reaches the site at | `http://127.0.0.1:18081` | `http://127.0.0.1:18080` |
| Database | its own, `mentora-staging-db` | its own, `mentora-production-db` |

nginx serves each site's `public/` folder. The site runs on PHP **8.5** (`php-fpm8.5`); the server's plain `php` is 8.4, so server-side commands use `php8.5`. The database is MySQL 8.4 (Percona); credentials live only in each site's `.env`. PHP-FPM revalidates files every 2 seconds (`opcache.validate_timestamps=1`, no preload), so new code needs no FPM restart.

## What a deploy does, in order

0. **CI** runs first (the `ci` job calls `ci.yml`). If it fails, nothing below runs. For production, the tag is checked first and an approval is awaited after CI.
0b. **Check the environment is configured**: the secrets and variables below must exist for this environment; otherwise it stops here and names what is missing.
1. **Build** on the GitHub runner: Composer (production only, PHP 8.5), `npm ci`, `npm run build`.
2. **Check the target**: stops unless the live folder has `.env` and `writable/`, and `php8.5` exists. A wrong path fails here instead of silently deploying to a dead folder.
3. **Back up the database** to `~/backups/deploy-<timestamp>.sql.gz` on the server. The deploy stops if the dump did not finish. The 10 newest backups are kept.
4. **Copy** the app with `rsync --delete`. Excludes are anchored to the project root (`/tests`, not any folder named tests). Never touched: `.env`, `writable/`, `node_modules/`. Never copied: tests, docs, `.github`, `.claude`, the Python question-builder service, and other development files.
4b. **Copy the worker's code** to `QB_PATH` and install its libraries (only when `QB_PATH` is set). The running worker keeps its old code for now.
5. **Fix `writable/` permissions.**
6. **Run `php8.5 spark migrate --all`** so the database matches the code.
6b. **Clear the application cache** (`spark cache:clear`; a warning, not a failure).
6c. **Restart the worker** (only when `QB_PATH` is set), after the migrations so the new worker never meets an old schema. See "The question-builder worker" below. A warning, not a failure: the website does not depend on the worker.
7. **Health check**: `GET /health` must answer `{"status":"ok"}` (it only does when the app can reach its database). Retried for about a minute, then the deploy fails. The check skips certificate verification (`curl -k`) only while the environment's `TLS_INSECURE` variable is `true` (staging uses a self-signed certificate); set it to `false` once the site has a real one. Production defaults to verifying.
8. **Smoke test** (`.github/scripts/smoke-test.php`, 11 checks): `/health` again, then what a visitor meets first. `/login` answers 200 and shows the email and password fields, with no debug toolbar or error output; the built stylesheet and script it links to load as CSS and JavaScript (not an HTML error page); and `/`, `/student/dashboard`, `/instructor/dashboard` and `/files/1` send an anonymous visitor to the login page. The same `TLS_INSECURE` setting decides whether it passes `--insecure`. You can run it by hand against any copy of the site: `php .github/scripts/smoke-test.php https://host --insecure`.

## Secrets and settings it needs

Per **environment** (Settings > Environments > staging / production; step by
step in `github-environments.md`). Each environment has its own copy of these,
with that server's values.

Secrets:

| Secret | What |
|---|---|
| `VPS_PRIVATE_KEY` | SSH private key whose public half is in the server user's `authorized_keys` |
| `VPS_USERNAME` | `mentora` |
| `VPS_HOST` | the server's host name or IP |
| `VPS_KNOWN_HOSTS` | *(recommended)* the server's ssh-ed25519 host key, so the runner verifies it is talking to the real server. Only the `ssh-ed25519 AAAA...` part is used and it is paired with `VPS_HOST`, so it does not matter whether the scan used the IP or the host name |

Variables:

| Variable | What |
|---|---|
| `DEPLOY_PATH` | the live folder on the server, e.g. `/home/mentora/htdocs/mentora` |
| `SITE_URL` | the site's `https://` address, no trailing slash |
| `SSH_PORT` | *(optional, default 22)* |
| `PHP_BIN` | *(optional, default `php8.5`)* the PHP binary used for `spark migrate` on the server |
| `TLS_INSECURE` | *(optional)* `true` to skip certificate checks in the health check and smoke test. Default `true` on staging, `false` on production |
| `QB_PATH` | *(optional)* the question-builder worker's folder. Unset: the worker is not deployed from here |
| `QB_SERVICE` | *(optional, default `mentora-qb-worker-<environment>`)* the worker's systemd unit |

There are no fallbacks for `DEPLOY_PATH` and `SITE_URL`: a missing value stops
the deploy with a clear message. `VPS_USERNAME` is `mentora-staging` or
`mentora-production`, not `mentora`.

To create `VPS_KNOWN_HOSTS`, run this on your own computer and copy the output
line(s) into the secret:

```bash
ssh-keyscan -p 22 -t ed25519 <the same host as VPS_HOST>
```

Check once that the fingerprint matches the server's real key (on the server:
`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`, and locally
`ssh-keyscan -t ed25519 <host> | ssh-keygen -lf -`). Until the secret exists the
deploy prints a warning and accepts the key on first contact.

## The question-builder worker

AI question generation runs as a small separate process on the same server, one per environment (`mentora-qb-worker-staging`, `mentora-qb-worker-production`). It opens no port: it asks Mentora for queued jobs over loopback, calls the hosted model (Groq's free tier by default) and reports back. Mentora's own `.env` never holds the provider key.

### What each environment needs

| Piece | Rule |
|---|---|
| **Unit** | Made from `python-question-builder-service/deploy/mentora-qb-worker.service` (replace `<env>`). Runs as the environment's own site user, `Restart=on-failure`, `RestartSec=5`, gives up after 10 failed starts in 5 minutes (`systemctl reset-failed` after fixing it), `TimeoutStopSec=30`, `MemoryHigh=768M` and `MemoryMax=1G`, `CPUQuota=100%`, `TasksMax=64`, read-only filesystem except its own folder (`ProtectSystem=strict`, `ProtectHome`, `PrivateTmp`, `PrivateDevices`, `NoNewPrivileges`). |
| **Logs** | The journal: `journalctl -u mentora-qb-worker-<env> -f`. Start, model list, each failed job with its code and reason, and "PHP unavailable" warnings while the site is down. Never a key or a source text. The site user can read its own unit's journal. |
| **Secrets file** | `/etc/mentora/qb-worker-<env>.env`, **owner root, mode 600** (systemd reads it before dropping to the site user, so the website's PHP cannot read the Groq key). From `qb-worker.env.example`: `QB_PHP_URL`, `QB_WORKER_SECRET`, `QB_WORKER_ID` (`staging-1` / `production-1`), `QB_BACKEND=groq`, `GROQ_API_KEY`. |
| **Separate keys** | `GROQ_API_KEY` is different in staging and production, ideally from different Groq organisations (the free allowance is per organisation), and so is `QB_WORKER_SECRET`. Never copy one environment's file to the other. To check without printing anything, compare short hashes of the values in each running process (see "Reviewing a server" below). |
| **Mentora's `.env`** | `questionBuilder.workerSecret` = the worker's `QB_WORKER_SECRET` for that environment, and `questionBuilder.useJobs = true`. `questionBuilder.serviceUrl` is for the original HTTP service and is not used while `useJobs` is true. `app.baseURL`/`CI_ENVIRONMENT` are unrelated to the worker. |
| **Health signal** | The monitoring page's "Question-builder (AI) worker" row (`monitoring.md`): up while the worker's heartbeat is under 90 s old. With `useJobs = true` it is the only signal used, so a stopped worker is visible. |
| **Deploy step** | Copies the code to `QB_PATH`, installs `requirements.txt`, and after the migrations restarts the unit (below). |

### One-time setup, by someone with root

1. Create `QB_PATH` owned by the environment's site user, copy the service code there and create its virtual environment: `python3 -m venv venv && venv/bin/pip install -r requirements.txt`. Install Tesseract if scanned PDFs are wanted.
2. Create the secrets file as above (root, mode 600) and edit it.
3. Install the unit and start it: `systemctl daemon-reload && systemctl enable --now mentora-qb-worker-<env>`.
4. In Mentora's `.env` set `questionBuilder.workerSecret` (same secret) and, when ready to switch generation to background jobs, `questionBuilder.useJobs = true`.
5. Set the environment variables `QB_PATH` (and `QB_SERVICE` if the unit has another name) so each deploy updates the code and restarts the unit. Without `QB_PATH` the deploy leaves the worker alone.
6. Optional: let the deploy user restart the unit directly with a sudoers line such as `mentora-staging ALL=(root) NOPASSWD: /usr/bin/systemctl restart mentora-qb-worker-staging`. Without it the deploy uses the signal route below, which needs no sudo.

### How the deploy restarts it

The deploy users have no `sudo` for `systemctl`. The step therefore tries `sudo -n systemctl restart <unit>`, and otherwise sends the worker process `SIGUSR1` (the user owns the process). The worker finishes or hands back its current job, exits with status 75 and `Restart=on-failure` starts it on the new code. The step then waits for a new process id and checks the unit is still active ten seconds later. A plain `systemctl stop` sends `SIGTERM`, which ends the worker cleanly and keeps it stopped. If anything here fails the deploy shows a warning and carries on; the first thing to check is the monitoring row, then the journal.

If the **first** deploy after this change finds the old worker running (it has no `SIGUSR1` handler), the signal simply ends it and systemd restarts it, which has the same effect.

Rolling back: set `questionBuilder.useJobs = false` and generation goes back to the original in-request service (then it needs that service running on `questionBuilder.serviceUrl`; neither server has one today, so generation reports itself unavailable). Stopping the worker only stops new jobs; queued jobs wait and a job that was running is put back at most twice.

### Reviewing a server against this

Run as the environment's own user. Nothing below prints a secret.

```bash
id -un; systemctl cat mentora-qb-worker-<env>                        # unit as installed
systemctl show mentora-qb-worker-<env> -p MemoryMax,MemoryHigh,NRestarts,ActiveEnterTimestamp
pid=$(systemctl show mentora-qb-worker-<env> -p MainPID --value)
h() { printf %s "$1" | sha256sum | cut -c1-8; }
tr '\0' '\n' < /proc/$pid/environ | grep -E '^(QB_|GROQ_API_KEY)' | while IFS='=' read k v; do
  case $k in QB_WORKER_SECRET|GROQ_API_KEY) echo "$k len=${#v} hash=$(h "$v")";; *) echo "$k=$v";; esac; done
grep -E '^questionBuilder\.workerSecret' .env | sed 's/^[^=]*=[ ]*//' | tr -d "'\"" | sha256sum | cut -c1-8   # must equal the worker's QB_WORKER_SECRET hash
journalctl -u mentora-qb-worker-<env> -n 20 --no-pager
```

The two environments' `GROQ_API_KEY` hashes and `QB_WORKER_SECRET` hashes must differ from each other; a site's secret hash must equal its own worker's.

### Findings of the last review (2026-10-20)

| | Staging | Production | Action |
|---|---|---|---|
| Unit name | `mentora-qb-worker-staging` | `mentora-qb-worker-production` | the deploy used to restart `mentora-qb-worker`, which does not exist; fixed (`QB_SERVICE`) |
| Restart without sudo | not possible | not possible | fixed: `SIGUSR1` route |
| Secrets file owner | `root`, 600 | **the site user**, 600 | change to `root:root` so the website's PHP cannot read the Groq key |
| Groq key, worker secret | distinct from production's, secret matches the site `.env` | distinct from staging's, secret matches the site `.env` | none |
| `QB_WORKER_ID` | `staging-1` | **unset** (shows as `host-pid`, a new row after every restart) | set `production-1` |
| Hardening in the installed unit | `MemoryMax`, `CPUQuota`, `ProtectSystem` etc. | the same | add `MemoryHigh`, `TimeoutStopSec`, `StartLimit*`, `TasksMax`, `PrivateDevices`, `SyslogIdentifier` from the new template |
| `/opt` folder mode | 755 | 770 | harmless; production's is tighter |
| Worker folder contents | no `eval/` | has `eval/` (copied by hand before the deploy step existed) | harmless; the deploy never deletes excluded folders |
| `questionBuilder.useJobs` | true | true | none |
| `questionBuilder.serviceUrl` | `http://127.0.0.1:8008`, nothing listens | same | unused while `useJobs` is true; can be removed |
| Heartbeat on the monitoring page | fresh | fresh | none |

## When a deploy fails

| Failing step | Meaning | What to do |
|---|---|---|
| Check this environment is configured | A secret or variable is missing for this environment (the message names it) | Add it under Settings > Environments > *name*. Nothing was changed. |
| Check the deploy folder | The path is wrong or `.env`/`writable` is missing | Fix the environment's `DEPLOY_PATH` variable, or investigate the server. Nothing was changed. |
| Back up the database | The dump failed or was cut short | Nothing was deployed. Check disk space and the DB credentials in the server `.env`. |
| Deploy application / permissions | File copy problem | Re-run the workflow. Safe to repeat. |
| Run database migrations | A migration errored | The new code is already on the server. Read the error, fix the migration in a new PR, or restore the backup (below). Do not re-run blindly. |
| Clear the application cache / Restart the question-builder worker | Warnings, not failures | The site is fine. Restart or inspect the worker by hand (see "The question-builder worker"). |
| Check the site is healthy | The site does not answer ok | Open the site and the logs (`writable/logs/`, `~/logs/php/error.log`). The backup from this deploy is on the server. |
| Smoke test the site | The site is up and reaches its database, but something a visitor needs is broken. The FAIL lines say what: a login page that errors or shows the debug toolbar (wrong `CI_ENVIRONMENT`), no built assets or a 404 on them (the `public/build` folder did not deploy, or `app.baseURL` is wrong), or a protected page that no longer sends a visitor to login (**a security problem: treat it as urgent**) | The new code is already live. Fix forward in a new PR, or revert the merged PR (see "Rolling back code"). |

## The first system administrator

A new server has no accounts. Nobody can sign up as a system administrator;
create the first one on the server with spark, using a **real email address you
can open** (the sign-in code is emailed there):

```bash
cd /home/mentora-production/htdocs/mentora.onesyssolutions.com   # your DEPLOY_PATH
php8.5 spark mail:test you@yourdomain.com   # first prove mail works (see mail.md)
php8.5 spark admin:create you@yourdomain.com yourname
```

It asks for the password (at least 12 characters, typed twice, hidden). Leave
the password off the command line so it does not end up in the shell history.
Then sign in at `/login`; a 6-digit code arrives by email; enter it.

Outside `development` the command refuses placeholder addresses such as
`@example.com` or `@mentora.test`, and `SystemAdminSeeder` (demo data with a
published password) refuses to run in `production`. Never seed a real server.

## Restoring a database backup

Use only if a migration or deploy damaged the data. Run as the environment's site user on the
server, with the backup file you want:

```bash
cd /home/mentora-production/htdocs/mentora.onesyssolutions.com   # your DEPLOY_PATH
get() { grep -E "^$1[[:space:]]*=" .env | head -1 | sed 's/^[^=]*=[[:space:]]*//' | tr -d "'\""; }
gunzip -c ~/backups/deploy-YYYYMMDD-HHMMSS.sql.gz | MYSQL_PWD="$(get database.default.password)" \
  mysql -h "$(get database.default.hostname)" -u "$(get database.default.username)" "$(get database.default.database)"
```

This replaces the tables in the backup with their saved copies. Restoring puts
the data back to that moment; anything entered since is lost.

## Rolling back code

There is no automatic rollback yet; a failed deploy writes these steps to its run summary. Code is rolled back by deploying the previous good version:

- **Production**: Actions > *Deploy Mentora to production* > **Run workflow**, choose the previous version **tag** under "Use workflow from". It runs CI, waits for the approval and redeploys that tag. No new tag is needed.
- **Staging**: revert the pull request on GitHub (the "Revert" button on the merged PR) and merge the revert into `main`.

A migration is not undone by redeploying older code (the older code just runs against the newer schema; the migrations in this project only add). If a release also changed data badly, restore the backup of that deploy (above) after deciding what to do about anything entered since.

## Migrations before a deploy

A deploy runs the migrations itself, after copying the code. When a release should have its migrations applied earlier, put just the new migration files into the site's `app/Database/Migrations/` and run `php8.5 spark migrate --all` there, after a backup (`~/backups/premigrate-*.sql.gz`). Only do this for migrations that add (columns, tables, wider enums), since the code that is still live must keep working against them. The deploy that follows copies the same files over themselves and finds nothing left to run.

## Known limits (planned improvements)

- Files are copied straight into the live folder, so for a few seconds the new
  code runs before the migrations finish. Release folders with an instant
  switch and automatic rollback are the next step.
- Files excluded from the copy that already exist on the server (for example an
  old `python-question-builder-service/` folder) are left in place; they can be
  deleted by hand.
- The deploy folders on both servers still hold files from the first manual
  installation (`.git`, `tests`, `docs`, `node_modules`, `.github`, `.claude`,
  `builds`, `env`, `python-question-builder-service`). nginx serves only
  `public/` (checked: `/.git/config`, `/.env` and `/composer.json` answer 404),
  so they are not reachable, but the deploy never removes excluded files and
  `node_modules` alone is 244 MB per site. They can be deleted by hand.
- Both sites' `.env` points the database at the server's public address
  instead of `127.0.0.1`. Port 3306 is closed from outside, so it works, but
  loopback would be the safer setting.
- Staging runs with `CI_ENVIRONMENT = production` like production, so its
  errors are as terse as production's; use the logs in `writable/logs/`.
- The database backups live on the same disk as the data (`~/backups`, 10
  kept). Copy them off the server for real protection (`backup.md`).
