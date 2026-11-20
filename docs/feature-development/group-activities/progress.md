# Progress

- Stage: 4 — Implementation, Phase 7 (group grading) in progress; Phases 1-6 committed (63b150cc, c918376b, bcfa5852, e556d79c, deab3c46, 404f5645)
- Phase: n/a
- Active branch: feat/group-activities-p7-grading (from p6)
- Last verified checkpoint: Stage 2 approved by user ("approved, proceed to Stage 3"); implementation-plan.md + test-strategy.md written
- Completed: workspace scaffold
- Pending: interview (one question per message)
- Exact next action: Phase 7: 7.1 rubric scope, 7.2 GroupGradingService + group_member_scores (+ resolveDropped/resolveLateAdd), 7.3 gradebook/result/student-view integration, 7.4 Grade Work group UI + student result + dialogs 4D. Rulings: one group_member_scores row per member (submission_id = shared row, or the member own row for per-member style); gradebook reads final via AssessmentResultService; average = everyone gets average of members own scores; adjustments allowed when mode collective_adjust or rubric has individual criteria. One phpunit at a time. Commit at phase end; then branch p8-learning-items.
## Phase 1 ledger
- Task 1.1: DONE, reviewed by controller, GroupSchemaTest 4 tests OK (uncommitted)
- Task 1.2: DONE; controller reviewed + mutation-checked (2 mutations caught); 22 tests OK. Note: implementer skipped TDD red phase
- Task 1.3: DONE; red phase shown (8 failures), 10 route tests OK, diff reviewed (instructor+chair full, dean GET only, no student routes); 32 tests OK on rerun
- Task 1.4: DONE_WITH_CONCERNS (Playwright not run, no browser check). Full phpunit 9521 OK, vitest 1158 OK, eslint clean
- PHASE 1 COMPLETE, awaiting user "done committing" (suggested message in chat)

## Phase 2 ledger
- Task 2.1: DONE; red phase shown (16 errors); 16 generator tests OK on my rerun
- Task 2.2: DONE_WITH_CONCERNS (no PHP red phase, Playwright not run, phpstan unverified). Full phpunit 9555 OK, vitest 1165 OK
- PHASE 2 COMPLETE and committed on feat/group-activities-p2-generate

## Phase 3 ledger
- Task 3.1: DONE_WITH_CONCERNS (service written before tests, then red run recorded); 210 group tests OK on my rerun, no leftover rows
- Task 3.2: DONE_WITH_CONCERNS (red phase shown 16 errors; 378 wide tests OK on my rerun; hooks in EnrolmentService::setStatus + SisImporter; limitation: drop after finalized offering leaves group rows)
- Task 3.3: DONE_WITH_CONCERNS (red phases shown; Playwright not run). Full phpunit 9688 OK, vitest 1177 OK
- PHASE 3 COMPLETE and committed on feat/group-activities-p3-attach

## Phase 4 ledger
- Task 4.1: DONE_WITH_CONCERNS (red phase 22 errors; 346 group tests OK; 20/20 flake runs; concurrency proven via second-process row lock; cleanup truncates test DB like CsvUploadServiceTest)
- Task 4.2: DONE_WITH_CONCERNS (red phases shown; Playwright not run; gaps found: editor lacks formation-mode/window controls, card missing on module view)
- Task 4.3: DONE_WITH_CONCERNS (red phases shown). Full phpunit 9746 OK, vitest 1192 OK
- PHASE 4 COMPLETE and committed on feat/group-activities-p4-signup

## Phase 5 ledger
- Task 5.1: DONE_WITH_CONCERNS (red 15 errors; 398 group tests OK; invitations table + GroupProposalService; GroupSchemaTest rollback test updated)
- Task 5.2: DONE (red 20 errors; 418 group tests OK). Known gaps: destination group not recounted after a move; instructor-set leader with no votes is kept
- Task 5.3: DONE_WITH_CONCERNS (red phases shown; Playwright not run). Full phpunit 9843 OK, vitest 1205 OK
- PHASE 5 COMPLETE and committed on feat/group-activities-p5-leader

## Phase 6 ledger
- Task 6.1: DONE (red 20 errors; 538 tests OK in wide filter)
- Task 6.2: DONE_WITH_CONCERNS (red 10 failures+1 error; 1214 wide OK). GradeReportRoutesTest:224 order-dependent flake seen once, verify in full run. Teammate sees no grade until Phase 7
- Task 6.3: DONE_WITH_CONCERNS (red phases shown; Playwright not run). Full phpunit 9895 OK, vitest 1211 OK
- PHASE 6 COMPLETE and committed on feat/group-activities-p6-submissions

## Phase 7 ledger
- Task 7.1: DONE (red 2 errors + 4 failures; rubric tests OK; vitest 1214 OK per implementer)
- Task 7.2: DONE_WITH_CONCERNS (red 19 errors + 1 failure; 20 new tests OK; 1833 Assessment|Grade OK per implementer). Gradebook does not read group_member_scores until 7.3; per_member late-add has no pending row
- Task 7.3: DONE_WITH_CONCERNS (red 4 errors + 9 failures; 15 new tests OK; Assessment|Grade 1848 OK). Open: teammate cannot reply to group feedback thread (fix in 7.4); ParentNotifier reports teammate as missing (Phase 9); ordinary Grade Work on a group submission leaves members pending until 7.4 blocks it; excused+pending reads 0% for min-score prereq
- Task 7.4: DONE_WITH_CONCERNS (red phases shown; Playwright not run). Full phpunit 9985 OK, vitest 1231 OK
- PHASE 7 COMPLETE and committed on feat/group-activities-p7-grading
