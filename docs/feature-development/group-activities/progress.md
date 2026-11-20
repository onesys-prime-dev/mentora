# Progress

- Stage: 4 — Implementation, Phase 3 (attach to activity) in progress; Phases 1 (63b150cc) and 2 (c918376b) committed
- Phase: n/a
- Active branch: feat/group-activities-p3-attach (from p2)
- Last verified checkpoint: Stage 2 approved by user ("approved, proceed to Stage 3"); implementation-plan.md + test-strategy.md written
- Completed: workspace scaffold
- Pending: interview (one question per message)
- Exact next action: Phase 3 tasks 3.1 (ActivityGroupService+migrations), 3.2 (freeze/unlock/settings/drop, member ops on activity groups), 3.3 (editor UI). Ruling: keep-or-excuse decision for dropped students in GRADED groups deferred to Phase 7 (no grades exist yet). Commit at end of phase.
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
