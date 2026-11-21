# Task 10: security sweep, data-leak review, accessibility static review, static checks

Branch `feat/group-activities-p10-hardening`, uncommitted. PHPUnit ran on the scratch MariaDB (:3307), one process at a time, with `--filter` only.

## Findings

| # | Finding | Severity | Fixed | Test |
|---|---|---|---|---|
| 1 | `DELETE .../group-sets/{set}/members/{student}`, `.../assessments/{a}/groups/members/{student}` and `.../learning-items/{i}/groups/members/{student}` returned 200 `{success:true}` for a student who is not in that set or activity (for example a student of another course). Nothing was deleted, because membership is scoped by set or config, but the route did not answer 404 for a foreign id. Red: `6 problem(s): foreign member: DELETE instructor/api/courses/3/group-sets/3/members/21 -> 200 {"success": true}` (and the same for activity, item and the chairperson mirrors). | Low (no data changed or exposed) | Yes: `GroupSetsController::removeMember` and `ActivityGroupsController::removeMember` (the item controller inherits it) answer 404 when the student is not a member | `GroupRoutesSecuritySweepTest::testAForeignIdIsNotFoundOnEveryRouteForTheRightfulCaller` |
| 2 | `POST .../group-sets/{set}/generate/preview` answers 200 on a finalized offering. It writes nothing; saving (`/generate`) is refused with the lock message. | Info | No (accepted: a preview is a read) | Exempted in `testAFinalizedOfferingRefusesEveryWrite`, with a comment |
| 3 | `phpstan`: `SeededShuffle::shuffle()` by-ref list type (2 errors) | Low | Yes: shuffle a local copy, assign `array_values` back, `@param-out list<int>`. Same order; generator tests unchanged (16 OK) | `GroupGenerator*` tests |
| 4 | Student learning-item roster: "Done" drawn in `text-[#5C9F72]` (about 3.3:1 on white, below AA; no token) | Low (a11y) | Yes: success tone `#2B7549` | Vitest suite |
| 5 | Score panel group breakdown: "· Individual / · Group" scope in `text-[#8A8A8A]` on `#F9F9FC` (below AA) | Low (a11y) | Yes: muted token `#646464` | `GroupGradingRoutesTest` |
| 6 | Grade Work group view: every member row repeats the same labels ("Reason for the adjustment", "Adjustment", "Private note", criterion titles), so a screen reader did not say whose field it is | Medium (a11y) | Yes: a screen-reader-only ", {name}" in each member label | Vitest suite |
| 7 | Leader star `<span aria-label="leader">★</span>` (aria-label on a plain span is not announced reliably) | Low (a11y) | Yes: `aria-hidden` star plus `sr-only` "(leader)" | Vitest suite |
| 8 | Small action buttons (`SMALL` in detail.js, activity-panel.js, grading.js) are about 24 px tall on a fine pointer; they reach 44 px only on coarse pointers (`pointer-coarse:min-h-11`), which is the house convention elsewhere | Low (a11y) | No: house-wide convention, not a group-only issue | n/a |
| 9 | Student card: "Leader", "Switch to" headings are `h3`, siblings of "Your group" (`h3`) instead of nested under it | Low (a11y) | No: needs a heading-level pass across the card and its selectors | n/a |
| 10 | Group activity on/off switch is `disabled` when the offering is locked; the lock-note is shown beside it but not tied with `aria-describedby` | Low (a11y) | No | n/a |
| 11 | Score panel prints `(int) score / total` (pre-existing): a group final of 10.24 shows "10/20" while the percentage says 51.2% | Low (display) | No: pre-existing in main, affects all assessments | n/a |
| 12 | Feedback route `POST student/api/courses/{o}/submissions/{s}/feedback` ignores the offering id in the address (pre-existing); the service checks the author is staff of the submission's course, the submitter or a member of its group, so no leak | Info | No | `GroupGradingRoutesTest::testAnyMemberCanReplyOnTheGroupsFeedbackThread` |

## Task A: route sweep (`tests/feature/GroupRoutesSecuritySweepTest.php`)

The route list is read from a fresh copy of the real route table (`Services::routes(false)->loadRoutes()`): every route whose handler is in `App\Controllers\Courses\Groups`. 85 routes: instructor 51, chairperson 51 (mirror asserted), dean 8 (GET only asserted), student 13. Parent has no group route. Placeholders are filled from the preceding segment (courses, assessments, learning-items, group-sets, groups, members, group-grading, invitations). A route added later is swept automatically.

