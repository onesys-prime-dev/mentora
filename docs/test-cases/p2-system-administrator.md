# P2 test cases: the system administrator

12 cases, automated in `tests/e2e/system-admin.spec.js`, 12 added in Wave 1, 14 in Wave 2, 18 in Wave 3 and 5 in Wave 4 of the QA plan (2026-10-28, at the end, in the full case format). Read [README.md](README.md)
first. **All are `Draft`** (see the README).

The administrator signs in with a 6-digit code the app emails. In the browser
tests nothing is really sent: the app is pointed at `tests/e2e/support/mail-sink.php`
(`E2E_MAIL_SINK`, see `app/Config/Email.php`), which files each message in
`writable/e2e-mail`, where the test reads the code and the invitation link.
The administrator account is created by `tests/e2e/support/serve.js` with
`php spark admin:create` (the demo seeder refuses to run in production).

Cases TC-SA-04 to TC-SA-08 are one story about a new institution, **E2E College**,
and run in order.

| ID | Case | Role |
|---|---|---|
| TC-SA-01 | The administrator needs the emailed code, and the admin area stays closed until it is entered | System administrator |
| TC-SA-02 | Nobody but the system administrator can open the admin area | Student, Instructor, Anonymous |
| TC-SA-03 | The home page says what needs attention and its menu reaches the right pages | System administrator |
| TC-SA-04 | Onboarding an institution in three steps, and finding it afterwards | System administrator |
| TC-SA-05 | The invited administrator sets a password, signs in, and cannot export while the subscription runs | Institutional administrator |
| TC-SA-06 | A lapsed subscription makes the institution read-only, and its administrator can then export | Institutional administrator |
| TC-SA-07 | Recording a renewal brings a read-only institution back | System administrator |
| TC-SA-08 | Viewing as a student is view only, always marked, and ends with one click | System administrator |
| TC-SA-09 | What happened is in the audit log | System administrator |
| TC-SA-10 | The main administration screens pass an automated accessibility check | System administrator |
| TC-SA-11 | The users list narrows by search, and ticking people shows how many are chosen | System administrator |
| TC-SA-12 | A guarded action asks for the password in a dialog over the page, and carries on with the keyboard alone | System administrator |

## TC-SA-01 The administrator needs the emailed code

- **Steps:** sign in with the administrator's email and password. On "One more step" press *Send code*. Before entering anything, open `/admin/dashboard`. Enter a wrong code. Ask for a new code and enter the right one.
- **Expected:** the admin area stays closed after the password and after a wrong code; the right code opens `/admin/dashboard` ("Home").
- **Already automated:** `tests/feature/AdminTwoFactorTest.php`, `tests/session/`.

## TC-SA-02 Nobody but the system administrator can open the admin area

- **Steps:** as a student, then an instructor, then signed out, open `/admin/dashboard`, `/admin/institutions`, `/admin/users`, `/admin/audit-log`.
- **Expected:** signed in people are sent to their own area; signed out visitors to the login page. No admin page is shown.
- **Already automated:** `tests/feature/RouteAccessTest.php` (every admin route, every role).

## TC-SA-03 Home and menu

- **Steps:** sign in as the administrator. Look at the home page. Use *Add an institution* and each of: Institutions, Users, System status, Audit log, General.
- **Expected:** the page shows *Needs attention* and *Snapshot*, and the menu in four groups; every link reaches its own page.
- **Already automated:** `tests/feature/AdminHomePageTest.php`, `tests/database/AdminHomeTest.php`.

## TC-SA-04 Onboarding

- **Steps:** *Add an institution* with the name "E2E College" and code "E2ECOL". Choose the plan "Standard", a period starting today and ending in 30 days. Enter the first administrator (Ada Principal, ada@e2e-college.test) and *Finish onboarding*. Open the institutions list.
- **Expected:** "E2E College is onboarded", the institution is **active**, and it is in the list.
- **Already automated:** `tests/database/OnboardingServiceTest.php`, `tests/database/OnboardingRollbackTest.php`, `tests/feature/AdminOnboardingTest.php`.

## TC-SA-05 The invited administrator

- **Steps:** open the link in the invitation email. Set a password. Open the same link again. Sign in with the new password.
- **Expected:** the link works once; the administrator lands on *Data export*, which says it is available once the subscription has ended; no read-only banner; the admin area is closed to them.
- **Already automated:** `tests/database/InvitationServiceTest.php`, `tests/feature/InstitutionExportTest.php`.

## TC-SA-06 Lapse to read-only and export

- **Setup:** the subscription period ended 20 days ago (the test moves the dates, then runs `php spark subscriptions:sync`, as the scheduler does).
- **Steps:** the institution's administrator signs in. Press *Create an export*. Download it.
- **Expected:** the read-only banner is shown; the export is made and downloads as `e2ecol-….zip`.
- **Already automated:** `tests/database/SubscriptionLifecycleTest.php`, `tests/feature/ReadOnlyModeTest.php`, `tests/database/InstitutionExportServiceTest.php`.

## TC-SA-07 Renewal

- **Steps:** as the system administrator open E2E College: the status is *read-only*. Confirm the password. On the Subscription tab record a renewal ending in about a year.
- **Expected:** the institution is **active** again, and its administrator no longer sees the banner.
- **Already automated:** `tests/database/SubscriptionLifecycleTest.php`.

## TC-SA-08 Viewing as a student

- **Steps:** confirm the password. Find the demo student `student5@mentora.test` in Users. Give a reason and *View as*. Open the courses. *Exit view*.
- **Expected:** the student's dashboard opens with a banner saying "view only" on every page; *Exit view* returns to the administrator's page of that person, with no banner.
- **Already automated:** `tests/feature/ImpersonationTest.php`.

## TC-SA-09 The audit log

- **Steps:** open the audit log.
- **Expected:** it holds `impersonation.started`, `impersonation.ended`, `invitation.accepted` and `institution.exported`.
- **Already automated:** `tests/feature/AdminAuditLogPageTest.php`.

## TC-SA-10 Accessibility

- **Steps:** sign in as the administrator and open Home, Institutions, Plans, Users, Feature flags, Roles, System status, Backups, Audit log, Archive, General and Security. Run the automated check (axe, WCAG 2 A and AA) on each.
- **Expected:** no serious or critical problem. Found and fixed when the case was added: the System status page refreshed itself with a `<meta>` refresh the person could not stop; it now reloads from a script that a "Refresh every minute" box turns off (the `<meta>` stays only for browsers without scripts).
- **Already automated:** `tests/feature/AdminMonitoringPageTest.php`.

## TC-SA-11 The users list

- **Steps:** open Users. Search for `student5@mentora.test`. Tick the one row.
- **Expected:** one row remains; before ticking there is no bulk bar; after, it says "1 person selected" and offers Deactivate, Reactivate and Export selected.
- **Already automated:** `tests/feature/AdminUsersPageTest.php`, `tests/js/shared/data-table.test.js`.

## TC-SA-12 The password in a dialog

- **Steps:** on Backups, focus *Back up now* with the keyboard and press Enter. Press Escape. Press Enter again, type the password and press Enter.
- **Expected:** the first time a dialog "Confirm your password" opens over the page (the address does not change); Escape closes it, nothing is requested and focus is back on the button; the second time the backup is requested and its message and a *requested* row are on the page.
- **Already automated:** `tests/feature/AdminAjaxRedirectTest.php`, `tests/js/shared/dialog.test.js`.

---

## Wave 1 (added 2026-10-28)

From the QA plan's Wave 1. These cases use the full case format of the README (role, priority, data, basis, review). The expected results come from `docs/qa/decision-register.md`; none has been run. QA status: **FIX?** a fix is committed and a test pins it, expected to pass; **REG** a defect seen in the code, expected to fail until fixed; **NB** required but not built, expected to fail.

