# Progress

- **Stage:** 4 Implementation (`superpowers:subagent-driven-development` + TDD). Gates 1 → 2, 2 → 3 and 3 → 4 approved by the user 2026-11-23 (plan, test strategy, audit and both data moves approved).
- **Phase:** 1 (Parties, not roles) **complete and verified 2026-11-26, uncommitted**. Waiting for the user to commit and say `done committing`.
- **Active branch:** `feat/messages-open-p1-parties`, recreated 2026-11-25 from `main` @ 37a9c5f9 (User Profile #301 merged). The older branch (merge 5435f18a on top of feat/user-profile) is kept as `feat/messages-open-p1-parties-pre-main`. The Messages spec note was re-applied (it had been stashed as `stash@{0}` by another session; stash left untouched). Workspace docs still uncommitted.
- **Latest verified checkpoint:** 2026-11-26, Phase 1 complete: all task reviews + whole-phase review clean after fixes; full PHP suite green except 12 environmental script-test failures confirmed green on rerun; Vitest 1306 OK; eslint clean.
- **Pending approval:** user commits Phase 1 and replies `done committing`; then branch `feat/messages-open-p2-blocks` from it and start Phase 2.

## Done
- Read the brief, User Profile D3/D4/D18/D20/D25/D28/D31/D32, Messages spec §3, single-identity brief
- Inventory of the current permission and discovery code (`existing-implementation-audit.md`)
- Workspace files created

## Pending
- Stage 1 grilling done: Q1-Q22, D1-D22 (`decision-register.md`)
- Stages 2-4

## Stage 2 state (2026-11-23)
- Register repaired (D3-D24 restored) after the builder check found the gap.
- D25 (safety first, reach last), D26 (minors in groups) from builder-check T1/T2; BC-1..5 Claude calls.
- 7 mockup screens picked (first-contact B, restriction A, reports C, group B, institution settings B, my settings B, minor flag B); files in `mockups/`.
- `requirements.md`, `workflows.md`, `ui-ux-design.md` drafted.
- Visual companion server was stopped by Claude Code (low system memory) on 2026-11-23; all picked mockups are saved in `mockups/`. Restart only if a new mockup round is needed.

## Phase 1 state
- SDD ledger: `.superpowers/sdd/implementation-plan/progress.md` (git-ignored) holds the pre-flight scan and rulings R1-R6: tests on MySQL :3306; settings tasks 3.3/3.4/5.3 retargeted to `/settings` Notifications (User Profile D15 shipped); PersonProfileController joins Task 1.3/3.1; Party::topRole uses RoleRank.
- Baseline 2026-11-25: Vitest 127 files / 1306 passed; PHPUnit recorded in test-strategy.md.

## Phase 1 result (2026-11-26)
- Added `app/Services/Messaging/Party.php` (+ `withRole`, `topRole`); resolver `party()`/`roles()` with per-direction child facts; policy decides on parties; every active-role read removed from Messages (grep guard `tests/unit/NoActiveRoleInMessagesTest.php`); profile Message button uses the party-based start.
- Rulings R1-R10 and deferred minors in `.superpowers/sdd/implementation-plan/progress.md`.

## Next action
Stopped for the user's commit. After `done committing`: create `feat/messages-open-p2-blocks` from the committed Phase 1 branch, then Phase 2 (blocking rework + the approved block-reset data move) via the SDD ledger.
