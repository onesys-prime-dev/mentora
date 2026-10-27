# Audit Plan 6 — Scheduled Jobs Report In; Backups Are Proven; An Off-Server Copy; The Export Runs in the Background Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nothing the platform depends on can fail silently.
- **Scheduled jobs (6.2–6.4, U2, D5, D11):** every scheduled job reports in, a stalled one warns the system administrators and emails them once when it stops and once when it recovers, and the deploy installs the jobs itself.
- **Backups (11.1–11.3):** a backup counts only once it is proven complete.
- **Off-server copy (U3):** each good backup can be copied off the server.
- **Export (12):** the institution data export is prepared in the background at the target scale.

**Architecture:**
- **Heartbeats:** one registry, `ScheduledJobs`, names the seven jobs, their heartbeat setting and their allowed gap. Every command records its heartbeat; `SystemHealth` and a new `POST internal/scheduler-check` endpoint (called hourly by `uptime.yml`) read them.
- **Cron install:** `CrontabBlock` renders and merges the marked crontab block, which a deploy step installs and verifies.
- **Backups:** `BackupService` proves each dump by reading it back. It keeps only proven-good files, alerts at once on failure, and then runs the configured off-site command.
- **Export:** it becomes a queue. A request is one open row per institution (a unique marker), and `exports:run` builds it by streaming keyset pages into the ZIP.

**Tech Stack:** CodeIgniter 4.7 + Shield, MySQL, PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`, `PeopleFactory`, `CourseFactory`, `FakeEmail`), GitHub Actions (`deploy-reusable.yml`, `uptime.yml`), bash on the server.

**Spec:** `docs/superpowers/specs/2026-10-23-audit-fixes-design.md` § Findings 6, 11, 12 and § 4 (6.3 → D5, six jobs → seven). Decisions: `docs/audit-2026-10-23-decisions.md` 6.2–6.4, 11.1–11.4, 12.1–12.4, U2, U3, D5, D11. 6.1 was done in plan 2 (`EffectiveStatus`). 6.5 was dropped.

## Global Constraints

- **Branch and commits:** branch `fix/jobs-backups`, created from `fix/course-role` at cc48ddb. No commits by Claude: each commit step stages files and states the message.
- **Line endings:** CRLF files (`app/Config/Routes.php`, several services and tests) are edited with the Edit tool, or with a Python script written to a file with the Write tool (shell heredocs collapse backslashes). Never `sed -i` on them.
- **The seven jobs and their allowed gaps (6.2, 6.4, spec):** the allowed gap is how long a job may go without reporting before it counts as stale.

  | Command | Heartbeat setting | Allowed gap | Counts only with SIS |
  |---|---|---|---|
  | `backup:run` | `Monitoring.backupRunAt` | 15 min | no |
  | `parents:notify` | `Monitoring.parentsNotifyAt` | 45 min | no |
  | `exports:run` | `Monitoring.exportsRunAt` | 15 min | no |
  | `subscriptions:sync` | `Monitoring.subscriptionSyncAt` (exists) | 26 h | no |
  | `institutions:purge-expired` | `Monitoring.purgeExpiredAt` | 26 h | no |
  | `sis:sync` | `Monitoring.sisSyncAt` | 26 h | yes |
  | `sis:grades` | `Monitoring.sisGradesAt` | 26 h | yes |

  - **When a heartbeat is written:** when the command ends without an uncaught exception, including a run with nothing to do. A run with failed items (a failed backup) still reports; that failure has its own alert.
  - **Never reported:** a job with no heartbeat counts as stale.
  - **SIS jobs:** they count only while some institution has an enabled SIS connection.
- **The missed-job alert (U2, D11):**
  - **Who:** every system administrator (`NotificationRecipients::administrators()`).
  - **How:** urgent, so it is emailed too.
  - **When:**
    - "stopped": once when a job turns stale, with the dedupe key `jobs:stale:<command>:<last heartbeat or never>`;
    - "running again": once when it recovers, with the key `jobs:recovered:<command>:<new heartbeat>`, sent only if a "stopped" alert went out for that stale period.

    The stale period is remembered in the setting `Monitoring.staleSince.<command>`.
  - **Copy, "stopped":** title "Scheduled job stopped: {command}", body "{command} has not reported since {Y-m-d H:i, or 'it was installed'}. Check the server's crontab (docs/server-setup.md, section 6)."
  - **Copy, "running again":** title "Scheduled job running again: {command}", body "{command} reported again at {Y-m-d H:i}."
- **The scheduler-check endpoint (U2):** `POST internal/scheduler-check`.
  - **Guard:** the header `X-Scheduler-Secret` must equal `scheduler.checkSecret` in `.env`. If the secret isn't configured the endpoint answers 503 `{"error":"The scheduler check is not configured."}`; a wrong secret gets 401. It is not loopback-only, because GitHub calls it.
  - **CSRF:** the route is exempt (it is added to `csrf`'s `except`).
  - **Answer:** 200 `{"stale": [commands], "alerted": n}`.
- **The crontab block (D5):**
  - **Markers:** it lives between `# BEGIN mentora jobs` and `# END mentora jobs`.
  - **Install:** it replaces only that block, adding the block if there is none, and leaves every other line untouched.
  - **Contents:** the template is `deploy/crontab.txt`, with `{site}` and `{php}` placeholders and one line per job: the six lines of `docs/server-setup.md` section 6 plus `* * * * * cd {site} && {php} spark exports:run >> writable/logs/cron-exports.log 2>&1`.
  - **Check:** the deploy fails if, after installing, `crontab -l` lacks any of the seven commands.
