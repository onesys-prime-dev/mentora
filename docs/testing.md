# Testing Mentora

## Running the tests locally

Tests run against **MySQL**, in a database of their own. They never touch
`mentora_db`.

1. Create an empty database once (XAMPP, phpMyAdmin or the console):
   ```sql
   CREATE DATABASE mentora_test;
   ```
2. Run the suite:
   ```bash
   composer test            # fast run, no coverage
   composer test:coverage   # needs PCOV or Xdebug installed; CI does this for you
   ```

### Frontend (JavaScript)

The browser code in `resources/js` is unit-tested with Vitest in jsdom. No PHP,
database or build is needed:

```bash
npm test             # one run, what CI does
npm run test:watch   # re-runs as you edit
```

Tests live in `tests/js/`, mirroring the paths under `resources/js/`, and are
named `*.test.js`.

### Automated pull-request checks

Use `bash scripts/create-pr.sh` from Git Bash to push the current branch and open
a pull request using the repository template:

```bash
bash scripts/create-pr.sh main "feat: describe the change" "What and why"
```

The script runs no checks. It fills the template from the branch (the summary you
give, the commit list and the size of the change), pushes, and opens the pull
request; if the branch already has an open one, it prints that instead. CI runs
the complete suite on the pull request, and the "CI passed" check is what gates
the merge. To check something locally before pushing, run the commands above
yourself.

The tests need a local database. The first run builds the whole schema from the
migrations (App and Shield), so it takes a few seconds longer; later runs reuse it.

Connection defaults are in `app/Config/Database.php` (`$tests`): host
`127.0.0.1`, user `root`, no password, database `mentora_test`. If your XAMPP
differs, override in your own `.env` (never committed):

```
database.tests.hostname = 127.0.0.1
database.tests.username = root
database.tests.password = secret
database.tests.database = mentora_test
```

**Safety:** every database test extends `Tests\Support\DatabaseTestCase`, which
refuses to run unless the configured database name contains `test`. If you
point `database.tests.database` at a real database by mistake, the suite stops
before changing anything.

### Browser tests (Playwright)

Real-browser tests of the main user journeys, in `tests/e2e/*.spec.js`. Each test
name starts with its case ID from `docs/test-cases/` (for example `TC-AUTH-01`),
so a failure points straight at the case.

```bash
npm run build                    # once: the pages load public/build
npx playwright install chromium  # once: downloads the browser
npm run e2e
```

