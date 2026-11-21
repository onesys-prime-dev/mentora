# Task 9.2 report: read-only group views for the dean (4E) and the parent (4F)

Status: DONE_WITH_CONCERNS. Nothing is committed.

## Results
- `--filter 'Dean|Parent|Group'` on port 3307: `OK, Tests: 1775, Assertions: 7756`. The only warning is "No code coverage driver available".
- After the phpstan tidy-up, `GroupReadOnlyViewsTest` was rerun on its own: `OK, Tests: 7, Assertions: 114`.

## What exists and what was added

### Dean
- **Today:** the dean opens a course read-only under `dean/courses/...`. `coursesConfig.role` is `dean`, so clicking
  an assessment opens the **assessment view page** (`dean/api/courses/:id/assessments/:a/view`). The dean has no Grade
  Work route. The dean GET endpoints for group-grading (list and detail), activity groups, learning-item groups,
  proposals and the Groups tab already exist. `GroupGradeController::access()` already answers `canWrite=false` and
  `lockReason = "View only. You can see the groups and their grades but not change them."` for the dean.
- **Groups tab (sets list):** exists from Phase 1 and is read-only for the dean (`GroupsTabTest::testTheDeanReadsWithoutAnyWriteControl`).
  Nothing was added.
- **Added:** a read-only "Groups" section on the dean's assessment view page, drawn only for a dean on a group
  assignment. It uses the existing frame and `grading.js` read-only mode against the existing dean GETs. No new route or page.
  - `AssessmentService::viewAssessment` passes `deanGroupView` (assignment + `CourseViewer::isDeanViewer` + an
    `activity_group_configs` row).
  - `assessment-types/views/assignment.php` draws `<section data-dean-groups>` with the frame.
  - The frame (lock-note, status slot, list, detail, live region) moved to the new partial
    `partials/group-grading-frame.php`. Grade Work (`group-grade-work.php`) includes it and keeps its dialogs; the
    dean's copy has **no dialogs, forms or inputs**.
  - `assessments/index.js` `loadAssessmentView` calls `initializeGroupGrading` on `[data-dean-groups] [data-group-grading]`.
    With no `gradeStudent` callback, no solo "Grade" buttons are drawn.
  - `grading.js`: the empty state for a read-only viewer now says "This assignment has no groups yet. They appear
    here once the instructor adds them." It no longer tells the viewer to use the editor.
  - What the dean sees, all from the existing payload:
    - the group list with status badges in words
    - the submitter and the work
    - group scores and criteria as text
    - the group feedback thread
    - each member's individual scores, adjustment, reason, private note ("Private note: ..."), total, and Excused or "Joined after grading" badges
    - the lock-note "View only. ..."
    - no input, textarea, select, submit or action button
- **Write refusal:** `DeanCourseViewTest::testNoRouteExistsToChangeAnythingInACourse` already POSTs, PUTs, PATCHes and
  DELETEs every chairperson write route at the dean address, and asserts that no dean write route exists. The new test
  adds an explicit sample of every group route family and checks that the group tables are unchanged afterwards.

### Parent
- **Today:** a parent has four read-only tabs on the child's course. The Assessments tab shows title, due date and a
  status word, and **never a score** (by design). The Grades tab and the Grades page show category and overall
  standing only. No parent page showed any per-assignment score.
- **Added (4F):** on the parent Assessments tab, a group activity row gets a `data-parent-group` block:
  - "Group: Team 1 (Ann, Ben, Cai, Dee)". These are the **first names** of the members of the child's own active
    group, as in mockup F, so teammates' full names are not exposed to another student's parent.
  - Once the child's own member row is graded (not pending): a status-badge "Grade 14.5 / 20", then
    "Group part 7.5 + individual part 6 · Adjustment +1: <reason>", then "Other students' scores and adjustments are not shown."
  - Excused shows an "Excused" badge.
  - Pending or ungraded shows the group line only.
- **Numbers:** the grade comes from `AssessmentResultService::resultFor(false, memberAttempts(...))['counted']`, the
  same path as the student's result and the gradebook. Only the child's own `group_member_scores` row is ever read.
- **Non-group assignments and quizzes are unchanged:** `group => null`, no score shown.
- **Access** is unchanged: the `parentportal` and `parentchild` filters, plus `childUser`/`childOffering`. An unlinked
  parent, or a teammate's id, gets 404.