- **A good backup (11.1):** every check must pass, or the backup counts as failed:
  - mysqldump exited 0;
  - every `gzwrite` wrote its bytes, and `gzclose` returned true;
  - the file opens again with `gzopen`, reads to the end, and its last non-empty line starts with `-- Dump completed`.

  **On failure:**
  - the file is deleted and the row is `failed` with the reason;
  - all system administrators get an urgent notification at once. The title is "Backups need attention", the body is the reason, and the dedupe key is `backup:failed:<finished_at>`. That is the same key `NotificationTriggers::backups` uses, so the nightly run never repeats it.
- **Rotation (11.2):** the newest 14 `succeeded` backups keep their files. Files of older succeeded rows, and stray `mentora-*.sql.gz` files that belong to no succeeded or running row, are deleted. Pruning runs only after a good backup.
- **The off-site copy (U3):**
  - **Setting:** `backup.offsiteCommand` in `.env`. It is unset on staging (U3a); unset means "not copied".
  - **When:** after a good backup, the command runs with two arguments: the dump's path and the uploads folder (`WRITEPATH . 'uploads'`).
  - **Result:** exit 0 is "copied". Anything else is "failed": all system administrators get an urgent notification titled "Off-site backup copy failed", whose body is the first 300 characters of the output (the database password masked), with the dedupe key `backup:offsite:<backup id>`.
  - **Recording:** the result goes in new columns on `backups`: `offsite_status` (`copied` | `failed` | null) and `offsite_message`.
  - **Docs:** the tool, the other provider, encryption, append-only keys and 30-day retention are set up on the server (U3b, U3c), documented in `docs/server-setup.md` with the go-live check (U3d).
- **The export (12.2–12.4):**
  - **Statuses:** `queued` → `running` → `ready` | `failed`. The table gains `started_at` and `open_marker` (1 while queued or running, null otherwise), plus the unique key `institution_exports_one_open (institution_id, open_marker)`.
  - **A second request** while one is open is refused, with "An export is already being prepared. You'll be told when it's ready." (12.3).
  - **Requesting:** "Your export is being prepared. We'll tell you here and by email when it's ready."
  - **A stalled run:** a `running` export with `started_at` more than 30 minutes ago becomes `failed`, "The export stopped before it finished. Ask for a new one."
  - **Notifying:** only the requester, urgent, with the link `institution/export`:
    - ready: "Your data export is ready", "The export of {institution} is ready to download for 7 days.";
    - failed: "Your data export failed", "The export of {institution} could not be made. Ask for a new one, or contact support.".

    Other administrators see the status on the export page (12.4).
  - **When it's allowed:** read-only and past institutions, as today.
