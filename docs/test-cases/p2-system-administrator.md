# P2 test cases: the system administrator

12 cases, automated in `tests/e2e/system-admin.spec.js`, and 12 added in Wave 1 of the QA plan (2026-10-28, at the end, in the full case format). Read [README.md](README.md)
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
- **QA status:** REG (PS-BAK-06)
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
- **QA status:** FIX? (PS-JOB-06) for steps 1 and 3. Step 2 is suspected to fail (REG?): `ParentsNotify::run` records the heartbeat whatever exit code the work returned; only an exception skips it [read in the code; not run].
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
- **QA status:** NB (PS-SEC-08)
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
- **QA status:** NB (PS-SEC-09)
- **Review:** Draft. Expected result set by MISS-AUTH-03.
