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

### Phase 6 (2026-11-21)
- `phpunit` full suite: Tests 9895, Assertions 39117, OK (the one-off `GradeReportRoutesTest:224` failure seen in an implementer's `Student` filter run did not reproduce in the full suite). `npx vitest run`: 118 files, 1211 tests passed. eslint clean; phpstan on changed files (implementers): no errors.
- Red phases shown: resolver + extension (20 errors), GroupAssignmentSubmission (10 failures + 1 error), 6.3 PHP (6 failures + 3 errors), 6.3 Vitest (5 failed).
- NOT run: Playwright TC-GAS-07 and the other group e2e specs (e2e DB :3306 broken); no browser/dark-mode/360 px check. Student block messages are rendered server-side (assignment-work.php) and covered by PHP feature tests, not Vitest.
- Known limits: in a finalized offering a group submission still goes through (same as solo hand-ins today); teammates see no grade/score panel until Phase 7; old solo rows made before joining a group are hidden from the group history; group block message wins over closed/unpublished reasons.

### Phase 7 (2026-11-21)
- `phpunit` full suite: Tests 9985, Assertions 39588, OK (the one-off ParentProfilePageTest order failure seen in an implementer's extra run did not reproduce). `npx vitest run`: 120 files, 1231 tests passed. eslint clean; phpstan on changed files (implementers): no errors.
- Red phases shown: rubric scope (2 errors + 4 failures), GroupGradingService (19 errors + 1 failure), GroupGradebook (4 errors + 9 failures), GroupGradingRoutes (16 failures), grading.js Vitest (module missing). `testOverviewAndFeedShowTheMembersOwnFinal` was written after the code.
- NOT run: Playwright group-grading.spec.js and the other group specs (e2e DB :3306 broken); no browser/dark-mode/360 px check.
- Known gaps: "Grade separately" for a late enrollee is not persisted beyond the pending row (dialog offered again after reload); per-member style shows group criteria/feedback from the first member who handed in; the dean has no Grade Work page (only GET endpoints; screen in Task 9.2); ParentNotifier still reports a non-submitting teammate as missing (Phase 9); an excused member with a pending grade reads 0% for min-score prerequisites; ordinary Grade Work refuses group submissions (guard) and solo-policy students are graded on the old page.

### Phase 8 (2026-11-21)
- `phpunit` full suite: Tests 10105, Assertions 39926, OK. `npx vitest run`: 121 files, 1241 tests passed. eslint clean; phpstan on changed PHP (implementer): no errors.
- Red phases shown: LearningItemGroup service (14 errors), routes (16 failures), learning-item Vitest (10 failed). The Task 8.3 implementer hit the 5-hour usage limit mid-task and was resumed from its saved state.
- NOT run: Playwright learning-item-groups.spec.js (e2e DB :3306 broken); no browser/dark-mode/360 px check.
- Known limits: proposed mode is not offered for learning items (refused at attach); the generate-groups dialog is not on the item card (it lives on the Groups tab for sets); completions made before the change show "Completed for the group" without a name (audit row groups.completed_for_group added going forward); the dean has no editor page, so only the dean GET endpoint exists.

### Phase 9 (2026-11-21)
- `phpunit` full suite: Tests 10139, Assertions 40397, OK (ParentProfilePageTest order failure seen once in a broad run did not recur). `npx vitest run`: 121 files, 1243 tests passed. eslint clean; phpstan on changed PHP (implementers): no errors.
- Red phases shown: GroupNotifier (21 errors), read-only views (4 failures; Vitest 1 failed), offering copy (1 failure). Export/import tests are characterization tests (green from the start, no per-assessment export exists).
- Incident: the session restarted mid-phase and Claude Code killed the scratch DB and one full run when the machine ran low on memory; both were restarted on the user's request and the full suite was re-run to completion.
- NOT run: all Playwright specs (e2e DB :3306 broken); no browser/dark-mode/360 px check.
- Known limits: D20a (no Group column in exports, flagged for the user); drop hook can run inside the enrolment transaction (notifier error would still mark it failed, to review in Phase 10); parent Assessments tab shows a grade for group activities but none for plain assignments; removed-after-grading child shows no group line to the parent; email rows are written one at a time; a student removed by an instructor is not notified.
