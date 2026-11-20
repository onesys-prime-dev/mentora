# Group Activities — Orchestration

Feature: instructors organize students into groups within a learning item or assessment; grade a group collectively or students individually.

## Resume protocol (run first in every session)
1. Read `progress.md` (stage, phase, branch, next action), then `open-questions.md` and `decision-register.md`.
2. `git branch --show-current` and `git status`; compare with `progress.md`. Flag any mismatch to the user.
3. Resume at "Exact next action". Never redo completed work; never mark unfinished work done.
4. Before stopping, update `progress.md`.

## Stages and approval gates
| # | Stage | Skill | Exit gate (user approval required) |
|---|-------|-------|------|
| 1 | Requirements discovery | grilling (grill-me equivalent), ONE question per message | user says discovery is complete |
| 2 | Consolidation + design + mockups | superpowers:brainstorming, frontend-design | user approves requirements + designs |
| 3 | Implementation plans | superpowers:writing-plans | user approves plans |
| 4 | Implementation, one phase at a time | subagent-driven-development, TDD | per phase: user replies `done committing` |

## Rules
- User makes all product/architecture/design decisions. No silent assumptions; never overwrite an approved decision — flag conflicts and ask.
- Failing tests/errors: stay in phase, root-cause, fix, rerun; log in `test-strategy.md`.
- Final PR to `main` is created by the user.

## Files
progress.md · requirements.md · decision-register.md · open-questions.md · workflows.md · ui-ux-design.md · implementation-plan.md · test-strategy.md

## Amendment 2026-11-21 (user instruction)
The user delegated all commits: Claude commits at the end of each phase (conventional message + Co-Authored-By trailer), creates the next phase branch from the previous one, and continues without waiting for `done committing`, until the last phase is finished. Claude still never pushes or opens/merges the PR. Quality gates are unchanged: a phase is committed only after its tests, regression run and acceptance review pass; a failing gate keeps work in the phase. Each phase's summary and results are written to `progress.md` and `test-strategy.md`.
