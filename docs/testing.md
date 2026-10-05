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

The first run builds the whole schema from the migrations (App and Shield), so
it takes a few seconds longer; later runs reuse it.

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

## Writing a test

- Put it in `tests/unit/` (no database), `tests/database/` (service and model
  tests that need MySQL) or `tests/feature/` (real HTTP routes through the filters
  and controllers: extend `Tests\Support\RouteTestCase`, then `$this->loggedInAs($user)`
  and `$this->get(...)` / `->post(...)`).
- A database test: `final class FooTest extends \Tests\Support\DatabaseTestCase`.
  The schema is built once per run. Set `protected bool $isolateTests = true;`
  and each test runs inside a transaction that is rolled back afterwards (the
  tables are emptied once before the class's first test), so tests never see
  each other's rows and the suite stays fast.
- Build data with `Tests\Support\CourseFactory` (users in a role, an offering,
  enrolments, modules, units, items, progress). A test then reads as the
  situation it sets up, for example `CourseFactory::items($unitId, [[], ['mode' => 'previous']])`.
- A bug fix includes a test that fails without the fix.
- Never call the AI question service from a test; use a fake that implements
  `App\Services\AI\QuestionGeneratorClientInterface`.

## In CI

The **Tests on MySQL** job starts a throwaway MySQL 8.4 container, runs
`php spark migrate --all -g tests` on the empty database, then PHPUnit, on PHP
8.2 (with a coverage report, downloadable from the run as `coverage-report`)
and PHP 8.5 (the version the staging site runs).

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
- [ ] Calendar and reminders: `CalendarEventService`, `ReminderService`
- [ ] Dashboard and overview: `DashboardService`, `OverviewService`, `ActivityFeedService` (with query-count budgets)
- [ ] Helpers: announcement, icon, navigation, storage, vite
- [ ] Frontend: Vitest for the shared JS helpers; Playwright for the main user flows
