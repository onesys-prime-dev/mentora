# Messages actions, slice 2: message actions and chat controls — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Members can react to, reply to, forward, pin, bump and delete-for-everyone messages; managers can switch each of those off per chat; other people's changes reach an open thread through polling.

**Architecture:** New columns and one table (migration). Pure decisions go in `MessagingPolicy`; per-chat switches in a small `ChatControls` service; the message operations in a new `MessageActions` service that reuses `MessageService` for the actual insert so blocks, locks, read-only and rate limits keep applying. JSON controllers and routes sit in the existing `messages/api` group.

**Tech Stack:** PHP 8.2+, CodeIgniter 4.7, MySQL/MariaDB, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-04-messages-actions-design.md` (sections 3.2, 3.3, 3.4, 4, 5). Slice 1 plan: `2026-11-04-messages-actions-1-chats.md` (must be done first).

## Global Constraints

- Branch `feat/messages-actions-messages`, stacked on `feat/messages-actions-chats`. Local commits only; **no push, no PR** until the owner says so. `git status` before each `git add`; stage only your own files.
- **Do not run PHPUnit or PHPStan locally** (owner's rule for Messages; CI does it). Write each PHP test first anyway; locally run `php -l <file>` only.
- Migrations sort after the newest file: `2026-11-22-000002_AddMessageActionColumns.php` (slice 1 uses `…000001`).
- Switch names (exact, used in column names, JSON and audit): `reactions`, `replies`, `forwarding`, `bump`, `delete_everyone`. Columns on `conversations`: `allow_reactions`, `allow_replies`, `allow_forwarding`, `allow_bump`, `allow_delete_everyone`, each `TINYINT(1) NOT NULL DEFAULT 1`. The lock stays `is_locked`.
- Limits: max **3** pinned messages per chat; forward to at most **5** chats; Delete-for-everyone window **15 minutes** (`MessageActions::DELETE_EVERYONE_MINUTES = 15`); one bump per original message per hour; reactions **60 per minute** per person.
- Reaction palette, exact and in this order (24; the first five are the quick row): 👍 ❤️ 😂 😮 😢 🙏 👏 🎉 🔥 💯 ✅ ❌ 😀 😍 🤔 😅 😭 😡 👀 💪 🙌 👌 🤝 ⭐. Held in `MessageActions::PALETTE` (`QUICK` = first five). Anything else is refused.
- "Delete for me" (`hideForMe`) is **never** a switch: always available. Managers' `remove` is always available.
- Unknown, foreign or other-institution ids answer the shared 404 (`MessagingController::notFound()`), identical to a missing id. Switch-off, limit and similar refusals are 403/409 with `{"error": "<reason>"}` for a person who *is* in the chat.
- No message text in logs, audits or notifications. Audit with `service('auditLogger')->log($action, $user, [...])` using ids and switch names only.
- Parents never receive reactions, pins, quotes or forwards: `ParentFeedService` output is unchanged.

## Review Focus

- Delete-for-everyone: after it runs, the raw `messages.body_enc` no longer decrypts to the original text, and a report made earlier still shows the original in its snapshot.
- Forwarding out of a parent thread (`subject_student_id` set) is refused, even for the instructor.
- Reply with a `reply_to` id from another conversation or one the person cannot see is refused without leaking that it exists.
- A switch turned off refuses the action server-side even when the client still shows the button (stale page).
- A chairperson outside the course's department, and a dean outside its college, cannot change that chat's controls and get the shared 404 (not 403).

---

### Task 0: Branch

- [ ] **Step 1:** `git status` clean; `git switch -c feat/messages-actions-messages` from the tip of `feat/messages-actions-chats`.

### Task 1: Migration

**Files:**
- Create: `app/Database/Migrations/2026-11-22-000002_AddMessageActionColumns.php`
- Modify: `tests/database/MessagingMigrationsTest.php`

**Interfaces — Produces:**
- `messages` gains `reply_to_message_id INT UNSIGNED NULL` (FK `messages.id` SET NULL), `forwarded TINYINT(1) NOT NULL DEFAULT 0`, `bumped_from_message_id INT UNSIGNED NULL` (FK `messages.id` SET NULL), `deleted_everyone_at DATETIME NULL`, `pinned_at DATETIME NULL`, `pinned_by INT UNSIGNED NULL` (FK `users.id` SET NULL), `changed_at DATETIME NULL` (index).
- `conversations` gains the five `allow_*` columns above.
- Table `message_reactions`: `id`, `message_id` (FK CASCADE), `user_id` (FK CASCADE), `emoji VARCHAR(16) NOT NULL`, `created_at DATETIME NOT NULL`; unique `(message_id, user_id)`.
- `down()` reverses all of it (drop FKs before columns, drop the table).

- [ ] **Step 1:** In `MessagingMigrationsTest` add `testTheMessageActionColumnsAndReactionsTableExist` (field and table existence, the five `allow_*` defaults equal 1, the unique pair rejects a second reaction row by the same user on the same message).
- [ ] **Step 2:** Write the migration. `php -l`.
- [ ] **Step 3:** Commit `feat(messages): add the message action columns and the reactions table`.

### Task 2: Policy decisions

**Files:**
- Modify: `app/Services/Messaging/MessagingPolicy.php`
- Test: `tests/unit/MessagingPolicyTest.php`

**Interfaces — Produces:**
- `canManageControls(string $role, string $type, bool $teachesCourse, bool $inScope, bool $createdGroup, bool $sameInstitution): bool` — false unless `$sameInstitution`; `institutional_admin` → true (any type except `direct`); `type='course'`: `instructor && $teachesCourse`, or `chairperson|dean && $inScope`; `type='group'`: `chairperson|dean && $createdGroup`; `direct` → false for everyone.
- `canPinMessage(string $type, bool $isModerator): bool` — `course` → `$isModerator`; `direct`/`group` → true.
- `canReact(PostContext $c): bool` — like `canPost` but ignores `locked` (a reaction is not a post); still false when not a participant, inactive, read-only institution, closed offering, lost parent link, or blocked direct thread.

- [ ] **Step 1: Failing tests** as data providers over every role × type × context: `testWhoMayManageControls` (include: instructor of another course, chairperson with `inScope=false`, dean with `inScope=true` on a course, chairperson who did not create the group, student, parent, other institution), `testWhoMayPinAMessage`, `testReactionsAreAllowedInALockedCourseChatButNotWhenBlockedOrReadOnly`.
- [ ] **Step 2:** Implement. `php -l`.
- [ ] **Step 3:** Commit `feat(messages): policy decisions for chat controls, pinning and reacting`.

### Task 3: `ChatControls` service and route

**Files:**
- Create: `app/Services/Messaging/ChatControls.php`, `app/Controllers/Messaging/ChatControlsController.php`
- Modify: `app/Config/Routes.php`, `app/Services/Messaging/ConversationPresenter.php`
- Test: `tests/database/ChatControlsTest.php`, `tests/feature/MessagesChatControlsRoutesTest.php`

**Interfaces:**
- `ChatControls::SWITCHES = ['reactions','replies','forwarding','bump','delete_everyone']`.
- `ChatControls::allows(array $conversation, string $switch): bool` — reads `allow_{$switch}` from a `conversations` row.
- `ChatControls::forConversation(array $conversation): array<string,bool>` — the five switches plus `lock` (from `is_locked`).
- `ChatControls::canManage(User $user, array $conversation): bool` — resolves teaches/inScope/created/sameInstitution with `MessagingContextResolver::isCourseInstructor`, `ChairpersonAccessService::canAccess($user, $offeringId)`, `DeanAccessService::canAccessOffering($user, $offeringId)`, `created_by`, then calls `MessagingPolicy::canManageControls`. Do not write new scope SQL.
- `ChatControls::set(User $user, int $conversationId, string $switch, bool $on): ?array` — null (→404) if not the person's chat or `canManage` false; unknown switch → `['ok'=>false,'reason'=>'invalid']`; otherwise updates, writes audit `messages.chat_control_changed` with `old`/`new` `[$switch => bool]`, returns `['ok'=>true,'controls'=>forConversation(...)]`. `switch === 'lock'` delegates to `ConversationService::setLocked`'s behaviour for course chats (keep its existing audit) but is allowed for the wider managers via `canManage`.
- Route: `POST conversations/(:num)/controls` body `{"switch": "reactions", "on": false}` → `ChatControlsController::update/$1`. Presenter rows gain `controls` (the six values) and `can_manage_controls`.

- [ ] **Step 1: Failing tests:** `ChatControlsTest` — each manager role may set each switch in its own scope; out-of-scope chairperson/dean, student, parent get null; a `direct` chat cannot be managed by anyone; unknown switch → `invalid`; audit row has the switch name and no text; setting the same value writes no audit row. Feature test — 404 for foreign chat and for out-of-scope chairperson (identical body), 200 for an instructor, read-only institution refused, flag off 404.
- [ ] **Step 2:** Implement service, controller, route, presenter fields. `php -l`.
- [ ] **Step 3:** Commit `feat(messages): chat controls per chat for managers`.

### Task 4: Reactions

**Files:**
- Create: `app/Services/Messaging/MessageActions.php`, `app/Controllers/Messaging/MessageActionController.php`
- Modify: `app/Services/Messaging/MessageRateLimiter.php` (new kind `'reaction'`, 60 per 60 s, counted on `message_reactions.created_at`), `app/Services/Messaging/MessageNotifier.php`, `app/Config/Routes.php`
- Test: `tests/database/MessageActionsReactionsTest.php`, `tests/feature/MessagesActionRoutesTest.php`

**Interfaces:**
- `MessageActions::__construct(?MessageService $messages = null, ?ConversationService $conversations = null, ?MessagingContextResolver $resolver = null, ?MessagingPolicy $policy = null, ?MessageCipher $cipher = null)`.
- `MessageActions::PALETTE`, `MessageActions::QUICK` (see Global Constraints).
- `react(User $user, int $conversationId, int $messageId, ?string $emoji): array{ok: bool, reason?: 'not_found'|'not_allowed'|'invalid'|'switched_off', reactions?: list<array{emoji:string,count:int,mine:bool}>}` — `$emoji === null` removes the caller's reaction; the same emoji again also removes it; a different emoji replaces it (delete + insert, so `created_at` moves). Requires `MessagingPolicy::canReact` and `ChatControls::allows(..., 'reactions')`. Sets the message's `changed_at`.
- `reactors(User $user, int $conversationId, int $messageId): ?array<string, list<string>>` — emoji → display names; null if not the person's chat.
- `MessageNotifier::notifyReaction(int $messageId, int $reactorId): void` — one notification to the message's author, dedupe key `react:{messageId}`, text "{Name} reacted to your message." with the link the other message notices use; nothing when the author is the reactor, is muted in that chat, or already has an unread `react:{messageId}` notice.
- Routes (POST): `conversations/(:num)/messages/(:num)/react` body `{"emoji": "👍"|null}`; GET `conversations/(:num)/messages/(:num)/reactors`. → `MessageActionController::react/$1/$2`, `::reactors/$1/$2`. `MessageRateLimiter::check('reaction', $userId)` before writing; 429 via `rateLimited()`.

- [ ] **Step 1: Failing tests:** palette enforced (`'🦄'` → `invalid`); replace, remove-by-null, remove-by-same-emoji; counts and `mine`; refused when `reactions` switch off (`switched_off`), blocked, closed offering; allowed in a locked course chat; foreign message → `not_found`; the 61st reaction in a minute is 429; author notified once per message and not when muted or self-reacting; `changed_at` set.
- [ ] **Step 2:** Implement. `php -l`.
- [ ] **Step 3:** Commit `feat(messages): reactions`.

### Task 5: Replies

**Files:**
- Modify: `app/Services/Messaging/MessageService.php` (`send`, `present`), `app/Controllers/Messaging/MessageController.php` (`send` reads `reply_to`)
- Test: `tests/database/MessageServiceTest.php`

**Interfaces:**
- `MessageService::send(User $user, int $conversationId, string $body, string $clientUuid, ?int $replyTo = null): array` — new optional last parameter; extra failure reason `'switched_off'` (replies off) and `'invalid_reply'` (original not in this conversation or not visible to the sender: `id < first_visible_message_id` or hidden by them). Stores `reply_to_message_id`.
- Message payload gains `reply: ?array{id:int, sender_name:string, text:string}` where `text` is the original decrypted on the server and cut to 80 characters with `…`; `null` when no reply; when the original is removed or deleted-for-everyone, `text` is `"Message removed"`. Also `forwarded` (bool), `bumped` (bool) — set by later tasks, present and false now.
- To keep one insert path, give `send` an internal helper `post(User $user, array $conversation, string $clean, string $clientUuid, array $extra = []): array` (inserts, advances read, un-archives others, notifies) that Tasks 7 and 8 reuse.

- [ ] **Step 1: Failing tests:** reply stores the id and the payload quote is truncated at 80; reply to a message of another conversation → `invalid_reply`; to one before the person joined → `invalid_reply`; replies switched off → `switched_off`; quote of a removed message reads "Message removed"; sending without `reply_to` is unchanged.
- [ ] **Step 2:** Implement and extract `post`. Existing `MessageServiceTest` must stay green. `php -l`.
- [ ] **Step 3:** Commit `feat(messages): replies with a quoted original`.

### Task 6: Delete for everyone

**Files:**
- Modify: `app/Services/Messaging/MessageActions.php`, `app/Services/Messaging/MessageService.php` (`present`), `app/Controllers/Messaging/MessageActionController.php`, `app/Config/Routes.php`
- Test: `tests/database/MessageActionsDeleteEveryoneTest.php`

**Interfaces:**
- `MessageActions::DELETE_EVERYONE_MINUTES = 15`.
- `deleteForEveryone(User $user, int $conversationId, int $messageId): array{ok: bool, reason?: 'not_found'|'not_allowed'|'switched_off'|'too_late'}` — own messages only (else `not_allowed`), within 15 minutes of `created_at` (else `too_late`), switch `delete_everyone` on. Overwrites `body_enc` with `MessageCipher::encrypt('', $conversationId, $senderId)`, sets `deleted_everyone_at` and `changed_at`, audit `messages.message_deleted_everyone` (target message id, conversation id). Idempotent.
- Payload: `deleted_everyone` (bool); `body` is `null` for such messages; the front end renders "You deleted a message" / "{Name} deleted a message". `can_delete_everyone` per viewer (own, within window, switch on, not already deleted).
- Route: `POST conversations/(:num)/messages/(:num)/delete-everyone` → `MessageActionController::deleteEveryone/$1/$2`.

- [ ] **Step 1: Failing tests:** after the call, decrypting the raw `body_enc` with `MessageCipher::decrypt` yields `''`, not the original; a report made **before** keeps `snapshot_enc` readable with the original text; other person's message → `not_allowed`; at minute 16 → `too_late`; switch off → `switched_off`; twice is harmless with one audit row; the audit entry contains no text.
- [ ] **Step 2:** Implement. `php -l`.
- [ ] **Step 3:** Commit `feat(messages): delete for everyone erases the text`.

### Task 7: Bump

**Files:** modify `MessageActions.php`, `MessageActionController.php`, `Routes.php`; test `tests/database/MessageActionsBumpTest.php`.

**Interfaces:** `bump(User $user, int $conversationId, int $messageId): array{ok: bool, reason?: 'not_found'|'not_allowed'|'switched_off'|'limit'|'unavailable', message?: array}` — own, not deleted-for-everyone message; the original of a chain (a bump of a bump bumps the original, `bumped_from_message_id` = original); decrypts and re-posts the body through `MessageService::post` with a server-made `client_uuid` (`bin2hex(random_bytes(16))` fits the `^[A-Za-z0-9-]{8,36}$` rule) and `extra = ['bumped_from_message_id' => $originalId]`; requires `canPost` (so locks/blocks/read-only apply); `limit` when the original was already bumped in the last hour; consumes the `message` rate-limit budget. Payload `bumped: true`. Route `POST conversations/(:num)/messages/(:num)/bump`.

- [ ] **Step 1: Failing tests:** new message at the bottom with the same text and `bumped: true`; other person's message → `not_allowed`; second bump within an hour → `limit`, after an hour allowed; bump of a bump points at the original; blocked or locked → refused; switch off → `switched_off`; the other participant is notified like for a new message.
- [ ] **Step 2:** Implement. `php -l`. **Step 3:** Commit `feat(messages): bump`.

### Task 8: Forward

**Files:** modify `MessageActions.php`, `MessageActionController.php`, `Routes.php`; test `tests/database/MessageActionsForwardTest.php`.

**Interfaces:** `forward(User $user, int $conversationId, int $messageId, array $targetIds): array{ok: bool, reason?: 'not_found'|'not_allowed'|'switched_off'|'parent_thread'|'too_many'|'unavailable', results?: list<array{conversation_id:int, ok:bool, reason?:string}>}` — source chat must be the person's and the message visible and not removed/deleted-for-everyone/hidden; `switched_off` when the **source** chat has `forwarding` off; `parent_thread` when the source has `subject_student_id`; `too_many` over 5 targets; each target must be one of the person's own chats (via `ConversationService::find`), distinct from duplicates, and goes through `MessageService::post` with `extra = ['forwarded' => 1]` (so `canPost`, blocks, locks, read-only and rate limits apply per target; a refusal for one target is reported in `results` and does not stop the others). The forwarded copy carries no original sender name. Payload `forwarded: true`. Route `POST conversations/(:num)/messages/(:num)/forward` body `{"targets": [12, 15]}`.

- [ ] **Step 1: Failing tests:** copies arrive in each target with `forwarded: true` and the text intact; forward *into* a chat whose own `forwarding` switch is off still works; from a chat with it off → `switched_off`; from a parent thread → `parent_thread` (even for the instructor); six targets → `too_many`; a target the person is not in is reported `not_found` in `results` and nothing is created there; a locked target refuses only that target; removed message → `not_found`.
- [ ] **Step 2:** Implement. `php -l`. **Step 3:** Commit `feat(messages): forward to up to five chats`.

### Task 9: Pin message

**Files:** modify `MessageActions.php`, `MessageService.php` (`page` adds `pinned`), `MessageActionController.php`, `Routes.php`; test `tests/database/MessageActionsPinTest.php`.

**Interfaces:** `pinMessage(User $user, int $conversationId, int $messageId, bool $pinned): array{ok: bool, reason?: 'not_found'|'not_allowed'|'limit'}` — `MessagingPolicy::canPinMessage($type, ConversationService::canModerate(...))`; max 3 pinned per chat (`limit`); sets/clears `pinned_at`, `pinned_by`, `changed_at`; audit `messages.message_pinned` / `messages.message_unpinned`. `MessageService::page()` result gains `pinned: list<array{id:int, sender_name:string, text:string}>` (newest pin first, text cut to 80, removed/deleted messages skipped). Routes `POST conversations/(:num)/messages/(:num)/pin` and `/unpin`.

- [ ] **Step 1: Failing tests:** a student cannot pin in a course chat (`not_allowed`), the instructor can; either side can pin in a direct chat; the 4th pin → `limit`; unpin; `page()['pinned']` lists them with no raw ciphertext; audit has ids only.
- [ ] **Step 2:** Implement. `php -l`. **Step 3:** Commit `feat(messages): pin messages`.

### Task 10: Changes feed, viewer flags, isolation

**Files:** modify `MessageService.php` (`present`, new `changedSince`), `MessageController.php`, `Routes.php`; test `tests/database/MessageChangesTest.php`, extend `tests/feature/MessagesActionRoutesTest.php`, `tests/database/ParentMessagingTest.php`.

**Interfaces:**
- `MessageService::changedSince(User $user, int $conversationId, string $since): ?array{messages: list<array>, server_time: string}` — messages whose `changed_at > $since` (and `id >= first visible`, not hidden by the viewer); `server_time` is the DB's `NOW()` formatted `Y-m-d H:i:s` for the client to send back next poll. Route `GET conversations/(:num)/changes?since=…` → `MessageController::changes/$1`. Invalid `since` → treated as "now" (empty result).
- Each message payload gains: `reactions: list<{emoji,count,mine}>` (one batched query for the page, not per message), `pinned: bool`, `can_react`, `can_reply`, `can_forward`, `can_pin`, `can_bump`, `can_delete_everyone` (all bool, computed for the viewer from policy + controls), and the page result `controls` is not repeated (it is on the conversation row).
- `MessageActions::react/pin/delete` set `changed_at` so they appear here.

- [ ] **Step 1: Failing tests:** a reaction, a pin and a delete-for-everyone by someone else all appear in `changedSince` for the other participant and not before `since`; `reactions` for a page of 30 messages costs a constant number of queries (assert with the query log: at most +1 versus no reactions); `can_*` flags match the switches; **IDOR sweep** in the feature test — every new endpoint called with a foreign conversation id, a foreign message id in the person's conversation, a missing id, and another institution's id returns the identical shared 404 body; flag off → 404; missing CSRF refused; read-only institution refuses writes; system admin and an impersonation session refused. `ParentMessagingTest` — the parent feed payload contains no `reactions`, `pinned`, `reply`, `forwarded` or `bumped` keys.
- [ ] **Step 2:** Implement. `php -l`. **Step 3:** Commit `feat(messages): changes feed and per-viewer action flags`.

### Task 11: Slice check

- [ ] **Step 1:** `php -l` every PHP file touched; `git status` shows only your files; `git log --oneline -14` shows the commits above. Do not push. Report to the owner what is done and anything not finished.
