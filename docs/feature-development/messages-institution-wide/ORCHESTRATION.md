# Institution-wide Messages: orchestration

Read `progress.md` first. It holds the current stage, branch and exact next action. The original brief is `SESSION-PROMPT.md`.

## Lifecycle

| Stage | Skill | Output | Gate to leave it |
|---|---|---|---|
| 1 Discovery | `mattpocock-skills:grilling` (stands in for `grill-me`, which is not installed) | decisions in `decision-register.md`, open items in `open-questions.md` | user approves moving to stage 2 |
| 2 Consolidation and design | `superpowers:brainstorming`, `frontend-design:frontend-design`, visual companion | `requirements.md`, `workflows.md`, `ui-ux-design.md` + `mockups/` (2-3 options per major screen, user picks) | user approves requirements and designs |
| 3 Planning | `superpowers:writing-plans` | `implementation-plan.md`, `test-strategy.md`, final `existing-implementation-audit.md` | user approves the plan |
| 4 Implementation | `superpowers:subagent-driven-development` + `superpowers:test-driven-development` | one phase per branch | per phase: tests green, regression run, review, summary, commit message given, **stop**; the user commits and replies `done committing` |

## Rules

- Grilling: **one question per message**, each with alternatives, trade-offs, edge cases and a recommendation. Record every answer in `decision-register.md` right away.
- Never change an approved decision silently, here or in `../user-profile/decision-register.md`. A conflict goes to `open-questions.md` and to the user.
- Coordinate with `../single-identity/`: Messages must not deepen its dependence on `active_role`.
- **Claude does not commit.** At the end of each phase Claude gives a commit message and stops.
- Before the first branch: **ask the user which branch to base it on.** Each later phase branches from the previous phase's committed branch.
- Data safety: conversations, messages, attachments, blocks, reports and preferences are never deleted or rewritten. Any destructive change, schema replacement or data migration needs a written plan and explicit approval.
- Do not claim tests pass without real output. A failing test keeps the work in its phase; log the failure and fix in `test-strategy.md`.
- Local DB: XAMPP MySQL :3306 is broken, do not repair it. PHP tests: `env 'database.tests.port=3307' 'database.tests.hostname=127.0.0.1' vendor/bin/phpunit` (see `../group-activities/test-strategy.md`). Playwright that cannot run locally is recorded as NOT RUN.

## Approval gates (never crossed without the user's explicit word)

1 → 2, 2 → 3, 3 → 4, and each phase → next (`done committing`).
Also: Phase 1 starts only when the user explicitly says so (added 2026-11-23); approval of the plan alone does not start it.

## Resume procedure

1. `git branch --show-current` and `git status`.
2. Read `progress.md`, then `open-questions.md` and `decision-register.md`.
3. Continue from "Next action". Do not redo items marked done.
4. Update `progress.md` before stopping or when context runs low.
