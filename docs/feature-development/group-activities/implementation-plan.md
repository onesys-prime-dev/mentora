# Group Activities Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development with superpowers:test-driven-development. Steps use checkbox (`- [ ]`) syntax. **Claude never commits.** At the end of every phase: run the full verification, report, give a commit message, STOP and wait for the user to say `done committing`. Then branch from the previous phase branch.

**Goal:** Instructors group students per offering and attach group copies to assignments and learning items; groups submit, are graded collectively or individually, and flow into the per-student gradebook.

**Architecture:** New `group_sets` (templates per offering) and `activity_group_configs` (one per assessment or learning item) own `student_groups` and `student_group_members`. Attaching a set copies its groups into the activity's config (D2). A group submission is one `assessment_submissions` row tagged with `group_id`; a resolver maps each member to it, and grading fans out one `gradebook_item_scores` row per member in one transaction. Rubric criteria gain a `scope` (group or individual). Everything is server-rendered CI4 views with vanilla JS modules, as the rest of the course area.

**Tech Stack:** CodeIgniter 4 (PHP), MySQL, Tailwind v4, vanilla JS + Vite, PHPUnit (`tests/database`), Vitest (`tests/js`), Playwright (`tests/e2e`).

**Spec:** `requirements.md`, `workflows.md`, `ui-ux-design.md`, `decision-register.md` (D1-D27) in this folder. Mockups: `.superpowers/brainstorm/2011-1795186363/content/`.

## Global Constraints
- Code and UI say "group set" / "activity group" / "student group". Never `Section*`, messaging `GroupService`/`GroupController`, or Shield "groups" names.
- Limits: 50 groups per activity, 30 members per group, 20 sets per offering (constants in one `GroupLimits` class).
- A student is in at most one group per activity (DB unique indexes, not only code).
- Every membership, leadership, grading, setting and unlock change calls `AuditLogger::log('groups.<action>', ...)` with old/new and reason.
- Every write refuses with `GradeFinalizationService::lockRefusal` when the offering is finalized.
- Instructor routes are mirrored in the chairperson group; dean routes are read-only; students use the student group in `app/Config/Routes.php`.
- Group fan-out grading and auto-generate run synchronously; each fan-out is ONE DB transaction.
- UI per `docs/ui-guide.md`: shared components and tokens only, loading/empty/error/filled states, 360 px, keyboard, non-drag alternative, confirm for irreversible actions, dark mode through tokens.
- Migrations sort after `2026-11-26-000001` (use `2026-12-01-0000NN`). Migrations are reversible.
- Notifications that mention grades never reveal another member's adjustment (D8).
- Tests run on MySQL `mentora_test` via `tests/_support/DatabaseTestCase.php`. Run: `composer test`, `npx vitest run`, `npx playwright test` where a phase says so.

## Decisions made in this plan (user may overrule)
- **Permissions (my call):** reuse existing permissions (`courses.manage` for writes, `courses.view` for reads, `grading.manage` for grading) and add rules to `CoursePermissionFilter::RULES` for the new paths. No new permission names, to avoid changing `AuthGroups::APPLICABLE` and role seeds.
- **Release order (needs your approval):** manual + auto-generate first (P1-P2), activity attach (P3), self-signup + proposals + leader (P4-P5), submissions (P6), grading (P7), learning items (P8), notifications, read-only roles, exports, copy (P9), hardening (P10). All modes and styles in D3/D6 ship; only the order is set here.
- **Voting rule (my call, D15 detail):** majority of all current members (more than half); a one- or two-member group needs every member; a tie means no leader; the instructor can always set one. Votes are re-counted when membership changes before freeze.
- **Where config lives:** `activity_group_configs` has nullable `assessment_id` and nullable `learning_item_id` (each unique, exactly one set), not a polymorphic type column, so foreign keys hold.

## Review Focus
1. Two students join the last spot at once: exactly one wins, the other gets "Group is full" (race-safe capacity).
2. A student appears in two groups of one activity through concurrent requests, a re-copied set, or a late enrollee: the unique index rejects it and the user sees a plain message.
3. Fan-out grading fails halfway (one member row cannot be written): no member keeps a new score.
4. A dropped student: still counted in the group's size, vote majority, or "below minimum" flag after removal.
5. A student who is in a group but whose assessment assignee row was removed (D23) tries to submit or is graded.
6. A finalized offering: every group write route refuses, including unlock, move, join, vote and grade.
7. A student opens another group's submission, feedback or adjustment by changing an id in the URL (must be 404).

