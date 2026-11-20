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

### Phase 2 (2026-11-21)
- `phpunit` full suite: Tests 9555, Assertions 38111, OK. `--filter Group`: 191 tests / 1115 assertions.
- `npx vitest run`: 116 files, 1165 tests passed (7 new in generate.test.js; true red phase 7 failed before wiring). eslint clean.
- PHP red phases shown for the generator service (16 errors); the route/controller tests were written after the controller (no PHP red phase).
- NOT run: Playwright TC-GRP-03 (e2e DB :3306 broken); phpstan on Phase 2 files timed out and was not verified; finalized-offering route test accepts 403/409/422/423 (status not pinned); no browser check.

### Phase 3 (2026-11-21)
- `phpunit` full suite: Tests 9688, Assertions 38471, OK. `npx vitest run`: 117 files, 1177 tests passed. eslint clean; phpstan on the 4 changed PHP classes (implementer): no errors.
- Red phases shown: ActivityGroupService (19 errors), ActivityGroupMembershipService (16 errors + 1 failure), activity routes (12 failures + 2 errors), activity-panel Vitest (module missing). Task 3.1 wrote the service first, then removed it to record the red run.
- NOT run: Playwright `group-activity.spec.js` (e2e DB :3306 broken); no browser check. Unlock and late-enrollee are covered by PHPUnit/Vitest only (nothing freezes a group in the app until Phase 6).
- Known limits: drop after a finalized offering leaves group rows (all group writes refused); the dean has no editor route, only the GET JSON endpoint; keep/excuse dialog (4D) deferred to Phase 7; proposals queue (4B) deferred to Phase 5; `activity_group_configs.frozen_at` column unused (freeze lives on student_groups).

### Phase 4 (2026-11-21)
- `phpunit` full suite: Tests 9746, Assertions 38636, OK. `npx vitest run`: 118 files, 1192 tests passed. eslint clean.
- Red phases shown: GroupSignupService (22 errors), student routes (6 failures + 2 errors), Task 4.3 (PHP 3 failures + 1 error; Vitest 3 failed). Concurrency: second-process row lock test (join waits, then 'Group is full'); class passed 20/20 repeated runs.
- A broad `--filter` run during Task 4.3 showed 27 errors that did not reproduce on rerun (1330 tests OK); likely overlapping runs on one test DB. Rule: never run two PHPUnit processes against the scratch DB at once.
- NOT run: Playwright `group-activity-student.spec.js` and `group-activity.spec.js`; no browser/dark-mode/360 px visual check.
- Known limits: join/switch need formation_mode=self_signup (now settable in the editor); candidate groups show member names during an open signup window; GroupSignupServiceTest concurrency cleanup truncates test-DB tables (precedent in CsvUploadServiceTest).

### Phase 5 (2026-11-21)
- `phpunit` full suite: Tests 9843, Assertions 38938, OK. `npx vitest run`: 118 files, 1205 tests passed. eslint clean; phpstan on changed controllers/service (implementer): no errors.
- Red phases shown: GroupProposalService (15 errors), GroupLeaderService (20 errors), routes (6 failures + 3 errors), student-card Vitest (7 failed), activity-panel Vitest (5 failed).
- NOT run: Playwright `group-activity-leader.spec.js` (e2e DB :3306 broken); no browser/dark-mode/360 px check.
- Known gaps: destination group of a move/switch is not recounted for a leader majority; an instructor-set leader with no votes is kept; instructor board shows only active groups (proposed ones live in the proposals queue); Approve has no confirm dialog; leader shown as a badge on the group card, not a star on the chip.
