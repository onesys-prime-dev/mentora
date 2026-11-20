# Test Strategy

## Approach
- TDD per task: write the failing test, run it, implement, run it green (superpowers:test-driven-development). No phase closes with a failing or skipped test.
- Layers: PHPUnit database/service tests (`tests/database`), route/permission tests (`tests/feature`), Vitest for JS modules (`tests/js`), Playwright journeys at 360/768/1440 px with axe (`tests/e2e`).
- Regression: every phase runs the full `composer test` plus the existing suites of the code it touches (`AssignmentSubmissionServiceTest`, grading, gradebook, rubric tests).
- Concurrency: join-the-last-seat test uses two DB connections and is run 20 times at phase 4 close.
- Quality gates per phase: phpstan, eslint, `docs/ui-guide.md` section 7 checklist for each new screen.

## Per-phase verification commands
`composer test` · `vendor/bin/phpunit --filter <Name>` · `npx vitest run` · `npx playwright test <spec>` · `vendor/bin/phpstan analyse` · `npx eslint resources/js`

## Cross-cutting cases (must exist by phase 10)
Foreign id returns 404; wrong role refused; read-only institution refused; finalized offering refuses every group write; audit row written for each change; no private adjustment in any other student's response body; non-group assignments and quizzes behave exactly as before.

## Results log
### Phase 1 (2026-11-20/21)
- `phpunit` full suite (scratch DB :3307): Tests 9521, Assertions 37797, OK (1 warning: no coverage driver).
- New PHP tests: GroupSchemaTest 4, GroupSetServiceTest + GroupMembershipServiceTest 18 (22 together), GroupSetsRoutesTest 10, GroupsTabTest (38 tests / 159 assertions across the Group* filter).
- `npx vitest run`: 115 files, 1158 tests passed (10 new in tests/js/pages/courses/groups).
- eslint on new JS: clean. phpstan on new/changed PHP (reported by implementer): no errors.
- Mutation check on membership rules (capacity, enrollment): both caught by tests.
- NOT run: Playwright `tests/e2e/groups-tab.spec.js` (e2e scripts reset the DB on :3306 which is broken); no browser/visual check (states, dark mode, 360 px, keyboard) done yet. Vitest tests were written after the module and not mutation-checked.

## Local environment note (2026-11-20)
The XAMPP MySQL data directory fails to start (InnoDB "log sequence number is in the future"; do not force-recover, it holds the user's other databases). Tests run against a throwaway MariaDB on port 3307 whose datadir is in the Claude scratchpad (`mysql-data`), database `mentora_test`:
`env 'database.tests.port=3307' 'database.tests.hostname=127.0.0.1' vendor/bin/phpunit --filter <Name>`
Start it with `mysqld.exe --no-defaults --datadir=<scratch>/mysql-data --port=3307 --standalone` (see progress.md). Baseline `AssessmentAssigneesTest`: 15 tests, 30 assertions, OK.