---

## File structure (new unless noted)
- `app/Database/Migrations/2026-12-01-0000NN_*.php` (one per table/alter below)
- `app/Models/Groups/`: `GroupSetModel`, `ActivityGroupConfigModel`, `StudentGroupModel`, `StudentGroupMemberModel`, `GroupMemberScoreModel`
- `app/Services/Groups/`: `GroupLimits`, `GroupSetService`, `GroupMembershipService`, `GroupGeneratorService`, `ActivityGroupService`, `GroupLeaderService`, `GroupSignupService`, `GroupSubmissionResolver`, `GroupGradingService`, `GroupNotifier`
- `app/Controllers/Courses/Groups/`: `GroupSetsController`, `ActivityGroupsController`, `StudentGroupController`, `GroupGradeController`
- `app/Views/pages/courses/features/groups/` (Groups tab, set detail, dialogs), `.../assessments/partials/group-activity-section.php`, `.../assessments/partials/group-grade-work.php`, `.../modules/partials/learning-item-group.php`
- `resources/js/pages/courses/groups/` (one module per screen), `resources/css/components/groups.css` only if tokens are not enough
- Modify: `app/Config/Routes.php` (student, instructor, chairperson, dean groups), `app/Filters/CoursePermissionFilter.php` (RULES), `AssignmentSubmissionService`, `GradeWorkService`, `AssessmentResultService`, `GradebookSyncService`, `LearningProgressService`, `RubricService`, `GradeSheetExportService`, `NotificationService` callers, `MailOutbox` kind enum.

---

## Phase 1: Group sets and manual groups
**Increment:** an instructor can create/edit/archive group sets and place students in groups by hand (list picker plus drag), with audit and limits. No activity yet.
**Schema:** `group_sets(id, offering_id, name, formation_mode ENUM manual|auto|self_signup|proposed, min_size, max_size, signup_opens_at NULL, signup_closes_at NULL, status ENUM active|archived, created_by, timestamps)`; `student_groups(id, set_id NULL, config_id NULL, name, leader_user_id NULL, leader_finalized_at NULL, status ENUM active|proposed|declined, proposed_by NULL, frozen_at NULL, timestamps)`; `student_group_members(id, group_id, set_id NULL, config_id NULL, student_user_id, joined_at, UNIQUE(set_id, student_user_id), UNIQUE(config_id, student_user_id))`.

### Task 1.1: Schema and models
**Files:** Create the three migrations and `GroupSetModel`, `StudentGroupModel`, `StudentGroupMemberModel`, `GroupLimits`. Test: `tests/database/GroupSchemaTest.php`.
**Interfaces:** Produces `GroupLimits::MAX_GROUPS_PER_ACTIVITY=50, MAX_MEMBERS=30, MAX_SETS_PER_OFFERING=20`.
- [ ] Test `test_student_cannot_be_in_two_groups_of_one_set` (second insert throws 1062), `test_nulls_allow_same_student_across_sets`, `test_migration_rolls_back`. Run, see fail.
- [ ] Implement migrations and models. Run, see pass.

### Task 1.2: GroupSetService and GroupMembershipService (manual)
**Files:** Create both services. Test: `tests/database/GroupSetServiceTest.php`, `GroupMembershipServiceTest.php`.
**Interfaces:** `GroupSetService::create(int $offeringId, array $data, int $userId): int`, `update(int $setId, array $data, int $userId): void`, `archive(int $setId, int $userId): void`. `GroupMembershipService::addToSet(int $groupId, int $studentId, int $actorId): void`, `moveInSet(int $studentId, int $toGroupId, int $actorId): void`, `removeFromSet(int $studentId, int $setId, int $actorId): void`, `poolForOffering(int $offeringId): array` (enrolled/completed students).
- [ ] Tests: `test_21st_set_refused`, `test_group_over_max_members_refused`, `test_dropped_student_not_in_pool`, `test_move_changes_group_atomically`, `test_finalized_offering_refuses`, `test_each_change_writes_audit_row` (action `groups.member_moved`).
- [ ] Implement; each method checks `lockRefusal`, limits, audits.

