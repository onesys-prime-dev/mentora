# Task 7.4 report: Grade Work group view, endpoints, post-grading dialogs, student-side fixes

Status: DONE_WITH_CONCERNS (see Concerns)

## Files
New
- `app/Controllers/Courses/Groups/GroupGradeController.php`: `index` (list), `show` (detail), `grade`, `reopen`, `lateDecision`, `dropDecision`. The writes go to `GroupGradingService`. A `StructureException` gives a 422 with `{success:false, message, errors}`. An assessment of another offering, an assignment that is not a group activity, a group that is not active in this activity, or a student who is neither in the group nor holds a score row from it all give 404.
- `app/Views/pages/courses/features/assessments/group-grade-work.php`: the frame. It has the same header as Grade Work, the lock-note, the status slot, a two-column layout (it stacks below lg), a live region, and three dialogs: late add (separate/inherit, reason), drop (keep/excuse, reason) and the reopen confirm. It uses only `dialog`, `confirm`, `alert`, `lock-note`, `status-badge` and `form-field`.
- `resources/js/pages/courses/groups/grading.js`: the screen 2B module (list, detail, live totals, atomic save, reopen, decisions, read-only).
- `tests/feature/GroupGradingRoutesTest.php` (17 tests), `tests/js/pages/courses/groups/grading.test.js` (17 tests), `tests/e2e/group-grading.spec.js` (Playwright, NOT RUN).

Changed
- `app/Config/Routes.php`: the six routes in the instructor and chairperson `api/courses` groups (same filters as the neighbouring group routes: coursemember, assessmentscope, closedoffering, coursepermission, plus chairpersoncourseaudit for the chairperson). The dean group gets the two GETs. Students get no routes.
- `app/Filters/CoursePermissionFilter.php`: the rule `#^assessments/\d+/group-grading(/|$)#` → `grading.manage`. Without it the path fell to `courses.manage`.
- `app/Services/Courses/Assessments/GradeWorkService.php`:
  - `gradeWork` now draws `group-grade-work` for a group activity. A quiz, a non-group assignment, and a `?student=` who is in no group (solo policy) still get the unchanged per-student page.
  - New `groupRefusal`, called from `submitGrade`, `gradeQuestion`, `reopenGrading` and `startGrading`. It refuses a row with `group_id`, and also a per-member row whose student has an active group in a group activity. The message is "This is a group submission. Grade it from the group view." and the code is `group_submission`.
  - `listStudentsForAssessment` is untouched.
- `app/Controllers/Courses/Assessments/GradeWorkController.php`: the four write actions go through `answer()`, which sets 422 for `code=group_submission`. Every other answer stays 200, as before.
- `app/Services/Courses/Assessments/AssessmentFeedbackService.php`: `postMessage` lets a student reply when the submission's `group_id` is their active shared group (`GroupSubmissionResolver::sharedGroup`). Non-members are still refused.
- `resources/js/pages/courses/groups/api.js`: `fetchGroupGrading`, `fetchGroupGradingDetail`, `sendGroupGrading`.
- `resources/js/pages/courses/features/assessments/index.js`: `loadGradeWork` starts `initializeGroupGrading` when the swapped HTML has `[data-group-grading]`. Otherwise it runs `initializeGradeWork` as before.

## Payloads
- List: `{groups:[{id,name,status,memberCount}], ungrouped:[{student_user_id,name,hasSubmission}], ungroupedPolicy, blockers:[string], flags:{canWrite,finalized}, lockReason}`.
  - `status` is `not_submitted`, `needs_grading`, `graded` or `pending_members`. `pending_members` wins over `graded` while a late add has `pending=1`.
  - `blockers` names the ungrouped students when the policy is block.
- Detail: `{group, status, totalPoints, config:{gradingMode, allowsAdjustment, usesAverage, submissionStyle, isRubric}, submission:{id, attemptNumber, status, isGraded, submittedAt, isLate, submittedBy:{id,name}, workHtml}|null, criteria:[{id,title,description,max_points,scope}], groupScores, groupScore, feedback, members:[...], flags:{canWrite, finalized, graded}, lockReason}`.
  - `workHtml` is drawn with the existing `partials/submitted-work.php`.
  - Each member has `{student_user_id, name, isLeader, hasSubmission, submission (per_member), individualScores, individualScore, adjustment, reason, note, final, finalPercentage, pending, excused, removed}`.
  - `removed` means a score row in this group for someone no longer a member.
  - `canWrite` is false for the dean or a viewer without `grading.manage` (`CourseAbilities::viewOnly('grading')`), and on a finalized or closed offering.

