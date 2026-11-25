# Institution-wide Messages Implementation Plan

Status: **approved** 2026-11-23 (gate 3 → 4), including the Phase 2 and Phase 5 data moves.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (chosen by the user) with superpowers:test-driven-development. Steps use checkbox (`- [ ]`) syntax. **Claude never commits** (user rule): each phase ends with a commit message for the user, then a full stop until the user says `done committing`.

**Goal:** Everyone in an institution can find and message each other, with blocking, first-contact, limits, restrictions, an automatic pause, minor protection and owner-run groups, and with no `active_role` anywhere in Messages.

**Architecture:** `MessagingPolicy` stays the one pure decision class (D24). `MessagingContextResolver` stops returning a role and returns facts about **parties** (every role a person holds, minor flag, restriction state) plus pair facts. Services and controllers pass `Party` objects and contexts to the policy. Safety tables are additive. The wider reach (D1) switches on in the last backend phase (D25).

**Tech Stack:** PHP 8 / CodeIgniter 4 / Shield, MariaDB, vanilla JS modules (Vite, Tailwind), PHPUnit, Vitest (jsdom), Playwright.

**Spec:** `docs/feature-development/messages-institution-wide/requirements.md` (approved 2026-11-23; FR/NFR/AC numbers below refer to it), with `decision-register.md` (D1-D26, S2-a..i, BC-1..5), `workflows.md`, `ui-ux-design.md` + `mockups/`. Older spec: `docs/superpowers/specs/2026-11-02-messages-design.md` (amended).

## Global Constraints

> **Execution rulings 2026-11-25 (after User Profile merged; details in the SDD ledger):** R1 PHP tests run on the main MySQL :3306 (`vendor/bin/phpunit --no-coverage`), the :3307 note below is outdated. R2 the email switch (Tasks 3.3/3.4) and quiet-hours switch (Task 5.3) go into `/settings` Notifications (`app/Views/pages/settings/partials/notifications.php`, `AccountSettingsController`), not the Messages dialog, which now holds only the blocked list. R3 `PersonProfileController::canMessage`/`message` are canStart callers: converted in Task 1.3; the profile Message button opens the draft in Task 3.1. R4 line numbers below are stale; the grep test is the source of truth. R6 `Party::topRole()` uses `App\Services\Profiles\RoleRank::highest()`.

- Branches: ask the user for the base branch before Phase 1. Each later phase branches from the previous phase's committed branch: `feat/messages-open-p1-parties` … `feat/messages-open-p7-reach`.
- PHP tests: `env 'database.tests.port=3307' 'database.tests.hostname=127.0.0.1' vendor/bin/phpunit` (scratch MariaDB :3307; never repair XAMPP :3306). JS: `npm test`. E2E: `npm run e2e`, recorded as **NOT RUN** if it cannot run locally. Lint: `npm run lint`.
- Test bases: `Tests\Support\DatabaseTestCase` (DB), `Tests\Support\RouteTestCase` (HTTP), factories `PeopleFactory`, `DeanFactory`, `CourseFactory`, traits `MessagingScenario`, `ParentScenario`.
- Migrations: `app/Database/Migrations/YYYY-MM-DD-NNNNNN_PascalName.php`, dated after `2026-12-01-000011`. Every migration is **additive** or a **reversible move** with a working `down()`. No dropped table or column. Migrations marked **[DATA MOVE]** need the user's explicit approval before they run anywhere but the test DB.
- Audit through `service('auditLogger')->log(string $action, ?User $actor, array $details)`; ids and outcomes only, never message text (D22). Action names are prefixed `messages.`.
- Privacy: no response, error or search result reveals another person's block, minor flag, restriction or pause (NFR Privacy). Refusals look like today's `notFound()`/generic message.
- No `active_role`, `ActiveRoleService`, `usableGroups(`, `preferredRole(` in `app/Services/Messaging`, `app/Controllers/Messaging`, `app/Filters/MessagesFilter.php`, `app/Views/pages/messages`, `resources/js/pages/messages` once Phase 1 lands (AC3).
- UI follows `ui-ux-design.md` picks 1-7 and the existing Messages shell: `rounded-2xl` white cards, `bg-brand` pills, `text-[#646464]`, `pointer-coarse:min-h-11`, `role="status"` for notices.
- Values pinned by the spec: limits students/parents 10/h + 25/day, staff 20/h + 60/day, inst. admins exempt (S2-b); pause 3 distinct reporters / 24 h, expiry 72 h (D8); group max 50 members, title 80 chars (S2-c); report snapshot 5 (unchanged).

## Review Focus

1. **Multi-role people** (student who teaches, staff + parent, minor + staff): each check must use the capacity rule (D4), never "the first role". Owned by Task 1.2 / 7.1 tests `multiRole*`.
2. **Enrolment changing under a block or a thread** (a student drops, an instructor is unassigned): the block pauses/resumes and D3/D9 read-only follows live facts, not cached ones. Task 2.2 `testBlockResumesWhenCourseEnds`, Task 7.2 `testThreadReadOnlyAfterDrop`.
3. **Concurrent first sends** from both sides: one conversation, two messages, one counted thread each. Task 3.1 `testConcurrentFirstSendsMakeOneThread`.
4. **Admin acting on a stale pause/report** (already expired or already reviewed): idempotent, no double audit. Task 4.3 `testReviewingExpiredPauseIsHarmless`.
5. **Hidden-information leaks through errors and counts** (blocked, minor, restricted people): the same refusal shape and no count differences. Task 7.3 `testRefusalsAreIndistinguishable`.

---

## Phase 1: Parties, not roles (no reach change)

**Objective:** remove every active-role read from Messages; the policy decides on `Party` objects. Who can message whom stays as today, evaluated over every role a person holds (transitional, replaced in Phase 7).
**DB:** none. **Data:** untouched. **Depends on:** nothing.
**Authorization:** unchanged reach; the system admin and impersonation still refused; a person with several roles is allowed if any held non-parent role allows a non-child thread, and the parent role only for child-labelled threads (D4 capacity).