### Task 1.3: Routes, filter rules, controller
**Files:** Create `GroupSetsController`; modify `Routes.php` (instructor, chairperson; dean GET only), `CoursePermissionFilter::RULES`. Test: `tests/feature/GroupSetsRoutesTest.php`.
- [ ] Tests: student gets 403; dean gets 200 on GET and 403 on POST; foreign offering id returns 404; chairperson routes match instructor.
- [ ] Implement JSON endpoints used by the Groups tab.

### Task 1.4: Groups tab UI (screen 1)
**Files:** Create `pages/courses/features/groups/index.php` and partials, JS modules, add the tab to the course shell. Test: `tests/js/groups-tab.test.js`, `tests/e2e/groups-tab.spec.js`.
- [ ] Vitest: picker move calls the endpoint and updates counts; empty/loading/error states render.
- [ ] Playwright: create set, add group, place a student with the picker and by drag; 360 px check; axe clean.
**Phase 1 done when:** all new tests plus `composer test` pass, `docs/ui-guide.md` checklist met, no regression in course shell tests.

---

## Phase 2: Auto-generate
**Increment:** "Generate groups" dialog with preview, pairs, leftovers, replace confirmation. Branch from phase 1.

### Task 2.1: GroupGeneratorService
**Files:** Create `GroupGeneratorService`. Test: `tests/database/GroupGeneratorServiceTest.php`.
**Interfaces:** `preview(int $offeringId, array $pool, array $opts): array` where `$opts = ['by'=>'size'|'count','n'=>int,'together'=>[[a,b]],'apart'=>[[a,b]],'min'=>int,'max'=>int,'seed'=>?int]`; returns `['groups'=>[[ids]], 'ungrouped'=>[ids]]`. Pure, no writes. `GroupSetService::saveGenerated(int $setId, array $preview, bool $replace, int $userId): void` (one transaction).
- [ ] Tests: `test_by_size_4_of_42_gives_10_groups_and_leftover_spread_within_max`, `test_leftovers_ungrouped_when_max_exceeded`, `test_keep_together_pairs_share_group`, `test_keep_apart_pairs_never_share`, `test_seeded_run_is_repeatable`, `test_save_refuses_replace_without_flag`, `test_save_is_atomic_on_failure`.
- [ ] Implement; shuffle with seed, place pairs first.

### Task 2.2: Generate dialog (screen 4A)
**Files:** `groups/generate-dialog.php`, JS module, controller actions `preview`/`save`. Test: Vitest + Playwright.
- [ ] Tests: preview shows "Nothing has been saved yet"; Shuffle again changes result; saving over existing groups requires the confirmation.
**Phase 2 done when:** tests above pass; 42-student generation under 1 s locally.

---

## Phase 3: Attach to an activity
**Increment:** an assignment can become a group activity; its groups are copied from a set; settings saved; freeze, ungrouped policy and drop handling work. No student UI yet.
**Schema:** `activity_group_configs(id, offering_id, assessment_id NULL UNIQUE, learning_item_id NULL UNIQUE, source_set_id NULL, formation_mode, min_size, max_size, signup_opens_at, signup_closes_at, ungrouped_policy ENUM block|solo DEFAULT block, submission_style ENUM shared|leader_only|per_member DEFAULT shared, grading_mode ENUM collective|collective_adjust|individual, use_average TINYINT, nonleader_can_rename DEFAULT 0, nonleader_can_vote DEFAULT 0, nonleader_can_leave DEFAULT 1, groups_visible DEFAULT 0, completion_mode ENUM any|each NULL, frozen_at NULL, created_by, timestamps)`.

### Task 3.1: ActivityGroupService.attach and copy
**Interfaces:** `attachAssessment(int $assessmentId, ?int $setId, array $settings, int $userId): int` (copies groups and members, or starts empty), `copyFromActivity(int $configId, int $fromConfigId, int $userId): void`, `recopy(int $configId, int $userId): void` (refused after freeze).
- [ ] Tests: `test_copy_is_independent_of_set` (edit set after attach, activity unchanged), `test_pool_limited_to_assignees` (D23), `test_only_assignment_type_assessments_accepted` (quiz refused), `test_over_50_groups_refused`, `test_one_config_per_assessment`.

