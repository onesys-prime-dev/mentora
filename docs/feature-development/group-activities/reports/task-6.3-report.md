# Task 6.3 report: group deadline extension and student submit messages

Status: DONE_WITH_CONCERNS (concerns at the end). Nothing committed.

## Files

Changed:
- app/Config/Routes.php: POST `.../groups/groups/(:num)/extension` in the instructor and chairperson route groups (same filters as the neighbouring routes; the dean group has no POST).
- app/Controllers/Courses/Groups/ActivityGroupsController.php: `extend()` (404 for foreign ids via `group()`, 422 for StructureException via `run()`); `show()` now returns `dueDate` and a per-group `extension {until, reason}|null`.
- app/Controllers/Courses/Groups/StudentGroupController.php: `myGroup.extension {until, reason}|null`; members still `{id, name}`.
- app/Services/Courses/Assessments/AssignmentSubmissionService.php: state `group` block gained `extensionReason` and `leaderName` (the resolver message only carried the username).
- app/Views/.../partials/assignment-work.php: group notice (shared alert component), "Go to the group card" link, form kept but inside a `<fieldset disabled>` for needs_group / leader_only / not_in_pool, "Submitted by X on date.", extension notice.
- app/Views/.../partials/student-group-card.php: `id="student-group"` anchor.
- app/Views/.../partials/group-activity-section.php: `ga-extend-dialog` (datetime-local, reason textarea, shared form-field and dialog components).
- resources/js/pages/courses/groups/activity-panel.js: "Extend deadline" action per group, dialog, "Extended to ..." badge (read-only for the dean: badge shown, no action).
- resources/js/pages/courses/groups/student-card.js: extension notice in the student group card (text via textContent, so reason is escaped).
- Tests: tests/feature/ActivityGroupsRoutesTest.php, tests/feature/StudentGroupRoutesTest.php, tests/js/pages/courses/groups/activity-panel.test.js, student-card.test.js, tests/e2e/group-activity-student.spec.js (TC-GAS-07).

## Red phase (before implementation)
- PHPUnit (10 new tests): `Tests: 10, Assertions: 16, Errors: 3, Failures: 6, PHPUnit Warnings: 1.` (for example `ErrorException: Undefined array key "extension"`, `Failed asserting that 404 is identical to 422`, missing "You're not in a group yet" text). The solo-policy test passed in red, as expected.
- Vitest: `Test Files 2 failed | 2 passed (4); Tests 5 failed | 58 passed (63)`.

## Green phase
- `vendor/bin/phpunit --filter 'Group|Assessment'` (port 3307): `OK, but there were issues! Tests: 1119, Assertions: 3637, PHPUnit Warnings: 1.` (the warning is "No code coverage driver").
- `npx vitest run` (whole suite): `Test Files 118 passed (118); Tests 1211 passed (1211)`.
- `npx eslint resources/js/pages/courses/groups tests/js/pages/courses/groups`: clean.
- phpstan on the changed controllers and AssignmentSubmissionService: `[OK] No errors` (under 90 s).

## NOT run
- Playwright: TC-GAS-07 was added by convention but cannot run (e2e DB on :3306 is broken). It is untested and may need selector fixes.

## Deviations and concerns
- The student submit messages are drawn by the server (assignment-work.php), not by JS, because the page and the state already live there and the form must stay on the page disabled. They are therefore covered by PHP feature tests (page HTML through the module learning-item route: needs_group with disabled fieldset, leader_only name, not_in_pool, solo policy shows nothing, extension notice, "Submitted by X on Nov 20, 2026 2:30 PM."), not by Vitest. Vitest covers the extension dialog and the student card extension notice only. If you want a JS-side Vitest for the blockedCode messages, that needs the messages moved to JS.
- If the group-blocked student has no submission method enabled, no form is drawn and the existing grey reason box is shown (the disabled form would be empty).
- When a group block coincides with another reason (not yet published, closed), the group message and the disabled form are shown rather than the other reason.
- `extend` accepts `until` as `YYYY-MM-DDTHH:MM` (datetime-local) or with seconds; the panel stores it locally as `until + ":00"` after success.
- Routes.php has mixed line endings in the repository; I kept them as they were (diff is two added lines). Tests used `+30 days` because the factory's due date is 2026-12-01.