- **Export scale (12.1):** no table is ever read whole. Each CSV is filled from keyset pages of 5,000 rows on its driving table's id, written to a temp file, then added to the ZIP. CSV rows are ordered by that id, not by course code (ruling, recorded in the README).
- **Known local failures:** the six `AiWorkerEndpointsTest` failures are known. The full suite runs about 25 minutes, so run `vendor/bin/phpunit --no-coverage` in the background, and never alongside Playwright.

## Review Focus

- **A job that never ran:** a fresh server with no heartbeat at all shows every job stale on the admin home and alerts once, not every hour.
- **Recovery is told once:**
  - a job stale for three hourly checks gets one "stopped" alert;
  - when it reports again, it gets one "running again";
  - a second stall later is a new period, with a new alert.
- **A backup that dies part-way:** a truncated gzip, or a dump without its closing line, is failed and deleted, alerts at once, and never pushes a good backup out of the 14.
- **The off-site command fails or hangs:** the backup itself stays `succeeded` and only the copy is `failed`. The command's output never shows the database password.
- **Two admins ask for an export at once:** one export is queued and the other admin is told one is being prepared. The database refuses the second, not only the code.

---

### Task 1: Every scheduled job reports in, and each stale one shows (6.2, 6.4)

**Files:**
- Create: `app/Services/Monitoring/ScheduledJobs.php`, with:
  - `const JOBS` (the table in Global Constraints);
  - `static function heartbeat(string $command, ?int $now = null): void`;
  - `static function lastRun(string $command): ?int`;
  - `static function stale(int $now): list<string>` (commands, with SIS-only jobs left out when no enabled `sis_connections` row exists);
  - `static function status(string $command, int $now): array{status: string, detail: string}`.
- Modify: each of the six commands in `app/Commands/` calls `ScheduledJobs::heartbeat(<its command>)` just before it returns. `SyncSubscriptions.php:45` switches to it.
- Modify: `app/Services/Monitoring/SystemHealth.php:242-247`. `jobs()` returns `DEGRADED` with the detail "Not reporting: {comma-separated commands}." when any are stale, otherwise `UP` with "All seven scheduled jobs reported on time.". `jobStatus()` and `JOB_LATE` / `JOB_DEAD` are removed, with their tests rewritten to `ScheduledJobs::status`.
- Test: `tests/database/ScheduledJobsTest.php`, `tests/feature/AdminHomeJobsTest.php`

- [ ] **Step 1: Write the failing tests**
  - `testAJobWithinItsGapIsNotStale` (`backup:run` 10 min ago).
  - `testAJobPastItsGapIsStale` (`backup:run` 20 min ago; `subscriptions:sync` 27 h ago).
  - `testAJobThatNeverReportedIsStale`.
  - `testTheSisJobsCountOnlyWhenSisIsEnabled`.
  - `testEachCommandRecordsItsHeartbeat`: run each command via `command('<name>')` with nothing to do, then assert `lastRun` is set.
  - `testTheAdminHomeNamesEachStaleJob`: the home page warning contains "Not reporting: backup:run, parents:notify".

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/ScheduledJobsTest.php tests/feature/AdminHomeJobsTest.php`
Expected: FAIL, because `ScheduledJobs` doesn't exist.

- [ ] **Step 3: Implement as listed under Files.**

- [ ] **Step 4: Run them, and the monitoring and command suites**

Run: the command above, plus `grep -rlE "SystemHealth|AdminHome|Monitoring|spark|command\(" tests/database tests/feature tests/unit`
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: every scheduled job reports in, and the admin home names any that stopped`

---

### Task 2: The hourly scheduler check emails once when a job stops and once when it recovers (U2, D11)

