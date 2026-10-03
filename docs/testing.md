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
  tests that need MySQL) or later `tests/feature/` (HTTP routes).
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
baseline and only ever raised.

Checklist, one pull request per domain, in this order:

- [x] Foundation: MySQL test database, safety guard, migrations apply cleanly on an empty database
- [ ] Modules and progress: `LearningProgressService` is covered (locks, `lockFor` equals `locksFor`, completion rules, video progress, dependencies; 49 tests). `ContentDeletionService` is covered too (cascades, renumbering, other courses untouched, discussion threads kept, assessments removed, files purged only when unused; 14 tests). Still to do: `ModuleService`
- [ ] Grades: `GradingEngineService` is covered (weighted categories and periods, ungraded work left out, band and pass boundaries, stored records, overrides and their history; 44 tests). Still to do: `GradingSchemeService`, `GradeService`, `GradeItemSync`, `GradebookSync`, sheet import and export, `RosterRiskService`
- [ ] Auth and roles: `LoginService`, `RedirectController`, role separation on every route group
- [ ] Discussions: `DiscussionService`, `DiscussionController`
- [ ] Assessments: assessment, quiz, assignment, grading, rubric, question import/export and generation services
- [ ] Courses and schedule: `CourseService`, `ScheduleService`
- [ ] Announcements: `AnnouncementService`
- [ ] Resources and storage: `ResourceService`, `StorageService`, `FileAccessService`
- [ ] Calendar and reminders: `CalendarEventService`, `ReminderService`
- [ ] Dashboard and overview: `DashboardService`, `OverviewService`, `ActivityFeedService` (with query-count budgets)
- [ ] Helpers: announcement, icon, navigation, storage, vite
- [ ] Frontend: Vitest for the shared JS helpers; Playwright for the main user flows