### Task 3.2: Freeze, unlock, settings changes, drop handling
**Interfaces:** `GroupMembershipService::freezeIfFirstSubmission(int $configId): void` (called later by P6), `unlock(int $groupId, string $reason, int $userId): void`, `changeSettings(int $configId, array $changes, bool $confirmed, int $userId): void` (style and ungrouped-policy changes after submissions need `$confirmed`), `onStudentDropped(int $offeringId, int $studentId): void` (removes from every unfrozen group; frozen groups remove too per D9; graded groups return `needsDecision` instead of silently removing), `addLateEnrollee(int $groupId, int $studentId, string $reason, int $userId): void`.
- [ ] Tests: `test_frozen_group_refuses_move_and_leave`, `test_unlock_requires_reason_and_audits`, `test_drop_removes_member_from_unfrozen_group`, `test_drop_in_graded_group_requires_keep_or_excuse`, `test_late_enrollee_can_be_added_to_frozen_group`, `test_style_change_after_submission_needs_confirmation`, `test_assignee_removal_after_submission_follows_freeze`.
- [ ] Hook `onStudentDropped` into the existing enrollment status change (find the single enrollment write path with grep before editing).

### Task 3.3: Editor section and Groups views (screens 2A, 4B-4D)
**Files:** `assessments/partials/group-activity-section.php`, JS, controller `ActivityGroupsController`; modify assessment editor view. Test: Playwright.
- [ ] Tests: toggling "Group activity" attaches; settings persist; unlock dialog needs a reason; dropped-student dialog offers keep/excuse; quiz editor does not show the section.
**Phase 3 done when:** tests pass; existing assessment editor tests unchanged.

---

## Phase 4: Student view, self-signup, joining
**Increment:** students see "Your group", join/leave/switch in the window; capacity is race-safe.

### Task 4.1: GroupSignupService
**Interfaces:** `join(int $configId, int $groupId, int $studentId): void`, `leave(int $groupId, int $studentId): void`, `switchTo(int $groupId, int $studentId): void`. Join uses a transaction with `SELECT ... FOR UPDATE` on the group row and counts members.
- [ ] Tests: `test_window_closed_refuses_join`, `test_full_group_refuses_join`, `test_two_concurrent_joins_for_last_seat` (two DB connections, exactly one succeeds), `test_leave_refused_after_first_submission`, `test_leave_refused_when_toggle_off`, `test_unassigned_student_cannot_join` (D23), `test_student_cannot_join_other_offering`.

### Task 4.2: Student routes and activity card (screens 3A, 3C, 3E)
**Files:** `StudentGroupController`, student route group with `assessmentscope`, view partial on the student assessment page, JS. Test: `tests/feature/StudentGroupRoutesTest.php`, Playwright (360 px).
- [ ] Tests: student sees only their own group; others only when `groups_visible`; foreign group id returns 404; frozen and extended states render.
**Phase 4 done when:** tests pass including the concurrency test run 20 times without failure.

---

## Phase 5: Proposals and leader
**Increment:** students can propose groups; instructor approves; leader voting, naming and toggles work.

### Task 5.1: Proposals
**Interfaces:** `GroupSignupService::propose(int $configId, int $studentId, array $inviteeIds, string $name): int`, `approve(int $groupId, int $userId): void`, `decline(...)`. A proposal is `status=proposed` and members are invitees who accepted; approval needs min size.
- [ ] Tests: `test_proposal_below_min_cannot_be_approved`, `test_invitee_already_grouped_refused`, `test_decline_frees_members`, `test_window_close_flags_below_min_groups`.

### Task 5.2: GroupLeaderService
**Interfaces:** `nominate(int $groupId, int $nomineeId, int $byStudentId): void`, `vote(int $groupId, int $studentId): void`, `setLeader(int $groupId, int $studentId, int $actorId): void` (instructor), `rename(int $groupId, string $name, int $byUserId): void`, `onLeaderDropped(int $groupId): void` (next-joined member).
- [ ] Tests: `test_majority_of_members_finalizes_leader`, `test_two_member_group_needs_both_votes`, `test_tie_gives_no_leader`, `test_nonleader_cannot_rename_after_finalize_unless_toggle`, `test_leader_frozen_after_first_submission`, `test_dropped_leader_passes_to_next_joined`, `test_votes_recounted_when_member_leaves`.