**Files:**
- Create: `app/Services/Monitoring/SchedulerCheck.php`, with `run(int $now): array{stale: list<string>, alerted: int}`, per the Global Constraints (alert copy, keys, `Monitoring.staleSince.<command>`).
- Create: `app/Filters/SchedulerCheckFilter.php` (secret header, 503 or 401, as `AiWorkerFilter` but without the loopback rule), aliased `schedulercheck` in `app/Config/Filters.php`.
- Create: `app/Controllers/Internal/SchedulerCheckController.php::run`.
- Modify:
  - `app/Config/Routes.php`: `$routes->post('internal/scheduler-check', 'Internal\SchedulerCheckController::run', ['filter' => 'schedulercheck'])`.
  - `app/Config/Filters.php`: add `internal/scheduler-check` to `csrf`'s `except`.
- Modify: `.github/workflows/uptime.yml`, with a step "Check the scheduled jobs" after "Smoke test the site", run only on success. It sends `curl -fsS -X POST -H "X-Scheduler-Secret: $SCHEDULER_SECRET" $TLS_FLAG "$SITE_URL/index.php/internal/scheduler-check"`. The secret is `secrets.SCHEDULER_SECRET_STAGING` or `secrets.SCHEDULER_SECRET_PRODUCTION`, chosen by `matrix.slug`; with no secret set, the step is skipped with a notice.
- Test: `tests/database/SchedulerCheckTest.php`, `tests/feature/SchedulerCheckEndpointTest.php`

- [ ] **Step 1: Write the failing tests**
  - `testAStaleJobAlertsEverySystemAdministratorOnce`: run twice, and each admin has one "Scheduled job stopped: backup:run" notification, with an email sent (`FakeEmail`).
  - `testRecoveryIsToldOnceAndOnlyAfterAStop`.
  - `testANewStallAfterRecoveryAlertsAgain`.
  - `testWithoutTheSecretConfiguredItAnswers503`, `testAWrongSecretIs401`, `testTheRightSecretRunsTheCheckWithoutACsrfToken` (use `withoutCsrfToken()`, expect 200 JSON).

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement as listed.**

- [ ] **Step 4: Run them, and the filter and route suites**

Run: the tests above, plus `tests/feature/RouteAccessTest.php tests/feature/PostFormsCarryATokenTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: an hourly check emails the system administrators when a scheduled job stops, and when it is back`

---

### Task 3: The deploy installs the scheduled jobs and fails if they aren't there (D5, 6.3, 6.4)

**Files:**
- Create: `deploy/crontab.txt` (the seven lines, as in Global Constraints, with `{site}` and `{php}`).
- Create: `app/Services/Monitoring/CrontabBlock.php`, with:
  - `static function render(string $template, string $site, string $php): string` (wrapped in the markers);
  - `static function merge(string $existing, string $block): string`;
  - `static function missing(string $installed): list<string>` (commands of `ScheduledJobs::JOBS` absent from `$installed`).
- Create: `app/Commands/JobsCrontab.php`, the command `jobs:crontab`:
  - `--site=<path> --php=<bin>` reads the current crontab on stdin and prints the merged crontab;
  - `--verify` reads `crontab -l` on stdin, prints the missing commands and exits 1 if there are any.
- Modify: `.github/workflows/deploy-reusable.yml`, with a step "Install the scheduled jobs" after "Run database migrations":

  ```bash
  ssh $SSH_OPTS "$REMOTE" bash -s -- "$DEPLOY_PATH" "$PHP_BIN" <<'REMOTE_SCRIPT'
  set -euo pipefail
  cd "$1"
  (crontab -l 2>/dev/null || true) | "$2" spark jobs:crontab --site="$1" --php="$2" > /tmp/mentora-crontab.$$
  crontab /tmp/mentora-crontab.$$ && rm -f /tmp/mentora-crontab.$$
  crontab -l | "$2" spark jobs:crontab --verify
  REMOTE_SCRIPT
  ```

  A failure fails the deploy, with `::error::The scheduled jobs are not installed; see docs/server-setup.md section 6.`