Several of these need a server, not only a browser: a crontab, the off-site copy command, the hourly check. Run them on a test server made for it (register PLN-07), never on staging or production without the team agreeing. Mail goes to the mail sink.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-SA-13 | Purging an institution removes its accounts, their files and its export zips | System administrator | P1 | FIX? |
| TC-SA-14 | Purging keeps what is not the institution's: other institutions, shared files, the audit log, system administrators | System administrator | P1 | FIX? |
| TC-SA-15 | A backup counts only once it reads back complete | System administrator | P1 | FIX? |
| TC-SA-16 | Failing backups never delete a good one, and only good ones count toward the 14 kept | System administrator | P1 | FIX? |
| TC-SA-17 | Each good backup, with the uploads, is copied off the server | System administrator | P1 | FIX? |
| TC-SA-18 | A backup stuck "running" is failed after two hours and does not block the next one | System administrator | P2 | REG |
| TC-SA-19 | A job that stops reporting is shown on Home and emailed once, and its recovery once | System administrator | P1 | FIX? |
| TC-SA-20 | The hourly check runs only with the right secret | (uptime workflow) | P1 | FIX? |
| TC-SA-21 | The deploy installs the seven jobs and fails if one is missing | (deploy) | P1 | FIX? |
| TC-SA-22 | `parents:notify` and the export job report like every other job | System administrator | P2 | FIX? |
| TC-SA-23 | Security headers, secure cookies and HTTPS in production | Anonymous | P2 | NB |
| TC-SA-24 | Invitation and password-reset links are rate limited | Anonymous | P2 | NB |

## TC-SA-13 Purging an institution removes its accounts, their files and its export zips

- **Role:** System administrator. **Priority:** P1
- **Data:** a test institution "Purge College" onboarded as in TC-SA-04, with: its administrator; a student and an instructor who belong only to it; an uploaded logo; a course file uploaded by the instructor; a submission file uploaded by the student; one finished data export (zip). Note the file paths on disk under `writable/uploads` and the export folder.
- **Steps:**
  1. Delete Purge College, then open Archive, choose it and press Purge. Confirm the password.
  2. Look for its accounts under Users and try to sign in as each.
  3. Look on disk for the noted files and the export zip.
  4. Open the audit log.
- **Expected:**
  - The institution and everything it owns are gone, including its accounts (with their profiles, notifications and reminders), so none of them can sign in.
  - The files and the export zip are gone from disk as well as from the database.
  - The audit log has `institution.purged`, with the number of accounts and files removed.
  - If a file could not be deleted from disk, the purge still finishes, the failure is recorded, and the next purge run retries it.
- **Already automated:** `tests/database/InstitutionPurgeFilesTest.php`: `testAccountsTheirFilesOnDiskAndExportZipsAreRemoved`, `testTheLogoGoesWithTheInstitution`, `testALogoTheInstitutionsOwnAdministratorUploadedGoesOnce`, `testAFileThatCouldNotBeDeletedIsRetriedByTheNextRun`; `tests/database/InstitutionPurgeTest.php`
- **Playwright:** no
- **Basis:** `InstitutionService::purge`; decisions 10.1–10.4; commits `2154a2f`, `67cc8bf`
- **QA status:** FIX? (PS-INS-04, PS-INS-05, PS-INS-06)
- **Review:** Draft. Expected result set by decisions 10.1–10.4.

## TC-SA-14 Purging keeps what is not the institution's

- **Role:** System administrator. **Priority:** P1
- **Data:** as TC-SA-13, plus: the demo institution 1 with its files; one file record of Purge College that another record still uses (for example a file referenced from outside the institution, set up on the test database); a system administrator account
- **Steps:** purge Purge College as in TC-SA-13. Then check institution 1, the shared file, the audit log and the system administrator.
- **Expected:**
  - Institution 1's accounts, files and data are untouched.
  - A file still used by another record stays on disk.
  - Purge College's audit entries stay in the audit log (decision 10.5).
  - No system administrator is ever removed.
- **Already automated:** `InstitutionPurgeFilesTest`: `testAnotherInstitutionsFilesAndAccountsStay`, `testAFileStillUsedByAnotherRowStaysOnDisk`, `testAuditEntriesStay`, `testASystemAdministratorIsNeverRemoved`
- **Playwright:** no
- **Basis:** `InstitutionService::purge`, `PurgePolicy`; decisions 10.2, 10.5; commits `2154a2f`, `67cc8bf`
- **QA status:** FIX? (PS-INS-04, PS-INS-06)
- **Review:** Draft. Expected result set by decisions 10.2 and 10.5.

## TC-SA-15 A backup counts only once it reads back complete

- **Role:** System administrator. **Priority:** P1
- **Data:** the test server with `mysqldump` installed. To make a backup fail, point the dump command at a wrapper that stops halfway (or fill the backup disk).
- **Steps:**
  1. On Backups, press Back up now and confirm the password. Run `php spark backup:run`.
  2. Make the next dump stop halfway, and run `php spark backup:run` again.
  3. Run it twice more with the dump still failing, then fix it and run it once.
  4. Read Backups, Home and the notifications of every system administrator after each run.
- **Expected:**
  - Step 1: the backup is **succeeded** only after its file was read back to its last line.
  - Step 2: the run is **failed**, its partial file is deleted, and every system administrator is told at once: "Backups need attention", "The latest backup failed: …".
  - Step 3: while failures continue, the alert is sent once for the run of failures, not once per run. After the next good backup the run of failures ends.
  - The database password never appears in a message.
- **Already automated:** `tests/database/BackupProvenTest.php` (all), `tests/unit/BackupFileTest.php` (all), `tests/database/MysqldumpRunnerTest.php`
- **Playwright:** no
- **Basis:** `BackupService`, `BackupFile`; decision 11.1; commit `5069228`
- **QA status:** FIX? (PS-BAK-02)
- **Review:** Draft. Expected result set by decision 11.1.

## TC-SA-16 Failing backups never delete a good one, and only good ones count toward the 14 kept

