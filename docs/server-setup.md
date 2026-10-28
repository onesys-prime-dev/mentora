# Setting up a server environment for the first time

This is the one-time setup of a Mentora environment on the VPS, written from
how staging and production were actually built and inspected on 2026-10-20.
Do it once per environment, then everything after that is the deploy workflow
(`deploy.md`). Nothing secret belongs in this file: where a step needs a
password, key or secret, it says where to put it and how to make it.

Staging and production are the same shape and share one VPS, so each step
gives both values side by side. Replace `<env>` with `staging` or `production`.

| | Staging | Production |
|---|---|---|
| Site user | `mentora-staging` | `mentora-production` |
| Domain | `mentora.staging.srv1404227.hstgr.cloud` | `mentora.onesyssolutions.com` |
| Site folder | `/home/mentora-staging/htdocs/<domain>` | `/home/mentora-production/htdocs/<domain>` |
| PHP-FPM pool port | 20007 | 20006 |
| Database | `mentora-staging-db`, user `mentora-staging` | `mentora-production-db`, user `mentora-production` |
| Worker folder | `/opt/mentora-qb-staging` | `/opt/mentora-qb-production` |
| Worker loopback port | 18081 | 18080 |
| Certificate | self-signed (`TLS_INSECURE=true`) | real (`TLS_INSECURE=false`) |

What is on the VPS (Ubuntu 24.04): CloudPanel with nginx, PHP 8.5 FPM (the
plain `php` is 8.4: always run `php8.5`), MySQL 8.4 (Percona), Python 3.12.
Other sites share the machine. The firewall (`ufw`) allows 22, 80, 443 and
CloudPanel's 8433-8443; MySQL listens on every interface but 3306 is not
allowed through, which must stay that way.

Who does what: steps marked **root** need the VPS root account (or CloudPanel's
admin); steps marked **site user** run as `mentora-<env>`; steps marked
**GitHub** are in the repository settings. The deploy itself never needs root.

## 1. The site (root, in CloudPanel)

1. Add a **PHP site** for the domain (PHP 8.5), with its own **site user**
   named `mentora-<env>`. CloudPanel creates `/home/mentora-<env>/htdocs/<domain>`,
   the PHP-FPM pool (`/etc/php/8.5/fpm/pool.d/<domain>.conf`, `user =
   mentora-<env>`) and the nginx vhost.
2. Set the site's **root directory to `public`** (the folder inside the app,
   not the app itself). Only `public/` may ever be served: `.env`, `app/`,
   `vendor/`, `writable/` and `.git` must answer 404 from the web. Check after
   the first deploy:
   ```bash
   for p in .env .git/config composer.json writable/logs/; do
     curl -sk -o /dev/null -w "$p %{http_code}\n" https://<domain>/$p; done   # all 404
   ```