- Test: `tests/unit/CrontabBlockTest.php`

- [ ] **Step 1: Write the failing tests**
  - `testTheBlockHasTheSevenJobsWithThisSiteAndPhp`.
  - `testMergingAddsTheBlockToACrontabWithout`.
  - `testMergingReplacesOnlyTheBlock`: other lines before and after are kept, and a stale six-line block becomes seven.
  - `testMergingTwiceChangesNothing`.
  - `testMissingNamesTheAbsentCommands`.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/unit/CrontabBlockTest.php`
Expected: FAIL, because the class doesn't exist.

- [ ] **Step 3: Implement as listed.**

- [ ] **Step 4: Run them, and check the workflow parses.**

Run: the command above, then `php -r 'echo yaml_parse_file(".github/workflows/deploy-reusable.yml") ? "ok" : "bad";'` if the yaml extension exists; otherwise review the step's indentation against its neighbours.
Expected: PASS / ok.

- [ ] **Step 5: Stage**

Message: `feat: the deploy installs the seven scheduled jobs and fails if they are missing`

---

### Task 4: A backup counts only once it is proven complete, and only good ones are kept (11.1–11.3)

**Files:**
- Modify: `app/Services/Backup/MysqldumpRunner.php:52-66`, so every `gzwrite` result is compared with the chunk length and the `gzclose` result is checked. Each failure throws a `RuntimeException` with "The backup file could not be written completely.".
- Create: `app/Services/Backup/BackupFile.php`, with `static function isComplete(string $path): bool` (the read-back rule in Global Constraints).
- Modify: `app/Services/Backup/BackupService.php::take` (`:119-160`):
  - after the dump, `BackupFile::isComplete` or a failure with "The backup file is incomplete: it does not end the way a finished dump does.";
  - on any failure, delete the file, mark the row `failed`, and alert at once per Global Constraints.
- Modify: `prune()` (`:194-200`) keeps the files of the newest 14 succeeded rows, per Global Constraints.
- Test: `tests/database/BackupProvenTest.php`, using fake `BackupRunner`s that write a complete dump, a truncated gzip, and a dump without its closing line.

- [ ] **Step 1: Write the failing tests**
  - `testACompleteDumpSucceeds`.
  - `testATruncatedFileFailsIsDeletedAndAlertsAtOnce`: the row is failed, the file is gone, and each admin has one urgent "Backups need attention" with an email.
  - `testADumpWithoutItsClosingLineFails`.
  - `testTheNightlyTriggerDoesNotRepeatTheAlert`: run `NotificationTriggers` after it, and the notification count is unchanged.
  - `testAFailingRunNeverDeletesAGoodBackup`: 14 good files, then a failing run, leaves all 14.
  - `testOnlyGoodBackupsCountTowardTheFourteen`: 14 good plus a stray broken file, then a good run, leaves the newest 14 good and removes the stray.

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement as listed.**

- [ ] **Step 4: Run them, and the backup suites**

Run: the tests above, plus `grep -rlE "Backup" tests/database tests/feature tests/unit`
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `fix: a backup counts only once it reads back complete, and a failing one never pushes out a good one`

---

### Task 5: Each good backup is copied off the server when configured (U3)

**Files:**
- Create: `app/Database/Migrations/2026-11-17-000001_AddOffsiteToBackups.php`, adding `offsite_status` (ENUM `copied`, `failed`, null) and `offsite_message` (VARCHAR 500, null).
- Create: `app/Services/Backup/OffsiteCopier.php` (interface, `copy(string $dumpPath, string $uploadsPath): void`, throws on failure).
- Create: `app/Services/Backup/ShellOffsiteCopier.php`. It runs `backup.offsiteCommand` with both paths `escapeshellarg`ed, through `proc_open` with a 2-hour limit. A non-zero exit throws with the masked output.
- Register a `service('offsiteCopier')` that returns null when the setting is empty.
- Modify: `BackupService::take`. After a good backup and pruning, if a copier exists: copy, then record `copied`, or record `failed` with the message and alert per Global Constraints. The backup row itself stays `succeeded`.
- Modify: `app/Views/pages/admin/backups.php` gets an "Off-site copy" column: "Copied", "Failed: {message}" or "—".
- Modify: `docs/server-setup.md` § 6, a new subsection "Off-server backup copy (required before the first real institution)":
  - the rclone setup with no root;
  - an encrypted `crypt` remote to a non-Hostinger provider, under a separate account;
  - a key that can write but not delete or overwrite;
  - a 30-day lifecycle rule;
  - an example `offsite-copy` script that uploads the dump and syncs `writable/uploads`, then checks each with `rclone check --one-way`;
  - the go-live check: fetch one copy back and open the dump and a sample of the files (U3d).

  `docs/backup.md` explains the column and the setting.
- Test: `tests/database/BackupOffsiteTest.php`, using fake copiers.

- [ ] **Step 1: Write the failing tests**
  - `testAGoodBackupIsCopiedWithTheDumpAndTheUploads`: the fake records both paths, and the row is `copied`.
  - `testAFailedCopyKeepsTheBackupGoodAndAlerts`.
  - `testWithoutACommandNothingIsCopied`: `offsite_status` is null, with no alert.
  - `testAFailedBackupIsNeverCopied`.
  - `testTheShellCopierMasksThePassword`: a script that echoes the password and exits 1 gives a message with `********`.

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement as listed, and migrate dev.**

- [ ] **Step 4: Run them, and the backup suites**

Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: each good backup is copied off the server when an off-site command is configured`