It needs a MySQL or MariaDB server on `127.0.0.1` that `root` with no password can
use (XAMPP's is). `npm run e2e` then does everything itself
(`tests/e2e/support/serve.js`): it empties a database, runs the migrations and the
demo seeder, starts the app on port 8089 as production would, and drives Chromium
against it. The demo data is reseeded on every run, because the cases consume it
and the seeded dates are relative to today. A run takes about 25 seconds.

**It drops and recreates a database called `mentora_e2e` on every run.** It never
uses the database named in your `.env`, and it has three guards: a name that does
not contain `e2e` or `test` is refused (in `serve.js`, `reset-database.php` and
`app/Config/Database.php`, which `tests/unit/E2EConfigTest.php` checks); and before
anything is migrated or seeded, `serve.js` asks the app which database it will
connect to and stops if it is not that one.

| Setting | Default | Meaning |
|---|---|---|
| `E2E_DATABASE` | `mentora_e2e` | the database to reset and use |
| `E2E_DB_HOST`, `E2E_DB_USER`, `E2E_DB_PASSWORD` | `127.0.0.1`, `root`, empty | how to reach the server |
| `E2E_PORT` | `8089` | where the app is served |
| `E2E_VERBOSE` | unset | `1` shows the web server's request log |

These settings have no dots on purpose. The framework's own dotted settings
(`database.default.database`, `app.baseURL`) cannot be overridden from the process
environment on Windows, where `.env` silently wins, so a setup that relied on
them could reset the wrong database.

**Mail and the administrator.** The app never sends real mail in these tests. `serve.js`
sets `E2E_MAIL_SINK`, which `app/Config/Email.php` turns into "pipe every message to
`tests/e2e/support/mail-sink.php`"; that script files each one in `writable/e2e-mail`,
and `tests/e2e/support/mail.js` reads the system administrator's sign-in code and
invitation links from there. `serve.js` also creates the administrator with
`php spark admin:create` (the demo seeder refuses to run in production). For a case
that needs time to have passed, `support/database.js` runs one SQL statement on the
e2e database (same name guard as the reset); `support/spark.js` runs a spark command
such as `subscriptions:sync`.

A failed test leaves a screenshot and a trace in `test-results/` and an HTML report
in `playwright-report/` (`npx playwright show-report`); CI uploads them as the
`playwright-report` artifact, with `writable/logs/`. Tests run one at a time.

To add a test: take a case from `docs/test-cases/`, name the test with its ID, sign
in through the form with a demo account, and set the case's Playwright field to the
file.

## Writing a test

- Put it in `tests/unit/` (no database), `tests/database/` (service and model
  tests that need MySQL) or `tests/feature/` (real HTTP routes through the filters
  and controllers: extend `Tests\Support\RouteTestCase`, then `$this->loggedInAs($user)`
  and `$this->get(...)` / `->post(...)`).
- A database test: `final class FooTest extends \Tests\Support\DatabaseTestCase`.
  The schema is built once per run. Set `protected bool $isolateTests = true;`
  and each test runs inside a transaction that is rolled back afterwards (the
  tables are emptied once before the class's first test), so tests never see
  each other's rows and the suite stays fast. Every database test also starts
  with the permission caches empty (`RolePermissions::flush()` in `setUp()`):
  they are static, and a user id can come back in another institution.
- A test that needs DDL (a trigger that makes the database refuse a write, say)
  cannot run inside that transaction, because DDL commits it. Leave
  `$isolateTests` false and drop what it made in `tearDown()`, as
  `QuizAttemptFailureTest` does.
- Build data with `Tests\Support\CourseFactory` (users in a role, an offering,
  enrolments, modules, units, items, progress). A test then reads as the
  situation it sets up, for example `CourseFactory::items($unitId, [[], ['mode' => 'previous']])`.
- A bug fix includes a test that fails without the fix.
- **A new service or a new table needs tests, and two guard tests enforce it.**
  `tests/unit/ServiceTestInventoryTest.php` reads every class under
  `app/Services` and `tests/database/TableTestInventoryTest.php` reads every
  table in the migrated database. Each must be named in some test (a table
  by its quoted name or through its model). If you add one without tests the
  guard fails and names it. Write the tests; only if that is truly not
  possible, add the entry to the `NOT_NAMED_IN_A_TEST` / `NOT_TOUCHED_BY_A_TEST`
  list in the guard with a real reason. Those lists must not go stale: once the
  thing gets a test, the guard fails until you delete the entry. Tables that
  belong to Shield or Settings go in `PACKAGE_TABLES`. "Named in a test" is a
  floor, not a measure of quality; the coverage checks judge the depth.
- Never call the AI question service from a test; use a fake that implements
  `App\Services\AI\QuestionGeneratorClientInterface`.
- **A test never depends on what your `.env` says.** To give a setting a value
  for a test, use `Tests\Support\TestEnv::set('backup.offsiteCommand', '')` (or
  `unset`), and call `TestEnv::restore()` in `tearDown()`. CodeIgniter's `env()`
  reads `$_ENV` first, and a `.env` value sits in `$_ENV`, `$_SERVER` and
  `getenv()` at once, so setting only one of them (`putenv`, say) loses to the
  file. `TestEnv` sets all three and puts back exactly what was there. A config
  object read once per call (the logger in the testing environment builds a new
  `Config\Logger` for every `log_message()`) is set the same way, not through
  `config()`.

## In CI

The **Tests on MySQL** job starts a throwaway MySQL 8.4 container, runs
`php spark migrate --all -g tests` on the empty database, then PHPUnit, on PHP
8.2 (with a coverage report, downloadable from the run as `coverage-report`)
and PHP 8.5 (the version the staging site runs).

The PHP 8.5 run then runs PHPUnit **a second time with every setting filled
in**: it copies `tests/_support/ci-full.env` (every key of the `env` template,
with harmless values, both database groups on the test database) over `.env`.
A test that passes only because a setting happens to be empty on a clean
machine fails there. `tests/unit/CiFullEnvTest.php` keeps the file complete: a
key added to `env` must be added to it too. To run the same check locally,
back up your `.env`, copy `ci-full.env` over it, run `vendor/bin/phpunit
--no-coverage`, and put your `.env` back.

The **End-to-end (Playwright)** job runs the browser tests above: it builds the
frontend, starts a MySQL 8.4 container, and runs `npm run e2e` (most of its time is
building and installing Chromium). It is part of **CI passed**.

## Coverage plan

"Covered" here means every service, controller and helper has tests, with the
depth following the risk (grades, locks, permissions and file access get every
branch and boundary; plain CRUD gets the normal case plus a permission and a
validation check). A coverage minimum will be set from the first measured
baseline and only ever raised. It lives in `.github/coverage-floor` (one number) and CI fails if overall line coverage falls below it (checked on the PHP 8.2 job by `.github/scripts/check-coverage.php`). Raise the number in the same PR that adds tests; never lower it to merge. Migrations and seeders are excluded from the measurement.

Checklist, one pull request per domain, in this order:

- [x] Foundation: MySQL test database, safety guard, migrations apply cleanly on an empty database
- [x] Modules and progress: `LearningProgressService` is covered (locks, `lockFor` equals `locksFor`, completion rules, video progress, dependencies; 49 tests). `ContentDeletionService` is covered too (cascades, renumbering, other courses untouched, discussion threads kept, assessments removed, files purged only when unused; 14 tests). `ModuleService` is covered too (who may edit, modules, units and items: create, edit, publish, reorder, delete; quiz, discussion and link items; the linked assessment following its item; PDF settings, video links, the module picker and the module pages; 56 tests, plus 17 that point every write method at another section's module, unit or item and expect a refusal; module, unit and item statuses outside draft, published and inactive are refused with a message). Not covered: uploading a video or PDF file (needs a real HTTP upload), and the item view/editor context builders
- [x] Grades: `GradingEngineService` is covered (weighted categories and periods, ungraded work left out, band and pass boundaries, stored records, overrides and their history; 44 tests). `GradingSchemeService` is covered too (copying the system default, publish checks, settings, categories, periods, items, manual scores and what each edit does to "needs recalculation"; 46 tests). `GradeItemSyncService` (publish to grade item, category matching, snapshot points and resync, archive; 24 tests), `GradebookSyncService` (counted attempt per type, cleared when nothing counts, manual scores, when final grades refresh; 16 tests) and `RosterRiskService` (inactive and behind thresholds, ordering; 13 tests) are covered too. The offline grade sheet is covered too: `GradeSheetLayout` (columns, signature and row hash, every formula; 29 tests) and export, import preview and apply (CSV and XLSX round trips, every refusal, out-of-range scores, locked and calculated cells, when final grades refresh; 44 tests). `GradeService` is covered too (the student's page: final grade hidden until the scheme is published, overrides, first-visit calculation, per-category breakdown, visible assessments; the instructor's page: grouping, needs-review counts, class average and passing count; 23 tests)
- [x] Auth and roles: every route under `/student` and `/instructor` (read from the real route table, so new routes are checked automatically) sends a logged-out visitor to login and turns away the other role (336 route checks); the role landing pages, a 403 instead of a crash for users with no role, switched-off registration and magic-link login, login-only file downloads, and course-level access (enrolled student / assigned instructor only). A new top-level route fails a test until someone decides who may reach it. 355 tests in `tests/feature/`. (`LoginService` is an empty file, so there is nothing to test there.)
- [x] Discussions: `DiscussionService` is covered (access, start/edit/delete, pin and lock, replies, answers, reactions, list order/search/filters/paging, draft-item threads, unread, screens; 41 tests). `DiscussionController` is covered through the real routes as a student and as the instructor (start, list with search and paging, open, edit, delete, pin, lock, reply, answer, react; the other section's threads read as not found and every write there is refused and changes nothing; 50 tests in `tests/feature/DiscussionRoutesTest.php`). Not covered: a real file upload
- [x] Assessments: `AssessmentResultService` (which attempt counts: best quiz attempt, latest assignment, pending and reopened work; 19 tests), `ShortAnswerGrader` (accepted answers, spacing and case, no fuzzy matching; 25 tests) and `QuizAttemptService` (start and resume, attempt limit, availability window and locks, saving answers, auto-grading every question type, the resulting status, lateness, the gradebook, answers only for the quiz's own questions, a server-side time limit with 60 seconds' grace; 51 tests) are covered, and `tests/feature/AssessmentSectionAccessTest.php` checks that an assessment id only opens inside the course in the URL (8 tests). `AssignmentSubmissionService` (allowed ways to submit, open/closed/late rules, submission limit, link checks, who may submit; 31 tests), `AssessmentFeedbackService` (who may write in a feedback thread and when; 11 tests) and `GradeWorkService` (starting and reopening, grading by points or rubric, question-by-question quiz grading, the gradebook, the Grade Work page; 33 tests) are covered too. Every route that names an assessment, submission, question, rubric, criterion or generated question is also checked against the course in its URL (the `assessmentscope` route filter; `tests/feature/AssessmentRoutesScopeTest.php`, 93 tests: every instructor route from another section and from an unassigned instructor, nonexistent ids, and own-section controls). `QuestionService` (adding, editing, duplicating, reordering and deleting questions, answer keys, the quiz total; 29 tests) and `RubricService` (create and replace, the course library and copying, criteria with the total following; 21 tests) are covered too. `AssessmentService` is covered too (creating with defaults and its learning item, partial edits and clearing optional fields, passing score, attempt limit, status carrying to the Modules item and the gradebook, duration, who sees what, the availability window, submission counts, the pages; 67 tests including statuses outside Draft, Published, Closed and Archived being refused, duplicating with its own Modules item, questions and rubric, and the settings nothing enforces not being stored). The question export and import are covered too (the Word and RTF files, reading a file back as added, changed and removed questions, the AI fallback for foreign files, applying a confirmed preview and only ever to the quiz's own questions; 30 tests). Question generation is covered too, with a fake generator standing in for the AI (batches and chunks, the busy and unavailable cases, the review tray edit, discard, accept and accept all, accepting a question only once; 31 tests). `AssessmentOverviewService` (the student's status on each assessment and what is due, overdue, up next and newly marked; the instructor's grading queue, submission rate, missing work and drafts; the date labels; 56 tests) and the learning-unit content extractor (readings and PDFs as generation text, the length cap, unreadable PDFs; 18 tests) are covered too
- [x] Courses and schedule: `CourseService` (21 tests), `ScheduleService` (16 tests); fixed co-taught courses listed twice and instructor-less courses hidden from students
- [x] Announcements: `AnnouncementService` (32 tests) and the routes (17 tests); fixed announcements readable by users outside the course, attaching other users' files, the unused 10-attachment cap, deleting replies across sections, and unchecked reaction values
- [x] Resources and storage: `ResourceService` (34 tests), `StorageService` (21), `FileAccessService` (14), the `files/{id}` route (8) and the Resources route (4); fixed announcement and discussion attachments being unreadable to classmates, and instructors of a sibling section reading this section's student work
- [x] Calendar and reminders: `CalendarEventService` (15 tests), `ReminderService` (10) and the Calendar routes (6); fixed hidden-content due dates showing on a student's calendar and reminders filed under courses the person is not in
- [x] Dashboard and overview: `DashboardService` (32 tests), `OverviewService` (27 + 3 route tests), `ActivityFeedService` (16), and query-count budgets (6) that fail on an N+1; fixed the course Overview being readable by people outside the section
- [x] Helpers: announcement (18 tests), icon (30), navigation (4 + 3), storage and vite (6); fixed stored XSS through a message block's alignment and crashes on malformed announcement content
- [x] Frontend, Vitest: the shared SPA helpers (swap, load guard, busy button, error state, highlight row, skeleton rows), the header menus, assignment submission, and the reading/instructions editor JSON (119 tests; runs in CI)
- [ ] Frontend, Playwright: the main user flows in a real browser. **Set up and running in CI** (config, a freshly seeded database, twenty tests: TC-AUTH-01 to 04, TC-ANN-01 and 02, TC-MOD-01 and 02, TC-QUIZ-01 to 03, TC-ASG-01 and 02, TC-GRD-01 to 03). The cases to automate are written first, in `docs/test-cases/` (draft, awaiting review); each Playwright test names its case ID. More flows come as their cases are confirmed

## Handoff notes (where this stands, and what is still open)

Written at the end of the coverage work so whoever continues has the context
that is not obvious from the code. Everything in the checklist above is done
except Playwright.

### Open decisions (none of these are started)

- ~~Playwright end-to-end tests.~~ Done for the P1 cases: the setup (config, a database that
  is reset and seeded on every run, the app served as production would, a CI job with
  MySQL) and twenty tests (the assessment editor's Total Points field in `assessment-editor.spec.js`, and TC-AUTH-01 to 04, TC-ANN-01 and 02, TC-MOD-01 and 02, TC-QUIZ-01 to 03, TC-ASG-01 and 02, TC-GRD-01 to 03) cover every confirmed P1 case except
  TC-AUTH-05, which the PHP route tests cover better. Writing them found and fixed three
  app bugs (a quiz or assignment was not completed when handed in; a student could not see
  the instructor's feedback on the item page; a quiz's essay score box stayed disabled after
  Grade Work). Still open: the P2 cases (content authoring, discussions, the calendar,
  resources), which still need cases written and confirmed; file upload and video; and a decision on how long the suite may
  grow before it is split across CI jobs.
- ~~The PHP coverage floor is stale.~~ Done: measured at **86.92%** lines
  (9424/10842, PHP 8.2 with PCOV, 1,735 tests, 2026-10-06) and the floor in
  `.github/coverage-floor` is now `86.5`. Keep raising it with new tests, in the
  same PR. A changed-lines check now runs on pull requests too (so new code
  cannot hide behind the overall number): at least the percentage in
  `.github/diff-coverage-threshold` (80) of the executable lines a PR adds or
  edits under `app/` must be covered. If it fails, the job log lists the
  uncovered lines; add tests for those. Note that it makes any edit to a weakly
  covered file (see below) come with tests for the edited lines. The weakest
  areas at the 2026-10-06 baseline are the controllers (assessment, question,
  rubric, course and quiz-attempt controllers are at roughly 9-35% of lines)
  and `ModuleService` (77%).
- **Static analysis.** PHPStan now runs in CI (level 5, `phpstan.neon`, with
  the existing 13 findings recorded in `phpstan-baseline.neon`, so only new
  problems fail the build). ESLint now runs in CI too (`eslint.config.js`,
  recommended rules over `resources/js`, `tests/js` and the config files; the
  10 findings it started with are in `eslint-suppressions.json`: unused
  variables and parameters, one duplicated `null` key and one deliberate
  grouped `case`, none a bug). Still open: raising the PHPStan level and
  turning on stricter ESLint rules. PHPStan level 6 and up reports
  about 700 to 1,500 findings, mostly missing array and return types and
  null-safety (`argument.type`, `offsetAccess.nonOffsetAccessible`,
  `method.nonObject`), which is the real value but needs a gradual clean-up.
  A code-style tool is lower value here: the code mixes CRLF and LF line
  endings, so it would reformat many files at once.
  The baselined findings looked at on 2026-10-06 are not runtime bugs: the
  `RosterRiskService::atRisk()` docblock leaves out the `student_id` key the
  dashboard reads, a `match` in `GradeSheetExportService` has no default arm,
  and the rest are PHPStan not seeing CodeIgniter's magic (a `Model` forwarding
  `get()` to its builder, `UploadedFile` typed as `File`).
- ~~`deploy.yml` does not wait for CI.~~ Done: it now calls `ci.yml` and the
  deploy job `needs: ci`; the trunk is `main` (the `staging` branch is retired).
- **JavaScript coverage is partial.** Only the shared helpers and the editor
  JSON are tested (`tests/js/`). The large page scripts (calendar, questions,
  grading, rubrics, AI generation) have no tests and there is no JS coverage
  threshold.
- **The AI question-generation boundary.** The PHP side is tested against a fake
  generator. Nothing exercises the real `python-question-builder-service`
  against the PHP code, so a contract change there would not fail CI.

### Behaviour that was looked at and deliberately left alone

- **The student dashboard writes on a first visit.** The first time a student
  opens the dashboard, each enrolled course that has no grading scheme gets one
  copied from the system default (created as `Published`; the average grade
  depends on it). The Grades tab does the same. That is lazy initialisation by
  design, not a bug, so it was only measured (about 73 queries on the first
  visit, about 18 afterwards). If it should change, create the scheme when the
  offering is created instead.
- **The calendar shows only `Published` assessments to students**, while the
  course lists also show `Closed` ones, so past-due closed work does not appear
  on the calendar. Left as it was; say so if closed work should be included.
- **Not covered by any test:** uploading a real video or PDF file over HTTP
  (the services are tested, the multipart upload is not), the Vite
  development-server branch of `vite()` (it depends on the `ENVIRONMENT`
  constant), and the "+" button on the dashboard's Reminders card, which has
  no handler yet.

### Pitfalls that cost time while writing these tests

These apply to any new database test; the first two silently break *other*
tests, which makes them confusing to track down.

1. **Never use `emptyTable()` or `TRUNCATE`.** In MySQL that is an implicit
   commit, so it ends the per-test transaction and leaks data into later
   tests. Use `->where('id >', 0)->delete()`.
2. **A failed insert can poison unrelated tests.** The connection does not
   throw on a SQL error (a wrong column name, a duplicate key); it marks the
   shared transaction as failed, and later code that uses a transaction (for
   example the grade sheet import) then rolls back with a vague message. If a
   test passes alone but unrelated tests fail after it, look for an insert
   with a wrong column name, and run the two files together in that order.
3. **Grade-reading code needs the system-default grading scheme.** Anything
   that reaches `GradingSchemeService::forOffering()` (the student dashboard,
   the course Overview, the Grades page) copies it per course. Call
   `GradeFactory::systemDefault()` in `setUp()`; a real install always has it
   seeded.
4. **Do not name a test helper `count()`, `fileExists()` or similar.** PHPUnit
   declares those `final`, which is a fatal error that shows up as a confusing
   framework "ErrorException" rather than a normal failure.
5. **Real people have a profile row and a sign-in.** Several queries (the
   instructor's course list, the Overview header, the roster) join
   `user_profiles` and `auth_identities` with inner joins. `CourseFactory::user()`
   makes neither, so tests that go through those queries add them (see the
   `person()` helpers in the dashboard and overview tests).
6. **When mutation-testing by hand, never restore with `git checkout <file>`
   if the file also holds an uncommitted fix.** Copy the file aside first.
   Most of the source files use CRLF line endings, so a scripted edit has to
   keep them (edit through Python and restore `\r\n`).
7. **Route registry:** `service('routes')->getRoutes('GET')` needs an upper-case
   verb, and in a unit test `\Config\Services::routes(false)->loadRoutes()` is
   needed first (see `NavigationHelperTest`).

### Running the pieces

- PHP: `composer test` (needs the `mentora_test` MySQL database, see above).
- JavaScript: `npm test`.
- A single PHP test file: `vendor/bin/phpunit --no-coverage tests/database/FooTest.php`.
- Query-count budgets live in `tests/database/DashboardQueryBudgetTest.php`.
  The numbers in its comments were measured on a repeat visit; if a legitimate
  change adds a query, raise the budget in the same PR and say why, rather
  than loosening it silently.
- The question-builder worker's unit tests run with `python -m pytest tests -q` from `python-question-builder-service/` (CI runs them too). They include the stop and restart signals the deploy relies on (`StopRequest` in `qb/worker.py`). The monitoring status rules for the worker are in `tests/unit/SystemHealthTest.php`.
- After a deploy, the worker's health is checked by hand on the monitoring page: "Question-builder (AI) worker" must read up within a minute of the restart. The deploy itself only confirms the unit has a new process that stays up.
- The question-builder worker has an evaluation runner (`python -m eval.qb_run`, see `python-question-builder-service/README.md`). It calls the real model, so it is run by hand and is not part of CI.