- **Role:** System administrator. **Priority:** P1
- **Data:** the test server with 14 succeeded backups (make them by running `backup:run` 14 times, moving the clock or the rows' dates as the test does)
- **Steps:**
  1. Make the dump fail and run `backup:run` three times.
  2. Fix it and run `backup:run` once.
- **Expected:**
  - Step 1: the 14 good backups are all still there. Failed runs are never counted toward the 14.
  - Step 2: the new good backup is kept and only then the oldest good one is removed, so there are again 14 good backups.
- **Already automated:** `BackupProvenTest::testAFailingRunNeverDeletesAGoodBackup`, `testOnlyGoodBackupsCountTowardTheFourteen`
- **Playwright:** no
- **Basis:** `BackupService` pruning; decision 11.2; commit `5069228`
- **QA status:** FIX? (PS-BAK-03)
- **Review:** Draft. Expected result set by decision 11.2.

## TC-SA-17 Each good backup, with the uploads, is copied off the server

- **Role:** System administrator. **Priority:** P1
- **Data:** the test server with the off-site command configured (`backup.offsiteCommand`, see `docs/server-setup.md`), pointing at a test bucket or folder
- **Steps:**
  1. Run `backup:run`. Read Backups.
  2. Make the off-site command fail (wrong target) and run it again.
  3. Fetch the copy from step 1 back and restore it into a scratch database. Open one uploaded file from it.
  4. Clear the command and run once more.
- **Expected:**
  - Step 1: the backup row shows the off-site copy as **copied**. The command was given the dump and the `writable/uploads` folder.
  - Step 2: the backup itself stays good; the row shows the copy **failed** with its reason, and the system administrators are told "Off-site backup copy failed".
  - Step 3: the fetched copy restores, and the uploaded file opens.
  - Step 4: with no command, nothing is copied and nothing is reported as failed.
  - A failed backup is never copied. The password in the command never appears in a message.
  - Encryption, retention (30 days) and append-only storage are done by the off-site command and the target, not by the app: check them on the target as in `docs/server-setup.md`.
- **Already automated:** `tests/database/BackupOffsiteTest.php` (all)
- **Playwright:** no
- **Basis:** `BackupService` (off-site step), `ShellOffsiteCopier`; decisions 11.4, U3; commit `5069228`
- **QA status:** FIX? (PS-BAK-04, PS-BAK-05)
- **Review:** Draft. Expected result set by decisions 11.4 and U3.

## TC-SA-18 A backup stuck "running" is failed after two hours and does not block the next one

- **Role:** System administrator. **Priority:** P2
- **Data:** the test database. **Setup:** insert a `backups` row with status `running` and `started_at` 3 hours ago (as if the server died during a dump).
- **Steps:**
  1. On Backups, press Back up now and confirm the password.
  2. Run `php spark backup:run` (the nightly trigger).
  3. Read Backups and the system administrators' notifications.
- **Expected:**
  - The stuck row is marked **failed** (the reason says it stopped while running), and the system administrators are alerted once.
  - The new backup is requested and runs.
  - A row running for less than two hours is left alone, and a new backup waits for it.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision MISS-OPS-03 (register Part I3: two hours). Today any `running` row stops both a request and the nightly run (`BackupService` lines 62 and 107), so one crashed run blocks every later backup [read in the code; not run].
- **QA status:** REG (PS-BAK-06) **Defect confirmed 2026-10-28** by reading the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft. Expected result set by MISS-OPS-03.

## TC-SA-19 A job that stops reporting is shown on Home and emailed once, and its recovery once

- **Role:** System administrator. **Priority:** P1
- **Data:** the test server with two system administrators. **Setup:** stop `backup:run` from running (comment it out of the crontab), and leave the others.
- **Steps:**
  1. After the job's allowed gap has passed (15 minutes for `backup:run`), call the hourly check (TC-SA-20) twice.
  2. Open Home and System status as each administrator. Read their email in the mail sink.
  3. Put the job back, let it run, and call the check again.
  4. Stop it again and call the check.
- **Expected:**
  - Step 2: Home names the stopped job. Each administrator gets one email and notification, "Scheduled job stopped: backup:run", "backup:run has not reported since <time>. Check the server's crontab (docs/server-setup.md, section 6).", however often the check runs.
  - Step 3: each gets one "Scheduled job running again: backup:run", and Home no longer names it.
  - Step 4: a new stop alerts again.
  - A job that has never reported says so. With SIS off, `sis:sync` and `sis:grades` are not expected and raise nothing.
- **Already automated:** `tests/database/SchedulerCheckTest.php` (all), `tests/database/ScheduledJobsTest.php` (all)
- **Playwright:** no
- **Basis:** `SchedulerCheck`, `ScheduledJobs`; decisions 6.2, U2, D11; commits `5069228`, `e4457b0`
- **QA status:** FIX? (PS-MON-04)
- **Review:** Draft. Expected result set by decisions 6.2, U2 and D11.

## TC-SA-20 The hourly check runs only with the right secret

- **Role:** the uptime workflow (an HTTP client). **Priority:** P1
- **Data:** the test server, with and without the scheduler secret configured
- **Steps:** send `POST /internal/scheduler-check` with no secret configured on the server; with a wrong secret; with the right secret and no CSRF token. Then look at the uptime workflow's configuration for production.
- **Expected:**
  - No secret configured: 503. Wrong secret: 401. Right secret: the check runs (TC-SA-19's alerts) without a CSRF token.
  - Production uses its own secret and never falls back to the staging one.
- **Already automated:** `tests/feature/SchedulerCheckEndpointTest.php` (all), `tests/unit/UptimeWorkflowTest.php`
- **Playwright:** no
- **Basis:** `SchedulerCheckController`, `SchedulerCheckFilter`, `.github/workflows` uptime workflow; decision U2; commits `5069228`, `67cc8bf`
- **QA status:** FIX? (PS-MON-05)
- **Review:** Draft. Expected result set by decision U2.

## TC-SA-21 The deploy installs the seven jobs and fails if one is missing

- **Role:** the deploy. **Priority:** P1
- **Data:** a test server whose crontab has an unrelated line (for example another site's job and a `MAILTO` line)
- **Steps:**
  1. Run the deploy's crontab step (`php spark jobs:crontab`, as the deploy does). Read the crontab.
  2. Run it a second time.
  3. Remove one job's line from the Mentora block by hand, and run the step's check.
  4. Remove the block's end line and run the step.
- **Expected:**
  - Step 1: a marked Mentora block holds exactly seven jobs (`backup:run`, `parents:notify`, `exports:run`, `subscriptions:sync`, `institutions:purge-expired`, `sis:sync`, `sis:grades`) with this site's path and PHP. The unrelated lines are untouched.
  - Step 2: nothing changes (no second block).
  - Step 3: the check names the missing command and the deploy fails.
  - Step 4: the step refuses rather than guessing where the block ends.
- **Already automated:** `tests/unit/CrontabBlockTest.php` (all), `ScheduledJobsTest::testThereAreSevenJobs`
- **Playwright:** no
- **Basis:** `JobsCrontab`, `CrontabBlock`, `deploy/crontab.txt`; decisions D5, 6.4; commit `5069228`
- **QA status:** FIX? (PS-JOB-02, PS-JOB-05)
- **Review:** Draft. Expected result set by decisions D5 and 6.4.

## TC-SA-22 `parents:notify` and the export job report like every other job

- **Role:** System administrator. **Priority:** P2
- **Data:** the test server
- **Steps:**
  1. Run `php spark parents:notify` and `php spark exports:run`. Open System status.
  2. Make `parents:notify` fail partway (for example stop the mail sink so sending throws) and run it.
  3. Stop both jobs for longer than their gaps (45 minutes and 15 minutes) and call the hourly check.
- **Expected:**
  - Step 1: each records when it last ran (`Monitoring.parentsNotifyAt`, `Monitoring.exportsRunAt`).
  - Step 2: a run that failed is not recorded as a successful run.
  - Step 3: both are reported as stopped, as in TC-SA-19.
- **Already automated:** `ScheduledJobsTest::testEachCommandRecordsItsHeartbeat`
- **Playwright:** no
- **Basis:** `ScheduledJobs`; decisions 6.2, D11, DOC-24; commit `5069228`
- **QA status:** FIX? (PS-JOB-06) for steps 1 and 3. Step 2 is suspected to fail (REG?): `ParentsNotify::run` records the heartbeat whatever exit code the work returned; only an exception skips it [read in the code; not run]. **Defect confirmed 2026-10-28** by reading the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft. Expected result set by DOC-24.

## TC-SA-23 Security headers, secure cookies and HTTPS in production

- **Role:** Anonymous (any HTTP client). **Priority:** P2
- **Data:** the application running with the production environment on HTTPS, and once on `http://localhost` in development
- **Steps:** request the login page and a signed-in page over HTTPS and read the response headers and cookies. Request `http://` on the production host.
- **Expected:**
  - `X-Frame-Options: DENY` and a matching CSP `frame-ancestors 'none'`; `X-Content-Type-Options: nosniff`.
  - A Content-Security-Policy, first in report-only mode; the pages still work under it (no blocked scripts or styles in the browser console).
  - The session and remember-me cookies are `Secure`, `HttpOnly` and have a `SameSite` value.
  - `http://` on the production host redirects to `https://`. HSTS is sent only once HTTPS is set up on every host it covers, without `includeSubDomains` or `preload` unless all subdomains are ready.
  - On `http://localhost` in development the app still works without HTTPS.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision MISS-AUTH-02. Today `secureheaders` is commented out in `app/Config/Filters.php`, `CSPEnabled` and `forceGlobalSecureRequests` are false, and `Cookie::$secure` is false [read in the code; not run].
- **QA status:** NB (PS-SEC-08) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft. Expected result set by MISS-AUTH-02.

## TC-SA-24 Invitation and password-reset links are rate limited

- **Role:** Anonymous. **Priority:** P2
- **Data:** one valid invitation token and one valid reset token, and made-up tokens
- **Steps:**
  1. Request `GET /invitation/<random>` and `GET /reset-password/<random>` many times from one address, faster than the configured limit.
  2. Ask for a password reset many times for one email, and for an email with no account.
  3. Fail the password re-entry dialog several times, start a new session, and fail it again.
- **Expected:**
  - Past the limit the server answers with a throttling response (429, or the app's "try again later" page), and keeps doing so until the cooldown ends. The response reveals nothing about which tokens exist.
  - Reset requests answer the same for an existing and a missing email, and are limited per address and per account.
  - The re-entry limit cannot be reset by starting a new session.
  - The limits and cooldowns are settings, documented with the security settings.
- **Already automated:** none (the per-session re-entry counter: `tests/unit/ReauthServiceTest.php`)
- **Playwright:** no
- **Basis:** decision MISS-AUTH-03. Today no throttle protects these routes [read in the code; not run].
- **QA status:** NB (PS-SEC-09) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft. Expected result set by MISS-AUTH-03.

---

## Wave 2 (added 2026-10-28)

P1 scenarios whose expected result is the code's behavior (**VCI**) or a decision (**IBR**), expected to pass; **FIX?** a committed fix with a test, expected to pass. None has been run. Server-side cases run on a test server, with mail going to the mail sink.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-SA-25 | The password is asked again for guarded actions, for five minutes, and five wrong answers end the session | System and institutional administrator | P1 | VCI, IBR |
| TC-SA-26 | Viewing as someone is view only, ends by itself, and saves nothing for them | System administrator | P1 | IBR, FIX? |
| TC-SA-27 | Every change needs a fresh token, and only a button signs out | Every role | P1 | IBR |
| TC-SA-28 | Exports defuse formulas, and secrets never appear in the audit log, logs or error pages | System administrator | P1 | VCI |
| TC-SA-29 | The worker interface refuses everyone but the local worker | (worker) | P1 | VCI |
| TC-SA-30 | Suspending, deleting and restoring an institution | System administrator | P1 | VCI |
| TC-SA-31 | Onboarding is all or nothing, and a failed email can be resent | System administrator | P1 | VCI |
| TC-SA-32 | A purge is recorded before it starts, and the nightly purge honors the retention period | System administrator | P1 | VCI |
| TC-SA-33 | A subscription moves through its stages on the right days | System administrator | P1 | VCI |
| TC-SA-34 | Plan limits stop seats, storage and AI generation, and warn first | Institutional administrator | P1 | VCI |
| TC-SA-35 | A requested backup runs once, and the daily one is taken when none is recent | System administrator | P1 | VCI |
| TC-SA-36 | The health check, and maintenance mode | Anonymous, every role | P1 | VCI |
| TC-SA-37 | Each scheduled command, real and dry, tells people once | System administrator | P1 | VCI |
| TC-SA-38 | The test suite passes whatever a developer's settings file holds | (CI) | P1 | FIX? |

## TC-SA-25 The password is asked again for guarded actions, for five minutes, and five wrong answers end the session

- **Role:** System administrator, institutional administrator. **Priority:** P1
- **Data:** the admin's guarded pages (Backups' Back up now, onboarding, restore, purge, impersonation) and the institution's (roles, people roles, deactivate, term close, sign-off)
- **Steps:**
  1. Without confirming, open a guarded page and send a guarded POST. Send a guarded request from a script.
  2. Confirm the password. Use guarded actions at 4 minutes 59 seconds and at 5 minutes 1 second.
  3. Confirm with a wrong password, an empty one, then five wrong ones. Repeat in the institution's dialog.
  4. Plant a return address outside the area (`https://evil.example`, `//evil.example`) and confirm.
  5. As a second administrator, use the first one's confirmation (shared confirm page).
- **Expected:**
  - Step 1: the page goes to the confirm page ("Confirm your password to continue."); the POST is stopped before it runs; a script gets JSON (`reauth_required`) so the page can ask in a dialog over itself.
  - Step 2: guarded actions open inside 300 seconds and are asked again after.
  - Step 3: a wrong or empty password stays on the page and unlocks nothing; the fifth ends the session, and the dialog says so.
  - Step 4: the return address is limited to the admin and institution areas; a planted one falls back to the area's home.
  - Step 5: another person's confirmation does not count.
  - Signing off a section or a program and changing a permission (both layers) ask for the password; reopening a sign-off asks for a reason only (register Part I4).
- **Already automated:** `tests/unit/ReauthServiceTest.php`, `tests/feature/AdminReauthTest.php`, `InstitutionReauthTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-12)
- **Basis:** the `reauth` filter, `ReauthService`; decisions AMB-OPS-05 (300 s), 2.4, U1c
- **QA status:** VCI (PS-REA-01 to PS-REA-03), IBR (PS-REA-05)
- **Review:** Draft

## TC-SA-26 Viewing as someone is view only, ends by itself, and saves nothing for them

- **Role:** System administrator. **Priority:** P1
- **Data:** `student5`; another system administrator; a deactivated person; the impersonation time limit set to 5 minutes for the test
- **Steps:**
  1. View as student5 without a reason, then with one (password asked). Open their courses, a quiz, a notification and a lesson.
  2. Send every kind of change: a form, a script request, a discussion post, a role switch, the admin area.
  3. Sign out while viewing.
  4. View again; deactivate student5 from another browser; use the view. View again and wait out the time limit.
  5. Try to view another system administrator, yourself, an unknown id; start a view while already viewing.
- **Expected:**
  - Step 1: the banner says view only on every page; viewing saves nothing for student5: no quiz attempt (the quiz shows its landing), no notification read, no last-active time, no progress, no saved role.
  - Step 2: every non-GET request is refused, a form with "You are viewing as another person, which is view-only. Nothing can be changed. Exit the view to make changes as yourself.", a script with 403 JSON (`impersonation_view_only`); only Exit works. The admin area is not reachable while viewing.
  - Step 3: signing out ends the view, not the administrator's session.
  - Step 4: "That account was deactivated, so the view was ended."; "The time limit for viewing as this person has passed, so the view was ended." Writes stop at once when the time is up.
  - Step 5: each is refused. Audit entries written during the view carry the administrator.
- **Already automated:** `tests/feature/ImpersonationTest.php` (all), `ViewAsWritesNothingTest.php`, `tests/database/ViewAsQuizTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-08)
- **Basis:** `ImpersonationFilter`, `Impersonation`; decisions 3.1, 3.2; commit `5140068`
- **QA status:** IBR (PS-IMP-01), FIX? (PS-IMP-02, PS-IMP-03)
- **Review:** Draft

## TC-SA-27 Every change needs a fresh token, and only a button signs out

- **Role:** Every role. **Priority:** P1
- **Data:** any form (for example a course announcement) and the browser's developer tools
- **Steps:**
  1. Open a form, type in it, remove the hidden token, and submit. Do the same from a script (fetch) and with a production environment.
  2. Let the session end, then submit a form.
  3. Submit with the token twice in a row.
  4. Open `/logout` as a plain link (an image or a link on another page). Use the menu's Sign out.
  5. Call `internal/ai/*` and `internal/scheduler-check` without a token.
- **Expected:**
  - Step 1: refused with "This page was open too long. Reload it and try again." and the typed input kept; a script gets 403 JSON (`csrf_expired`) with the message, in production too.
  - Step 2: sent to sign in with the message.
  - Step 3: the same token works for the second request.
  - Step 4: a plain link signs nobody out; the menu signs out with a form.
  - Step 5: only the worker and the scheduler check are exempt; each has its own secret (TC-SA-20, TC-SA-29). Every POST form in the views carries the token.
- **Already automated:** `tests/feature/CsrfProtectionTest.php` (all), `tests/unit/PostFormsCarryATokenTest.php`
- **Playwright:** no
- **Basis:** `app/Config/Filters.php` (csrf), `CsrfFilter`; decisions 4.1–4.4
- **QA status:** IBR (PS-SEC-01, PS-SEC-02, PS-SEC-03)
- **Review:** Draft

## TC-SA-28 Exports defuse formulas, and secrets never appear in the audit log, logs or error pages

- **Role:** System administrator, institutional administrator. **Priority:** P1
- **Data:** a person named `=HYPERLINK("http://x","click")`, another `+1+1`, `-2+3`, `@SUM(A1)`; a password change, an SIS settings save with a password, an off-site command with a password
- **Steps:** export Users, the audit log, reports and a grade sheet; open each in a spreadsheet program. Read the audit entries of the password and settings changes, `writable/logs`, and an error page made to fail (production mode).
- **Expected:** every cell that would run as a formula is defused (it shows as text); the files open with one line per row; passwords, tokens and keys show as redacted in the audit log (nested ones too), never in logs, and never on an error page.
- **Already automated:** `tests/unit/UserExportTest.php`, `AuditRedactionTest.php`, the export tests of each list; `BackupOffsiteTest::testTheShellCopierMasksThePassword`
- **Playwright:** no
- **Basis:** the CSV writers, `AuditLogger` redaction
- **QA status:** VCI (PS-SEC-05, PS-SEC-10)
- **Review:** Draft

## TC-SA-29 The worker interface refuses everyone but the local worker

- **Role:** (an HTTP client). **Priority:** P1
- **Data:** the test server with and without `X-Worker-Secret` configured
- **Steps:** call `internal/ai/*` with no secret configured; from another machine with the right secret; locally with a wrong or missing secret; locally with the right one. As a second worker, try to complete the first worker's job; send a very long progress note and failure message.
- **Expected:** 503 when not configured ("The worker interface is not configured."); 403 from outside this machine even with the secret; 401 for a wrong or missing secret; the right one claims, reports and completes a job. Another worker cannot complete someone else's job; long notes and messages are cut short.
- **Already automated:** `tests/feature/AiWorkerEndpointsTest.php` (all)
- **Playwright:** no
- **Basis:** the worker filter and controller
- **QA status:** VCI (PS-SEC-11, PS-AIQ-06)
- **Review:** Draft

## TC-SA-30 Suspending, deleting and restoring an institution

- **Role:** System administrator. **Priority:** P1
- **Data:** institutions: one active with a running subscription, one whose subscription ended, one whose subscription ends today, one past by its dates before the nightly job
- **Steps:** suspend the active one; try to delete it, and the suspended one while its subscription runs; delete the ended one with a wrong name, then the exact name; delete the one past by its dates before the job; try the one ending today; restore a deleted one.
- **Expected:** suspending makes it past; a running subscription blocks deletion even when suspended, including one that ends today; deletion needs the exact name; one past by its dates can be deleted before the job runs; restoring keeps the earlier status (decision AMB-OPS-02: the status then shown is the one its dates give). Each row of the list says what its dates make it.
- **Already automated:** `tests/database/InstitutionServiceTest.php`
- **Playwright:** no
- **Basis:** `InstitutionService`
- **QA status:** VCI (PS-INS-02)
- **Review:** Draft

## TC-SA-31 Onboarding is all or nothing, and a failed email can be resent

- **Role:** System administrator. **Priority:** P1
- **Data:** the onboarding steps of TC-SA-04; a test switch that makes the subscription or the account creation fail; the mail sink stopped
- **Steps:** onboard with the subscription step failing; with the account step failing; with the mail sink stopped; then start the mail sink and resend the invitation.
- **Expected:** a failure after the subscription undoes it; a failure while making the account leaves nothing behind and the invitation unused; with mail down, the institution is onboarded and the invitation can be resent; the email is sent only after everything is saved.
- **Already automated:** `tests/database/OnboardingRollbackTest.php`, `OnboardingServiceTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-04)
- **Basis:** `OnboardingService`
- **QA status:** VCI (PS-INS-03)
- **Review:** Draft

## TC-SA-32 A purge is recorded before it starts, and the nightly purge honors the retention period

- **Role:** System administrator. **Priority:** P1
- **Data:** deleted institutions: one deleted 10 days ago, one 100 days ago (retention 90 days); an active one
- **Steps:** purge the active one; purge a deleted one with the wrong name; make the audit write fail and purge; run `php spark institutions:purge-expired --dry-run`, then for real; make one purge fail in the run.
- **Expected:** an institution that is not deleted cannot be purged; the exact name is required; the purge is recorded first and nothing is purged if that fails (a failure records `institution.purge_failed`); the dry run lists and removes nothing; the real run purges only what is past the retention, records the actor as "system (scheduled purge)", and one failure does not stop the rest; the deleted list shows when each is purged, soonest first.
- **Already automated:** `tests/database/InstitutionPurgeTest.php` (all)
- **Playwright:** no
- **Basis:** `InstitutionService::purge`, `InstitutionsPurgeExpired` command; decisions 10.x
- **QA status:** VCI (PS-INS-07)
- **Review:** Draft

## TC-SA-33 A subscription moves through its stages on the right days

- **Role:** System administrator. **Priority:** P1
- **Data:** the default settings: warning 30 days, grace 14, read-only 30 (`app/Config/Platform.php`); subscriptions whose end dates are set relative to today (test database), including across a month end and 29 February
- **Steps and expected:**

  | Day, relative to the end date | Stage |
  |---|---|
  | 31 days before | active |
  | 30 days before, up to the end date itself | expiring (warning) |
  | 1 to 14 days after | grace |
  | 15 to 44 days after | read-only |
  | 45 days after and later | expired (past) |

- **Then:** set warning to 0 (never "expiring"), grace to 0 (straight to read-only), read-only to 0 (grace straight to expired), all to 0 (expired the day after). Run `php spark subscriptions:sync` and check that only read-only and expired change the institution; the time of day does not matter.
- **Already automated:** `tests/unit/SubscriptionStatusTest.php` (all), `tests/database/SubscriptionLifecycleTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-06)
- **Basis:** `SubscriptionStatus`
- **QA status:** VCI (PS-SUB-01). Boundaries read from `SubscriptionStatus::at` [read in the code].
- **Review:** Draft

## TC-SA-34 Plan limits stop seats, storage and AI generation, and warn first

- **Role:** Institutional administrator, instructor. **Priority:** P1
- **Data:** a plan with 10 student seats, 100 MB of storage and 20 AI questions per term; institution 1 set to 9 students and 99 MB used
- **Steps:** invite a student (90%); invite another (100%); reactivate a deactivated student; add the student role to someone; upload a file that fits, one that exactly fills the room, one 1 KB over; an announcement attachment; generate 20 questions, then 1 more, a top-up and a replace.
- **Expected:** at 90% the administrators are warned, at 100% it is urgent; the invite, reactivation and role change are refused at 100%; an upload that fits or exactly fills the room is allowed, one over is refused and nothing is moved; the announcement attachment is stopped too; another institution's limits do not stop this one; generation beyond the allowance is refused and starts nothing ("over an exhausted allowance" says so); a plan with no limit never refuses; the system administrator is never limited.
- **Already automated:** `tests/database/PlanLimitEnforcementTest.php` (all), `NotificationsTest::testASeatLimitAtNinetyPercentWarnsAndAtOneHundredIsUrgent`
- **Playwright:** no
- **Basis:** `PlanLimits`
- **QA status:** VCI (PS-SUB-03, PS-SUB-04)
- **Review:** Draft

## TC-SA-35 A requested backup runs once, and the daily one is taken when none is recent

- **Role:** System administrator. **Priority:** P1
- **Data:** the test server
- **Steps:** request a backup (password asked); request again; run `backup:run`; run it again with a recent good backup; make the last good backup older than a day and run it.
- **Expected:** the request is recorded and audited; a second request while one waits is refused; the job takes what was requested; no daily backup when one succeeded recently; one is taken when none is recent; nothing is done while another is running (see TC-SA-18 for a stuck one); only the newest 14 good files are kept (TC-SA-16).
- **Already automated:** `tests/database/BackupServiceTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-12)
- **Basis:** `BackupService`
- **QA status:** VCI (PS-BAK-01)
- **Review:** Draft

## TC-SA-36 The health check, and maintenance mode

- **Role:** Anonymous, every role. **Priority:** P1
- **Data:** `/health`; General settings › maintenance mode
- **Steps:**
  1. Open `/health` signed out; stop the database and open it again; check the response headers.
  2. Turn maintenance mode on. As a student, an instructor, the institution's administrator, the system administrator, and an administrator viewing as someone, open a page and send a script request. Open `/login` and `/health`.
- **Expected:**
  - Step 1: 200 with only the status and the database state; 503 when degraded; never cached.
  - Step 2: non-administrators get 503 with a Retry-After header (HTML page, or JSON for scripts); the system administrator and someone viewing as another person get through; the open paths (login, logout, health, the reset and invitation links) work. The institution's administrator getting 503 today is a defect (decision AMB-AUTH-03; PS-ROM-09, Wave 4).
- **Already automated:** `tests/feature/HealthEndpointTest.php`; maintenance through `GeneralSettingsTest`
- **Playwright:** no
- **Basis:** `MaintenanceFilter`, the health controller
- **QA status:** VCI (PS-MON-01, PS-ROM-07)
- **Review:** Draft

## TC-SA-37 Each scheduled command, real and dry, tells people once

- **Role:** System administrator. **Priority:** P1
- **Data:** the test server with data for each: a backup due, a subscription in each stage, an institution due for purge, parents to tell, an SIS connection
- **Steps:** run each of `backup:run`, `subscriptions:sync`, `institutions:purge-expired`, `parents:notify`, `sis:sync`, `sis:grades` with `--dry-run` where it has one, then for real, then again. Read exit codes, notifications and the mail sink.
- **Expected:** a dry run changes and tells nothing; the real run does its work and exits 0 (non-zero when it failed); running again creates no duplicate rows, notifications or emails. Each subscription stage, plan limits at 90% and 100%, a term ending, a section with no instructor, invitations expiring, and an SIS failure each tell the right people once. An urgent notification is also emailed and records when it was emailed only when the email succeeded; a failed email keeps the in-app notification. The bell panel shows seven at a time, newest first.
- **Already automated:** `tests/database/NotificationsTest.php`, the tests of each command
- **Playwright:** no
- **Basis:** `app/Commands/`, `NotificationService`
- **QA status:** VCI (PS-JOB-01, PS-JOB-03, PS-NOT-01, PS-NOT-02)
- **Review:** Draft

## TC-SA-38 The test suite passes whatever a developer's settings file holds

- **Role:** (a developer, CI). **Priority:** P1
- **Data:** a checkout with an `.env` holding real-looking values (a worker secret, an off-site command, SIS settings), and one with none
- **Steps:** run the PHP suite with each `.env`; run CI's second pass, which copies `tests/_support/ci-full.env` over `.env`.
- **Expected:** the suite gives the same result with either `.env`; tests set settings through `TestEnv` and put them back; `ci-full.env` holds every key of the `env` template (its own test fails otherwise).
- **Already automated:** `tests/unit/TestEnvTest.php`, `CiFullEnvTest.php`
- **Playwright:** no
- **Basis:** `tests/_support/TestEnv.php`, `.github/workflows/ci.yml`; decisions 14.1, 14.2; commit `2154a2f`
- **QA status:** FIX? (PS-AIQ-13, PS-CI-01). This is the first thing the baseline run (register PLN-05) confirms.
- **Review:** Draft

---

## Wave 3 (added 2026-10-28)

P2 scenarios, and the P1 scenarios of medium-risk modules that no earlier wave took (users, feature flags, the audit log). QA status as in the README; none has been run.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-SA-39 | Deactivating people and changing their roles from the admin area | System administrator | P1 | VCI |
| TC-SA-40 | Deleting a user keeps the history they created | System administrator | P1 | REG |
| TC-SA-41 | Bulk actions and the users export | System administrator | P2 | VCI |
| TC-SA-42 | Feature flags resolve in a fixed order, and each flag really gates its feature | System administrator | P1 | VCI |
| TC-SA-43 | The audit log cannot be changed, and every security action is in it | System administrator | P1 | VCI |
| TC-SA-44 | The platform audit log's export and addresses | System administrator | P2 | VCI |
| TC-SA-45 | Platform settings: limits, the time zone, and their audit | System administrator | P2 | VCI, IBR |
| TC-SA-46 | The audit retention setting archives old entries | System administrator | P2 | NB |
| TC-SA-47 | System status and the home page's attention list | System administrator | P2 | VCI |
| TC-SA-48 | The backup age alert, and the dump password | System administrator | P2 | VCI |
| TC-SA-49 | Creating an institution: its code and name | System administrator | P2 | VCI |
| TC-SA-50 | Renewals and corrections, and a history nobody can rewrite | System administrator | P2 | VCI |
| TC-SA-51 | A renewal keeps a manual suspension; a restore follows the dates | System administrator | P2 | IBR |
| TC-SA-52 | Lowering a plan below what an institution uses keeps what it has and stops growth | System administrator | P2 | IBR |
| TC-SA-53 | An institution's own expiry timeline may only be stricter | Institutional administrator | P2 | VCI |
| TC-SA-54 | Which other actions ask for the password again | System and institutional administrator | P2 | REG? |
| TC-SA-55 | The database refuses impossible values | (database) | P2 | REG? |
| TC-SA-56 | Seeds and the first administrator | (deploy) | P2 | VCI |

## TC-SA-39 Deactivating people and changing their roles from the admin area

- **Role:** System administrator. **Priority:** P1
- **Data:** Users; `student5`; an SIS-managed student; a second system administrator; `chairperson1`; `dean2`; a plan with no free seat
- **Steps:** deactivate yourself, the last active system administrator, the SIS-managed person, student5 (twice), and a system administrator while another is active; reactivate student5 with no seat; sign student5 out everywhere. Change roles: remove all, add `admin`, change a system administrator's roles, an unknown role, make someone a student with no seat, remove the chairperson role from `chairperson1` and the dean role from `dean2`; save with nothing changed.
- **Expected:** you cannot deactivate yourself or the last active administrator; SIS-managed people are refused; deactivating twice is refused; sessions end (TC-AUTH-07); reactivating a student needs a seat; sign-out everywhere is recorded and leaves the account on; a person keeps at least one role; `admin` is never given and a system administrator's roles are not changed from the screen; an unknown role is refused; becoming a student needs a seat; removing the chairperson or dean role ends the assignment; an unchanged save writes no entry; every change is recorded.
- **Already automated:** `tests/database/UserAdminServiceTest.php`, `tests/feature/AdminUsersPageTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-11)
- **Basis:** `UserAdminService`
- **QA status:** VCI (PS-USR-01, PS-USR-02)
- **Review:** Draft