### Parent-visibility decision
The parent sees what the child sees on their own result, except the private note. That means:
- shown: the final, the group part, the individual part, and the child's own adjustment and its reason (the student's
  score panel shows both)
- never shown: the private note text ("Note from your instructor"), and any teammate's score, adjustment, reason or
  note, or another group
- not shown: the group feedback thread and the submitted work (the parent tab has never shown feedback or work, and
  the privacy canary forbids it)

## Files
New
- `app/Views/pages/courses/features/assessments/partials/group-grading-frame.php`
- `tests/feature/GroupReadOnlyViewsTest.php` (7 tests)
- `tests/e2e/group-readonly.spec.js` (Playwright, NOT RUN)

Changed
- `app/Services/Courses/Assessments/AssessmentService.php` (`deanGroupView`)
- `app/Views/pages/courses/features/assessments/assessment-types/views/assignment.php` (dean Groups section)
- `app/Views/pages/courses/features/assessments/group-grade-work.php` (uses the frame partial; dialogs unchanged)
- `resources/js/pages/courses/features/assessments/index.js` (init on the view page)
- `resources/js/pages/courses/groups/grading.js` (read-only empty-state text)
- `app/Services/Parents/ParentCourseTabs.php` (`groupLine`)
- `app/Views/pages/parent/course/assessments.php` (group line and grade)
- `tests/js/pages/courses/groups/grading.test.js` (2 tests)

## Commands and results
All from `C:\xampp\htdocs\mentora`. The PHP runs used `env 'database.tests.port=3307' 'database.tests.hostname=127.0.0.1'`, one process at a time.
- Red, PHP (`--filter GroupReadOnlyViewsTest`, before any production change): `Tests: 7, Assertions: 70, Failures: 4`.
  - The 4 failures: the dean view had no `data-group-grading`, and three parent tests had no group line or grade.
  - 3 tests passed in red, as expected. Dean reads and dean writes refused were already true, and the unlinked
    parent was already refused. They are regression guards.
- Red, Vitest (`grading.test.js` against the HEAD `grading.js`): `Tests 1 failed | 18 passed (19)`. The read-only empty
  state still said "in its editor". The dean-frame test passed in red because `grading.js` already supported read-only
  mode without dialogs. It guards the no-dialog frame.
- Green, PHP: `GroupReadOnlyViewsTest` gives `Tests: 7, Assertions: 114` OK.
- Vitest whole suite: `Test Files 121 passed (121), Tests 1243 passed (1243)`.
- `npx eslint` on grading.js, assessments/index.js, grading.test.js and group-readonly.spec.js: no problems (after replacing `document` with `globalThis.document` in the spec).
- phpstan on ParentCourseTabs.php, AssessmentService.php and GroupReadOnlyViewsTest.php: `[OK] No errors` (about 9 s), after making an unused `$team2` property local.
- `--filter 'Dean|Parent|Group'`: `OK, Tests: 1775, Assertions: 7756` (see Results).

## NOT run
- Playwright `tests/e2e/group-readonly.spec.js`. It was written by convention and never run, because the e2e DB on :3306
  is broken. The dean account (`dean2`), the college of IT101, Lab 1's total points (the spec expects 20) and the SQL
  fixture are all unverified. Expect fixes on the first run.
- There is no Vitest for the `loadAssessmentView` wiring, because no test of that function exists to extend. The
  wiring is one call; the server-side frame is asserted in PHP and the module in Vitest.
- The full `composer test` was not run, only the filter above.

## Deviations
- No dean page or route was added: the dean's existing assessment view page hosts the view, as the task allowed.
- The frame markup was extracted into a partial instead of duplicated (Grade Work output is unchanged).
- Parent teammates are named by first name, per mockup F.

## Concerns
1. The parent Assessments tab was "never a score" by design. A group activity now shows the child's grade there,
   while a plain assignment still shows none. This follows D14/4F, but it is inconsistent across rows. The product
   owner may want either all assignment grades or none on that tab.
2. The adjustment reason is written by the instructor for the student. Parents now read it too. Instructors may need
   to be told, for example with a hint next to the reason field.
3. A child removed from the group after grading has no active group, so the parent sees no group line or grade for
   that row, even though the gradebook keeps the score.
4. `groupLine` runs a few small queries per assignment row: the group, the members, the own submissions and
   `memberAttempts`. That is fine for a course page, but it is not batched.