## UI (screen 2B / 4D / 4E)
- **Sidebar:** each group with a status badge in words and its member count. The selected group has `aria-current`. Below them, "Ungrouped (n)" and the blockers. Under the solo policy, an ungrouped student with a hand-in gets a "Grade" button that opens the unchanged per-student page.
- **Main panel, top:** "Submitted by X (leader), date · on time/late", the attempt badge and the work.
- **Main panel, group part:** group criteria inputs (or one group score), the group's feedback thread and a new feedback box.
- **Member rows:** individual criterion inputs or an individual score, then adjustment, reason and private note when `allowsAdjustment`. Each row has a live total (same sum and clamping as the server) and the hint "visible only to that student".
- **Save:** "Save grades for N members" (one POST, all or none). A missing reason is shown beside that member before anything is sent. A server 422 `reason` is placed beside each adjusted member; any other 422 or a network failure is the form message. Typed values are kept.
- **Late enrollee:** a "Joined after grading" badge and a "Decide…" dialog with a reason. Save is blocked until every late member has a decision.
  - "Grade separately" opens their inputs for this session.
  - "Inherit" reloads.
- **Removed graded member:** "Keep or excuse…" dialog with the current score and a reason. Excused shows a badge.
- **Graded group:** read-only, with "Reopen grading" behind a confirm.
- **Read-only (dean, finalized):** the lock-note with its reason, and no input, textarea, select, submit or action button. Adjustment reasons stay visible (4E).
- **Four states:** skeleton, "No groups yet" empty state, error with Try again, filled. At phone width, member rows stack and the buttons are full width. Only the shared tokens and status tones are used.

## Commands and results
- Red, PHP (`--filter GroupGradingRoutes`, before any production code): `Tests: 17, Assertions: 26, Failures: 16`. Everything was a 404 or a wrong filter rule, plus the guard (200 ≠ 422) and the group view missing. `testForeignIdsAre404` passed in red, trivially, because no routes existed yet.
- Red, Vitest (`grading.test.js`): `Test Files 1 failed (1), Tests no tests`, because the module was missing.
- Green: `GroupGradingRoutes` gives `OK, Tests: 17, Assertions: 168`. `grading.test.js` gives `Tests 17 passed (17)`.
- `env 'database.tests.port=3307' 'database.tests.hostname=127.0.0.1' vendor/bin/phpunit --filter 'GroupGradingRoutes|Group|GradeWork|Assessment|Feedback'` gives `OK, Tests: 1258, Assertions: 4295` (the only warning is "no coverage driver").
- `npx vitest run` gives `Test Files 120 passed (120), Tests 1231 passed (1231)`.
- `npx eslint` on grading.js, api.js, assessments/index.js, grading.test.js and group-grading.spec.js: no problems.
- phpstan on the 6 changed or new PHP files: `[OK] No errors` (7 s).

## NOT run
- Playwright `tests/e2e/group-grading.spec.js` was written by convention and never run, because the e2e DB on :3306 is broken. Expect selector fixes on the first run, especially the member-row lookup by name and the SQL fixture inserts.
- The full `composer test` was not run, only the filter above.

## Deviations
- **Dean UI:** the dean has no Grade Work page route today. Only the dean GET endpoints were added. The JS read-only mode is tested in Vitest, but the dean page itself belongs to Task 9.2 (dean read-only views).
- **Solo work:** under the solo policy, `gradeWork` with `?student=` of an ungrouped student still opens the per-student page. Without this, solo work of a group activity could not be graded at all.
- **`startGrading` and `reopenGrading`:** both are guarded too, not only `gradeWork`, `submitGrade` and `gradeQuestion`.
- **When inputs are open:** inputs are editable while the status is `needs_grading` or `pending_members`. `graded` is read-only until reopened.
- **No "Save draft":** as instructed.

## Concerns
1. **"Grade separately" is not stored.** The server only audits it and the row stays `pending=1`. The view remembers the choice only for the open session. After a reload the dialog is offered again (choosing again writes another audit row). A persistent "decided" flag would need a column.
2. **Removed students' names:** they are looked up from `users`/`user_profiles` directly, so a deleted user shows "Former member".
3. **Per-member style:** feedback and group criteria come from the first member who handed in, because `gradeGroup` writes the same feedback to every member's row.
4. **Ungrouped students can show no hand-in:** their hand-in is counted only for rows with `group_id NULL`. A solo student whose old row has a `group_id` from an earlier group would show none.
5. **Navigate skeleton:** the per-student Grade Work skeleton still shows while the page loads, before the group frame arrives.