## TC-SA-40 Deleting a user keeps the history they created

- **Role:** System administrator (database level). **Priority:** P1
- **Data:** the test database; a user who created a dean assignment, an override request, a sign-off and a department announcement; a learning resource that uses a file
- **Steps:** delete the user row; read those four records. Delete the resource's file row.
- **Expected:** the records stay, with the creator reference cleared (SET NULL) and their other details kept; deleting a file that a learning resource uses is refused (RESTRICT). Routine removal stays deactivation; a purge follows its own rules (TC-SA-13).
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision PLN-02. Today seven foreign keys cascade because of reversed arguments, so the records are deleted with the user (inventory V10) [read in the code; not run].
- **QA status:** REG (PS-USR-05) **Defect confirmed 2026-10-28** by the database schema (`docs/qa/defects-2026-10-28.md`). **Fixed in `c461dc3` on `fix/qa-defects`** (2026-10-29), not yet merged to `main`; expected to pass there.
- **Review:** Draft

## TC-SA-41 Bulk actions and the users export

- **Role:** System administrator. **Priority:** P2
- **Steps:** tick five people including yourself, the last administrator and an SIS-managed person; Deactivate; Reactivate including a student with no seat; send an unknown action and no one. Export the users list with 10,000 and 10,001 rows (test data) and a name that looks like a formula.
- **Expected:** bulk does everyone it can and reports a reason per skipped person; one refusal does not stop the rest; the last administrator is never deactivated; the same id twice is done once; no one or an unknown action is refused. The export is capped at 10,000 rows, defuses formulas and is audited.
- **Already automated:** `UserAdminServiceTest` (bulk tests), `tests/unit/UserExportTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-11)
- **Basis:** `UserAdminService`, `UserExport`
- **QA status:** VCI (PS-USR-03, PS-USR-04)
- **Review:** Draft

## TC-SA-42 Feature flags resolve in a fixed order, and each flag really gates its feature

- **Role:** System administrator. **Priority:** P1
- **Data:** the flags `sis.sync`, `ai.question_generation` and `parent.portal` (`app/Config/FeatureFlags.php`); institution 1 on a plan that grants one of them
- **Steps and expected (one flag; each row replaces the last):**

  | Setting | Result for institution 1 |
  |---|---|
  | nothing set | the code's default |
  | plan grants it, default off | on |
  | global on row | on for everyone |
  | institution override off, plan grants it | off |
  | institution override on, global on | on |
  | global off (kill switch), override on | off for everyone |
  | override that ended yesterday | ignored |
  | override ending today | still applies |
  | kill switch that has ended | no longer kills |

- **Then:** with each of the three flags off for institution 1, try an SIS sync, AI generation and the parent portal.
- **Expected (also):** each is refused while its flag is off and works when on; the time of day does not matter; an unknown flag is a mistake; switching is audited.
- **Already automated:** `tests/unit/FeatureFlagResolverTest.php`, `tests/database/FeatureFlagsTest.php`, `FeatureFlagAdminTest.php`, `SisImporterTest::testARunNeedsTheFeature`, `QuestionGenerationSafetyTest::testGenerationIsRefusedWhenTheInstitutionSwitchedItOff`, `ParentPortalTest`
- **Playwright:** no
- **Basis:** `FeatureFlagResolver`
- **QA status:** VCI (PS-FLG-01, PS-FLG-03)
- **Review:** Draft

## TC-SA-43 The audit log cannot be changed, and every security action is in it

- **Role:** System administrator (and database level). **Priority:** P1
- **Steps:** try to update, save over, batch-update, delete, purge and replace an audit row through the model; then do each security action of the inventory (sign-in success and failure, lockout, password reset, role change, deactivation, permission change, view-as start and end, purge, export, backup request, settings change) as a multi-role person and while viewing as someone, and read the log.
- **Expected:** every change to an existing row is refused and leaves it as it was; new entries can still be added and read. Each action writes an entry with the actor, the role they were acting in (their actual active role), their institution, the time, and the administrator when viewing as someone; old and new values are stored without secrets; an entry keeps its label if the user is deleted.
- **Already automated:** `tests/database/AuditLogTest.php` (all), `tests/feature/AuditAuthEventsTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-09)
- **Basis:** `AuditLogger`, the audit model
- **QA status:** VCI (PS-AUD-01, PS-AUD-02)
- **Review:** Draft

