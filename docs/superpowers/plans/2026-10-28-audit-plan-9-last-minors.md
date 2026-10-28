# The last open minors (audit plan 9) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the two minors plan 8 left open, and make the acceptance checklist list the plan 6 pre-launch checks.

**Architecture:** One change in `SchedulerCheck` (a stop is remembered when an administrator already holds its notice, not only when one was sent now), one test class made stricter, and docs.

**Tech Stack:** CodeIgniter 4.7, PHP 8.2, MySQL, PHPUnit 10.

**Spec:** `docs/superpowers/specs/2026-10-23-audit-fixes-design.md` (decisions in `docs/audit-2026-10-23-decisions.md`, 6.2/U2/D11 for the scheduler check, 15 for the quiz). The open items come from plan 8's final review (`docs/superpowers/plans/2026-10-28-audit-plan-8-deferred-minors.md`).

## Global Constraints

- Branch `fix/last-minors` from `67cc8bf`. No commits by the executor; stage and give a commit message. Not merged to main.
- Never set the database or a setting through command-line env vars (`.env` wins); tests use `Tests\Support\TestEnv`.
- Keep each file's line endings (check with `tr -cd '\r' < f | wc -c`).
- Copy is plain and second person where it speaks to people; notification titles stay exactly `Scheduled job stopped: <command>` and `Scheduled job running again: <command>`.

## Review Focus

