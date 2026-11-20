# Task 7.2 report: GroupGradingService

Status: DONE (see Concerns)

## Files
New
- `app/Database/Migrations/2026-12-01-000010_CreateGroupMemberScores.php`: table `group_member_scores` as ruled (DECIMAL 8,2 for points/adjustment/final, 5,2 for final_percentage, matching `assessment_submissions.score`/`percentage`). UNIQUE(submission_id, student_user_id); FKs: submission CASCADE, student CASCADE, group SET NULL, graded_by SET NULL. Reversible (`dropTable`).
- `app/Models/Groups/GroupMemberScoreModel.php`
- `app/Services/Groups/GroupGradingService.php`
- `tests/database/GroupGradingServiceTest.php` (19 tests)
- `tests/database/CreateGroupMemberScoresMigrationTest.php` (shape, unique key, down/up)

Changed (minimal)
- `app/Services/Groups/ActivityGroupMembershipService.php`: `addLateEnrollee` now calls `GroupGradingService::onMemberAdded`. In `onStudentDropped` the Phase 3 comment is replaced by a one-line pointer to `resolveDropped`. Behaviour does not change: the score row stays, which is the "keep" decision.

Not touched: controllers, routes, views, `AssessmentResultService`, `GradebookSyncService` (only `syncItemScore` is called).

## Behaviour
- `gradeGroup` refuses when the offering is finalized (`GroupSetService::refuseIfFinalized` → `lockRefusal`), when the assignment is not a group activity, or when the group is not an active group of this activity's config. It also refuses member keys from outside the group. Members that are not listed get no adjustment.
- Rubric (criteria exist): the scopes decide. Group points are the sum of the group criteria and individual points the sum of the member's individual criteria. Points are clamped to 0..max_points, and a missing criterion scores 0, as in `GradeWorkService::submitGrade`. A criterion id that is in another scope or another rubric is refused.
- Single score: `collective` gives everyone the group score. `collective_adjust` adds the adjustment to the group score. `individual` uses each member's own `score` (group_points NULL).
- `use_average` with the per_member style: every graded member's final is the average of the `score` inputs of members who handed in. Members with no hand-in are left out of the average and stay ungraded. Each member keeps their own input in `individual_points`.
- Adjustments are allowed only for `collective_adjust` or a rubric with an individual criterion. A non-zero adjustment needs a reason (trimmed, 1-500 characters, error field `reason`). A zero adjustment stores reason NULL.
- Final = clamp(group + individual + adjustment, 0, total_points). final_percentage = final / total × 100, rounded to 2 places.
- Shared and leader_only styles: the latest handed-in group attempt (status in `FINISHED_STATUSES`) is required, otherwise "This group has not submitted yet." The submission's score is the group part, and `rubric_scores` holds the group criteria only. Status becomes Graded, is_graded 1, graded_by and graded_at are set. Feedback is appended once to the shared row through `appendInstructorMessage`, which also dedupes a repeated text.
- per_member style: each member's own latest hand-in row (group_id NULL) gets score = final and `rubric_scores` = group criteria merged with that member's individual criteria. Feedback goes on each of these rows. A member with no hand-in is skipped and listed in the `groups.graded` audit as `skipped`.
- One transaction covers the submission updates, the feedback rows and the score upserts. On any throwable or failed query it rolls back and throws "The grades could not be saved. Nothing was changed." After commit it writes the audits (`groups.graded` once with mode/graded/skipped, and `groups.adjusted` per non-zero adjustment with old/new/reason), then calls `syncItemScore` for each member inside a try/catch that logs failures.
- A regrade upserts on (submission_id, student_user_id), so no rows are duplicated. `excused` is never overwritten, and `private_note` changes only when `note` is in the input. `pending` is set to 0.
- `onMemberAdded`: for shared styles with a graded group submission, it inserts a row with pending=1 and final NULL, unless a row already exists.
- `resolveLateAdd`: needs a reason and a valid decision, and a pending row. `inherit` sets pending=0, group_points = the shared row's score, individual NULL and final = the group part, then syncs. The audit flags `group_part_only` when the rubric has individual criteria. `separate` only writes the audit, and the row stays pending until the next `gradeGroup`.
- `resolveDropped`: works whether or not the student is still a member. `excuse` sets excused=1 and keeps the final score as history. `keep` sets excused=0. Both are audited and synced.
- `reopenGroup`: the group's Graded submissions go to status Submitted with is_graded 0, as the ruling says. Member rows get final_score and final_percentage NULL, while notes and adjustments are kept. It runs in one transaction, then audits `groups.grading_reopened` and syncs. If nothing is graded it refuses with "Only graded work can be reopened."
- `memberScoreFor`: returns the row with `individual_rubric_scores` decoded, or null.

## Tests
- Red: `Tests: 20, Assertions: 1, Errors: 19, Failures: 1, PHPUnit Warnings: 1.` (service and table missing)
- Green, new tests: 20/20 OK. One assertion was relaxed from assertSame to assertEquals because AuditLogger's JSON drops `.0` from floats.
- `--filter 'GroupGrading|GradeWork|Group|Rubric'`: `OK, Tests: 741, Assertions: 2787` (the warning is only "no coverage driver").
- `--filter 'Assessment|Grade'`: `OK, Tests: 1833, Assertions: 5998`
- phpstan on the 4 changed or new PHP files: No errors (14 s).
- The rollback test leaves the isolation transaction so a real rollback can be seen. It is the same pattern as `ActivityGroupServiceTest::testAttachIsAtomicOnFailure`. Its committed rows are cleaned in `finally` (audit rows after the start id, the assignment, the offering). Users and the course stay behind, as in the existing test.

## Concerns
- Reopen sets status `Submitted`, as ruled. Single `reopenGrading` uses `Grading`, so the two paths differ slightly.
- `onMemberAdded` does nothing for the per_member style, because the new member has no submission row to hang a score on (submission_id is NOT NULL). They are graded when they hand in their own work.
- Until Task 7.3, the gradebook sync does not see `group_member_scores`. For shared styles, `syncItemScore` still reads only the submitter's own row, which holds the group part. Excused rows have no gradebook effect yet.
- In `individual` mode with a shared submission, the shared row's score is 0, because there is no group part.
- Feedback is written only when the grader's user can be loaded (`findById`). Callers always pass a real user.