---

### Task 6: The export is queued, one at a time per institution, and only the requester is told (12.2–12.4)

**Files:**
- Create: `app/Database/Migrations/2026-11-17-000002_QueueInstitutionExports.php`. The status enum becomes `queued`, `running`, `ready`, `failed`. It adds `started_at` (DATETIME null), `open_marker` (TINYINT null) and the unique `institution_exports_one_open (institution_id, open_marker)`.
- Modify: `app/Services/Institutions/InstitutionExport.php`:
  - **`request(int $institutionId, User $actor, ?DateTimeImmutable $now = null): int`:** the eligibility check, then an insert of a `queued` row with `open_marker` 1. The unique key's refusal, or an open row found first, gives `InvalidArgumentException` with the Global Constraints copy. Audited as `institution.export_requested`.
  - **`runQueued(?DateTimeImmutable $now = null): list<int>`:** fails stalled `running` exports, then takes the oldest `queued` one. It is marked `running` with `started_at`, its ZIP is built (with today's file code; Task 7 makes each file stream), and it becomes `ready` with `open_marker` null, `expires_at`, `row_counts` and `size_bytes`. The requester is notified, the `institution.exported` audit entry is written, then the next one is taken.
  - Failure is `failed` with the message, `open_marker` null, the file removed and the requester notified.
  - `build()` is removed; its callers and tests move to `request` + `runQueued`.
- Create: `app/Commands/ExportsRun.php`, the command `exports:run`. It calls `runQueued`, prunes, and records its heartbeat (Task 1).
- Modify: `app/Controllers/Institution/ExportController.php::create` calls `request` and redirects with the Global Constraints message.
- Modify: `app/Views/pages/institution/export.php`:
  - queued or running rows show "Being prepared" with who asked;
  - the request button is replaced by "An export is being prepared." while one is open;
  - it keeps the page's existing table and badge styles.
- Test: `tests/database/InstitutionExportQueueTest.php`, `tests/feature/InstitutionExportPageTest.php` (extend the existing export page test if there is one: `grep -rl "institution/export" tests`).

- [ ] **Step 1: Write the failing tests**
  - `testARequestIsQueuedAndBuiltByTheJob`.
  - `testASecondRequestWhileOneIsOpenIsRefused`.
  - `testTheDatabaseKeepsOneOpenExport`: inserting a second `open_marker` 1 row fails.
  - `testOnlyTheRequesterIsToldWhenItIsReady`: one urgent notification with an email, and none for the other admin.
  - `testAFailedBuildTellsTheRequesterAndFreesTheSlot`.
  - `testAStalledRunIsFailedAfterThirtyMinutes`.
  - `testThePageShowsBeingPreparedToEveryAdministrator`.

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement as listed, and migrate dev.**

- [ ] **Step 4: Run them, and the export suites**

Run: the tests above, plus `grep -rlE "InstitutionExport|institution/export" tests`
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: the institution export is prepared in the background, one at a time, and the requester is told`

---

### Task 7: The export streams, so it holds at the target scale (12.1)

**Files:**
- Modify: `InstitutionExport::files()` (`:198-229`). Each entry becomes:
  - `header`;
  - `sql`, with a `{after}` placeholder in a `WHERE <driving id> > ?` clause, `ORDER BY <driving id> LIMIT 5000`;
  - `key`, the driving id's column alias, selected first and dropped from the CSV row.
- Add `private function buildFile(ZipArchive $zip, string $name, array $spec, int $institutionId): int`. It pages with keyset ids into a temp file (`tmpfile()` path kept until the ZIP closes), then `$zip->addFile`. It returns the row count.
- The README states that rows are in the order they were recorded.
- Test: `tests/database/InstitutionExportScaleTest.php`.

- [ ] **Step 1: Write the failing test.** `testMemoryDoesNotGrowWithTheData`: build an export of one institution with 20,000 submissions (inserted with one `INSERT … SELECT` over a numbers CTE) and record `memory_get_peak_usage(true)` around `runQueued`; repeat with 80,000. The peak difference must be under 8 MB, and `submissions.csv` must hold every row (count lines in the ZIP entry). The 12.1 scale itself (a few million rows) is a manual check in Task 8, because a test at that size runs for minutes.

- [ ] **Step 2: Run it to verify it fails.** Today every table is read whole (`getResultArray`), so memory grows with the rows.

- [ ] **Step 3: Implement as listed.**

- [ ] **Step 4: Run it, and the export suites**

Expected: PASS.

- [ ] **Step 5: Stage**

Message: `fix: the institution export streams each file in pages, so its memory stays flat`

---

### Task 8: Full check, the scale check, and docs

- [ ] **Step 1: Run every suite, one at a time.** `vendor/bin/phpunit --no-coverage` (in the background), then `npm test`, then `npm run build && npm run e2e`.
Expected: PASS, apart from the six known `AiWorkerEndpointsTest` failures and the known calendar flake.

- [ ] **Step 2: Scale check (12.1), on dev.** Generate one institution with 20,000 students and 2,000,000 submissions with `php spark db:seed ExportScaleSeeder`, a new seeder under `app/Database/Seeds/` that refuses to run when `CI_ENVIRONMENT` is production. Then queue an export and run `exports:run`, recording the time and peak memory (`/usr/bin/time` or the command's own `memory_get_peak_usage` line). Expected: it finishes, with peak memory under 128 MB. Record the numbers in `docs/institution-data-export.md`.

- [ ] **Step 3: Docs.**
  - **`docs/server-setup.md` § 6:** the deploy installs the block; the CloudPanel caution from the spec ("check on staging that CloudPanel's Cron Jobs screen does not drop lines it did not create"); the scheduler-check secret, set in `.env` and in the GitHub secrets; the off-site subsection (Task 5).
  - **`docs/uptime.md`:** the scheduler check step and its secrets.
  - **`docs/monitoring.md`:** the seven heartbeats and their gaps.
  - **`docs/backup.md`:** a proven-good backup, the rotation rule, and the off-site copy.
  - **`docs/institution-data-export.md`:** the queue, notices and scale numbers.
  - **`docs/deploy.md`:** the new step.

- [ ] **Step 4: Stage the plan file, the seeder and the docs**

Message: `docs: scheduled jobs, proven backups, the off-server copy and the background export`
