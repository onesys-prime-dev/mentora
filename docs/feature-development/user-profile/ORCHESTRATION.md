# User Profile redevelopment: orchestration

Read `progress.md` first. It holds the current stage, branch and exact next action.

## Lifecycle

| Stage | Skill | Output | Gate to leave it |
|---|---|---|---|
| 1 Discovery | `mattpocock-skills:grilling` (stands in for the requested `grill-me`, which is not installed) | decisions in `decision-register.md`, open items in `open-questions.md` | user approves moving to stage 2 |
| 2 Consolidation and design | `superpowers:brainstorming`, `frontend-design:frontend-design` | `requirements.md`, `workflows.md`, `ui-ux-design.md` (with mockups) | user approves requirements and designs |
| 3 Planning | `superpowers:writing-plans` | `implementation-plan.md`, `test-strategy.md` | user approves the plan |
| 4 Implementation | `superpowers:subagent-driven-development` + `superpowers:test-driven-development` | one phase per branch | per phase: tests green, review, commit message given, **stop**; the user commits and replies `done committing` |

## Rules

- Grilling: **one question per message**, each with alternatives, trade-offs and a recommendation. Record every answer in `decision-register.md` right away.
- Never change an approved decision silently. A conflict goes to `open-questions.md` and to the user.
- **Claude does not commit.** At the end of each phase Claude gives a commit message and stops. The user commits.
- Branches: `feat/user-profile` (docs, created from `feat/group-activities-p11-report-group`), then one branch per phase, each created from the previous phase's committed branch.
- Data safety: no destructive migration, no dropped column, no deleted record without a written plan and explicit approval. Existing `user_profiles` rows must survive every phase.
- Do not claim tests pass without real output. If a test fails, stay in the phase and log the failure in `test-strategy.md`.
- Local DB caveat (from Group Activities): the XAMPP MySQL is broken. Tests run on a scratch MariaDB on :3307; see `docs/feature-development/group-activities/test-strategy.md`.

## Resume procedure

1. `git branch --show-current` and `git status`.
2. Read `progress.md`, then `open-questions.md` and `decision-register.md`.
3. Continue from "Next action". Do not redo items marked done.
4. Update `progress.md` before stopping.