## TC-SA-44 The platform audit log's export and addresses

- **Role:** System administrator. **Priority:** P2
- **Steps:** export the audit log at and over its cap, with a formula-like value; export it again and look for the export's own entry; turn the setting that shows IP addresses off and on.
- **Expected:** the export stops at its cap, defuses formulas and is itself audited; addresses are shown only while the setting is on.
- **Already automated:** `tests/feature/AdminAuditLogPageTest.php`
- **Playwright:** no
- **Basis:** the admin audit controller
- **QA status:** VCI (PS-AUD-03)
- **Review:** Draft

## TC-SA-45 Platform settings: limits, the time zone, and their audit

- **Role:** System administrator. **Priority:** P2
- **Data:** General and Security settings
- **Steps:** save each setting at its minimum, its maximum and one past each (for example the idle limit 4, 5, 120, 121 minutes; the impersonation limit 4, 5, 240, 241); save without confirming the password. Change the time zone from Asia/Manila to UTC and look at a due date, an audit time and a class time. Make the audit write fail and save. Set the system name and an announcement.
- **Expected:** each limit is enforced with the reason and nothing is saved on a bad value; saving needs the password; every time in the app moves with the zone, class times included; an unknown stored zone is ignored; a change that cannot be audited is undone; the system name shows in the admin header and the announcement for everyone (nothing when empty).
- **Already automated:** `tests/feature/GeneralSettingsTest.php`, `SecuritySettingsTest.php`
- **Playwright:** no
- **Basis:** `PlatformSettings`, `GeneralSettings`; decisions 13.5, U4
- **QA status:** VCI (PS-SET-01, PS-SET-04), IBR (PS-SET-02)
- **Review:** Draft

