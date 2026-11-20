# Task 7.3 report: group grades in the gradebook and the student result

Status: DONE_WITH_CONCERNS (see Concerns)

## Design
`AssessmentResultService::resultFor(bool $isQuiz, array $attempts)` is a pure function that ~10 screens call with
attempts they already loaded, so it stays unchanged. One new method sits next to it:

`AssessmentResultService::memberAttempts(int $assessmentId, int $studentId, array $ownAttempts): array`
- Not a group activity (no `activity_group_configs` row): returns `$ownAttempts` untouched (asserted with `assertSame`).
- Group activity: adds the student's shared group rows (`GroupSubmissionResolver::sharedGroup`) and every row the
  student has a `group_member_scores` row on (only handed-in statuses are added). Then each row carries the student's
  OWN member row:
  - `score` / `percentage` = `final_score` / `final_percentage`; `member_score` = that row only (group_points,
    individual_points, decoded individual_rubric_scores, adjustment, adjustment_reason, private_note, final, excused, pending).
  - `is_graded` = the submission is graded AND pending = 0 AND final not null. So a pending late add, a reopened group
    and an ungraded group all come out as "pending, no score", never zero.
  - `excused` = 1: counted, flagged `excused`.
  - A shared row (group_id set) with no member row for this student is not graded for them (score null).
  - Rows with group_id NULL and no member row (solo students on a `solo` activity, per-member rows graded the old way)
    pass through as today.
- Only `gms.student_user_id = $studentId` is ever queried, so a teammate's adjustment, reason, note, individual
  scores or final cannot reach the payload.
- The config lookup is memoised per service instance.

Three SQL helpers on the same class (`MEMBER_SCORE_SQL`, `memberJoinSql`, `memberRowSql`) apply the same rule to the
two "grade posted" lists that read `assessment_submissions` directly.

## Files
Changed
- `app/Services/Courses/Assessments/AssessmentResultService.php`: `memberAttempts` and the SQL helpers.
- `app/Services/Courses/Grades/GradebookSyncService.php`: `writeScore` reads `memberAttempts`. An excused counted row
  is written as `is_missing = 1`, `raw_score/raw_percentage NULL`, `assessment_submission_id` = the graded row, the same
  shape as `setManualScore(excused)`. The published-scheme recalculation in `syncItemScore` is unchanged, so it runs once
  per member per sync. `syncAssessmentScores` now also covers students who have a member score but no own row.
- `app/Services/Courses/LearningProgressService.php`: `scoresFor` (min-score prerequisites) uses `memberAttempts`.
- `app/Services/Courses/Assessments/AssessmentService.php`: `viewAssessment` `scoreResult` uses `memberAttempts`, so the
  counted row is the shared submission and its feedback thread is the group thread.
- `app/Services/Courses/Assessments/AssignmentSubmissionService.php`: `getState` uses `memberAttempts` for
  `attempts/counted/result`, which also feeds the Modules learning-item score panel (`ModuleService`). `attemptsUsed`
  is still counted from the raw rows.
- `app/Services/Courses/Assessments/AssessmentOverviewService.php`: the student list state/score uses `memberAttempts`
  for assignments. `recentFeedback` uses the member SQL helpers.
