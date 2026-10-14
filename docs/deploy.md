# Deploying (staging and production)

Merging a pull request into `main` runs `.github/workflows/deploy.yml`, which
first runs the full CI workflow on that commit and deploys to the **staging**
environment only if it passes (you can also start it by hand from the Actions
tab; CI still runs first). **Production** is deployed by pushing a version tag
(`v1.2.3`) with `.github/workflows/deploy-production.yml`: it checks the tag is
on `main`, re-runs CI, then waits for an approval before it touches the server.
Both use the same steps (`deploy-reusable.yml`) against a GitHub Environment of
their own; how to create the environments, their secrets and the approval rule
is in `github-environments.md`. This page explains what a deploy does, what it
needs, and what to do when it fails. No secrets belong in this file. The server
described below is the staging server.

## Where it goes

| | |
|---|---|
| Server | Hostinger VPS, Ubuntu 24.04, managed with CloudPanel; other sites share it |
| Site user | `mentora` (no sudo) |
| Live folder | `/home/mentora/htdocs/mentora` (nginx serves its `public/` folder) |
| PHP | The site runs on PHP **8.5** (`php-fpm8.5`). The server's plain `php` is 8.4, so server-side commands use `php8.5` |
| Database | MySQL 8.4 (Percona); credentials live only in the server's `.env` |
| URL | https://mentora.srv1404227.hstgr.cloud |

## What a deploy does, in order

0. **CI** runs first (the `ci` job calls `ci.yml`). If it fails, nothing below runs. For production, the tag is checked first and an approval is awaited after CI.
0b. **Check the environment is configured**: the secrets and variables below must exist for this environment; otherwise it stops here and names what is missing.
1. **Build** on the GitHub runner: Composer (production only, PHP 8.5), `npm ci`, `npm run build`.
2. **Check the target**: stops unless the live folder has `.env` and `writable/`, and `php8.5` exists. A wrong path fails here instead of silently deploying to a dead folder.
3. **Back up the database** to `~/backups/deploy-<timestamp>.sql.gz` on the server. The deploy stops if the dump did not finish. The 10 newest backups are kept.
4. **Copy** the app with `rsync --delete`. Excludes are anchored to the project root (`/tests`, not any folder named tests). Never touched: `.env`, `writable/`, `node_modules/`. Never copied: tests, docs, `.github`, `.claude`, the Python question-builder service, and other development files.
5. **Fix `writable/` permissions.**
6. **Run `php8.5 spark migrate --all`** so the database matches the code.
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

Staging falls back to its original values when a variable is missing; production
does not, so a missing value stops the deploy with a clear message.

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

AI question generation runs as a small separate process, `mentora-qb-worker`, on the same server. It opens no port: it asks Mentora for queued jobs over loopback, calls the hosted model (Groq's free tier by default) and reports back. Mentora's own `.env` never holds the provider key.

One-time setup, by someone with root (the files are in `python-question-builder-service/deploy/`):

1. Copy the service code to `QB_PATH` (default suggestion `/opt/mentora-qb`) and create its virtual environment: `python3 -m venv venv && venv/bin/pip install -r requirements.txt`. Install Tesseract if scanned PDFs are wanted.
2. Create `/etc/mentora/qb-worker.env` (mode 600) from `qb-worker.env.example`: `QB_WORKER_SECRET` (the same value as `questionBuilder.workerSecret` below), `GROQ_API_KEY`, and `QB_BACKEND=groq`. Use a different Groq key or organisation for staging.
3. Install and start the unit: `cp mentora-qb-worker.service /etc/systemd/system/ && systemctl daemon-reload && systemctl enable --now mentora-qb-worker`.
4. In Mentora's `.env` set `questionBuilder.workerSecret` (same secret) and, when ready to switch generation to background jobs, `questionBuilder.useJobs = true`. Allow the deploy user to restart the unit without a password: `deploy ALL=(root) NOPASSWD: /bin/systemctl restart mentora-qb-worker`.
5. Set the repository variable `QB_PATH` for the environment so each deploy updates the code and restarts the unit. Without it the deploy leaves the worker alone.

Checking it: the system administrator's monitoring page shows "question-builder worker last seen N s ago". `journalctl -u mentora-qb-worker -f` shows its log (it never logs the key or a source text).

Rolling back: set `questionBuilder.useJobs = false` and generation goes back to the original in-request service. Stopping the worker only stops new jobs; queued jobs wait and a job that was running is put back at most twice.

## When a deploy fails

| Failing step | Meaning | What to do |
|---|---|---|
| Check this environment is configured | A secret or variable is missing for this environment (the message names it) | Add it under Settings > Environments > *name*. Nothing was changed. |
| Check the deploy folder | The path is wrong or `.env`/`writable` is missing | Fix the environment's `DEPLOY_PATH` variable, or investigate the server. Nothing was changed. |
| Back up the database | The dump failed or was cut short | Nothing was deployed. Check disk space and the DB credentials in the server `.env`. |
| Deploy application / permissions | File copy problem | Re-run the workflow. Safe to repeat. |
| Run database migrations | A migration errored | The new code is already on the server. Read the error, fix the migration in a new PR, or restore the backup (below). Do not re-run blindly. |
| Check the site is healthy | The site does not answer ok | Open the site and the logs (`writable/logs/`, `~/logs/php/error.log`). The backup from this deploy is on the server. |
| Smoke test the site | The site is up and reaches its database, but something a visitor needs is broken. The FAIL lines say what: a login page that errors or shows the debug toolbar (wrong `CI_ENVIRONMENT`), no built assets or a 404 on them (the `public/build` folder did not deploy, or `app.baseURL` is wrong), or a protected page that no longer sends a visitor to login (**a security problem: treat it as urgent**) | The new code is already live. Fix forward in a new PR, or revert the merged PR (see "Rolling back code"). |

## The first system administrator

A new server has no accounts. Nobody can sign up as a system administrator;
create the first one on the server with spark, using a **real email address you
can open** (the sign-in code is emailed there):

```bash
cd /home/mentora/htdocs/mentora             # your DEPLOY_PATH
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

Use only if a migration or deploy damaged the data. Run as `mentora` on the
server, with the backup file you want:

```bash
cd /home/mentora/htdocs/mentora
get() { grep -E "^$1[[:space:]]*=" .env | head -1 | sed 's/^[^=]*=[[:space:]]*//' | tr -d "'\""; }
gunzip -c ~/backups/deploy-YYYYMMDD-HHMMSS.sql.gz | MYSQL_PWD="$(get database.default.password)" \
  mysql -h "$(get database.default.hostname)" -u "$(get database.default.username)" "$(get database.default.database)"
```

This replaces the tables in the backup with their saved copies. Restoring puts
the data back to that moment; anything entered since is lost.

## Rolling back code

There is no automatic rollback yet. To undo a bad release, revert the pull
request on GitHub (a "Revert" button on the merged PR) and merge the revert into
`main`; that deploys the previous code to staging. For production, once the
revert has been checked on staging, push a new tag (for example `v1.0.1`). If the bad release also ran a
migration, decide separately whether to restore the backup.

## Known limits (planned improvements)

- Files are copied straight into the live folder, so for a few seconds the new
  code runs before the migrations finish. Release folders with an instant
  switch and automatic rollback are the next step.
- Files excluded from the copy that already exist on the server (for example an
  old `python-question-builder-service/` folder) are left in place; they can be
  deleted by hand.
- The AI question generator is not part of this deploy. Without a reachable
  service the app reports it as unavailable.