3. Issue the site's certificate in CloudPanel. Production needs a real one
   (Let's Encrypt). Staging currently runs on a self-signed one, so its
   `TLS_INSECURE` is `true`; switch it to `false` the day it has a real one.
4. PHP settings (site's PHP tab) at least: `memory_limit` 256M or more,
   `upload_max_filesize`/`post_max_size` to the size of the largest course file
   you accept, and keep `opcache.validate_timestamps=1` (the default): the
   deploy relies on PHP noticing new files within 2 seconds and never restarts
   FPM.

## 2. The database (root, in CloudPanel or MySQL)

Create one database and one user per environment, never shared:

```sql
CREATE DATABASE `mentora-<env>-db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mentora-<env>'@'%' IDENTIFIED BY '<a long random password>';   -- CloudPanel's Databases page does the same
GRANT ALL ON `mentora-<env>-db`.* TO 'mentora-<env>'@'%';
```

The user needs `ALL` on its own database only (migrations create and alter
tables). It must not see the other environment's database. The deploy's
backup also needs `SELECT`, `LOCK TABLES`, `SHOW VIEW`, `TRIGGER`, `EVENT`
(included in `ALL`).

Both sites currently connect to the server's public address
(`database.default.hostname = 76.13.198.113`) rather than `127.0.0.1`. It
works because the firewall blocks 3306, but `127.0.0.1` is the better value for
a new server; use it if the user is created for `'localhost'`.

## 3. The site's `.env` (site user)

`.env` is never deployed and never overwritten: it lives only on the server,
mode 660, owned by the site user. Create it once at
`<site folder>/.env` from the repository's `env` template. Set at least:

```ini
CI_ENVIRONMENT = production            # both staging and production: no debug toolbar, terse errors

app.baseURL = "https://<domain>/"      # real address, with https

database.default.hostname = 127.0.0.1
database.default.database = mentora-<env>-db
database.default.username = mentora-<env>
database.default.password = <the password from step 2>
database.default.DBDriver = MySQLi
database.default.port = 3306

encryption.key = <php8.5 spark key:generate --show>   # needed to seal SIS credentials (sis-integration.md). Neither current server has it yet.

email.protocol = smtp                  # see mail.md; prove it with: php8.5 spark mail:test you@yourdomain
email.SMTPHost = ...                   # SMTPPort, SMTPCrypto, SMTPUser, SMTPPass, fromEmail, fromName

questionBuilder.workerSecret = <openssl rand -hex 32>   # section 7; a different value per environment
questionBuilder.useJobs = true
```

`CI_ENVIRONMENT` must be `production` on both: the deploy's smoke test fails
a login page that shows the debug toolbar. `questionBuilder.serviceUrl` in the
current servers' `.env` points at an old HTTP service on port 8008 that does
not exist; leave it out of a new server.

## 4. Let the deploy in (site user and GitHub)

1. **A deploy-only SSH key pair per environment**, made on your own computer:
   `ssh-keygen -t ed25519 -f mentora-<env>-deploy -C "mentora <env> deploy"`.
   Never reuse a personal key, and use a different pair for staging and
   production so one can be revoked alone.
2. **Site user**: put the public half (`.pub`) in
   `/home/mentora-<env>/.ssh/authorized_keys` (directory mode 700, file 600).
   Each server user currently has exactly one key.
3. **GitHub** > Settings > Environments > `<env>`: add the secrets
   `VPS_PRIVATE_KEY` (the private half), `VPS_USERNAME` (`mentora-<env>`),
   `VPS_HOST` and `VPS_KNOWN_HOSTS`, and the variables `DEPLOY_PATH`,
   `SITE_URL`, `TLS_INSECURE` and `QB_PATH` (section 7). Full table and the
   production approval rule: `github-environments.md`, `deploy.md`.
4. `~/backups/` is created by the first deploy (the pre-deploy database dumps,
   10 kept). Nothing else to prepare.

SSH currently allows password login and root login (`PasswordAuthentication
yes`, `PermitRootLogin yes`). Once the keys above work, disabling both in
`/etc/ssh/sshd_config` (and `sshd_config.d/50-cloud-init.conf`) and reloading
`ssh` is the recommended hardening; keep a root session open while testing.

## 5. The first deploy and the first administrator

Run the deploy once (staging: merge to `main` or press *Run workflow*;
production: push a `vX.Y.Z` tag). It copies the code, runs every migration on
the empty database, and checks `/health` and the smoke test. Then create the
first system administrator by hand, with a real mailbox (`deploy.md`, "The
first system administrator"):

```bash
cd <site folder>
php8.5 spark mail:test you@yourdomain.com
php8.5 spark admin:create you@yourdomain.com yourname
```

Never run the demo seeders on a real server.

## 6. Scheduled jobs, the scheduler check, and the off-server backup copy

### The jobs (installed by the deploy)

The deploy installs the seven scheduled jobs itself (audit D5): it writes the
block between `# BEGIN mentora jobs` and `# END mentora jobs` in the site
user's crontab from `deploy/crontab.txt`, with the site's folder and
`PHP_BIN`, leaving every other line alone, and fails if afterwards `crontab -l`
is missing one. The block it writes looks like this:

```cron
# BEGIN mentora jobs
*/5 * * * *  cd '<site folder>' && php8.5 spark backup:run >> writable/logs/cron-backup.log 2>&1
15 2 * * *   cd '<site folder>' && php8.5 spark subscriptions:sync >> writable/logs/cron-sync.log 2>&1
30 2 * * *   cd '<site folder>' && php8.5 spark institutions:purge-expired >> writable/logs/cron-purge.log 2>&1
*/15 * * * * cd '<site folder>' && php8.5 spark parents:notify >> writable/logs/cron-parents.log 2>&1
0 3 * * *    cd '<site folder>' && php8.5 spark sis:sync >> writable/logs/cron-sis.log 2>&1
30 3 * * *   cd '<site folder>' && php8.5 spark sis:grades >> writable/logs/cron-sis.log 2>&1
* * * * *    cd '<site folder>' && php8.5 spark exports:run >> writable/logs/cron-exports.log 2>&1
# END mentora jobs
```

All seven run on every server (6.4); the SIS jobs do nothing where no
institution has SIS enabled. To change a schedule, edit `deploy/crontab.txt`
and deploy: a hand edit inside the block is overwritten by the next deploy.
To install it by hand (as the site user, from the site folder):

```bash
(crontab -l 2>/dev/null || true) | php8.5 spark jobs:crontab --no-header --site "$PWD" --php php8.5 > /tmp/next-crontab
crontab /tmp/next-crontab && crontab -l | php8.5 spark jobs:crontab --no-header --verify
```

**Check once on staging:** that CloudPanel's **Cron Jobs** screen does not
rewrite the crontab and drop lines it did not create (the repository can't tell).
After a deploy, open the screen, save something unrelated, and run `crontab -l`:
the block must still be there. If CloudPanel drops it, the jobs must be entered
through CloudPanel instead, and the deploy step changed to only verify.

### The scheduler check (alerts when a job stops)

Set `scheduler.checkSecret` in `.env` to a long random value, and put the same
value in the repository's Actions secrets as `SCHEDULER_SECRET_STAGING` or
`SCHEDULER_SECRET_PRODUCTION`. The hourly uptime workflow then asks the site
whether the jobs have reported, and the site emails every system administrator
once when one stops and once when it is back (`uptime.md`, `monitoring.md`).

### Off-server backup copy (required before the first real institution)

The app's backups live on the same server as the app. Before the first real
institution goes on production, each good backup must also be copied somewhere
else (audit 11.4, U3). Production only: staging has no off-server copy.

What is required (U3b, U3c):

- **A different provider than Hostinger, under a separate account**, so one
  provider's or account's trouble can't take both copies.
- **Encrypted before it leaves the server.**
- **Append-only from the server:** the server's key can add copies but can't
  delete or overwrite them, so a compromised server can't destroy the history.
- **Kept 30 days**, by the storage's own lifecycle rule.
- **Both the database dumps and the uploaded files** (`writable/uploads`), which
  are not in the dump.

The recommended setup is [rclone](https://rclone.org/) (no root needed), with an
S3-compatible bucket at another provider (for example Backblaze B2 or
Cloudflare R2):

1. In the provider's console: a bucket with **object lock or versioning** and a
   **30-day lifecycle rule**, and an **application key limited to that bucket
   that can write but not delete** (B2: `writeFiles` and `listFiles` only).
2. As the site user, `rclone config`: a remote `offsite` for the bucket with that
   key, then a `crypt` remote `offsite-crypt` on top of it (`offsite:mentora-prod`),
   with a generated password and salt. **Keep both in the password manager**:
   without them the copies can't be read.
3. A script, for example `~/bin/mentora-offsite`, executable by the site user:

   ```bash
   #!/usr/bin/env bash
   # $1 = the dump file, $2 = the uploads folder. Exit 0 only when the storage holds both.
   set -euo pipefail
   stamp=$(date +%Y%m%d)
   rclone copy "$1" offsite-crypt:dumps/ --immutable
   rclone check "$1" offsite-crypt:dumps/ --one-way --include "$(basename "$1")"
   rclone copy "$2" "offsite-crypt:uploads/$stamp/" --immutable
   rclone check "$2" "offsite-crypt:uploads/$stamp/" --one-way
   ```

4. In production's `.env`: `backup.offsiteCommand = /home/<site user>/bin/mentora-offsite`.
5. Take a backup from `/admin/backups` and check its **Off-site copy** reads
   **Copied**. A failed copy is shown there and emailed to the system
   administrators.

**Go-live check (U3d), done by the repository owner, once:** fetch one copy back
to another machine (`rclone copy offsite-crypt:dumps/<file> .` and a sample of
`offsite-crypt:uploads/<date>/`), then open both: `gunzip -t` the dump and load
it into a scratch database, and open a few of the files. It counts as done only
then. Record the date here:

- Production go-live check: _not done yet_

## 7. The question-builder worker (root for the install, then the deploy keeps it current)

Concept and daily operation: `deploy.md`, "The question-builder worker". The
first-time install, per environment, as root:

1. **Loopback listener.** The worker calls its own site over plain HTTP on the
   machine itself, through a tiny nginx server that forwards to CloudPanel's
   backend and sets the site's host name. Create
   `/etc/nginx/sites-enabled/mentora-qb-<env>-local.conf` (production shown;
   staging uses port 18081 and its own domain):
   ```nginx
   server {
       listen 127.0.0.1:18080;          # 18081 for staging
       server_name localhost;
       access_log off;
       location / {
           proxy_pass http://127.0.0.1:8080;
           proxy_http_version 1.1;
           proxy_set_header Host mentora.onesyssolutions.com;      # the site's domain
           proxy_set_header X-Real-IP 127.0.0.1;
           proxy_set_header X-Forwarded-For 127.0.0.1;
           proxy_set_header X-Forwarded-Host mentora.onesyssolutions.com;
           proxy_set_header X-Forwarded-Proto https;
           proxy_connect_timeout 10s;
           proxy_read_timeout 120s;
       }
   }
   ```
   `nginx -t && systemctl reload nginx`. Check: `curl -s http://127.0.0.1:18080/health`
   returns `{"status":"ok",...}`. It listens on 127.0.0.1 only, so it is not
   reachable from outside; the worker endpoints also refuse any non-loopback
   caller and need the secret.
2. **Code and libraries.**
   ```bash
   install -d -o mentora-<env> -g mentora-<env> /opt/mentora-qb-<env>
   su - mentora-<env> -c 'python3 -m venv /opt/mentora-qb-<env>/venv'
   ```
   The first deploy with `QB_PATH` set copies the code and runs
   `pip install -r requirements.txt`. Install `tesseract-ocr` if scanned PDFs
   are wanted. The folder is owned by the **site user** because the deploy
   writes it; secrets never go in it.
3. **Secrets file, owned by root.** Install the template
   `python-question-builder-service/deploy/qb-worker.env.example` as
   `/etc/mentora/qb-worker-<env>.env`:
   ```bash
   install -d -m 755 /etc/mentora
   install -m 600 -o root -g root qb-worker.env.example /etc/mentora/qb-worker-<env>.env
   ```
   Then edit it: `QB_PHP_URL` (the loopback address above),
   `QB_WORKER_SECRET` (the same value as the site's
   `questionBuilder.workerSecret`), `QB_WORKER_ID` (`<env>-1`),
   `QB_BACKEND=groq`, `GROQ_API_KEY`. **Root owns it, mode 600, in both
   environments**: systemd reads it as root before dropping to the site user,
   so the website's PHP, running as that same user, can never read the Groq
   key. Use a **different Groq key** (ideally a different Groq organisation)
   and a **different worker secret** for staging and production.
4. **Unit.**
   ```bash
   sed 's/<env>/production/g' mentora-qb-worker.service > /etc/systemd/system/mentora-qb-worker-production.service
   systemd-analyze verify /etc/systemd/system/mentora-qb-worker-production.service
   systemctl daemon-reload && systemctl enable --now mentora-qb-worker-production
   ```
5. **Checks.** `systemctl is-active mentora-qb-worker-<env>`;
   `journalctl -u mentora-qb-worker-<env> -n 20` shows "Worker <env>-1
   starting"; within a minute the monitoring page's worker row reads up. The
   deploy users have no `sudo` for systemctl; the deploy restarts the worker by
   signalling it (`deploy.md`), so no sudoers rule is required.
6. **GitHub** variable `QB_PATH` = `/opt/mentora-qb-<env>` (and `QB_SERVICE`
   only if the unit has a different name than `mentora-qb-worker-<env>`).

## 8. Acceptance checklist

Tick every line before calling an environment ready. The commands that read
secrets print hashes only.

- [ ] `https://<domain>/health` answers `{"status":"ok","database":"up"}`; the deploy's smoke test passes.
- [ ] `.env`, `.git/config`, `composer.json`, `writable/` answer 404 from the web.
- [ ] Port 3306 is closed from outside (`bash -c '</dev/tcp/<server ip>/3306'` fails from your computer).
- [ ] The first system administrator signs in and receives the emailed code.
- [ ] Backups: `php8.5 spark backup:run` works and the monitoring Backups row is up.
- [ ] Scheduled jobs are in the site user's crontab; "Scheduled jobs" reads up after the first night.
- [ ] Staging, once: CloudPanel's Cron Jobs screen keeps the mentora block after an unrelated save (section 6).
- [ ] `scheduler.checkSecret` is set, and the latest uptime run's **Check the scheduled jobs** log shows the site's answer, not "No scheduler secret" or a curl error: the step stays green either way, and the secret in GitHub can't be read back (section 6).
- [ ] Production: a backup's **Off-site copy** reads **Copied** on `/admin/backups` (section 6).
- [ ] Production, once: fetched back to another machine, the dump passes `gunzip -t` and loads into a scratch database, and a sample of the uploads opens; the date is recorded in section 6 (go-live check).
- [ ] Before go-live, once: the 2,000,000-submission export finished under `memory_limit=128M`, time recorded (`institution-data-export.md`, Scale).
- [ ] Worker: unit active, secrets file `root:root 600`, the worker's secret hash equals the site `.env`'s, and the Groq key hash differs from the other environment's (`deploy.md`, "Reviewing a server against this").
- [ ] A test question generation completes on staging before production is touched.
- [ ] Production's `TLS_INSECURE` is `false` and a deploy with a real certificate passes.
- [ ] SSH password and root logins are disabled once the keys work.