- `app/Services/Courses/ActivityFeedService.php`: the student "Grade posted" events use the member SQL helpers.
- `app/Views/pages/courses/features/assessments/partials/score-panel.php`: when the counted row has `member_score`, a
  `data-group-result` list shows the group part, the student's individual part, each rubric criterion with its scope
  (group criteria come from the shared row, individual ones from the student's own row), the student's own adjustment
  and reason, and their private note. An excused result shows "Excused" in place of a score. "Submitted by" is already
  shown by `assignment-work.php` (`assignmentState.group.submittedBy`).

New
- `tests/database/GroupGradebookTest.php` (15 tests).

Not touched: `GroupGradingService`, `GradingEngineService` (it already skips `is_missing`; reports and exports read
`gradebook_item_scores`), Grade Work (Task 7.4).

## Tests
- Red (before any production change): `Tests: 14, Assertions: 24, Errors: 4, Failures: 9`. The errors were the missing
  `memberAttempts`. The failures were: non-submitters had no gradebook row, excused was not missing, per-member excused
  was not missing, 1 of 4 final-grade rows, regrade and reopen cells, `syncAssessmentScores` wrote 1 of 3, the
  min-score lock was wrong, and the own breakdown was missing from the view. One test, `testUngroupedSoloStudentUsesOwnSubmission`,
  passed in red as a regression guard.
- `testOverviewAndFeedShowTheMembersOwnFinal` was added after the feed and overview changes, so it was never red.
- Green: `GroupGradebook` 15/15 OK (84 assertions).
- `--filter 'GroupGradebook|GroupGrading|AssessmentResult|Gradebook|GradeWork|GradeReport|LearningProgress|Group'`: `OK, Tests: 757, Assertions: 3055`
- `--filter 'Assessment|Grade'`: `OK, Tests: 1848, Assertions: 6082`
- `--filter 'ActivityFeed|Overview|Dependency|Student|Parent|Dashboard|Module|Learning'`: `Tests: 2079, Failures: 1`. The failure was
  `ParentProfilePageTest::testWhilePortalIsOffTheAccountStaysAndTheChildrenGoAway`, which passes alone (`OK, Tests: 11`).
  It depends on test order and does not touch this change (the parent profile page reads none of the changed code).
- phpstan on the 7 changed PHP files: No errors (finished in under 90 s).

The privacy test renders `viewAssessment` as two other members. It also serialises `getState` and the result payload.
It asserts that member 1's reason, note, adjustment (`-4.00`) and final (`11.00`) are absent, and that the group
feedback is present.

## Consumer table
| Consumer | Reads a student's score / submission by student_id? | Group member affected? | Decision |
|---|---|---|---|
| `GradebookSyncService::writeScore` / `syncItemScore` | yes | yes | CHANGED: member final, excused becomes is_missing, scheme recalculated as today |
| `GradebookSyncService::syncAssessmentScores` | student list from submissions | non-submitters missed | CHANGED: union with member-score students |
| `LearningProgressService::scoresFor` (min-score prerequisites) | yes | yes | CHANGED |
| `AssessmentService::viewAssessment` (scoreResult, score panel, feedback thread) | yes | yes | CHANGED |
| `AssignmentSubmissionService::getState` (assignment page and Modules item via `ModuleService`) | group rows already, raw scores | yes (group part shown as own) | CHANGED |
| `AssessmentOverviewService::forStudent/studentState` (student list, metrics) | yes | yes (non-submitter shown "Missed") | CHANGED |
| `AssessmentOverviewService::recentFeedback` | yes (SQL) | yes | CHANGED (member SQL) |
| `ActivityFeedService::forStudent` "Grade posted" | yes (SQL) | yes | CHANGED (member SQL) |
| `score-panel.php` | renders counted row | yes | CHANGED (own breakdown, Excused) |
| `AssessmentOverviewService::forInstructor`, `AssessmentService::submissionCounts`, `PendingGrading`, `UngradedWork`, `MonitorCollegeService`, `MonitorProgramService` | per-row instructor counts | counts the group row once, by its submitter | LEFT: instructor stats belong to Grade Work (7.4) and P9 |
| `GradeWorkService` (list, `submitGrade`, reopen, `syncItemScore` on submitter) | yes | yes | LEFT for Task 7.4. A shared row graded by plain Grade Work has no member rows, so every member now shows pending for it |
| `QuizAttemptService`, `QuestionService`, `AssessmentService` line ~827 (quiz state), `AssessmentRescoreService` | quizzes | no (groups are assignments only, D12) | LEFT |
| `GradingEngineService`, `GradeReportService`, `GradeSheetExportService`/`Import`, `GradeAdjustmentService`, `GradeFinalizationService`, `GradingSchemeService` | `gradebook_item_scores` only | covered by the sync | LEFT (no change needed) |
| `AssessmentFeedbackService::postMessage` | `submission.student_id === user` | yes: a non-submitter sees the composer but the reply is refused | LEFT (concern) |
| `ParentNotifier` missing-work query | NOT EXISTS submission by student_id | yes: a non-submitter can be reported missing to parents | LEFT for Task 9.1 (concern) |
| `InstitutionExport`, `ContentDeletionService`, `ContentTransferService`, `FileAccessService`, `StorageService`, `AssessmentScopeService` | export / ownership / scoping, no score decision | `FileAccessService` already has Phase 6 rules | LEFT |

## Concerns
1. Replying on the group feedback thread: `AssessmentFeedbackService::postMessage` still allows only the submitter
   (`submission.student_id`). Teammates now see the thread and the composer, but their reply is refused with "You can
   only reply to your own submission." The fix is small: allow it when the submission's `group_id` is the user's
   `sharedGroup`. It was left out because it changes authorization; it fits 7.4 or a quick follow-up.
2. `ParentNotifier` (missing-work alerts) still checks for a submission by student_id only, so a teammate who did not
   submit can be reported as missing to their parents. Left for Task 9.1, which already covers parent rules.
3. Until 7.4, a shared group row graded through the plain Grade Work has no member rows. By the 7.3 rule ("no row
   yet for a shared graded submission means pending") every member, including the submitter, now shows pending and the
   gradebook cell is removed on the next sync. Before this task the submitter got the raw score.
4. An excused member whose final is null (excused while pending) gets an `is_missing` cell. In `scoresFor` such a
   row reads as 0 %, so a min-score prerequisite stays locked. This is an edge case for dropped students.
5. `memberAttempts` costs one indexed config lookup per (assessment, call) for non-group work. The student overview
   calls it once per assignment.
6. No `GroupGradingService` bug was found.
