# Audit Plan 7 — Tests Independent of .env; Lesson Links for Read-Only Viewers; Purge Removes Everything Personal; Class Schedules With SIS Class Times Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The last four findings.
- **Finding 14:** the PHP suite passes whatever a developer's `.env` says, and CI proves it with a filled-in one (14.1, 14.2).
- **Finding 5:** a dean's or institution admin's link to a lesson opens the course on it, read-only (5.1–5.3).
- **Finding 10:** purging an institution removes its accounts, their files on disk and its export zips, and keeps the audit log (10.1–10.5).
- **Finding 13:** an institution admin enters each section's weekly class times, with clash warnings, unless the SIS sends them, in which case they come from the SIS and are locked (13.1–13.5, U4, D8, D9).

**Architecture:**
- **Settings in tests:** a `TestEnv` helper sets a setting in `$_ENV`, `$_SERVER` and `putenv` together and restores all three, since CI4's `env()` reads `$_ENV` first. Every test that sets a setting uses it.
- **Route checker:** a test checks every route's arguments against its handler's parameters, so a mismatch can't come back. The lesson links reuse `CourseController::view` with the lesson id as a checked fourth argument.
- **Purge:** it collects the accounts, file keys and export zips first, removes the database rows in one transaction, then deletes the files from disk. A file that couldn't be deleted is retried by the next purge run.
- **Class schedules:** a `ClassScheduleService` owns `course_schedules` (validation and clashes). A "Class times" page per section edits it, and the SIS gets a seventh dataset, `class_schedules`. A tracking table records which sections the SIS owns, as `sis_staff_assignments` does for staff.