1. SIS off then on while the job is still stalled, then it reports: every administrator gets "running again" once (Task 1's test).
2. The same, with an administrator added while SIS was off: the new one is told "stopped" by the re-check, and the period is kept (Task 1, second test).
3. A stop no administrator knows of (none exist) is still not remembered: the existing `testNoPeriodIsRecordedBeforeThereIsAnAdministrator` must stay green.
4. A refused submit leaves no trace: response scores back to null, no open transaction, exceptions off again, no gradebook score, no completion (Task 2).
5. The checks in Task 2 are not vacuous: the same submit without the refusal does write the score and the completion (Task 2's control test).

---

### Task 1: A stall SIS left and came back to is told when it ends

**Files:**
- Modify: `app/Services/Monitoring/SchedulerCheck.php` (the stale branch, lines 53–61; the class doc comment)
- Test: `tests/database/SchedulerCheckTest.php`
- Modify: `docs/monitoring.md` ("Three cases it handles on purpose" becomes four)

**Interfaces:**
- Consumes: `NotificationService::has(int $userId, string $dedupeKey): bool`; `ScheduledJobs::heartbeat`, `ScheduledJobs::watched`.
- Produces: nothing other tasks use.

- [ ] **Step 1: Write the failing tests** in `SchedulerCheckTest`, after `testASisJobLeftOutWhenSisIsOffIsDroppedQuietly`:

`testASisStallStillThereWhenSisComesBackIsToldWhenItEnds`:
- an institution with an enabled `sis_connections` row; `sis:sync` heartbeat at `$this->now - 30 * 3600` (stale).
- `run($this->now)`: each admin has 1 `Scheduled job stopped: sis:sync`.
- disable the connection, `run($this->now + 60)`; enable it again, `run($this->now + 120)`:
  - `alerted` is 0 (everyone already knows);
  - `json_decode(setting('Monitoring.staleJobs'))` has the key `sis:sync`.
- heartbeat `sis:sync` at `$this->now + 180`, `run($this->now + 240)`: each admin has exactly 1 `Scheduled job running again: sis:sync`, and the period is gone.

`testAnAdministratorAddedWhileSisWasOffIsToldOfTheStallAndItIsKept`:
- the same stall and first run with only `admins[0]` in the `admin` group (delete `admins[1]`'s `auth_groups_users` row before the first run);
- SIS off, run; add `admins[1]` back to `admin`; SIS on, run: `admins[1]` has 1 `Scheduled job stopped: sis:sync`, `admins[0]` still 1, the period is kept.

- [ ] **Step 2: Run them**

Run: `vendor/bin/phpunit --no-coverage --filter "testASisStallStillThere|testAnAdministratorAddedWhileSis" tests/database/SchedulerCheckTest.php`
Expected: the first FAILS (no `sis:sync` key after SIS comes back, and 0 "running again"); the second may pass already (it sends, so it records). Ledger the second as a pin if so.

- [ ] **Step 3: Implement.** In the stale branch, keep the period when `$sent > 0` **or** some administrator already holds the stale key. Add `private function known(array $admins, NotificationService $notices, string $key): bool` (true when `$notices->has($admin, $key)` for any admin). Compute the stale key once into a local and pass it to both `tell()` and `known()`. Update the comment on that line ("Kept once somebody knows, now or from before ...") and the class doc's last paragraph.

- [ ] **Step 4: Run the class**

Run: `vendor/bin/phpunit --no-coverage tests/database/SchedulerCheckTest.php`
Expected: all pass, including `testNoPeriodIsRecordedBeforeThereIsAnAdministrator`.

- [ ] **Step 5: Docs.** In `docs/monitoring.md` add a fourth case after "SIS turned off" and change "Three cases" to "Four cases":
  `- **SIS turned back on while still stopped.** The stop is remembered again without telling anyone twice, so "running again" is still sent when the job reports.`

- [ ] **Step 6: Stage** `app/Services/Monitoring/SchedulerCheck.php tests/database/SchedulerCheckTest.php docs/monitoring.md`.

### Task 2: A refused submit leaves no trace, shown against a submit that works

**Files:**
- Test: `tests/database/QuizAttemptFailureTest.php`

**Interfaces:**
- Consumes: `AssessmentFactory::multipleChoice(int, User, array $correct, float): array{question, choices}`, `AssessmentFactory::response(int $submissionId, int $questionId, array $values): int`, `GradeFactory::scheme(int $offeringId): int`, `GradeFactory::item(int $schemeId, ?int $categoryId, string $name, float $maxPoints, int $order = 1, ?int $assessmentId = null): int`, `CourseFactory::unit`, `CourseFactory::items`; `BaseConnection::$transDepth` (read-only property).
- Produces: nothing.

This task adds assertions to code already fixed in plan 8, so its tests are expected to pass first time: ledger them as pins. The control test is what proves the new assertions can fail.

- [ ] **Step 1: A fully auto-graded quiz fixture.** A private helper `gradedQuiz(): array{quiz:int, question:int, choice:int, item:int}` that makes, on the setUp offering (keep it in a property), a second quiz with one learning item (`CourseFactory::unit` + `CourseFactory::items`, passed as the assessment's `$itemId`), one multiple-choice question worth 5 with the first choice correct, opened, and an Active gradebook item on it (`GradeFactory::scheme` then `GradeFactory::item(..., assessmentId: quiz)`). The student starts it and a response picks the correct choice (`selected_choice_id`).

- [ ] **Step 2: Write `testTheSameSubmitWithoutARefusalScoresCountsAndCompletes`** (the control): submit succeeds; the response's `score` is 5; `gradebook_item_scores` has 1 row for the item and the student; `learning_completions` has the item `completed` for the student.

- [ ] **Step 3: Make `testASubmitTheDatabaseRefusesSaysSoAndChangesNothing` use `gradedQuiz()`** and, after the existing assertions, add:
  - the response's `score` is null and `is_auto_graded` is 0 (rolled back);
  - `db_connect()->transDepth` is 0;
  - `transException` is off again: read with `ReflectionProperty(BaseConnection::class, 'transException')`, assert false;
  - 0 `gradebook_item_scores` rows and 0 `learning_completions` rows for the item and the student.

- [ ] **Step 4: Run**

Run: `vendor/bin/phpunit --no-coverage tests/database/QuizAttemptFailureTest.php`
Expected: 4 tests pass. To see the new assertions can fail, temporarily comment out `$db->transException(false);` in `submitAttempt`'s `finally`, rerun (the reflection assertion fails), restore it. Ledger that.

- [ ] **Step 5: Stage** the test file.

### Task 3: The acceptance checklist lists the pre-launch checks

**Files:**
- Modify: `docs/server-setup.md` section 8

- [ ] **Step 1: Add these lines** to the checklist after "Scheduled jobs are in the site user's crontab":
  - `- [ ] Staging, once: CloudPanel's Cron Jobs screen keeps the mentora block after an unrelated save (section 6).`
  - `- [ ] `scheduler.checkSecret` is set and matches `SCHEDULER_SECRET_STAGING` or `SCHEDULER_SECRET_PRODUCTION`; the uptime workflow's scheduler step passes (section 6).`
  - `- [ ] Production: the off-server copy reads **Copied** on `/admin/backups` (section 6).`
  - `- [ ] Production, once: a copy fetched back to another machine opens, and its date is recorded (section 6, go-live check).`
  - `- [ ] Before go-live, once: the 2,000,000-submission export finished under `memory_limit=128M`, time recorded (`institution-data-export.md`, Scale).`

- [ ] **Step 2: Check** `grep -c "^- \[ \]" docs/server-setup.md` grows by 5, and the file's line endings are unchanged.

- [ ] **Step 3: Stage** `docs/server-setup.md` and this plan.

### Finish

Run the full suite in the background: `vendor/bin/phpunit --no-coverage > <workspace>/full.txt` (about 20 minutes). Expected: all pass. Then the final review.