## TC-SA-46 The audit retention setting archives old entries

- **Role:** System administrator. **Priority:** P2
- **Data:** audit entries 800 and 700 days old; `auditRetentionDays` 730; an entry under a legal hold (if holds exist)
- **Steps:** run the retention process; change the setting to 365 and run it again; set an invalid value.
- **Expected:** entries older than the setting are archived (kept in an archive readable by authorized roles) before leaving active storage, never silently deleted; held entries stay; the run is logged and a failure can be retried; an invalid value is refused.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision MISS-OPS-01. Today the setting has no effect (inventory V1).
- **QA status:** NB (PS-SET-03) **Not built: confirmed 2026-10-28** by not found in the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

## TC-SA-47 System status and the home page's attention list

- **Role:** System administrator. **Priority:** P2
- **Data:** the test server; a way to slow the database (a sleeping query) and to fill the disk to 90% and 97% (a test volume)
- **Steps:** open System status in each state: database answering in 999 and 1001 ms; disk at 89%, 90%, 97%; storage not writable; the AI worker's heartbeat old; a check that throws. Open Home with subscriptions in each stage, limits near and at their cap, a failed backup and a service down.
- **Expected:** a slow database (over 1000 ms) is degraded; disk at 90% is degraded and at 97% down (an unreadable disk is degraded); unwritable storage is shown; with jobs on only the worker's heartbeat counts; a check that throws is down and does not stop the rest; the overall status is the worst. Home lists the subscription stages with the urgent first, limits near and at cap, a backup problem as urgent, a service down with a link to the status page; nothing when all is fine; the snapshot counts and recent activity are right.
- **Already automated:** `tests/unit/SystemHealthTest.php`, `tests/database/AdminHomeTest.php`, `tests/feature/AdminMonitoringPageTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-03)
- **Basis:** `SystemHealth`, `AdminHome`
- **QA status:** VCI (PS-MON-02, PS-MON-03)
- **Review:** Draft

## TC-SA-48 The backup age alert, and the dump password

- **Role:** System administrator. **Priority:** P2
- **Data:** `backupAlertHours` 24 (the default); the last good backup 23 h 59 min and 24 h 1 min old
- **Steps:** open Backups and Home at each age; set the setting to 26 and look again. Make `mysqldump` fail with the password in its command.
- **Expected:** the alert appears once the last good backup is older than the setting (24 hours by default), and the administrators are told once; the setting moves the threshold. The database password never appears in an error, the backup row or the log, and a failing tool leaves no file.
- **Already automated:** `BackupServiceTest::testTheAlertFollowsTheHistory`, `tests/database/MysqldumpRunnerTest.php`
- **Playwright:** no
- **Basis:** `BackupStatus::assess`, `MysqldumpRunner`; register Part H (the code's 24 hours supersedes the 26 in the sign-off)
- **QA status:** VCI (PS-BAK-07, PS-BAK-08)
- **Review:** Draft

## TC-SA-49 Creating an institution: its code and name

- **Role:** System administrator. **Priority:** P2
- **Steps:** onboard with codes `E2E`, `e2ecol` (an existing code in another case), a deleted institution's code, and codes at and past the length limit; names of 1 and 2 characters (record the minimum); rename one; try to change a code.
- **Expected:** a duplicate code is refused even in another case and even when the other institution is deleted; a created institution starts pending; the code never changes after creation; a too-short name is refused; renaming is audited with before and after, and renaming to the same name does nothing.
- **Already automated:** `tests/database/InstitutionServiceTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-04)
- **Basis:** `InstitutionService`
- **QA status:** VCI (PS-INS-01)
- **Review:** Draft

