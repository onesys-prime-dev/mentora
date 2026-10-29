# Audit Plan 8: The Deferred Minors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every minor finding that the final reviews of audit plans 1–7 left open, each with a test that fails without its fix.

**Architecture:** Small, independent fixes, grouped by the plan that deferred them, so each task touches one area. No new subsystem. Where a reviewer offered "accept it", this plan fixes it anyway, because the user asked for the open items to be fixed.

**Tech Stack:** CodeIgniter 4.7 + Shield, PHP 8.2, MySQL, PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`), Vitest (jsdom), GitHub Actions YAML.

**Spec:** the deferred-minor lists of the final reviews of plans 1–7, recorded in memory (`project_audit-fix-plans.md`) and checked against the code at 2154a2f, plus `docs/audit-2026-10-23-decisions.md` for the rules they refer to (8.2, D9, D11, 3.1, 9.1, 15.1).

## Global Constraints

- Branch `fix/deferred-minors`, from 2154a2f. Never merge to `main`. No commits by Claude: stage, then give a commit message.
- Never set the database or any setting through environment variables on the command line: `.env` wins. Tests set settings with `Tests\Support\TestEnv`.
- The full PHPUnit suite takes about 25 minutes: run `vendor/bin/phpunit --no-coverage` in the background, and never alongside Playwright.
- Keep each file's line endings (most are LF; `app/Config/Routes.php` and `.github/workflows/ci.yml` are CRLF). Check with `tr -cd '\r' < file | wc -c`, not `grep -c $'\r'`.
- **Copy (exact):**
  - **Quiz start failed:** "The quiz could not be started. Reload the page to try again."
  - **Answer not saved:** "Your answer could not be saved. Try again."
  - **Submit failed:** "The quiz could not be submitted. Your answers are kept; try submitting again."
  - **Admin list, dates ahead of the record:** "Recorded as {Stored label} until the nightly update."
- A UI change matches the existing pages (components, tones, the "From the SIS" and lock patterns): UI/UX weighs the same as function.

## Review Focus

1. **A CSRF expiry inside a dialog form (`dialog.js`).** The page shows the toast once, and the form's inline error, with no browser alert. Pinned in Task 1 (`csrfExpired` is false for every other error, so other alerts still show).
2. **An instructor who has `courses.manage` but not `announcements.manage`.** The calendar still offers "Add Assessment" and "Edit Deadline", without "Add Announcement". Pinned in Task 3's Vitest.
3. **A job that stalls, recovers, then stalls again before the next hourly check.** Admins get "running again" and a new "stopped", once each, and a repeat check sends nothing. Pinned in Task 6.
4. **A purge where an uploaded file's storage key is also used by a row that survives.** The disk file stays. Pinned in Task 7.
5. **A dean whose institution cleared `courses.view`.** The list page refuses, the menu has no Courses entry, and the dean dashboard still opens. Pinned in Task 3.

---

### Task 1: The CSRF wrapper (plan 1)

**Files:**
- Modify: `resources/js/shared/csrf.js` (the wrapper's `init` default; a new export)
- Modify: every `alert(result.message …)` in `resources/js/pages/**` that shows a JSON result from a POST, PUT, PATCH or DELETE (find them with `grep -rn "alert(result.message" resources/js/pages`). At 2154a2f they are in `announcements/card-editor.js`, `card.js` and `composer.js`; `assessments/modals/assessments.js`; `discussions/composer.js` and `thread.js`; `modules/modals/learning-items.js`, `learning-units.js` and `modules.js`; and `calendar/calendar.js:1178`.
- Modify: `app/Config/Events.php:65-67` (the comment only)
- Test: `tests/js/shared/csrf.test.js`

**Interfaces:**
- Produces: `export function csrfExpired(result: unknown): boolean`, in `csrf.js`. It is true when `result?.error === "csrf_expired"`, because the wrapper has already shown that toast.

- [ ] **Step 1: Write the failing tests** in `csrf.test.js`:
  - `"accepts null as init, as native fetch does"`: `await window.fetch("/x", null)` resolves, and `original` was called.
  - `"says when the toast was already shown"`:
    - `csrfExpired({ error: "csrf_expired" })` is `true`;
    - `csrfExpired({ error: "reauth_required" })`, `csrfExpired({})`, `csrfExpired(null)` and `csrfExpired(undefined)` are `false`.
- [ ] **Step 2: Run them.** `npx vitest run tests/js/shared/csrf.test.js`. Expected: FAIL. The first throws a TypeError; the second fails because `csrfExpired` isn't exported.
- [ ] **Step 3: Implement.**
  - In the wrapper, `init = init ?? {}` before `init.method` is read.
  - Add `csrfExpired`, documented as "the wrapper already told the person".
  - At each call site, write `if (!csrfExpired(result)) alert(result.message);`, importing it with the right relative path to `shared/csrf.js`.
  - Leave alerts that are not a server result alone (for example "You can attach up to 10 files.").
- [ ] **Step 4: Reword the Events.php comment.** Shield already rotates the token at sign-in outside the testing environment (`Session::startLogin`). This hook makes it hold in every environment, tests included, and a second rotation is harmless.
- [ ] **Step 5: Run.** `npx vitest run tests/js/shared/csrf.test.js`, then `npm test`. Expected: PASS.
- [ ] **Step 6: Stage.** Message: `fix: fetch accepts null options, and an expired token is told once`

---

### Task 2: One institution leftovers (plan 2)

**Files:**
- Modify: `app/Services/Institutions/InstitutionService.php:190`. `delete()` refuses by `EffectiveStatus::of($id)`, not the stored status.
- Modify: `app/Services/Institutions/InstitutionService.php` `search()`, by adding `subscriptions.end_date` to the select. Each row gains `effective_status`, from `EffectiveStatus::combine($row['status'], $row['end_date'], (int) $row['id'], today)`.
- Modify: `app/Views/pages/admin/institutions/index.php:43`. The badge shows `effective_status`. When it differs from `status`, a muted line under it reads "Recorded as {Stored label} until the nightly update." The status filter keeps matching the recorded status.
- Create: `app/Database/Migrations/2026-11-19-000001_DropRedundantInstitutionUserKey.php`. It drops `institution_user_unique (institution_id, user_id)`. If the foreign key on `institution_id` needs an index, it first adds the plain index `institution_users_institution_id`. `down()` puts the composite key back.
- Modify: `app/Filters/ReadOnlyFilter.php:31`. The reminders pattern becomes `'#^(student|instructor|chairperson)/api/calendar/reminders(/\d+(/done)?)?$#'`.
- Test: `tests/database/InstitutionDeleteTest.php` (or the existing delete test class, if `grep -rln "Suspend it before deleting" tests` finds one), `tests/feature/AdminInstitutionsListTest.php` (or the existing list test), `tests/database/InstitutionUsersKeysTest.php`, the existing `ReadOnlyOwnThingsTest`, and `tests/feature/RenewalBeforeTheJobTest.php`.

- [ ] **Step 1: Write the failing tests:**
  - `testAnInstitutionPastByItsDatesCanBeDeletedBeforeTheJobRuns`. The stored status is `active`, and the subscription ended beyond its grace and read-only period. `delete()` with the exact name succeeds, and the row has `deleted_at`.
  - `testAnInstitutionActiveByItsDatesIsStillRefused`. The message is "This institution is active. Suspend it before deleting it."
  - `testTheListShowsWhatTheDatesSayAndWhatIsRecorded`. Stored `active`, dates read-only: the page contains the Read-only badge and "Recorded as Active until the nightly update."
  - `testOnlyTheOneMembershipKeyIsLeft`. `SHOW INDEX FROM institution_users` has no `institution_user_unique`, and still has a unique key on `user_id`.
  - `testANestedPathUnderRemindersIsNotOwnAccount`, in `ReadOnlyOwnThingsTest`. In a read-only institution, a POST to `student/api/calendar/reminders/5/anything` is refused like any write. `reminders`, `reminders/5` and `reminders/5/done` are still allowed.
  - `testARenewalRecordedBeforeTheJobRunsAllowsWritingAtOnce`. The institution is read-only by its dates and stored as `read_only`. Record a renewal through `SubscriptionService` (no job run). An institution admin's POST that read-only refuses, such as adding an offering, now succeeds.
- [ ] **Step 2: Run them.** Expected: the first, third, fourth and fifth FAIL. The renewal test may already pass, since it pins behaviour that already exists; record that in the ledger.
- [ ] **Step 3: Implement as listed above.** Then run `php spark migrate`. Never set the database through the command line.
- [ ] **Step 4: Run them, and the institution, admin and read-only suites** (`--filter 'Institution|ReadOnly|Subscription|Admin'`). Expected: PASS.
- [ ] **Step 5: Stage.** Message: `fix: delete and the admin list go by the institution's dates, one membership key, and a tighter own-account path`

---

### Task 3: Permissions leftovers (plan 3)

**Files:**
- Modify: `app/Views/pages/calendar/index.php:57-62`. Add `data-abilities="<?= esc(json_encode(\App\Services\Courses\CourseAbilities::for($user)), 'attr') ?>"` for instructor and chairperson calendars.
- Modify: `resources/js/pages/calendar/calendar.js:695-776`.
  - "Add Assessment" and "Edit Deadline" (the `editLinks`, and the click at 776) need `abilities.content`.
  - "Add Announcement" needs `abilities.announcements`.
  - With no attribute, `abilities` defaults to all false. Read-only stays as it is for parents.
- Modify: `app/Config/Routes.php:325`. `dean/courses` gets `['filter' => 'permission:courses.view']`.
- Modify: `app/Helpers/navigation_helper.php:62-79`. The dean's `courses` entry is dropped when `allows($user, 'courses.view')` is false, the way `monitor-department` is.
- Modify:
  - `tests/_support/DatabaseTestCase.php` `setUp()`: call `\App\Services\Auth\RolePermissions::flush()`.
  - `app/Config/Events.php`: `Events::on('pre_system', [\App\Services\Auth\RolePermissions::class, 'flush'])`.
- Modify: `app/Controllers/Institution/RolesController.php:57-59`. A non-string `role` or `permission`, or a `granted` that is neither null nor a string, gets the redirect and message the controller already gives for an unknown role or permission.
- Modify: `app/Services/Institutions/InstitutionRoles.php:102-112`. `replaceRow`:
  - for a value, one statement: `INSERT … ON DUPLICATE KEY UPDATE granted = VALUES(granted)`, through the query builder's `upsert()` or a bound raw query;
  - for `null`, the delete.
- Modify: `docs/deploy.md`, step 6. Add one line: `2026-11-14-000002_RemoveInapplicablePermissionRows` cannot be rolled back, because its rows are not restored (they were ignored anyway).
- Test: `tests/js/pages/calendar-abilities.test.js` (mount as in `calendar-read-only.test.js`), `tests/feature/DeanCoursesPermissionTest.php`, `tests/feature/InstitutionRolesTest.php` (or the existing roles page test), `tests/database/InstitutionRolesTest.php` (or the existing service test).

- [ ] **Step 1: Write the failing tests:**
  - **Vitest:**
    - as an instructor with `{content: true, announcements: false}`, the day panel has `add-assessment` and no `add-announcement`;
    - with `{content: false, announcements: true}`, it has `add-announcement` and no `add-assessment` or edit-deadline link;
    - with no attribute, it has neither.
  - **PHP:**
    - `testADeanWithoutCoursesViewIsRefusedTheListAndHasNoMenuEntry`: the list gives a 403 or a redirect, the same as the dean's other course pages. The dashboard's Apps menu has no `dean/courses` link, and the dashboard still opens.
    - `testAnArrayShapedPostIsToldNotFailed`: a POST of `role[]=x` to `institution/roles/permissions` redirects with the error message, not a 500.
    - `testSettingABoxThatAlreadyHasARowUpdatesItInPlace`: a row is pre-inserted; setting the opposite value leaves one row, with the new value.
    - `testTheCacheIsEmptyAtTheStartOfEveryTest`: an instructor's institution is cached by a call to `allows()`. The user's `institution_users` row is then changed directly in the database, `setUp()` runs again (call `parent::setUp()` in a second test method), and the result follows the database.
- [ ] **Step 2: Run them.** Expected: FAIL, except perhaps the in-place update. That one is sequential and may already pass: the fix removes the race window by construction, and the test pins that only one row exists.
- [ ] **Step 3: Implement as listed above.**
- [ ] **Step 4: Run them, plus `npm test` and the permission suites** (`--filter 'Permission|Roles|Dean|Calendar'`). Expected: PASS.
- [ ] **Step 5: Stage.** Message: `fix: the calendar offers only what the instructor may do, the dean's course list asks its permission, and the roles page takes odd input calmly`

---

### Task 4: The chairperson race has a test (plan 4)

**Files:**
- Test: `tests/database/OneChairpersonTest.php` (exists)

- [ ] **Step 1: Write `testTwoAdministratorsAssigningAtOnceGetTheRaceMessage`.**
  - Set up the state the other administrator left: a second person's `chairperson_assignments` row for the sub-department, with `status = 'revoked'` and `active_marker = 1`. The service's "who chairs it" read misses it, but the unique key `(sub_department_id, active_marker)` doesn't. First confirm the key's columns in the migration.
  - `assign()` for another candidate throws a `StructureException` with the message "Someone else just changed the chairperson of {sub name}. Reload the page to see who chairs it now."
  - No new active row exists, and no notification was sent.
- [ ] **Step 2: Run it.** Expected: PASS, because the code already handles the race. If it fails, the race handling is broken: debug it before going on. Record the result.
- [ ] **Step 3: Stage.** Message: `test: two administrators assigning a chairperson at once`

---

### Task 5: The quiz attempt and the student check (plan 5)

**Files:**
- Modify: `app/Services/Courses/Assessments/QuizAttemptService.php`.
  - `saveAnswer` (around line 300) and `submitAttempt` (around line 415) run their transaction with `transException(true)`, and reset it to false in a `finally`.
  - A `DatabaseException`, or `transComplete()` returning false, returns `['success' => false, 'message' => …]` with the Global Constraints copy.
  - On a failed submit, no gradebook sync and no item completion run.
- Modify: `QuizAttemptService::startAttempt` (around lines 160-176). When the insert fails and no in-progress attempt is found, set `$startFailed = true`, and pass `'startFailed' => $startFailed` to the view.
- Modify: `app/Views/pages/courses/features/assessments/quiz-answer-form.php`, before the branch at line 201. When `$startFailed` is set, show the "could not be started" copy in the same card style as the `$blocked` branch (189-200), never "This attempt has already been submitted".
- Modify: `app/Services/Courses/CourseViewer.php:50-55`. `isStudentHere` returns false when `self::isReadOnlyViewer($user)` is true, before the `inGroup('student')` fallback.
- Test: `tests/database/QuizAttemptFailureTest.php` (new). It is not isolated (`$isolateTests = false`), because a trigger is DDL. It wipes what it made in `tearDown()`, like `SisImporterRollbackTest`. The `isStudentHere` test goes in the existing `CourseViewer` test (`grep -rln "isStudentHere" tests`).

- [ ] **Step 1: Write the failing tests.** Each creates the trigger it needs in the test and drops it in `tearDown()` (`DROP TRIGGER IF EXISTS`).
  - `testASubmitTheDatabaseRefusesSaysSoAndChangesNothing`. Trigger: `BEFORE UPDATE ON assessment_submissions FOR EACH ROW SIGNAL SQLSTATE '45000'`. `submitAttempt` returns `success === false` with the submit copy, and the attempt is still `In Progress`.
  - `testAnAnswerTheDatabaseRefusesSaysSo`. Trigger: `BEFORE INSERT` on the responses table (the model's table). The answer copy comes back.
  - `testAStartTheDatabaseRefusesSaysItCouldNotStart`. Trigger: `BEFORE INSERT ON assessment_submissions`. The HTML contains "The quiz could not be started. Reload the page to try again.", and not "already been submitted".
  - `testADeanWhoIsAStudentElsewhereIsNotAStudentInADepartmentCourse`. A dean who is also enrolled in another course opens a department course through the dean route (no `coursemember` filter), and `isStudentHere($dean)` is false.
- [ ] **Step 2: Run them.** Expected: FAIL. The rolled-back submit reports success, the start shows "already submitted", and the dean counts as a student.
- [ ] **Step 3: Implement as listed above.**
- [ ] **Step 4: Run them, and the quiz and course suites** (`--filter 'Quiz|Assessment|CourseViewer|ViewAs|Dean'`). Expected: PASS.
- [ ] **Step 5: Stage.** Message: `fix: a quiz that the database refuses says so, and a dean is never a student of a course they look at`

---

### Task 6: Scheduled jobs, the uptime secret, the off-site copier (plan 6)

**Files:**
- Modify: `app/Services/Monitoring/ScheduledJobs.php`. Add `public static function watched(string $command): bool`: false for a `sisOnly` job while no institution has an enabled SIS connection. `stale()` uses it.
- Modify: `app/Services/Monitoring/SchedulerCheck.php:32-52`, per command:
  - **Stale, with a recorded period equal to `$last ?? 0`:** nothing (the same period).
  - **Stale, with a recorded period that differs:** it ran and stopped again. Send "running again", for the run at `$last`, then a new "stopped" alert since `$last`, and record `$last`.
  - **Stale, with no period:** alert, and record the period only when at least one notification was sent for it. With no administrator yet, nothing is recorded, so a later check tells the first administrator.
  - **Not stale, with a period, and `! watched($command)`:** drop the period without telling anyone.
  - **Not stale, with a period, otherwise:** "running again", as today.
- Modify: `.github/workflows/uptime.yml:63`.
  - Replace `SCHEDULER_SECRET` with `SCHEDULER_SECRET_PRODUCTION: ${{ secrets.SCHEDULER_SECRET_PRODUCTION }}` and `SCHEDULER_SECRET_STAGING: ${{ secrets.SCHEDULER_SECRET_STAGING }}`.
  - In the step that uses it, choose in the shell: `if [ "$ENV_SLUG" = production ]; then SECRET="$SCHEDULER_SECRET_PRODUCTION"; else SECRET="$SCHEDULER_SECRET_STAGING"; fi`.
  - An empty secret still skips the check.
- Modify: `app/Services/Backup/ShellOffsiteCopier.php:38-56`. Keep the `proc_get_status()` result of the last poll in the loop, and read `exitcode` from it, not from a second call after the loop.
- Test: `tests/database/SchedulerCheckTest.php` (exists), `tests/unit/UptimeWorkflowTest.php` (new), `tests/unit/ShellOffsiteCopierTest.php` (or the existing copier test).

- [ ] **Step 1: Write the failing tests:**
  - `testASecondStallIsANewPeriod`. A job stalls (alert sent), then reports at T and stalls again. The next check sends one "running again" and one "stopped" per admin, and a repeat check sends nothing.
  - `testNoPeriodIsRecordedBeforeThereIsAnAdministrator`. With no admins, a stale job leaves `Monitoring.staleJobs` without it. After an admin is created, the next check alerts them.
  - `testASisJobLeftOutWhenSisIsOffIsDroppedQuietly`. `sis:sync` is stale, with a period recorded, and every SIS connection is then disabled. The check sends nothing, no notification body contains "1970", and the period is gone.
  - `testProductionNeverFallsBackToTheStagingSecret`. The workflow file contains no `secrets.SCHEDULER_SECRET_PRODUCTION ||`, and contains the shell choice on `ENV_SLUG`.
  - `testACommandThatSucceedsIsASuccess`. The copier runs a command that exits 0 (`php -r "exit(0);"`) and throws nothing. A command that exits 3 throws a message containing "(exit 3)".
- [ ] **Step 2: Run them.** Expected: FAIL. The success test fails on PHP 8.2 only, so on 8.5 it is a pin: record which.
- [ ] **Step 3: Implement as listed above.**
- [ ] **Step 4: Run them, and the monitoring and backup suites** (`--filter 'Scheduler|ScheduledJobs|Monitoring|Backup|Offsite'`). Expected: PASS.
- [ ] **Step 5: Stage.** Message: `fix: a second stall is reported, nothing is lost before the first administrator, production gets only its own secret, and an off-site copy that works is a success`

---

### Task 7: A purge keeps a file another row still uses (plan 7)

**Files:**
- Modify: `app/Services/Institutions/InstitutionService.php`.
  - `personal()` returns `[accounts, keys, exportPaths]`: the storage keys of the files and the logo, and the export zips' paths.
  - After the commit, `purge()` keeps the keys that no remaining `files` row references (`whereIn('storage_key', $keys)`), turns those into paths with `StorageService::path`, adds the export paths, and calls `deleteFiles()`.
- Test: `tests/database/InstitutionPurgeFilesTest.php` (exists)

- [ ] **Step 1: Write `testAFileStillUsedByAnotherRowStaysOnDisk`.**
  - A purged account owns a file. A user of another institution owns a second `files` row with the same `storage_key`.
  - After the purge, the account's row is gone, the other row remains, and the disk file still exists.
- [ ] **Step 2: Run it.** Expected: FAIL (the disk file is deleted).
- [ ] **Step 3: Implement as listed above.**
- [ ] **Step 4: Run it, and the purge suites** (`--filter 'InstitutionPurge'`). Expected: PASS.
- [ ] **Step 5: Stage.** Message: `fix: a purge keeps a file on disk that another record still uses`

---

### Task 8: Full check and docs

- [ ] **Step 1: Run every suite, one at a time.** `vendor/bin/phpunit --no-coverage` (in the background), then `npm test`, then `npm run build && npm run e2e`. Expected: PASS everywhere.
- [ ] **Step 2: Docs:**
  - `docs/monitoring.md`: a second stall, before any administrator exists, and SIS jobs when SIS goes out of use.
  - `docs/institutional-admin-area.md` or `docs/roles-and-permissions` (wherever the dean's menu is described): the dean's Courses entry follows `courses.view`.
  - `docs/testing.md`: `RolePermissions` is flushed in `DatabaseTestCase::setUp()`.
- [ ] **Step 3: Stage the plan file and the docs.** Message: `docs: the deferred minors of the audit fixes`
