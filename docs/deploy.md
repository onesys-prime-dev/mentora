# Deploying to staging

Merging a pull request into `staging` runs `.github/workflows/deploy.yml`. This
page explains what it does, what it needs, and what to do when it fails. No
secrets belong in this file.

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

1. **Build** on the GitHub runner: Composer (production only, PHP 8.5), `npm ci`, `npm run build`.
2. **Check the target**: stops unless the live folder has `.env` and `writable/`, and `php8.5` exists. A wrong path fails here instead of silently deploying to a dead folder.
3. **Back up the database** to `~/backups/deploy-<timestamp>.sql.gz` on the server. The deploy stops if the dump did not finish. The 10 newest backups are kept.
4. **Copy** the app with `rsync --delete`. Excludes are anchored to the project root (`/tests`, not any folder named tests). Never touched: `.env`, `writable/`, `node_modules/`. Never copied: tests, docs, `.github`, `.claude`, the Python question-builder service, and other development files.
5. **Fix `writable/` permissions.**
6. **Run `php8.5 spark migrate --all`** so the database matches the code.
7. **Health check**: `GET /health` must answer `{"status":"ok"}` (it only does when the app can reach its database). Retried for about a minute, then the deploy fails. The check skips certificate verification (`curl -k`) because staging uses a self-signed certificate; remove that once the site has a real one.

## Secrets and settings it needs

Repository secrets (Settings > Secrets and variables > Actions):

| Secret | What |
|---|---|
| `VPS_PRIVATE_KEY` | SSH private key whose public half is in the server user's `authorized_keys` |
| `VPS_USERNAME` | `mentora` |
| `VPS_HOST` | the server's host name or IP |
| `VPS_KNOWN_HOSTS` | *(recommended)* the server's ssh-ed25519 host key, so the runner verifies it is talking to the real server. Only the `ssh-ed25519 AAAA...` part is used and it is paired with `VPS_HOST`, so it does not matter whether the scan used the IP or the host name |

To create `VPS_KNOWN_HOSTS`, run this on your own computer and copy the output
line(s) into the secret:

```bash
ssh-keyscan -p 22 -t ed25519 <the same host as VPS_HOST>
```

Check once that the fingerprint matches the server's real key (on the server:
`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`, and locally
`ssh-keyscan -t ed25519 <host> | ssh-keygen -lf -`). Until the secret exists the
deploy prints a warning and accepts the key on first contact.

## When a deploy fails

| Failing step | Meaning | What to do |
|---|---|---|
| Check the deploy folder | The path is wrong or `.env`/`writable` is missing | Fix `DEPLOY_PATH` in `deploy.yml`, or investigate the server. Nothing was changed. |
| Back up the database | The dump failed or was cut short | Nothing was deployed. Check disk space and the DB credentials in the server `.env`. |
| Deploy application / permissions | File copy problem | Re-run the workflow. Safe to repeat. |
| Run database migrations | A migration errored | The new code is already on the server. Read the error, fix the migration in a new PR, or restore the backup (below). Do not re-run blindly. |
| Check the site is healthy | The site does not answer ok | Open the site and the logs (`writable/logs/`, `~/logs/php/error.log`). The backup from this deploy is on the server. |

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
`staging`; that deploys the previous code. If the bad release also ran a
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