### Task 1.1: `Party` value object and capacity

**Files:**
- Create: `app/Services/Messaging/Party.php`
- Test: `tests/unit/PartyTest.php`

**Interfaces:**
- Produces: `final class Party { __construct(int $id, list<string> $roles, bool $active, ?int $institutionId, bool $minor = false) }`, methods `isStaff(): bool` (holds any of instructor, chairperson, dean, institutional_admin), `isParent(): bool`, `isStudent(): bool` (holds student and no staff role), `isSystemAdmin(): bool` (holds `admin`), `capacity(bool $aboutChild): 'parent'|'staff'|'student'|null` (null = cannot act in that capacity, e.g. parent-only person on a non-child thread). Constant `Party::STAFF_ROLES`.

- [ ] **Step 1: Write failing tests** `testStaffIfAnyStaffRole` (['student','instructor'] → isStaff true, capacity(false) 'staff'), `testParentOnlyCannotActOutsideChildThread` (['parent'] → capacity(false) null, capacity(true) 'parent'), `testStaffParentPicksByThread` (['instructor','parent'] → capacity(true) 'parent', capacity(false) 'staff'), `testStudentCapacity`, `testSystemAdmin`.
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/unit/PartyTest.php` (with the env prefix) → FAIL (class missing).
- [ ] **Step 3: Implement** `Party` as specified.
- [ ] **Step 4: Run** → PASS.

### Task 1.2: Resolver builds parties and role-union facts; policy takes parties

**Files:**
- Modify: `app/Services/Messaging/MessagingContextResolver.php` (remove `activeRole()` :33-47 and its calls :65, :66, :115, :168; add `party()`), `app/Services/Messaging/MessagingPolicy.php` (`canStart`, `canModerate` signatures), `app/Services/Messaging/StartContext.php`
- Test: `tests/unit/MessagingPolicyTest.php` (rewrite table rows to parties), `tests/database/MessagingContextResolverTest.php`

**Interfaces:**
- Produces: `MessagingContextResolver::party(User $user): Party` (roles from Shield groups the account may use, i.e. what `ActiveRoleService::usableGroups` returned, read directly from `auth_groups_users`; minor = false until Phase 5). `forStart(User $sender, User $recipient, ?int $subjectStudentId): ?StartContext` keeps its signature; facts become unions over held roles: `sameCollege` = any college of any held non-parent role intersects; `inScope` = any held chair/dean scope covers the recipient. `MessagingPolicy::canStart(Party $sender, Party $recipient, ?int $subjectStudentId, StartContext $c): bool` — transitional body: capacity per `Party::capacity($subjectStudentId !== null)`; if either is null → false; otherwise allowed if **any** pair (senderRole ∈ held roles valid for that capacity, recipientRole likewise) passes the existing `START` table with the existing facts. `MessagingPolicy::canModerate(Party $actor, string $type, bool $inScope, bool $sameInstitution = true): bool` (admin = holds institutional_admin; course = holds instructor and in scope; group unchanged until Phase 6).

- [ ] **Step 1: Write failing tests**: in `MessagingPolicyTest`, convert every existing TABLE row to `new Party(...)` with a single role (same expectations), then add `testMultiRoleStudentInstructorReachesAsStaff`, `testParentOnlyRefusedWithoutChild`, `testStaffParentChildThreadUsesParentRow`, `testSystemAdminAlwaysRefused`. In `MessagingContextResolverTest`: `testPartyListsEveryUsableRole`, `testPartyIgnoresSavedActiveRole` (set `user_profiles.active_role='parent'` on an instructor+parent → party still has both roles and the instructor can start with own student).
- [ ] **Step 2: Run** both files → FAIL.
- [ ] **Step 3: Implement** as in Interfaces. Keep `START` and all fact helpers; delete only the role selection.
- [ ] **Step 4: Run** → PASS.

### Task 1.3: Callers switch to parties

**Files:**
- Modify: `ConversationService.php` (:48, :49, :130), `RecipientSearch.php` (:55, :66 — result `role` becomes the top role by UP D12 order: dean, chairperson, instructor, institutional_admin, student, parent), `GroupService.php` (:51, :76, :98, :159 — "may create" = holds chairperson or dean, transitional), `BlockService.php` (:44 — blockable = target holds no role in UNBLOCKABLE, transitional), `ChatControls.php` (:56, :75, :101), `ConversationPresenter.php` (:45, :122), `MessageService.php` (:549), `MessagePreferences.php` (:47 — quiet allowed = holds instructor/chair/dean, transitional until Phase 5), `ReportReviewService.php` (:181), `app/Controllers/Messaging/MessagesPageController.php` (:8, :27, :28), `ReportController.php` (:64), `ReportsPageController.php` (:75), `app/Filters/MessagesFilter.php` (:8, :34 — refuse when the party is a system admin with no other role, or impersonating), `app/Views/pages/messages/index.php` (no change in markup)
- Create: `tests/unit/NoActiveRoleInMessagesTest.php`
- Test: existing `tests/database/*Messag*`, `tests/feature/Messages*` suites

**Interfaces:**
- Consumes: `MessagingContextResolver::party()`, `MessagingPolicy::canStart(Party, Party, ?int, StartContext)`.
- Produces: `Party::topRole(): string` (add to Task 1.1's class; D12 order) used for labels.

- [ ] **Step 0: Write a pinning test** `MessagesRoutesTest::testMarkReadRouteAnswers` for `POST messages/api/conversations/{id}/read` (duplicate at Routes.php :587/:601) asserting today's response shape, so the refactor cannot change which controller answers.
- [ ] **Step 1: Write failing test** `NoActiveRoleInMessagesTest::testNoActiveRoleReads`: scans the files/directories listed in Global Constraints for the regex `active_role|ActiveRoleService|usableGroups\(|preferredRole\(` and asserts zero matches (list offenders in the failure message).
- [ ] **Step 2: Run** it → FAIL listing the call sites above.
- [ ] **Step 3: Replace** each call site with `party()` / `Party` methods as noted.
- [ ] **Step 4: Run** the new test and the full Messages suites: `vendor/bin/phpunit --filter 'Messag'` (env prefix) → all PASS; `npm test -- messages` → PASS.

### Phase 1 completion
- [ ] Full regression: `vendor/bin/phpunit` (env prefix) and `npm test` → all green; record counts in `test-strategy.md`.
- [ ] Review against Phase 1 objective; update `progress.md`; give the user the commit message:
  `refactor(messages): decide on parties (every role held), drop active_role from Messages`
- [ ] **Stop** until `done committing`.

---

## Phase 2: Blocking rework and the old-block reset

**Objective:** FR4. Protected link, paused blocks, admin blocks never bind, D16 reset into history.
**DB:** `user_block_history` (new); **[DATA MOVE]** qualifying rows moved from `user_blocks`. **Depends on:** Phase 1.
**Data preservation:** every moved row is copied first, counted, then deleted from `user_blocks` in one transaction; `down()` moves them back; a test proves `count(before) == count(user_blocks after) + count(history)`.

### Task 2.1: Block history table and reset migration [DATA MOVE]

**Files:**
- Create: `app/Database/Migrations/2026-12-02-000001_CreateUserBlockHistory.php`, `app/Database/Migrations/2026-12-02-000002_ResetNonBindingBlocks.php`
- Test: `tests/database/ResetNonBindingBlocksTest.php`

**Interfaces:**
- Produces: table `user_block_history(id PK, institution_id, blocker_id, blocked_id, blocked_at DATETIME, moved_at DATETIME, reason VARCHAR(30))`, KEY(blocker_id), KEY(institution_id). Reset rule (BC-4): move a `user_blocks` row when the blocker's held roles (from `auth_groups_users`) are non-empty and all in {instructor, institutional_admin}. `reason='reset_non_binding'`. One audit entry per moved row: `messages.block_reset` (target_type user, target_id blocked_id, institution_id).

- [ ] **Step 1: Write failing tests** `testMovesBlocksOfInstructorOnlyBlocker`, `testKeepsBlocksOfStudentInstructor` (student+instructor blocker stays), `testKeepsChairpersonBlocks`, `testCountsAddUp`, `testDownRestoresRows`, `testOneAuditEntryPerMovedBlock`. Run the migration class directly (`(new ResetNonBindingBlocks(...))->up()` / `down()`).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** both migrations. **Step 4: Run** → PASS.

### Task 2.2: Protected link and binding rules

**Files:**
- Modify: `MessagingContextResolver.php` (`blocked()` :231 → `blockBinds(int $blockerId, int $blockedId): bool`; `forPost` :99-128), `BlockService.php`, `MessagingPolicy.php` (`canPost`, `canReact` drop `blockerIsExempt`), `PostContext.php`, `StartContext.php`
- Test: `tests/database/BlockServiceTest.php` (create if absent; else extend), `tests/unit/MessagingPolicyTest.php`

**Interfaces:**
- Produces: `MessagingContextResolver::protectedLink(int $a, int $b): bool` (one is assigned instructor and the other enrolled `status='enrolled'` in the same `active` offering, either direction), `blockBinds(int $blockerId, int $blockedId): bool` = row exists AND blocker is not institutional_admin AND NOT protectedLink. `StartContext::$blockedByRecipient` now means "binding block". `BlockService::block(User $user, int $blockedId): bool` refuses: self, other institution, target holds institutional_admin, protectedLink(user, target), user is parent and target teaches a linked child. `BlockService::blockedBy(User $user): list<array{id:int,name:string,paused:bool}>`.

- [ ] **Step 1: Write failing tests** `testCannotBlockOwnInstructor`, `testCanBlockUnrelatedInstructor`, `testInstructorBlockOnOwnStudentDoesNotBind`, `testAdminBlockNeverBinds`, `testParentCannotBlockChildsInstructor`, `testBlockResumesWhenCourseEnds` (block, then share a course → `blockedBy` paused true and start allowed; drop enrolment → paused false and start refused), `testBlockedListShowsPaused`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Task 2.3: Block UI: refusal copy and paused badge

**Files:**
- Modify: `app/Controllers/Messaging/BlockController.php` (refusal reason `protected_link` → message "You can't block your instructor while you share a course."; other refusals generic), `resources/js/pages/messages/settings.js` (`renderBlocked()` :41: "Paused" badge + "Paused while you share a course."), `resources/js/pages/messages/menu-items.js` (`can_block` from server)
- Test: `tests/feature/MessagesBlockRoutesTest.php`, `tests/js/pages/messages-settings.test.js`

- [ ] **Step 1: Write failing tests** feature `testBlockingOwnInstructorReturnsReason` (422 + exact sentence), `testBlocksListIncludesPausedFlag`; Vitest `renders a Paused badge and explanation for paused blocks`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Phase 2 completion
- [ ] Full regression (PHP + JS). Record in `test-strategy.md`, including the migration counts test.
- [ ] Commit message: `feat(messages): blocks protect only the teaching link; reset old non-binding blocks into history`
- [ ] **Stop**; remind the user that `ResetNonBindingBlocks` is a data move they approved in Stage 3.

---

## Phase 3: Drafts, first contact, tiered limits, email scope

**Objective:** FR3, FR5, D18 setting (backend + switch in today's settings dialog, UI pick 1 and the email half of pick 6).
**DB:** `conversations.first_contact TINYINT(1) DEFAULT 0`, `conversations.first_contact_cleared_at DATETIME NULL`; `message_preferences.email_scope VARCHAR(12) NOT NULL DEFAULT 'everyone'`. **Depends on:** Phase 2.

### Task 3.1: Create on first send

**Files:**
- Create: `app/Database/Migrations/2026-12-02-000003_AddFirstContactAndEmailScope.php`
- Modify: `ConversationService.php` (`startDirect` :39 split), `app/Controllers/Messaging/ConversationController.php` (`start`), `MessageService.php` (`send`), `ConversationService::listFor` / `unreadCount` / `archivedCount` (hide direct rows with `last_message_id IS NULL`)
- Test: `tests/database/ConversationDraftTest.php`, `tests/feature/MessagesRoutesTest.php`

**Interfaces:**
- Produces: `ConversationService::existingDirect(User $sender, int $recipientId, ?int $subjectStudentId): ?array` (no insert). `ConversationService::draftFor(User $sender, int $recipientId, ?int $subjectStudentId): ?array{recipient_id:int, subject_student_id:?int, first_contact:bool, conversation_id:?int}` (null if `canStart` refuses). `MessageService::sendFirst(User $sender, int $recipientId, ?int $subjectStudentId, string $body, string $clientUuid): array` — one transaction: rate-limit check (`thread`), insert-ignore conversation by `direct_key`, participants, message; returns the same shape as `send`. Route: `POST messages/api/conversations` now returns the draft (no insert); new `POST messages/api/conversations/first` → `ConversationController::sendFirst`.

- [ ] **Step 1: Write failing tests** `testDraftCreatesNothing`, `testDraftReturnsExistingThread`, `testSendFirstCreatesThreadAndMessage`, `testConcurrentFirstSendsMakeOneThread` (two `sendFirst` calls, A→B and B→A, same pair: one conversation row, two messages), `testEmptyLegacyThreadHiddenFromListAndCounts`, `testRefusedDraftIs404`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Task 3.2: First-contact fact and tiered thread limits

**Files:**
- Modify: `MessagingContextResolver.php`, `MessageService::sendFirst`, `MessageService::post` (first reply by recipient sets `first_contact_cleared_at`), `BlockService::block` (sets it too), `MessageRateLimiter.php`, `ConversationPresenter::present` (expose `first_contact_line: bool` to the recipient and `first_contact_sender: bool` in drafts)
- Test: `tests/database/FirstContactTest.php`, `tests/database/MessageRateLimiterTest.php`

**Interfaces:**
- Produces: `MessagingContextResolver::talkedBefore(int $a, int $b): bool` = a direct conversation with ≥1 message between them, OR protectedLink-or-classmates (share any active offering, any membership), OR parent↔staff of a linked child. `first_contact = !talkedBefore` at creation, forced true when the recipient is a minor (Phase 5 wires `minor`; until then false). `MessageRateLimiter::check(string $kind, int $userId, ?DateTimeImmutable $now = null, ?Party $party = null): void` — kind `thread`: if party holds institutional_admin → no limit; staff → 20/3600 s and 60/86400 s; else 10/3600 s and 25/86400 s. Messages for the day cap: "You've started a lot of conversations today. Try again tomorrow."

- [ ] **Step 1: Write failing tests** `testFirstContactWhenNeverTalked`, `testNoFirstContactForClassmates`, `testNoFirstContactForParentAndChildsInstructor`, `testLineClearsWhenRecipientReplies`, `testLineClearsWhenRecipientBlocks`, `testStudentHourlyAndDailyCaps` (10th ok, 11th throws; 25/day), `testStaffCaps` (20/60), `testAdminExempt`, `testGroupCreationCounts`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Task 3.3: Email scope in the notifier

**Files:**
- Modify: `MessagePreferences.php` (`get` returns `email_scope`; `save(User $user, ?string $quietStart, ?string $quietEnd, string $emailScope)` where `email_scope ∈ {off, everyone, talked}` maps `off` → `email_alerts=0`), `MessageNotifier.php` (`tell` emails only if scope `everyone`, or `talked` and `talkedBefore`), `PreferenceController.php`
- Test: `tests/database/MessageNotifierTest.php`, `tests/database/MessagePreferencesTest.php`, `tests/feature/MessagesPreferenceRoutesTest.php`

- [ ] **Step 1: Write failing tests** `testTalkedScopeSkipsEmailFromStranger`, `testTalkedScopeEmailsAfterReply`, `testEveryoneScopeDefault`, `testOffScopeSendsNoEmail`, `testInAppNoticeAlwaysSent`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Task 3.4: Frontend: draft flow, first-contact line, email switch

**Files:**
- Modify: `resources/js/pages/messages/api.js` (`start` → draft; new `sendFirst(recipientId, subjectStudentId, body, clientUuid)`), `messages.js` (`startWith` :851 opens a draft view; `submit` :734 calls `sendFirst` when no conversation id; draft text stored in `localStorage` key `messages:draft:{userId}:{recipientId}:{child|0}` inside try/catch), `render.js` (new `systemLine(text, actions)` used for the first-contact line: recipient "First message from {name} · {role} · you haven't talked before" with Block / Report pills; sender "First message to {name}"), messages.js prefs form :988-1020 (email checkbox → three-way switch Off / Everyone / People I've talked to, pick 6)
- Test: `tests/js/pages/messages-page.test.js`, `messages-render.test.js`, `messages-api.test.js`, `messages-composer.test.js`, `messages-settings.test.js`

- [ ] **Step 1: Write failing Vitest tests** `opening a person shows a draft and sends nothing`, `first send calls sendFirst then switches to the new thread`, `draft text survives reload via localStorage and is cleared after send`, `recipient sees the first-contact line with Block and Report`, `sender draft shows "First message to"`, `email switch saves off/everyone/talked`.
- [ ] **Step 2: Run** `npm test` → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS; `npm run lint` clean.

### Phase 3 completion
- [ ] Full regression; update `docs/test-cases/p2-messages.md` with cases for draft, first-contact line, caps; e2e or NOT RUN.
- [ ] Commit message: `feat(messages): create threads on first send, first-contact line, tiered thread limits, email scope`
- [ ] **Stop.**

---

## Phase 4: Restrictions, DM removal, automatic pause, reports by person

**Objective:** FR6 with UI picks 2 and 3.
**DB:** `message_restrictions` (new), `message_reports.pause_id INT NULL` (additive). **Depends on:** Phase 3 (`first_contact`).

### Task 4.1: Restrictions model and enforcement

**Files:**
- Create: `app/Database/Migrations/2026-12-02-000004_CreateMessageRestrictions.php`, `app/Services/Messaging/RestrictionService.php`
- Modify: `MessagingContextResolver::forStart` / `forPost`, `StartContext.php` (`senderRestriction: 'none'|'no_new'|'no_send'`), `PostContext.php` (`restriction`), `MessagingPolicy::canStart` (refuse `no_new`/`no_send`), `canPost`/`canReact` (refuse `no_send` unless course chat where `isCourseInstructor`), `GroupService` (create/add refused under any restriction, BC-5)
- Test: `tests/database/RestrictionServiceTest.php`, `tests/unit/MessagingPolicyTest.php`

**Interfaces:**
- Produces: table `message_restrictions(id, institution_id, user_id, kind VARCHAR(10) 'no_new'|'no_send', source VARCHAR(10) 'admin'|'pause', starts_at, ends_at NULL, ended_at NULL, ended_by NULL, end_reason VARCHAR(20) NULL, created_by NULL, created_at)`, KEY(user_id, ended_at), KEY(institution_id). `RestrictionService::active(int $userId, ?DateTimeImmutable $now = null): ?array` (latest row with `ended_at IS NULL` and `ends_at` null or future; an elapsed row is closed lazily with `end_reason='expired'` and audited `messages.restriction_expired`/`messages.pause_expired`, system actor). `restrict(User $admin, int $userId, string $kind, ?DateTimeImmutable $until): bool` (ends any active admin restriction first: one active per person), `lift(User $admin, int $userId): bool`. Audit `messages.restriction_placed` / `messages.restriction_lifted`.

- [ ] **Step 1: Write failing tests** `testNoNewBlocksStartButNotReply`, `testNoSendBlocksReplyButNotOwnCourseChat`, `testRestrictionExpiresAndIsAudited`, `testNewRestrictionReplacesOld`, `testRestrictedCannotCreateGroup`, `testOnlySameInstitutionAdminCanRestrict`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Task 4.2: Automatic pause

**Files:**
- Modify: `ReportService::report` (after insert, evaluate pause), `RestrictionService` (`startPause`), `app/Services/Notifications` caller for the admin notice
- Test: `tests/database/AutomaticPauseTest.php`

**Interfaces:**
- Produces: `RestrictionService::evaluatePause(int $reportedUserId, ?DateTimeImmutable $now = null): ?int` — counts distinct `reporter_id` of `message_reports` where `pause_id IS NULL`, created in the last 24 h, message in a `direct` conversation with `first_contact=1` and `created_by = reportedUserId`, message sender = reportedUserId, reporter not sharing any active offering with the reported user; if ≥3 and the reported user holds no institutional_admin role and has no active restriction → insert `source='pause', kind='no_new', ends_at=now+72h`, set those reports' `pause_id`, notify every institutional admin of the institution (`NotificationService::send`, type `message_pause`, link `messages/reports`), audit `messages.pause_started`.

- [ ] **Step 1: Write failing tests** `testThreeStrangersPause`, `testClassmatesReportsDoNotCount` (class brigading its instructor: no pause), `testSameReporterCountsOnce`, `testGroupOrCourseReportsDoNotCount`, `testAdminsNeverPaused`, `testCountedReportsNeverCountAgain`, `testPauseExpiresAfter72h`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Task 4.3: Review by person, DM removal, ending the pause

**Files:**
- Modify: `ReportReviewService.php` (`list` grouped by reported sender; `review` allows removing DMs; new `reviewAll`), `MessageService::remove` path for admin DM removal (tombstone kind `admin`), `app/Controllers/Messaging/ReportsPageController.php`, `ReportController.php`, `app/Views/pages/messages/reports.php`
- Test: `tests/database/ReportReviewByPersonTest.php`, `tests/feature/MessagesReportsPageTest.php`

**Interfaces:**
- Produces: `ReportReviewService::cases(User $admin, string $status = 'open'): list<array{user_id:int, name:string, role:string, reports:list<...existing row shape...>, pause:?array{ends_at:string}, restriction:?array}>`. `review(User $admin, int $reportId, string $status, bool $removeMessage): bool` — removal now allowed for direct messages (`removed_by` = admin; presenter shows "Message removed by an administrator"). `reviewAll(User $admin, int $userId, string $decision /* dismiss|remove */): int`. After any review, if every report with that `pause_id` is no longer `open`, end the pause (`end_reason='reviewed'`, audit `messages.pause_ended`). `restrict` from the case also ends a pause (`end_reason='restricted'`).

- [ ] **Step 1: Write failing tests** `testCasesGroupReportsBySender`, `testAdminCanRemoveDirectMessage` (tombstone text exact), `testReviewingAllCountedReportsEndsPause`, `testRestrictingEndsPause`, `testReviewingExpiredPauseIsHarmless` (no second audit), feature `testReportsPageShowsCaseWithPauseMarker`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** the page per pick 3: one card per person, stacked report snapshots, pause tag "Paused · {h}h left", buttons Dismiss all / Remove reported messages / Restrict… (dialog: no new conversations | no sending; duration 1 day, 7 days, 30 days, until lifted — builder's presets), each report still decidable. **Step 4: Run** → PASS.

### Task 4.4: Restricted person's notice (pick 2)

**Files:**
- Modify: `ConversationController::index` (payload `restriction: null|{kind, ends_at, source}` for the viewer only), `messages.js` (list pane notice card + greyed "+ New" with `aria-disabled` and click repeating the reason), `app/Views/pages/messages/index.php` (notice slot `data-role="restriction"` with `role="status"`)
- Test: `tests/feature/MessagesRoutesTest.php`, `tests/js/pages/messages-page.test.js`

- [ ] **Step 1: Write failing tests** feature `testIndexIncludesOwnRestrictionOnly`; Vitest `shows the restriction card and disables New`, `pause wording says while some reports are reviewed`, `no_send replaces reply boxes except course chats taught`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Phase 4 completion
- [ ] Full regression; test cases doc; e2e or NOT RUN.
- [ ] Commit message: `feat(messages): admin restrictions and DM removal, automatic pause, reports grouped by person`
- [ ] **Stop.**

---

## Phase 5: Minor flag, student scope, quiet hours for everyone

**Objective:** FR8, FR9, FR10 with picks 5, 6 (quiet half), 7.
**DB:** `institution_users.is_minor TINYINT(1) DEFAULT 0`; `message_preferences.quiet_mode VARCHAR(8) NULL` (NULL never set, `own`, `none`); settings keys `Platform.studentMessagingScope` and `Platform.defaultQuietStart/End`; **[DATA MOVE-lite]** `studentDmEnabled=false` overrides copied to `studentMessagingScope='off'` (old key kept, read no more). **Depends on:** Phase 4.

### Task 5.1: Minor flag service, People action, CSV column

**Files:**
- Create: `app/Database/Migrations/2026-12-02-000005_AddMinorFlag.php`, `app/Services/Institutions/MinorFlag.php`
- Modify: `app/Controllers/Institution/PeopleController.php` (+ routes `POST institution/people/(:num)/minor` and `/(:num)/minor/remove` next to Routes.php :1146-1169), `app/Views/pages/institution/people.php` (Actions menu item + confirm text per pick 7; "Minor" badge; shown only for people holding the student role), `app/Services/Sis/SisCsvParser.php` (`UPLOAD_SPECS` students optional `minor` with values yes/no/1/0), `app/Services/Sis/SisImporter.php` (`person()` :294 sets the flag), `MessagingContextResolver::party` (minor from `institution_users.is_minor`)
- Test: `tests/database/MinorFlagTest.php`, `tests/feature/InstitutionPeopleMinorTest.php`, `tests/database/SisImporterMinorTest.php`

**Interfaces:**
- Produces: `MinorFlag::set(User $admin, int $institutionId, int $userId, bool $minor): bool` (student role required; audit `messages.minor_flag_set` / `messages.minor_flag_cleared`), `MinorFlag::isMinor(int $institutionId, int $userId): bool`.

- [ ] **Step 1: Write failing tests** `testAdminMarksStudentMinorAndAudits`, `testNonStudentCannotBeMarked`, `testOtherInstitutionAdminRefused`, feature `testPeopleRowShowsMinorBadgeToAdmin`, `testCsvMinorColumnSetsFlag`, `testPartyCarriesMinor`, `testFirstContactAlwaysForMinorRecipient` (classmate writes to a minor → `first_contact=1`, FR5).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (confirm copy: "Only their instructors, department and college leads, classmates and admins will be able to message them. Conversations that no longer qualify become read-only."). **Step 4: Run** → PASS.

### Task 5.2: Three-way student scope and default quiet hours (pick 5)

**Files:**
- Create: `app/Database/Migrations/2026-12-02-000006_MapStudentDmSetting.php`
- Modify: `app/Config/Platform.php` (add `public string $studentMessagingScope = 'institution'; public ?string $defaultQuietStart = null; public ?string $defaultQuietEnd = null;`), `app/Services/Admin/PlatformSettings.php` (:57 definitions; institution-scoped; scope ∈ off|course|institution; times HH:MM), `app/Services/Messaging/StudentMessaging.php` (`scope(int): string`, `setScope(int, string)`; `enabled()` kept as `scope !== 'off'` for callers until Phase 7), `InstitutionSettingsController::saveMessages` :130, `app/Views/pages/institution/settings.php` :208-224 (segmented switch Off / Classmates only / Whole institution + default quiet hours, per pick 5)
- Test: `tests/database/MapStudentDmSettingTest.php`, `tests/feature/InstitutionMessagesSettingsTest.php`

- [ ] **Step 1: Write failing tests** `testOffMapsToOff`, `testUnsetStaysDefaultInstitution`, `testDownRemovesOnlyNewKey`, feature `testSavesScopeAndAudits`, `testSavesDefaultQuietHours`, `testRejectsBadTimes`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Task 5.3: Quiet hours for everyone (pick 6, quiet half)

**Files:**
- Create: `app/Database/Migrations/2026-12-02-000007_AddQuietMode.php` (backfill `quiet_mode='own'` where both times set; others NULL — BC-3)
- Modify: `MessagePreferences.php` (remove `QUIET_ROLES` and `quietHoursAllowed`; `get` returns `quiet_mode` and effective times; `isQuiet(int $userId, DateTimeImmutable $now, ?int $institutionId = null)` uses own times, none, or the institution default when NULL; platform timezone from `GeneralSettings`), `MessageNotifier::notifyConversation` (pass institution), `PreferenceController.php`, messages.js prefs form (quiet switch School default / My own / None, time inputs only for My own; remove `quiet_hours_allowed` use at :1011)
- Test: `tests/database/MessagePreferencesTest.php`, `tests/database/MessageNotifierTest.php`, `tests/js/pages/messages-settings.test.js`

- [ ] **Step 1: Write failing tests** `testStudentCanSetQuietHours`, `testNeverSetUsesInstitutionDefault`, `testNoneOverridesDefault`, `testExistingTimesBecomeOwn`, `testEmailOnlyRowsBecomeNeverSet`, `testNotifierHoldsDuringDefaultQuietHours`; Vitest `quiet switch shows times only for My own`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Phase 5 completion
- [ ] Full regression; e2e `setStudentMessages` helper updated to the scope switch; test cases doc.
- [ ] Commit message: `feat(messages): minor flag, three-way student scope, quiet hours for everyone with an institution default`
- [ ] **Stop**; remind the user `MapStudentDmSetting` copies settings (old key untouched).

---

## Phase 6: Owner-run groups

**Objective:** FR7 + D26 with pick 4.
**DB:** `conversations.owner_id INT UNSIGNED NULL` (FK users SET NULL), `conversations.closed_at DATETIME NULL`; backfill `owner_id = created_by` for `type='group'`. New message kind for system lines: `messages.system_event VARCHAR(30) NULL` (body empty; event + actor/target ids in `system_meta JSON`). **Depends on:** Phase 5 (minor flag).

### Task 6.1: Group membership service

**Files:**
- Create: `app/Database/Migrations/2026-12-02-000008_AddGroupOwnership.php`
- Modify: `GroupService.php` (MAX_MEMBERS = 50; remove `MEMBER_ROLES`)
- Test: `tests/database/GroupServiceTest.php`, `tests/database/GroupMinorRulesTest.php`

**Interfaces:**
- Produces: `GroupService::create(User $creator, string $title, array $memberIds): ?array` (any party with capacity `staff` or `student` may create; parent-only refused; each member: `canStart(actor, member, null, ctx)` and no binding block member→actor; D26 minor rules; ≤50 incl. creator). `add(User $actor, int $conversationId, array $memberIds): array{added:list<int>, refused:list<int>}`, `remove(User $actor, int $conversationId, int $memberId): bool`, `leave(User $user, int $conversationId): bool`, `rename(User $actor, int $conversationId, string $title): bool`, `close(User $actor, int $conversationId): bool`. Actor = owner or institutional admin. Handover on owner leave/deactivation: longest-standing (earliest `joined_at`, `left_at IS NULL`) member holding a staff role, else longest-standing member. One member left → `closed_at` set. Removed/left → `left_at` set (existing column; access ends). Each event writes a system message and an audit entry `messages.group_created|member_added|member_removed|member_left|renamed|closed|owner_changed`. Added members get an in-app notification at once (S2-g).

- [ ] **Step 1: Write failing tests** `testStudentCanCreateGroup`, `testParentCannotCreateOrBeAdded`, `testMemberMustBeReachableByActor`, `testBlockedActorCannotAddBlocker`, `testFiftyMemberLimit`, `testMinorNeedsEveryMemberToReachThem`, `testCannotAddUnreachableToGroupWithMinor`, `testOwnerRemovesAndRenames`, `testMemberLeavesLosesAccess`, `testHandoverPrefersStaff`, `testLastMemberClosesGroup`, `testClosedGroupIsReadOnlyNotDeleted`, `testExistingGroupsBackfilledOwner`, `testEveryEventAudited`, `testReadOnlyInstitutionRefusesGroupChanges` (FR11).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement**; `MessagingPolicy::canModerate` for `group` = actor is owner or institutional admin; `canPost` refuses when `closed_at` set. **Step 4: Run** → PASS.

### Task 6.2: Group routes and the Group settings dialog (pick 4)

**Files:**
- Modify: `app/Config/Routes.php` (messages/api: `POST groups/(:num)/members`, `POST groups/(:num)/members/(:num)/remove`, `POST groups/(:num)/leave`, `POST groups/(:num)/rename`, `POST groups/(:num)/close`), `app/Controllers/Messaging/GroupController.php`, `MessagesPageController.php` (`canStartGroups` = party capacity staff or student), `resources/js/pages/messages/group.js` (`MAX_MEMBERS = 50`), `menu-items.js` (chat menu "Group settings"), `messages.js` (dialog with Members / Details tabs), `render.js` (`systemLine` for group events), `app/Views/pages/messages/index.php` (dialog shell)
- Test: `tests/feature/MessagesGroupRoutesTest.php`, `tests/js/pages/messages-group.test.js`, `messages-menu-items.test.js`, `messages-render.test.js`

- [ ] **Step 1: Write failing tests** feature `testOwnerCanAddRemoveRenameClose`, `testMemberCannotRemoveOthers`, `testAdminCanManageAnyGroup`, `testNonMember404`; Vitest `Group settings opens with Members and Details tabs`, `owner sees Remove and Add; member sees Leave only`, `renders system lines for member events`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Phase 6 completion
- [ ] Full regression; test cases doc; e2e or NOT RUN.
- [ ] Commit message: `feat(messages): owner-run groups for students and staff, 50 members, minor-safe membership`
- [ ] **Stop.**

---

## Phase 7: Institution-wide reach

**Objective:** FR1 final rules, FR2 read-only rules, FR11, FR12 behaviour (search paging; picker UI waits for User Profile), AC1-AC10.
**DB:** none. **Depends on:** Phase 6.

### Task 7.1: Final start rules

**Files:**
- Modify: `MessagingPolicy.php` (replace transitional `canStart` body and delete `START`), `StartContext.php` (final fields), `MessagingContextResolver::forStart`
- Test: `tests/unit/MessagingPolicyTest.php` (rewritten as the AC1 matrix)

**Interfaces:**
- Produces: `StartContext` fields: `sameInstitution, senderActive, recipientActive, senderInstitutionWritable, impersonating, isSelf, sharedCourse, senderTeachesRecipient, senderLeadsRecipient (chair of recipient's department or dean of recipient's college), linkedChild, studentScope ('off'|'course'|'institution'), blockedByRecipient (binding), senderRestriction, recipientMinor`. `canStart(Party $sender, Party $recipient, ?int $subjectStudentId, StartContext $c): bool` evaluates FR1 steps 1-4 in order exactly as written in requirements §FR1.

- [ ] **Step 1: Write failing tests**: data provider over the 7 roles × 7 roles with the default context (D1 expectations: parent rows per D2, system admin false, student↔student per scope), plus `multiRoleStudentTeacherIsStaffToClassmates`, `multiRoleMinorStaffProtectedWhenReceiving`, `minorReachableByOwnInstructorChairDeanClassmateAdminOnly`, `parentNeedsLinkedChildAndStaffRecipient`, `anyoneCanReachInstitutionalAdmin`, `scopeOffCourseInstitution`, `restrictedAndPausedRefused`, `readOnlyInstitutionRefusesStart`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Task 7.2: Read-only threads and account/institution states

**Files:**
- Modify: `MessagingContextResolver::forPost` (`studentDmBlocked` → `scopeLost` using scope + shared course; new `minorLost`; `deactivatedOther`), `PostContext.php`, `MessagingPolicy::canPost`/`canReact`, `ChatStateService`, `BlockService`, `ReportService` (allowed in read-only, refused in past — FR11), `ConversationPresenter` (`composer_note`: "This account is deactivated" etc.)
- Test: `tests/database/ThreadReadOnlyTest.php`, `tests/database/ReadOnlyInstitutionSafetyTest.php`

- [ ] **Step 1: Write failing tests** `testThreadReadOnlyAfterDrop` (scope course), `testThreadReadOnlyWhenRecipientBecomesMinor`, `testDeactivatedOtherShowsNote`, `testReadOnlyInstitutionAllowsBlockReportMuteArchiveRead`, `testPastInstitutionRefusesSafetyActions`, `testGroupsNotMadeReadOnlyByMinorFlag` (D26).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Task 7.3: Paged recipient search and privacy

**Files:**
- Modify: `RecipientSearch.php` (page through candidates in batches of 50 ordered by last/first name with `?after=` cursor until `$limit` allowed results or candidates end; name LIKE; email match only for staff targets, or student targets when the searcher is staff — S2-e), `RecipientController.php`, `ConversationController::start` (refusal shape identical for every reason)
- Test: `tests/database/RecipientSearchTest.php`, `tests/feature/MessagesRoutesTest.php`, `tests/feature/MessagesIdorTest.php`

- [ ] **Step 1: Write failing tests** `testFindsPeopleBeyondTwoHundredCandidates`, `testExcludesMinorsUnreachable`, `testExcludesBindingBlockers`, `testEmailSearchFollowsVisibility`, `testRefusalsAreIndistinguishable` (blocked, minor, restricted, nonexistent → same status and body), `testParentWithNoChildGetsExplanation` (S2-i, reason `no_linked_child` returned only to that parent).
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS; a timing check `testSearchFirstPageUnder300ms` on a seeded 5,000-member institution (skipped with a message if seeding exceeds 60 s locally; record result).

### Task 7.4: Docs, e2e and final regression

**Files:**
- Modify: `docs/messages.md`, `docs/test-cases/p2-messages.md`, `tests/e2e/messages.spec.js` + `tests/e2e/support/messages.js` (cases: student messages unrelated dean; minor not findable by non-classmate; first-contact line; restriction notice; group owner flow; scope switch), `docs/feature-development/single-identity/` coordination note (Messages no longer reads `active_role`), `docs/superpowers/specs/2026-11-02-messages-design.md` status line.
- [ ] **Step 1:** Run `vendor/bin/phpunit` (env prefix), `npm test`, `npm run lint`, `npm run e2e` (or NOT RUN) → record counts and any NOT RUN in `test-strategy.md`.
- [ ] **Step 2:** Data integrity check: on a copy of a seeded DB, counts of conversations, messages, attachments (`files` linked to messages), reports, preferences, parent preferences unchanged across all migrations; blocks = kept + history.

### Phase 7 completion
- [ ] Code review (`superpowers:requesting-code-review`) on the whole chain; fix findings in this phase.
- [ ] Commit message: `feat(messages): open Messages to the whole institution with minor, scope and safety rules`
- [ ] **Stop**; final summary to the user. The user opens the PR to `main`.

---

## Deferred Phase 8 (after User Profile implementation lands; separate approval)

Picker with suggestions and avatar rows (FR12, D15) and moving the settings dialog into `/settings` → Notifications (UP D15). Needs its own mockup round (2-3 options) and plan before work starts.

## Assessment of the current implementation (reuse / refactor / replace / retire)

Full inventory in `existing-implementation-audit.md`. Summary:
- **Reuse:** encryption (`MessageCipher`, `MessageReencryptor`), course chats (`CourseChatSync`, hooks), parent feed, chat controls, chat state (pin/mute/archive), poller, reactions/actions, report snapshot, notifier throttling, `DirectKey`.
- **Refactor:** `MessagingContextResolver` (parties, facts), `ConversationService` (draft + first send), `MessageRateLimiter` (tiers), `MessagePreferences` (modes), `BlockService`, `ReportReviewService` (cases), `GroupService` (owner model), `RecipientSearch` (paging), `StudentMessaging` (scope), frontend `messages.js`, `render.js`, `settings.js`, `group.js`.
- **Replace:** `MessagingPolicy::START` table and `UNBLOCKABLE` (Phase 7 / 2), `StartContext` fields.
- **Retire:** `MessagingContextResolver::activeRole`, `MessagePreferences::QUIET_ROLES` / `quietHoursAllowed`, `GroupService::MEMBER_ROLES`, the `studentDmEnabled` read path (key kept in storage), "nobody moderates a direct thread" rule.
- **Noticed, not in scope:** duplicate route `POST messages/api/conversations/(:num)/read` (Routes.php :587 and :601). Phase 1 adds a feature test pinning which controller answers; no behaviour change.