### Task 5.3: Instructor proposal queue and student leader UI (screens 3B, 4B)
- [ ] Playwright: propose, approve, vote to leader, rename; phone width.

---

## Phase 6: Group submissions
**Increment:** shared, leader-only and per-member submissions with group deadline/late/attempt rules and extensions.
**Schema:** `assessment_submissions` add `group_id NULL (FK student_groups SET NULL)`, `submitted_by_user_id NULL`; add `UNIQUE(assessment_id, group_id, attempt_number)`; keep the existing student key. `activity_group_configs` already holds style. Add `group_extensions(id, group_id, assessment_id, until_at, reason, created_by, created_at)`.

### Task 6.1: GroupSubmissionResolver
**Interfaces:** `submissionFor(int $assessmentId, int $studentId): ?array` (the member's group submission, else their own), `groupFor(int $assessmentId, int $studentId): ?array`, `canSubmit(int $assessmentId, int $studentId): array{allowed:bool,reason:?string}` (ungrouped block/solo, leader-only).
- [ ] Tests: `test_ungrouped_blocked_by_default`, `test_ungrouped_solo_allowed_when_policy_solo`, `test_leader_only_blocks_nonleader_after_finalize`, `test_any_member_can_submit_in_shared_style`, `test_leader_only_allows_any_member_before_leader_final` (my call: no leader yet, nobody is blocked unfairly).

### Task 6.2: Extend AssignmentSubmissionService
**Files:** modify `AssignmentSubmissionService::submit` (call resolver, write `group_id` and `submitted_by_user_id`, group attempt count, group late flag using the group's extension, call `freezeIfFirstSubmission`, and `LearningProgressService::completeOnSubmission` for each member). Test: extend `AssignmentSubmissionServiceTest`.
- [ ] Tests: `test_group_submission_stored_once_with_submitter`, `test_attempt_limit_counted_per_group`, `test_extension_makes_late_submission_on_time`, `test_per_member_style_uses_own_deadline_and_row`, `test_first_submission_freezes_group`, `test_non_group_assignment_behaviour_unchanged` (regression).

### Task 6.3: Group extension and screens
- [ ] `GroupSubmissionResolver`-adjacent `GroupExtensionService::extend(int $groupId, string $until, string $reason, int $userId)`; tests: reason required, audited. UI: extension action on Groups view; student shows the extended notice.
**Phase 6 done when:** existing submission tests pass unchanged plus the new ones.

---

## Phase 7: Group grading
**Increment:** collective, collective plus adjustment, individual, rubric scopes, average, post-grading prompts, gradebook fan-out.
**Schema:** `assessment_rubric_criteria` add `scope ENUM group|individual DEFAULT individual` (existing rubrics keep per-student behaviour); `group_member_scores(id, submission_id, student_user_id, individual_rubric_scores JSON, adjustment DECIMAL, adjustment_reason, private_note, final_score, final_percentage, excused TINYINT, timestamps, UNIQUE(submission_id, student_user_id))`.

### Task 7.1: Rubric scope
**Files:** migration, `RubricService` (read/write `scope`), rubric editor view. Test: `RubricServiceTest` extended.
- [ ] Tests: `test_default_scope_individual_keeps_existing_rubrics_working`, `test_scope_saved_and_returned`.

### Task 7.2: GroupGradingService
**Interfaces:** `gradeCollective(int $submissionId, array $groupScores, string $feedback, array $perMember, int $graderId): void` (`$perMember[studentId] = ['individual'=>[criterionId=>pts], 'adjustment'=>float, 'reason'=>?string, 'note'=>?string]`), `gradeAverage(int $submissionId, ...)`, `fanOut(int $submissionId): void` (writes one `gradebook_item_scores` via `GradebookSyncService::syncItemScore` per member inside one transaction), `resolveDropped(int $groupId, int $studentId, 'keep'|'excuse', string $reason, int $userId)`, `resolveLateAdd(int $groupId, int $studentId, 'separate'|'inherit', string $reason, int $userId)`.
- [ ] Tests: `test_all_members_get_group_plus_own_individual_score`, `test_adjustment_requires_reason`, `test_final_score_clamped_0_to_total`, `test_fan_out_rolls_back_all_on_one_failure`, `test_finalized_offering_refuses`, `test_average_mode_averages_member_scores`, `test_excuse_sets_is_missing_and_keeps_history`, `test_late_add_separate_is_pending_and_inherit_copies_group_part`, `test_regrading_updates_without_duplicates`.

### Task 7.3: Resolver integration into gradebook
**Files:** modify `AssessmentResultService::resultFor` and `GradebookSyncService::syncItemScore` to use `GroupSubmissionResolver` and member scores. Test: extend existing tests plus `GroupGradebookTest`.
- [ ] Tests: `test_member_without_own_submission_gets_group_score_in_gradebook`, `test_non_group_gradebook_unchanged`, `test_published_scheme_recalculates_each_member_once`.

### Task 7.4: Grade Work group view and student result (screens 2B, 3B grade card)
- [ ] Playwright: grade a 4-member group, adjust one with a reason, save; student sees own adjustment only; another member's adjustment never appears in the student page source (assert response body).
**Phase 7 done when:** all grading and gradebook regression tests pass.

---

## Phase 8: Learning items
**Increment:** a learning item can use a group set; roster and completion work; never frozen.
- Task 8.1: `ActivityGroupService::attachLearningItem(int $itemId, ?int $setId, string $completionMode, int $userId)`. Tests: `test_attach_refuses_quiz_item`, `test_groups_never_freeze_on_items`.
- Task 8.2: `LearningProgressService::completeForGroup(int $itemId, int $studentId): void` writes one `learning_completions` row per member for `any` mode; `each` mode unchanged. Tests: `test_any_mode_completes_all_members`, `test_leaver_keeps_completion`, `test_new_joiner_gets_completion_when_group_done`, `test_prerequisite_check_sees_group_completion`.
- Task 8.3: roster partial on the item page (screen 3D) and editor section. Playwright check.

---

## Phase 9: Notifications, read-only roles, exports, offering copy
- Task 9.1: `GroupNotifier` for the ten D19 events (in-app via `NotificationService::sendToMany` with dedupe keys, email via `MailOutbox`); migration adds outbox kind `group`. Tests: `test_each_event_notifies_once`, `test_auto_generate_batches_with_dedupe`, `test_graded_notice_has_no_other_members_adjustment`, `test_parent_notice_follows_existing_parent_rules` (inspect `NotificationTriggers` first; document the finding).
- Task 9.2: dean read-only routes and views (screen 4E), parent view (4F). Tests: dean POST refused; parent sees only the linked child's data.
- Task 9.3: `GradeSheetExportService` adds a Group column; verify `GradeSheetImportService` stays per student (read it, add a test that an import ignores the Group column). Tests: export includes group name; non-group exports unchanged.
- Task 9.4: offering copy carries group-set structure (names, sizes, settings) without students. Find the copy service by grep (`copied_from`); test `test_copy_has_sets_without_members`.

## Phase 10: Hardening
- Full regression: `composer test`, phpstan, eslint, `npx vitest run`, `npx playwright test`.
- Accessibility pass (axe) on every new screen in light and dark; keyboard walk-through; 360/768/1440 px.
- Security review of new routes (foreign id, wrong role, readonly institution, finalized offering). Write `docs/group-activities.md` (feature doc in repo convention) and `docs/test-cases/` manual cases.
- Seed/demo data for QA (optional, only if asked).

---

## Self-review (done)
- **Coverage:** D1-D27 mapped: D1-2 P1/P3; D3 P1,P2,P4,P5; D4-5 P3,P6; D6,D11 P6; D7,D8,D10,D24 P7; D9,D25-27 P3,P4,P6; D12-13 P3,P8; D14-16 P5; D17 P4,P5; D18 P2; D19 P9; D20 P9; D21 all UI tasks; D22 constraints+P10; D23 P3,P4.
- **Known unknowns:** the enrollment drop write path, the offering copy service, and the exact `AssessmentResultService`/`GradebookSyncService` seams are located by grep at the start of their tasks, not assumed.
- **Proportion:** tasks list signatures and test names only; no function bodies.