## TC-SA-50 Renewals and corrections, and a history nobody can rewrite

- **Role:** System administrator. **Priority:** P2
- **Steps:** renew with an end date before the current end, on the same date, and after; move to another active plan and to a retired plan; stay on a retired plan; record a correction of the payment; renew after a lapse. Then try to update, save over and delete a history row (model and SQL through the app's model).
- **Expected:** a renewal must end after the current end and may move to an active plan (or stay on its plan, even a retired one); a correction changes only the payment; each adds a history row, newest first; history rows can never be edited or deleted.
- **Already automated:** `tests/database/SubscriptionServiceTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-07)
- **Basis:** `SubscriptionService`
- **QA status:** VCI (PS-SUB-02, PS-SUB-08)
- **Review:** Draft

## TC-SA-51 A renewal keeps a manual suspension; a restore follows the dates

- **Role:** System administrator. **Priority:** P2
- **Steps:** suspend an active institution by hand, then record a renewal. Delete an institution, move its subscription's end into the past, and restore it.
- **Expected:** the renewal does not lift the manual suspension; the restored institution shows the status its dates give (read-only or past), not the one it had.
- **Already automated:** `ReadOnlyOwnThingsTest::testARenewalRecordedBeforeTheJobRunsAllowsWritingAtOnce` (the normal renewal)
- **Playwright:** no
- **Basis:** `SubscriptionService::statusAfterRenewal`, `EffectiveStatus`; decisions AMB-OPS-01, AMB-OPS-02
- **QA status:** IBR (PS-INS-08, PS-INS-09)
- **Review:** Draft

## TC-SA-52 Lowering a plan below what an institution uses keeps what it has and stops growth

- **Role:** System administrator, institutional administrator. **Priority:** P2
- **Data:** institution 1 with 9 students on a plan of 10 seats
- **Steps:** lower the plan to 5 seats; as the institution, open People and the courses; invite a student, reactivate one, enrol one; drop students until 4 remain and invite again.
- **Expected:** existing students stay enrolled and active; anything that adds a seat is refused while usage is at or above the new limit; it works again once usage is below it.
- **Already automated:** none for a lowered plan
- **Playwright:** no
- **Basis:** `PlanLimits`; decision AMB-OPS-06
- **QA status:** IBR (PS-SUB-06)
- **Review:** Draft

## TC-SA-53 An institution's own expiry timeline may only be stricter

- **Role:** Institutional administrator. **Priority:** P2
- **Steps:** set the institution's warning, grace and read-only days shorter than the platform's, then longer.
- **Expected:** shorter values are accepted and used for this institution's subscription stages; longer ones are refused naming the field.
- **Already automated:** `InstitutionSecurityTest` (stricter-only rules)
- **Playwright:** no
- **Basis:** `InstitutionSecurity`
- **QA status:** VCI (PS-SUB-07)
- **Review:** Draft

## TC-SA-54 Which other actions ask for the password again

- **Role:** System administrator, institutional administrator. **Priority:** P2
- **Steps:** without a fresh confirmation, try each: delete an institution, suspend one, deactivate a user, reactivate one, sign a user out everywhere, bulk reactivate, change a per-institution flag, test and preview an integration.
- **Expected:** each asks for the password first (decision AMB-AUTH-05), except changing a flag for one institution, which `AdminFlagsPageTest::testAnOverrideDoesNotNeedThePasswordAndShowsItsReason` pins as needing none (register Part I4). Reopening a sign-off asks for a reason only.
- **Already automated:** the routes with the `reauth` filter have their own tests
- **Playwright:** no
- **Basis:** decision AMB-AUTH-05. Today restore, purge and sign-off ask; the actions listed are suspected not to [audit agent; not run].
- **QA status:** REG? (PS-REA-06) **Defect confirmed 2026-10-28** by reading the code (`docs/qa/defects-2026-10-28.md`). **Fixed in `57a9c92` on `fix/qa-defects`** (2026-10-29), not yet merged to `main`; expected to pass there.
- **Review:** Draft

## TC-SA-55 The database refuses impossible values

- **Role:** (database level). **Priority:** P2
- **Steps:** insert directly: an enrolment of a non-student; two students with the same `student_number` in one institution (and in two); `progress_percent` 101; `passing_percentage` 101; negative units; capacity 0; a term ending before it starts; two settings rows with the same key; two responses for one question of one attempt; a prerequisite cycle (through the app).
- **Expected:** a duplicate `student_number` within one institution is refused (two institutions may share one); range rules (percent 0–100, units ≥ 0, capacity ≥ 1, end after start) are refused by the database where they are row rules, and by the app with a clear message; duplicate settings rows are refused by their natural key; one response per question (already enforced since `5140068`); a non-student enrolment and a prerequisite cycle are refused by the app.
- **Already automated:** `QuizTwoTabsTest::testTheDatabaseKeepsOneAnswerPerQuestion`; the rest none
- **Playwright:** no
- **Basis:** decision MISS-DB-01. Suspected missing for most (inventory section 12) [audit agent; not run].
- **QA status:** REG? (PS-DAT-04) **Defect confirmed 2026-10-28** by the database schema (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

## TC-SA-56 Seeds and the first administrator

- **Role:** (deploy). **Priority:** P2
- **Steps:** run `php spark db:seed DatabaseSeeder` twice on an empty test database; run the demo seeder with the production environment; run `php spark admin:create` on a server with a placeholder email, a real-looking one, mismatched passwords and an invalid email.
- **Expected:** a second seed run changes nothing; the demo seeder refuses in production; `admin:create` refuses a placeholder email on a server and creates nothing, accepts a real one (flagging mail not set), asks for the password twice and hidden, and creates nothing on a mismatch or an invalid email.
- **Already automated:** `tests/database/CreateSystemAdminCommandTest.php`
- **Playwright:** no
- **Basis:** `app/Database/Seeds/`, `CreateSystemAdmin`
- **QA status:** VCI (PS-DAT-06)
- **Review:** Draft

---

## Wave 4 (added 2026-10-28)

P3 scenarios. QA status as in the README; none has been run.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-SA-57 | During maintenance the institution's administrators keep working, and only the exact open paths stay open | Every role | P3 | REG? |
| TC-SA-58 | Retiring and reinstating a plan | System administrator | P3 | VCI |
| TC-SA-59 | Turning a flag on or off needs a note, and an end date cannot be in the past | System administrator | P3 | VCI |
| TC-SA-60 | The smoke-test script after a deploy | (deploy) | P3 | VCI |
| TC-SA-61 | What empty values mean, and which grading scheme applies | (database) | P3 | IBR, VCI |

## TC-SA-57 During maintenance the institution's administrators keep working, and only the exact open paths stay open

- **Role:** Every role. **Priority:** P3
- **Steps:** turn maintenance mode on. As the institution's administrator, sign in and use their area; as a student, open a page. Open addresses that only contain an open word in another segment: `/student/courses/1/login-help`, `/auth-report`, `/x/health/y`.
- **Expected:** the system administrator and the institution's administrators keep working; everyone else sees a "system under maintenance" page (503 with Retry-After); only the exact open paths (sign-in, sign-out, health, the reset and invitation links, the worker) stay open: a segment that merely contains the word does not.
- **Already automated:** `GeneralSettingsTest` (maintenance tests)
- **Playwright:** no
- **Basis:** decisions AMB-AUTH-03, AMB-AUTH-09. Today only `admin` and impersonators bypass, and a path matches if any segment equals an open word (`MaintenanceFilter`) [read in the code; not run].
- **QA status:** REG? (PS-ROM-08, PS-ROM-09) **Defect confirmed 2026-10-28** by reading the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

## TC-SA-58 Retiring and reinstating a plan

- **Steps:** retire a plan in use; onboard a new institution and look for it; renew an institution on it; reinstate it. Create plans with a duplicate name in another case, an unknown feature, and empty limits.
- **Expected:** a retired plan cannot be chosen for new institutions but those on it keep it; reinstating offers it again; names are unique ignoring case; an unknown feature is refused and named; empty limits mean unlimited; changes are audited with before and after.
- **Already automated:** `tests/database/PlanServiceTest.php`
- **Playwright:** no
- **Basis:** `PlanService`
- **QA status:** VCI (PS-SUB-05)
- **Review:** Draft

## TC-SA-59 Turning a flag on or off needs a note, and an end date cannot be in the past

- **Steps:** turn a flag on for institution 1 without a note, with a note; set an end date of yesterday, today; set it back to default without a note; use an unknown flag; a deleted institution.
- **Expected:** a note is required for on or off (trimmed and bounded), not for going back to default, which removes the row; the last day must be today or later; unknown flags and missing or deleted institutions are refused; one institution's change does not touch another's.
- **Already automated:** `tests/database/FeatureFlagAdminTest.php`
- **Playwright:** no
- **Basis:** `FeatureFlagAdmin`
- **QA status:** VCI (PS-FLG-02)
- **Review:** Draft

## TC-SA-60 The smoke-test script after a deploy

- **Steps:** run the deploy's smoke-test script against a healthy test site, then against one with: the health check degraded, a login page without its form, a missing or empty asset, a protected page that does not redirect, a server error, the site down. Watch the uptime workflow's issue.
- **Expected:** a healthy site passes the four checks; each fault fails with its reason; the uptime issue opens on failure and closes on recovery.
- **Already automated:** `tests/unit/SmokeTestScriptTest.php`
- **Playwright:** no
- **Basis:** `.github/scripts/smoke-test.php`, `.github/workflows`
- **QA status:** VCI (PS-CI-03)
- **Review:** Draft

## TC-SA-61 What empty values mean, and which grading scheme applies

- **Role:** (database level, read through the app). **Priority:** P3
- **Steps:** make a notification with no institution (a platform notice) and a follow-up with student 0; list an institution's notifications and a student's follow-ups; run a report. Open a new section's grades in an institution with its own defaults and one without; switch a person's `active_role` on the database to a role they do not hold and sign in. Save a department with established year 1799 and the current year.
- **Expected:** a notification with no institution is platform-wide and never appears in an institution's list as theirs; a follow-up with student 0 is about no student and never in a student's results; the scheme is the section's own, else a copy of the institution's defaults, else the platform's, never another institution's; an unheld active role falls back to the default; `program_id` is used for students only; the year must be 1800 to the current year, in the form and the service.
- **Already automated:** `AcademicDefaultsTest`, `ActiveRoleServiceTest::testAStaleSessionRoleNoLongerHeldIsIgnored`, `StructureServiceTest`
- **Playwright:** no
- **Basis:** decisions AMB-DB-04 to 07 (register Part I3)
- **QA status:** IBR (PS-DAT-08), VCI (PS-DAT-09)
- **Review:** Draft
