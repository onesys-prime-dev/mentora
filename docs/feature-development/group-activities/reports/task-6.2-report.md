# Task 6.2 report: group activities in the student assignment submission flow

Branch: `feat/group-activities-p6-submissions` (uncommitted).

## What changed

### `app/Services/Courses/Assessments/AssignmentSubmissionService.php`
- New `protected GroupSubmissionResolver $resolver` (built in the constructor like the other dependencies; a test swaps it to force the race).
- `getState()`:
  - Shared styles (`shared`, `leader_only`) read the group's rows (`group_id`), so attempts used, latest, counted and history are the group's. Per-member and non-group read `student_id` as before.
  - Shared attempts get a `submitted_by` display name (profile name, else username). Non-shared attempts get no new key.
  - The due date used by `blockingReason` is `GroupSubmissionResolver::effectiveDueDate()` (group extension for shared styles; unchanged for everyone else).
  - The resolver's refusal (`needs_group`, `leader_only`, `not_in_pool`) is the reason when the existing rules find nothing; existing reasons (closed, draft, not open, past due, limit, no way to submit, module lock) still win, in the same order.
  - New `group` key, only on a group activity: `{name, canSubmit, blockedReason, blockedCode, submittedBy, extensionUntil}`. All existing keys unchanged and in the same order.
- `submit()`:
  - Refusal still comes from `getState()` (now group-aware), returned in the usual `['success' => false, 'message' => ...]` shape.
  - Attempt number from `attemptNumberFor()`; late flag from `effectiveDueDate()`.
  - Shared styles write `group_id` and `submitted_by_user_id = submitter`; per-member and non-group write both as NULL.
  - The 1062 handling is untouched and covers the new `(assessment_id, group_id, attempt_number)` key: a teammate taking the same attempt number at the same moment gets "You've already submitted this assignment.".
  - After a successful insert: `ActivityGroupMembershipService::freezeGroup()` for the student's active group (any style); `completeOnSubmission()` for every member on shared styles, the submitter only otherwise.

### `app/Services/Groups/GroupSubmissionResolver.php` (from 6.1, uncommitted)
- `effectiveDueDate()` now applies an extension only for shared styles (D11: "Per-member style uses each student's normal deadline"). Before, it applied the group extension to per-member groups too.
- `sharedGroup()` made public so the submission service reuses it instead of re-deriving the style rule.

### `app/Services/Storage/FileAccessService.php`
- A file on a shared group submission can be opened by any member of that group. Without this, the teammate's Submission History showed the group's file but the download link returned 404. No other access rule changed.

## Tests
New `tests/database/GroupAssignmentSubmissionTest.php` (16 cases incl. data sets):
ungrouped blocked/solo allowed, shared row stored once with group and submitter, attempt limit per group, second member sees the group submission (with submitter name) and cannot go over the limit, leader-only refuses a non-leader after finalization, extension makes a late submission on time, late without extension is refused or flagged per the existing setting, per-member uses own row/deadline/attempts (extension ignored), first submission freezes the group (shared and per-member), learning completion for every member on shared (submitter only on per-member), non-group behaviour unchanged (exact state keys), simultaneous duplicate attempt handled (resolver stubbed to return the taken number), closed still refuses / finalized behaves as before, groupmate can open the shared file.

- Red: `Tests: 16, Assertions: 51, Errors: 1, Failures: 10` (the 5 that passed were refusal/regression cases that already held).
- Green: `Tests: 16, Assertions: 83` OK.
- Wide filter `GroupAssignmentSubmission|AssignmentSubmission|Group|Assessment|LearningProgress|FileAccess|StudentRoute|Student.*Route`: `Tests: 1214, Assertions: 3870` OK.
- `FileRoute|ModuleRoute|LearningItem|CourseRoute`: `Tests: 59, Assertions: 557` OK.
- `Student` filter: `Tests: 883, Failures: 1`. The one failure is `GradeReportRoutesTest:224` (finalized-grade PDF report, which does not touch submission code). Run alone it passes (`Tests: 9, Assertions: 38` OK), so it is order-dependent in the wide run and not caused by this change.
- phpstan on the 3 changed PHP files: `[OK] No errors` (5 s).

## Findings and concerns
1. **Finalized offering does not block student submissions, and never did.** No code path in the student hand-in checks `GradeFinalizationService`. I kept that ("unchanged for non-group"), so a group member in a finalized offering can submit as before, and that submission freezes the group (a system write, as noted in the 3.2 report). If group submissions should refuse on finalized offerings, that is a product decision; it would also change solo behaviour unless done group-only.
2. **Not touched (Phase 7):** `AssessmentService::viewAssessment` score/feedback panel (`scoreResult`, `feedback_thread`) and `LearningProgressService::scoresFor` (min-score prerequisites) still read the student's own rows. A non-submitting member sees the group's submission in Submission History, but the score panel stays empty until Phase 7 wires grades through the resolver.
3. `LearningProgressService::completionRule` (manual "mark complete") still checks the student's own rows. That is harmless today because shared submissions already write a completion for every member.
4. A student's solo rows from before joining a group (group_id NULL) are not shown once they read the group's history. That is an edge case: groups freeze at the first submission.
5. Extra queries per `getState` call on non-group assignments (one config lookup and one membership lookup from the resolver). Results are identical.