**Tech Stack:** CodeIgniter 4.7 + Shield, MySQL, PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`, `PeopleFactory`, `CourseFactory`, `DeanFactory`, `SisFixture`), GitHub Actions (`ci.yml`), Playwright.

**Spec:** `docs/superpowers/specs/2026-10-23-audit-fixes-design.md` § Findings 5, 10, 13, 14. Decisions: `docs/audit-2026-10-23-decisions.md` 5.1–5.3, 10.1–10.5, 13.1–13.5, 14.1–14.2, U4, D8, D9.

## Global Constraints

- **Branch and commits:** branch `fix/links-purge-schedules`, created from `fix/jobs-backups` at 5069228. No commits by Claude: each commit step stages files and states the message.
- **Line endings:** CRLF files are edited with the Edit tool, or with a Python script written to a file with the Write tool, preserving each file's line endings (shell heredocs collapse backslashes). Never `sed -i`. New files are LF.
- **Settings:** never set the database (or any setting) for a command through the environment on the command line: `.env` wins (plan 6 incident).
- **Lesson links (5):**
  - **The links:** `dean/courses/(:num)/(:segment)/modules/items/(:num)` and `institution/courses/(:num)/(:segment)/modules/items/(:num)` open the course page on the Modules tab with that lesson open, read-only, as the instructor's link does.
  - **Drafts (5.2):** a draft lesson is shown with its "Draft" chip (`publish-chip`).
  - **Not found (5.3):** a deleted lesson, a lesson of another course, and a course out of reach all answer the app's 404 page.
- **Purge (10):**
  - **What goes:**
    - the institution (the existing cascade);
    - every account whose only membership is this institution (never a system administrator), with its profile, notifications, reminders and files (the database cascades from `users`);
    - the disk files of every `files` row owned by those accounts;
    - the institution's export zips (`institution_exports.file_name` in `InstitutionExport::folder()`).
  - **What stays:** audit entries (10.5). An account with another membership is kept; with D1 there is none, so this is a defensive check.
  - **Disk deletes after the commit:** one that fails is logged and kept in the setting `Purge.pendingFiles` (a JSON list of paths), which the next `purgeExpired` run retries first.
  - **Audit:** the `institution.purged` entry gains `accounts` and `files` counts.
- **Class times (13, U4):**
  - **Shape:** weekly rows of day (`mon`–`sun`), start, end, room (up to 50 characters, optional) and delivery mode (`onsite` | `online` | `hybrid`, default `onsite`), in the platform's time zone, nothing stored about zones. No one-off exceptions.
  - **Copy:**
    - **Bad times:** "Row {n}: the end time must be after the start time."
    - **Same start twice:** "Row {n}: the section already meets on {Day} at {H:i}." (the table's unique key)
    - **Room clash (a warning; it still saves):** "Room {room} is also used by {course_code} {section} on {Day} {H:i}–{H:i}."
    - **Instructor clash (a warning; it still saves):** "{name} also teaches {course_code} {section} on {Day} {H:i}–{H:i}."
    - **Lock:** "These class times come from your student information system. Change them there; the next sync brings them here." with a "From the SIS" badge.
  - **Clash rules:** a clash is an overlap (`start < other_end AND end > other_start`) on the same day in an offering of the same term. A room clash needs the same room, compared ignoring case, and is skipped when the room is empty or the mode is `online`. An instructor clash means an instructor assigned to both sections.
- **The SIS `class_schedules` dataset (D8, D9):** `class_schedules.csv`.
  - **Columns:** required `course_external_id`, `day_of_week`, `start_time`, `end_time`; optional `section` (`A` when empty), `room`, `delivery_mode`. Unique on course, section, day and start.
  - **Values:** `day_of_week` takes `mon`–`sun` or the full English day name, in any case. Times are `HH:MM`, 24-hour.
  - **D9 behaviour:**
    - a section in the file gets exactly its rows, and the SIS then owns it;
    - a SIS-owned section missing from the file loses its SIS times, and ownership is released;
    - a section the SIS never sent is untouched;
    - with no file, nothing changes.
  - **Ownership:** the lock holds only while the connection is enabled. The run log reports clashes as warnings.
  - **Counts per dataset:** `created`, `updated`, `skipped`, `failed`, plus `removed` (sections whose SIS times were cleared). A preview shows them too.
- **Known local failures:** after Task 1, none. The six `AiWorkerEndpointsTest` failures were finding 14. The full suite takes about 25 minutes, so run `vendor/bin/phpunit --no-coverage` in the background, and never alongside Playwright.

## Review Focus

- **A `.env` with every setting filled** (CI's second run): every test that relies on a setting being unset must set it itself.
- **A lesson link to a draft lesson, as a dean:** it opens, and the draft is marked "Draft". A student's link to the same draft is still not found.
- **Purging an institution whose admin uploaded the logo:** the logo file goes with the account, and a file of another institution's user is never touched.
- **A class that ends at midnight or runs past it:** `end_time` must be after `start_time` on the same day, so 23:00–00:00 is refused with the row's message.
- **An SIS file that sends a section twice at the same day and start:** the second row fails, the first is kept, and the run continues.

---

### Task 1: The test suite does not depend on the local .env (14.1)

**Files:**
- Create: `tests/_support/TestEnv.php`, with `final class TestEnv`:
  - `public static function set(string $name, string $value): void` sets `$_ENV[$name]`, `$_SERVER[$name]` and `putenv("{$name}={$value}")`, remembering each previous value;
  - `public static function unset(string $name): void` removes all three, also remembered;
  - `public static function restore(): void` puts every remembered value back.
- Modify: every test that writes `$_SERVER[...]`, `$_ENV[...]` or `putenv()` for a setting uses `TestEnv`, and calls `TestEnv::restore()` in `tearDown`.
  - The files the design names: `tests/feature/AiWorkerEndpointsTest.php`, `tests/database/MysqldumpRunnerTest.php`, `tests/feature/InstitutionCsvUploadTest.php`, `tests/unit/AiGenerateModalViewTest.php`, `tests/_support/FakeBackupRunner.php`.
  - Also every other file the search `putenv|\$_SERVER\[|\$_ENV\[` finds that sets a setting: `SisFixture.php`, `RouteTestCase.php`, `AdminIntegrationsTest.php`, `HealthEndpointTest.php`, the export tests, `SchedulerCheckEndpointTest.php` and `SisConnectionServiceTest.php`.
  - `$_SERVER['REMOTE_ADDR']`, `HTTPS` and other request values stay as they are.
- Test: `tests/unit/TestEnvTest.php`.
  - `testASettingIsSeenByEnvWhateverDotEnvSays`: with `$_ENV['questionBuilder.workerSecret']` pre-set to `from-dotenv`, `TestEnv::set(…, 'from-test')` makes `env('questionBuilder.workerSecret')` return `from-test`.
  - `testUnsetHidesADotEnvValue`: `env(…, 'none')` returns `none`.
  - `testRestorePutsTheDotEnvValueBack`.

- [ ] **Step 1: Write `TestEnvTest` (the failing test).**
- [ ] **Step 2: Run it, and `AiWorkerEndpointsTest`.** Expected: `TestEnvTest` FAILS because the class doesn't exist; `AiWorkerEndpointsTest` shows today's 6 failures, because the local `.env` sets `questionBuilder.workerSecret`.
- [ ] **Step 3: Implement `TestEnv`, and move every listed file to it.**
- [ ] **Step 4: Run `TestEnvTest`, `AiWorkerEndpointsTest`, and every file changed.** Expected: PASS. `AiWorkerEndpointsTest` passes 6/6 with the local `.env` unchanged.
- [ ] **Step 5: Stage.** Message: `fix: the tests set the settings they need, whatever the local .env holds`

---

### Task 2: CI also runs the suite with a filled-in .env (14.2)

**Files:**
- Create: `tests/_support/ci-full.env`. It holds every key of the repository's `env` template uncommented, with harmless test values:
  - secrets: random-looking strings;
  - `backup.offsiteCommand`: `/bin/false`;
  - `scheduler.checkSecret`: `ci-secret`;
  - `questionBuilder.useJobs`: `true`;
  - URLs: `http://127.0.0.1:9`.

  The database keys match CI's test database.
- Modify: `.github/workflows/ci.yml`, job "Tests on MySQL", with a step after "Run tests": "Run the tests with every setting filled in". It runs `cp tests/_support/ci-full.env .env && vendor/bin/phpunit --no-coverage`, only for the matrix row with `--no-coverage`.
- Test: `tests/unit/CiFullEnvTest.php`, `testItSetsEveryKeyOfTheTemplate`: every `# key =` / `key =` name in `env` appears as a set key in `ci-full.env`.

- [ ] **Step 1: Write `CiFullEnvTest`.**
- [ ] **Step 2: Run it.** Expected: FAIL, because the file doesn't exist.
- [ ] **Step 3: Write `ci-full.env` and the CI step.**
- [ ] **Step 4: Run it, and run the full suite locally with `ci-full.env` copied over `.env`**, backing up and restoring the real `.env` around the run (with `cp`, never via environment variables). Expected: the test passes, and the full run with the filled-in `.env` passes, apart from anything this run finds. Each failure is fixed with `TestEnv` in the test that relied on a setting being unset, and ledgered.
- [ ] **Step 5: Stage.** Message: `ci: run the PHP tests again with every setting filled in`

---

### Task 3: Read-only lesson links open the course on the lesson (5.1–5.3)

**Files:**
- Modify: `app/Config/Routes.php:375` and `:753`. Both handlers become `'Courses\CourseController::view/$1/$2/modules/$3'`, with the same filters.
- Modify: `app/Controllers/Courses/CourseController.php::view(int $offeringId, string $slug, string $activeFeature, ?int $learningItemId = null)`. With an item id, the item must belong to the offering (`learning_items` → `learning_units` → `course_modules.offering_id`, any status, not deleted), or `PageNotFoundException`. The view gets `openItemId` and opens it the way the instructor's link does (check how `modules/items/(:num)` is read today, and use the same mechanism).
- Modify: the instructor, student and chairperson item routes (`:64`, `:136`, `:493`) pass `$3` too, so a wrong item is a 404 for everyone (5.3).
- Create: `tests/unit/RouteHandlersTest.php`, `testEveryRouteFitsItsHandler`. For every route in `service('routes')->getRoutes(<each verb>)` whose handler is a `Controller::method`:
  - the class and method exist;
  - the number of arguments given is at least the method's required parameters and at most all of them;
  - each argument passed to an `int` parameter is a `$n` from a numeric placeholder (`(:num)` / `([0-9]+)`) or a literal integer.

  It names each route that breaks a rule.
- Test: `tests/feature/ReadOnlyLessonLinkTest.php`, for both the dean and the institution admin:
  - `testTheLinkOpensTheCourseOnTheLesson` (200, view-only banner, the item id handed to the page);
  - `testADraftLessonOpensAndIsMarkedDraft` (5.2);
  - `testADeletedLessonIsNotFound`;
  - `testAnotherCoursesLessonIsNotFound`;
  - `testACourseOutOfReachIsNotFound`.

  Also `testAStudentsLinkToADraftLessonIsNotFound`, from Review Focus.

- [ ] **Step 1: Write `RouteHandlersTest` and `ReadOnlyLessonLinkTest`.**
- [ ] **Step 2: Run them.** Expected: `RouteHandlersTest` FAILS naming the two read-only lesson routes; the link tests fail with a 500.
- [ ] **Step 3: Implement as listed.**
- [ ] **Step 4: Run them, and the course page suites** (`grep -rlE "modules/items|CourseController|DeanCourseView|InstitutionCourseView" tests`). Expected: PASS.
- [ ] **Step 5: Stage.** Message: `fix: a dean's or administrator's lesson link opens the course on that lesson, read-only`

---

### Task 4: Purging an institution removes its accounts, their files and its exports (10.1–10.5)

**Files:**
- Modify: `app/Services/Institutions/InstitutionService.php::purge` (`:258-296`), per Global Constraints:
  1. Collect the account ids (`institution_users` of this institution, without the `admin` group and with no other membership), the storage paths (`StorageService::path(storage_key)` of their `files` rows) and the export zip paths.
  2. Write the audit entry (as today, with the counts).
  3. In one transaction, delete the institution and then those `users` rows.
  4. After the commit, unlink every path; a failure is logged and added to `Purge.pendingFiles`.

  The docblock changes to match.
- Modify: `purgeExpired` retries `Purge.pendingFiles` first, removing the paths that are gone.
- Modify: `tests/database/InstitutionPurgeTest.php::testUserAccountsAreKeptButTheirMembershipGoes` becomes `testTheInstitutionsAccountsAreRemoved`.
- Test: `tests/database/InstitutionPurgeFilesTest.php`:
  - `testAccountsTheirFilesOnDiskAndExportZipsAreRemoved`: two members, each with a file on disk, plus an export zip; after the purge, no user rows, file rows, disk files or zip remain.
  - `testAnotherInstitutionsFilesAndAccountsStay`.
  - `testTheLogoGoesWithItsUploader`.
  - `testAuditEntriesStay`.
  - `testAnAccountWithAnotherMembershipIsKept` (the defensive case; insert the second membership directly).
  - `testAFileThatCouldNotBeDeletedIsRetriedByTheNextRun`: make the path a directory so the unlink fails, then remove the directory and run `purgeExpired`, and the pending list is empty.
  - `testASystemAdministratorIsNeverRemoved`.

- [ ] **Step 1: Write the tests.**
- [ ] **Step 2: Run them.** Expected: FAIL, because the accounts and files are kept.
- [ ] **Step 3: Implement.** If deleting `users` hits a `RESTRICT` foreign key from a table the institution cascade does not clear, ledger the table and clear its rows for those accounts in the same transaction before deleting them.
- [ ] **Step 4: Run them, and the purge suites.** Expected: PASS.
- [ ] **Step 5: Stage.** Message: `fix: purging an institution removes its accounts, their files and its exports, and keeps the audit log`

---

### Task 5: Weekly class times, with clash warnings (13.1, 13.3–13.5)

**Files:**
- Create: `app/Services/Institutions/ClassScheduleService.php`, with:
  - `public function forOffering(int $institutionId, int $offeringId): array{rows: list<array{day: string, start: string, end: string, room: ?string, mode: string}>, sisOwned: bool}`. Rows are sorted Monday first, then by start; times are `H:i`.
  - `public function save(int $institutionId, int $offeringId, array $rows, ?User $actor = null): array{warnings: list<string>}`:
    - rows with every field blank are ignored;
    - it validates per Global Constraints, with `StructureException::field('rows', …)` giving the first error's copy;
    - it refuses with `SisOwnership`'s lock copy when the SIS owns the times;
    - it replaces the section's rows in one transaction, audits `structure.offering_schedule_changed` (old and new rows), and returns the clash warnings.
  - `public function clashes(int $offeringId, array $rows): list<string>`.

  Scoped through `InstitutionScope::require('offering', …)`.
- Test: `tests/database/ClassScheduleServiceTest.php`:
  - `testRowsAreSavedAndReadBackInWeekOrder`;
  - `testBlankRowsAreIgnored`;
  - `testAnEndNotAfterTheStartIsRefused` (09:00–09:00, and 23:00–00:00 from Review Focus);
  - `testTheSameStartTwiceOnADayIsRefused`;
  - `testAnUnknownDayOrModeIsRefused`;
  - `testARoomClashWarnsButSaves`;
  - `testAnOnlineClassNeverClashesOnRoom`;
  - `testAnInstructorClashWarnsButSaves`;
  - `testAnotherTermNeverClashes`;
  - `testAnotherInstitutionsOfferingIsNotFound`;
  - `testTheChangeIsAudited`.

- [ ] **Step 1: Write the tests.**
- [ ] **Step 2: Run them.** Expected: FAIL, because the class doesn't exist.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them.** Expected: PASS.
- [ ] **Step 5: Stage.** Message: `feat: an institution's sections get weekly class times, with clash warnings`

---

### Task 6: The Class times page (13.1, 13.2 lock)

**Files:**
- Modify: `app/Config/Routes.php`, institution writing group (`offerings.manage`, `readonly`):
  - `GET institution/offerings/(:num)/schedule` → `Institution\OfferingScheduleController::show/$1`;
  - `POST institution/offerings/(:num)/schedule` → `::save/$1`.
- Create: `app/Controllers/Institution/OfferingScheduleController.php`.
- Create: `app/Views/pages/institution/offering-schedule.php`, in the existing institution page style (page-head with back link to Offerings, the form-field and alert components):
  - **The form:** one row per existing meeting plus three blank rows (day select, start and end `type="time"`, room text, mode select), no script needed, and one "Save class times" button.
  - **After saving:** clash warnings show as a warning alert; errors return to the form with the input kept.
  - **SIS-owned:** the rows are read-only, with the "From the SIS" badge and the lock copy, and no button.
- Modify: `app/Views/pages/institution/offerings.php`. Each row's actions gain a "Class times" link; the row shows its meetings compactly ("Mon 09:00–10:30 · Rm 204"), or "—".
- Test: `tests/feature/OfferingSchedulePageTest.php`:
  - `testTheAdministratorSavesClassTimesAndSeesTheWarnings`;
  - `testAnErrorKeepsWhatWasTyped`;
  - `testASisOwnedSectionIsReadOnlyWithTheLock`;
  - `testAnotherInstitutionsSectionIsNotFound`;
  - `testAReadOnlyInstitutionCannotSave`;
  - `testTheOfferingsListShowsTheMeetings`.

- [ ] **Step 1: Write the tests.**
- [ ] **Step 2: Run them.** Expected: FAIL (404, no route).
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run them, and the offerings page suites.** Expected: PASS.
- [ ] **Step 5: Stage.** Message: `feat: institution administrators enter class times on a section's Class times page`

---

### Task 7: The SIS sends class times (D8, D9, 13.2)

**Files:**
- Create: `app/Database/Migrations/2026-11-18-000001_CreateSisClassSchedules.php`, the table `sis_class_schedules`:
  - columns `id`, `institution_id`, `offering_id` (unique), `created_at`, `updated_at`;
  - foreign keys to institutions and course_offerings, cascading.
- Modify: `app/Services/Sis/SisCsvParser.php` `SPECS`, adding `class_schedules` per Global Constraints.
- Modify: `app/Services/Sis/SisImporter.php`, with `classSchedules(SisBatch $batch)` run after `staff_assignments` (sections may be created by enrolment rows first). It applies D9 through `ClassScheduleService`, bypassing the lock for the SIS itself. Clash warnings go to `issues` as warnings; failed rows go to `fail()`; counts go in `counts['class_schedules']`, including `removed`.
- Modify: `app/Services/Sis/SisOwnership.php`, adding `managesSchedule(int $offeringId): bool` (tracked and the connection enabled), used by `ClassScheduleService`.
- Modify: `docs/sis-integration.md`, documenting the dataset (columns, D9 rules, the lock).
- Test: `tests/database/SisClassSchedulesTest.php`:
  - `testASectionInTheFileGetsExactlyItsRowsAndIsLocked`;
  - `testASisSectionLeftOutLosesItsSisTimes`;
  - `testASectionTheSisNeverSentIsUntouched`;
  - `testNoFileChangesNothing`;
  - `testASecondRowAtTheSameDayAndStartFailsAlone` (Review Focus);
  - `testAPreviewCountsTheRemovalsAndWritesNothing`;
  - `testClashesAreReportedAsWarnings`;
  - `testTheLockEndsWhenTheConnectionIsDisabled`.

- [ ] **Step 1: Write the tests.**
- [ ] **Step 2: Run them.** Expected: FAIL, because the dataset is unknown.
- [ ] **Step 3: Implement, and migrate dev (`php spark migrate`).**
- [ ] **Step 4: Run them, and the SIS suites.** Expected: PASS.
- [ ] **Step 5: Stage.** Message: `feat: the SIS sends class times, and those sections are locked while it does`

---

### Task 8: Full check and docs

- [ ] **Step 1: Run every suite, one at a time.** `vendor/bin/phpunit --no-coverage` (in the background), then `npm test`, then `npm run build && npm run e2e`.
Expected: PASS everywhere, now without the six `AiWorkerEndpointsTest` failures. The known calendar flake may need one re-run.
- [ ] **Step 2: Docs.**
  - **`docs/institutional-admin-area.md`:** the Class times page, its clash warnings and the SIS lock (under Offerings); purge (accounts and files go); read-only lesson links.
  - **`docs/testing.md`:** `TestEnv`, and CI's second run with `ci-full.env`.
- [ ] **Step 3: Stage the plan file and the docs.** Message: `docs: class times, the purge, read-only lesson links, and tests independent of .env`