Tests (8, all green after fix 1):
- `testTheSweepFindsEveryGroupRouteOfEveryArea`: counts per area, chairperson mirrors instructor, no group route outside the four areas.
- `testTheRightfulCallerReadsEveryGetRoute`: control; every GET answers 200 to its rightful caller, so the addresses are real.
- `testEveryGroupRouteHasARoleAndTheReadOnlyFilterAndTheDeanOnlyReads`: each route carries `role:<area>` and `readonly` (the read-only institution case), no dean write route.
- `testNobodyWithoutTheRightRoleOrCourseGetsIn`: per route, refused (302/401/403/404/405, never 2xx or 500) for nobody, a parent (linked to a member), a student and a stranger instructor and another department's chairperson on instructor/chairperson routes, a dean on instructor writes, a student of another course, an instructor and a dean on student routes, a student and an instructor on dean routes. Requests carry a valid body so a missing check would succeed.
- `testTheDeanCannotWriteThroughTheDeanArea`: every instructor write path under `dean/` is refused.
- `testAForeignIdIsNotFoundOnEveryRouteForTheRightfulCaller`: each id position of each route replaced by the other offering's id of the same kind (set, assignment, item, group, member, grading group, invitation): 404 (a foreign offering: 403 or 404). Found finding 1.
- `testAForeignIdInTheBodyIsRefused`: ids that travel in the body (add member, move to group, generate save, set leader, late member, copy-from, item add/move, grade members, student join/switch/vote): refused, and no foreign student lands in this course's sets, groups or scores.
- `testAFinalizedOfferingRefusesEveryWrite`: every instructor, chairperson and student write on a finalized offering is refused and the body carries the lock message (`GradeFinalizationService::LOCKED_MESSAGE`, or the student card's "grades of this course are final"). Fixture made recopy and copy-from bodies valid so the lock, not validation, is what answers.

Not covered and why: messaging `Messaging\GroupController` routes (not this feature); the parent child pages and the assessment view/grades pages (not group routes; their payloads are covered in Task B); the institution area (has no group routes).

## Task B: data leaks

Reviewed: `StudentGroupController::show` (own group members `{id,name}`, candidates, other groups only with `groups_visible`, invitations, invitable), `StudentLearningItemGroupController::show`, `AssignmentSubmissionService::getState` group block, `AssessmentResultService::memberAttempts` (own `group_member_scores` row only; the shared submission row stores group-scope rubric scores only), `score-panel.php`, `AssessmentFeedbackService::postMessage`. No leak found.

Tests added (extending existing files):
- `GroupGradingRoutesTest::testAStudentSeesOnlyTheirOwnNumbersAndNoOtherGroupsWork`: rubric with rare numbers. Member u0's view, my-group, grades and assessment list never contain u2's final (10.24), percentage (51.2), individual (3.37), adjustment (1.13), reason or note; u0's view does show their own 2.11 and 50.55% (control). Team 2 and an ungrouped student see none of Team 1's work, feedback, member names or scores.
- `StudentGroupRoutesTest::testStateWithoutAGroupListsOpenGroups`: an open candidate group carries exactly `id, name, members_count, max, member_names, full`; member names are strings only.
- Already covered: other groups' members `{id,name}` only with no completion (`LearningItemGroupsRoutesTest`), non-member cannot post on a group thread.

The member's own private note is shown to that member; that matches the grading screen text ("visible only to that student").

## Task C: accessibility static review (no browser or axe run was possible)

Checked all new views (`groups/**`, `group-activity-section`, `student-group-card`, `group-grading-frame`, `group-grade-work`, `score-panel` group part, the learning item partials that include them) and `resources/js/pages/courses/groups/*.js`:
- Accessible names: every icon button has text or an `aria-label`; raw inputs have `<label for>` or a wrapping label; the rest use `form-field`.
- Dialogs: all use `components/dialog` / `components/confirm` and `shared/dialog.js` `createDialog` (focus trap, Escape).
- Status words: badges and "Done / Not done yet" carry words.
- Live regions: Groups tab, activity panel, student card and grading view each have one `aria-live` region that the JS writes to.
- Tables: the set list uses `data-table` with a caption; other lists are cards with headings.
- Headings: banner h1, page-header h2, set detail h3, groups h4; grading h2/h3 (see finding 9 for the student card).
- Drag and drop has the "Move…" button and dialog.
- Disabled controls: "Full" says why; the activity switch shows the lock-note (finding 10).
- Hex colours: all used hexes are the shared neutral/status palette that the dark block remaps, plus `#A82E2E` (same as `components/confirm`). Fixed `#5C9F72` and `#8A8A8A` (findings 4, 5).
Fixes: findings 4 to 7.

## Task D: static checks

- `vendor/bin/phpstan analyse --memory-limit=768M app/Services/Groups app/Controllers/Courses/Groups app/Models/Groups` plus the touched services, filter and GradeWork controller: 2 errors (finding 3), then 0.
- `npx eslint resources/js tests/js tests/e2e`: clean.
- `npx vitest run`: 1243 passed.
- PHPUnit `--filter 'GroupRoutesSecuritySweepTest|GroupGradingRoutesTest|StudentGroupRoutesTest|GroupSetsRoutesTest|ActivityGroupsRoutesTest|LearningItemGroupsRoutesTest|GroupsTabTest|GroupReadOnlyViewsTest'`: 116 tests, 1018 assertions OK. `--filter GroupGenerator`: 16 OK.

The full PHPUnit suite and Playwright were not run (memory; Playwright needs the broken :3306 database).
