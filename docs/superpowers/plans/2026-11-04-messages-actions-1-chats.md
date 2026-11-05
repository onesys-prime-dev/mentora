# Messages actions, slice 1: chat actions in the list — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A person can pin, mute, archive, mark unread and delete-for-me their own chats, with the list, the badge and notifications respecting those choices.

**Architecture:** Per-person state lives on `conversation_participants` (four new columns). A new `ChatStateService` owns the writes; `ConversationService::listFor` / `unreadCount` and `MessageService::page` read the new columns. One small controller exposes JSON routes. No front-end work here (slice 3).

**Tech Stack:** PHP 8.2+, CodeIgniter 4.7, MySQL/MariaDB, PHPUnit (`Tests\Support\DatabaseTestCase`).

**Spec:** `docs/superpowers/specs/2026-11-04-messages-actions-design.md` (sections 3.1, 3.4, 4, 5). Original design: `2026-11-02-messages-design.md`.

## Global Constraints

- Branch `feat/messages-actions-chats`, stacked on `refactor/messages-ui` (it holds the owner's unmerged UI tweaks). Local commits only; **no push, no PR** until the owner says so; open PRs by hand later (not `scripts/create-pr.sh`).
- `git status` before every `git add`; stage only the files you changed (the owner's IDE edits may be in the tree).
- **Do not run PHPUnit or PHPStan locally** to finish this branch (owner's rule for Messages; CI does it). Write each PHP test first anyway. Local checks are `php -l <file>` only.
- New migration files must sort after the newest file in `app/Database/Migrations` (today `2026-11-21-000003_…`): use `2026-11-22-000001_AddChatActionColumns.php`.
- Max pinned chats **5**. Course chats cannot be pinned or unpinned by the person (already pinned by rule) and cannot be deleted-for-me. Delete-for-me allowed for `direct` and `group` only.
- Every action resolves the chat through `ConversationService::find($user, $id)`; null → the shared 404 (`MessagingController::notFound()`), identical for foreign and missing ids.
- Personal actions change only the caller's own `conversation_participants` row.
- No message text in logs or audit entries. The new routes sit in the existing `messages/api` group (filters `messages`, `readonly`, `permission:messages.use`).

## Review Focus

- A chat deleted-for-me then receiving a new message: reappears showing only newer messages (the old history must not come back).
- An archived chat that is **muted** stays archived on a new message; an unmuted one returns to the main list.
- Pinning a 6th chat is refused with a clear reason, and unpinning then pinning works.
- A muted chat's unread messages do not count in the Messages badge but its row still shows its unread dot.
- Marking a chat unread then opening it clears the mark; marking an already-read chat unread shows a dot of 1.

---

### Task 0: Commit the owner's UI tweaks first

**Files:** `app/Controllers/Messaging/MessagesPageController.php`, `app/Views/pages/messages/index.php`, `resources/js/pages/messages/messages.js`, `resources/js/pages/messages/render.js` (already modified, uncommitted, on `refactor/messages-ui`).

- [ ] **Step 1:** `git status`; confirm only those four files are modified. Run `php -l` on the two PHP files.
- [ ] **Step 2:** Commit them as one commit: `refactor(messages): taller panes, wider list, larger fonts, one-line filters, roomier list`. Then `git switch -c feat/messages-actions-chats`.

### Task 1: Migration for the participant columns

**Files:**
- Create: `app/Database/Migrations/2026-11-22-000001_AddChatActionColumns.php`
- Modify: `tests/database/MessagingMigrationsTest.php`

**Interfaces:**
- Produces: `conversation_participants.pinned_at DATETIME NULL`, `.archived_at DATETIME NULL`, `.marked_unread TINYINT(1) NOT NULL DEFAULT 0`, `.cleared_before_message_id INT UNSIGNED NOT NULL DEFAULT 0`. `down()` drops the four columns.

- [ ] **Step 1:** In `MessagingMigrationsTest` add `testTheChatActionColumnsExist` asserting `db_connect()->fieldExists($c, 'conversation_participants')` for the four names, and that `marked_unread` and `cleared_before_message_id` default to 0.
- [ ] **Step 2:** Write the migration with `$this->forge->addColumn('conversation_participants', [...])` and the matching `dropColumn` in `down()`. `php -l` it.
- [ ] **Step 3:** Commit `feat(messages): add the per-person chat action columns`.

### Task 2: `ChatStateService`

**Files:**
- Create: `app/Services/Messaging/ChatStateService.php`
- Test: `tests/database/ChatStateServiceTest.php` (follow `ConversationServiceTest` setUp: `DeanFactory`, `PeopleFactory`, `CourseFactory`; a `startDirect` helper gives direct chats, a course chat comes from `CourseChatSync`).

**Interfaces:**
- Consumes: `ConversationService::find(User, int): ?array`.
- Produces (all return `array{ok: bool, reason?: 'not_found'|'not_allowed'|'limit'}`):
  - `public const MAX_PINNED = 5;`
  - `pin(User $user, int $conversationId): array` — `not_allowed` for `course` chats; `limit` when the person already has 5 pinned chats; sets `pinned_at = NOW()`; pinning an already pinned chat is `ok` and changes nothing.
  - `unpin(User $user, int $conversationId): array` — `not_allowed` for `course`.
  - `setMuted(User $user, int $conversationId, bool $muted): array` — uses existing `is_muted`.
  - `setArchived(User $user, int $conversationId, bool $archived): array` — sets/clears `archived_at`.
  - `markUnread(User $user, int $conversationId): array` — sets `marked_unread = 1`.
  - `deleteForMe(User $user, int $conversationId): array` — `not_allowed` for `course`; sets `cleared_before_message_id` to the chat's `last_message_id` (0 if none) and clears `pinned_at` and `archived_at`.

- [ ] **Step 1: Write `ChatStateServiceTest`** with these tests (assert on the `conversation_participants` row of the caller **and** that the other participant's row is untouched):
  `testPinSetsPinnedAtForTheCallerOnly`, `testAChatThatIsNotTheCallersIsNotFound` (foreign and missing id both `not_found`), `testACourseChatCannotBePinnedOrUnpinnedOrDeleted`, `testASixthPinIsRefusedWithLimit`, `testUnpinningFreesASlot`, `testMuteAndUnmute`, `testArchiveAndUnarchive`, `testMarkUnreadSetsTheFlag`, `testDeleteForMeRecordsTheLastMessageAndClearsPinAndArchive`.
- [ ] **Step 2:** Implement `ChatStateService` (constructor `?ConversationService`; each method calls `find` first, then one `UPDATE` scoped by `conversation_id` + `user_id`). `php -l`.
- [ ] **Step 3:** Commit `feat(messages): add ChatStateService for pin, mute, archive, mark unread and delete for me`.

### Task 3: The list, the badge and paging read the new state

**Files:**
- Modify: `app/Services/Messaging/ConversationService.php` (`listFor`, `unreadCount`, `unreadSql`), `app/Services/Messaging/MessageService.php` (`page`, `markRead`, `send`)
- Test: `tests/database/ConversationServiceTest.php`, `tests/database/MessageServiceTest.php`

**Interfaces:**
- `listFor(User $user, string $filter = 'all', bool $unreadOnly = false, bool $archived = false): array` — new fourth parameter; rows gain `pinned_at`, `archived_at`, `marked_unread` (int) and `unread` becomes `max(unread, marked_unread)`.
- Default (`$archived = false`) excludes archived chats; `$archived = true` returns only archived. Chats where `cleared_before_message_id > 0 AND last_message_id <= cleared_before_message_id` are excluded in both.
- Order: pinned (`pinned_at` DESC) → course chats → `last_message_at` DESC → `id` DESC.
- `public function archivedCount(User $user): int`.
- `unreadCount` ignores participants with `is_muted = 1`. `unreadSql` also requires `m.id > p.cleared_before_message_id`.
- `MessageService::page` uses `max(first_visible_message_id, cleared_before_message_id + 1)` as its lower bound. `markRead` also sets `marked_unread = 0`.
- `MessageService::send`, after a successful insert, runs `UPDATE conversation_participants SET archived_at = NULL WHERE conversation_id = ? AND user_id <> ? AND is_muted = 0`.

- [ ] **Step 1: Write failing tests:** `testPinnedChatsSortAboveCourseChats`, `testArchivedChatsLeaveTheMainListAndShowInTheArchivedView`, `testArchivedCount`, `testADeletedChatIsHiddenUntilANewMessage`, `testAfterDeleteForMeOnlyNewerMessagesShow` (page), `testAMutedChatDoesNotCountInTheBadge` (but its row `unread` still counts), `testMarkedUnreadShowsOneThenOpeningClearsIt`, `testANewMessageUnarchivesAnUnmutedChat`, `testANewMessageDoesNotUnarchiveAMutedChat`.
- [ ] **Step 2:** Implement the above. Keep SQL in `ConversationService`; do not add a second query path.
- [ ] **Step 3:** Commit `feat(messages): the list, badge and paging respect pin, mute, archive and delete for me`.

### Task 4: Pin down that muted chats do not notify

`MessageNotifier` already skips participants with `is_muted = 1` (it filters `is_muted = 0` when picking who to notify), so this task only adds the test that fixes it in place.

**Files:** Test: `tests/database/MessageNotifierTest.php`

- [ ] **Step 1:** Add `testAMutedParticipantIsNotNotified`: in a two-person direct chat, mute one person via `ChatStateService::setMuted`, send a message from the other, run `notifyConversation`; assert the muted person has no `notifications` row and no email, and that unmuting then sending notifies them.
- [ ] **Step 2:** If it fails, make the notifier skip muted participants; otherwise change nothing. Commit `test(messages): muted chats do not notify`.

### Task 5: Presenter flags and JSON routes

**Files:**
- Modify: `app/Services/Messaging/ConversationPresenter.php`, `app/Controllers/Messaging/ConversationController.php` (`index` reads `archived=1`, response gains `archived_count`), `app/Config/Routes.php` (inside the existing `messages/api` group)
- Create: `app/Controllers/Messaging/ChatStateController.php` (extends `MessagingController`)
- Test: `tests/database/ConversationPresenterTest.php`, `tests/feature/MessagesChatStateRoutesTest.php`

**Interfaces:**
- Presenter row gains `pinned` (bool), `archived` (bool), `muted` (bool), `marked_unread` (bool), `can_pin_chat` (`type !== 'course'`), `can_delete` (`type` in `direct`, `group`).
- Routes (all `POST`, body empty): `conversations/(:num)/pin`, `/unpin`, `/mute`, `/unmute`, `/archive`, `/unarchive`, `/unread`, `/delete` → `ChatStateController::{pin,unpin,mute,unmute,archive,unarchive,unread,delete}/$1`.
- Responses: `200 {"ok": true}`; `not_found` → shared 404; `not_allowed` → `403 {"error":"not_allowed"}`; `limit` → `409 {"error":"limit","max":5}`.

- [ ] **Step 1: Write failing feature tests** in `MessagesChatStateRoutesTest` (copy the setup and helpers of `MessagesRoutesTest`): each route 404s for a foreign conversation and for a missing id with identical bodies (IDOR); flag off → 404; read-only institution → write refused; missing CSRF refused; course chat `pin` → 403; sixth `pin` → 409 with `max: 5`; success paths change only the caller's row; `GET conversations?archived=1` returns the archived chat and `archived_count`; the default list omits it.
- [ ] **Step 2:** Implement presenter flags, controller and routes.
- [ ] **Step 3:** Commit `feat(messages): chat action routes and list flags`.

### Task 6: Slice check

- [ ] **Step 1:** `php -l` every PHP file you touched; `git status` shows only your files.
- [ ] **Step 2:** `git log --oneline -8` shows the commits above. Do not push. Tell the owner slice 1 is done locally and name any step you could not finish.
