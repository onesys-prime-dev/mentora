# Task 4.2 report: student routes and "Your group" card

Status: DONE_WITH_CONCERNS (Playwright spec written, not run). Nothing committed.

## Files
- app/Controllers/Courses/Groups/StudentGroupController.php (new): show, join, leave, switch; 404 for foreign assessment/group, 422 {message, errors} for StructureException.
- app/Config/Routes.php: 4 routes in the student `api/courses` group only (my-group, join, leave, switch).
- app/Views/pages/courses/features/assessments/partials/student-group-card.php (new): container, shared alert templates (warning/info), live region, leave confirm (components/confirm).
- app/Views/pages/courses/features/assessments/assessment-types/views/assignment.php: includes the card for non-instructor viewers.
- resources/js/pages/courses/groups/student-card.js (new), api.js (fetchMyGroup, sendMyGroup), features/assessments/index.js (init call).
- tests/feature/StudentGroupRoutesTest.php, tests/js/pages/courses/groups/student-card.test.js, tests/e2e/group-activity-student.spec.js (new).

## Commands and results
- Red PHP: `phpunit tests/feature/StudentGroupRoutesTest.php` before the controller: `Tests: 11, Assertions: 18, Errors: 2, Failures: 6` (routes 404).
- Green PHP: same file `OK, but there were issues! Tests: 11, Assertions: 58` (issue = no coverage driver warning).
- `phpunit --filter 'Group|Assessment'` (ports 3307): `Tests: 965, Assertions: 3137, PHPUnit Warnings: 1`, OK.
- Red Vitest: with student-card.js moved aside, `Test Files 1 failed (1)` (failed to resolve import). Note: the JS was written before the test file, so the red was shown afterwards by removing the module, not by a pre-implementation run.
- Green Vitest: new file 12 passed; whole suite `Tests 1189 passed (1189)`.
- eslint on groups JS, assessments index.js, new test and e2e spec: clean. phpstan on the controller: no errors.

## Behaviour notes
- GET returns exactly the planned shape; `otherGroups` is only present when `groups_visible`; members are `{id, name}` only. `candidates` (from candidateGroups) do carry member names, as specified.
- `inPool=false` student: `blocked=false`, no candidates, join gives 422 "You are not part of this activity".
- `canLeave` is false when frozen, offering finalized, or leader chosen and non-leaders may not leave (reason text in `canLeaveReason`).
- Not-found vs refusal: assessment of another course is 404 via assessmentscope; a student of another course and staff on the student routes are refused by the existing filters (tests assert not 200).
- Card: skeleton, error with Try again, filled, removed entirely for non-group assignments; Join/Switch/Leave toast + live-region announce + refresh; 422 shows toast and inline message with state kept; Leave confirm states the consequence; buttons full width below sm.

## NOT run
- Playwright `tests/e2e/group-activity-student.spec.js` was not run (e2e DB on :3306 is broken). Selectors and the SQL helper statements are unverified and may need fixes.
- Manual visual check at 360 px / dark mode not done in a browser.

## Deviations / concerns
- The card is only on the Assessments-tab assignment view, not on the module learning-item assignment view (which shares assignment-work.php); that is a gap if students reach assignments through modules.
- Join/switch are offered only when `formation_mode = self_signup` (the service enforces it); the editor does not expose formation mode on the activity yet, so the e2e spec sets it by SQL.
- No leader, vote, rename, propose, submission or grade UI, as instructed.
