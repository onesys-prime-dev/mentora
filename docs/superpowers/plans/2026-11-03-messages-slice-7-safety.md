# Messages Slice 7 — Safety Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Messages safe to switch on: people can block and report, an institutional admin reviews reports (and can blank a reported message in a course chat or group), sends are rate limited, notifications and email carry no message text and respect quiet hours, the encryption key can be rotated and its health is reported, and every moderation action is audited.

**Architecture:** Small services over the tables slice 1 already created (`user_blocks`, `message_reports`, `message_preferences`): `BlockService`, `ReportService` (snapshot encrypted with the existing `MessageCipher`), `ReportReviewService` (the admin's only view of chat text, and the admin's removal path). Rate limits count rows in the database, so they are exact and testable, and throw a `RateLimitedException` the controllers turn into 429. Notifications come from one idempotent `MessageNotifier::notifyConversation()` called after a send and by a scheduled sweep that releases what quiet hours held; a new `last_notified_message_id` column remembers how far each person has been told.

**Tech Stack:** PHP 8.2, CodeIgniter 4.7, MySQL/MariaDB, PHPUnit, vanilla JS, Vitest.

**Spec:** `docs/superpowers/specs/2026-11-02-messages-design.md` (slice 7 of section 7; sections 3.3 rules 6-8, 5.1 rotation and fail-closed, 5.3 limits/logging, 6.3, 8.2, 8.3, 9 call 5 and 6). Branch `feat/messages-safety`, stacked on `feat/messages-groups-admin`. Earlier plans: `docs/superpowers/plans/2026-11-03-messages-slice-{1..6}-*.md`.

## Global Constraints

- Create `feat/messages-safety` from the tip of `feat/messages-groups-admin`. Commit locally; **do not push or open a PR** until told.
- `git status` before staging; stage only the files named in each task (`package-lock.json` is modified in the tree and is not yours; the slice 2-7 plan files are untracked and not yours).
- PHPUnit and PHPStan are **not run** in this feature until the owner says so; lint PHP with `php -l`, run Vitest and ESLint for JS. Scratch scripts go in the session scratchpad, not `/tmp`.
- The new column and index go into the **existing** Messages migrations (`2026-11-21-000001`/`000002`), not a new file: they are not on `main` or any remote, and `MessagingMigrationsTest` undoes and redoes those files, which would silently drop columns added by a later migration. Anyone with a local database must run `php spark migrate:refresh` for the Messages tables.
- **No message text** in an audit entry, a log line, an exception message, a notification (title or body), an email, or a report list. The only places text is readable are a participant's thread payload and an institutional admin's report snapshot of their own institution.
- Blocks (rule 6): A blocking B stops B starting a thread with A and posting in the direct thread with A; A can still post. Instructors and institutional admins cannot be blocked. Blocking affects direct threads only and never removes anyone from a course chat or group. Blocks are per institution.
- Reports: any active participant may report another person's message in a conversation they are in, at most once per message; the snapshot is the message plus up to 5 before it (visible to the reporter at report time), encrypted when reported, so it survives later removal. Reasons: `harassment`, `spam`, `inappropriate`, `other`; the optional note is at most 500 characters and encrypted.
- The admin of the reported conversation's institution is the only reader. Reviewing sets `status` (`dismissed` or `actioned`), `reviewed_by`, `reviewed_at`. Removing a message from review is allowed only for `course` and `group` conversations (`MessagingPolicy::canModerate` with role `institutional_admin`); a reported direct message can only be dismissed or actioned (nobody moderates a DM, spec 3.3 rule 8). Removal blanks it for everyone and is audited with ids only.
- Rate limits (per user): 30 messages per minute, 10 new threads (direct or group created) per hour, 20 reports per day. Over the limit answers 429 `{"error": "rate_limited", "message": "<friendly sentence>"}` and writes nothing; a retried send with an existing `client_uuid` and starting a thread that already exists are never counted against the limit.
- Notifications: type `message`, title "New message", body "{sender name} sent you a message in {chat name}." (chat name for a direct thread is the sender's name only), link `messages/{id}`; grouped per thread (one notification per person per thread per sweep batch), never for a muted thread, never to the sender. Email only when the person's `email_alerts` is on, at most one per thread per hour, with the same words and link.
- Quiet hours (offered to instructors, chairpersons and deans; `HH:MM` start and end, may span midnight): while the recipient is inside them nothing is created or emailed; the sweep releases it after they end. The message and the Messages unread badge are unaffected. Parent messages respect an instructor's quiet hours like any other.
- `messages:reencrypt` re-encrypts `messages.body_enc`, `message_reports.snapshot_enc` and `note_enc` to the current key in batches of 200, `--dry-run` writes nothing, and a value that cannot be decrypted is counted and its row id reported, never its content.
- Fail closed (spec 5.1): the `messages` flag cannot be enabled while the key is missing or invalid; the health page reports the key.
- Audit actions (ids and actions only): `messages.user_blocked`, `messages.user_unblocked`, `messages.report_created`, `messages.report_reviewed`, `messages.message_removed` (existing), `messages.reencrypted`.

## Review Focus

- A blocked person tries every way back in: starting a thread, posting, starting via the picker, starting through a parent about a child; an instructor or admin tries to be blocked (refused, no row) (Task 2).
- A reporter tries to report a message in a thread they are not in, their own message, a message id from another conversation, the same message twice, and a removed message; a non-admin and an admin of another institution try to open a report (Task 3).
- A report of a message that is later removed still shows its original text to the admin, and a snapshot moved to another row fails to decrypt (Task 3).
- The 31st message in a minute, the 11th new thread in an hour, the 21st report in a day answer 429; the 31st retried (same `client_uuid`) message is not a new send (Task 1).
- A seeded secret phrase never appears in `audit_logs`, `notifications`, the email body, or a report list, across send, block, report, review, remove and re-encrypt (Task 6).
- A message sent at 22:30 to someone whose quiet hours are 22:00-07:00 creates no notification until 07:00, and then exactly one, not one per message (Task 5).

---

### Task 1: Rate limits and the new columns

**Files:**
- Create: `app/Services/Messaging/MessageRateLimiter.php`, `app/Services/Messaging/RateLimitedException.php`
- Modify: `app/Database/Migrations/2026-11-21-000001_CreateConversationTables.php`, `app/Database/Migrations/2026-11-21-000002_CreateMessageTables.php`, `app/Services/Messaging/MessageService.php` (`send`), `app/Services/Messaging/ConversationService.php` (`startDirect`), `app/Services/Messaging/GroupService.php` (`create`), `app/Controllers/Messaging/MessageController.php`, `app/Controllers/Messaging/ConversationController.php`, `app/Controllers/Messaging/GroupController.php`
- Test: `tests/database/MessagingMigrationsTest.php` (new assertions), `tests/database/MessageRateLimiterTest.php`, `tests/feature/MessagesRateLimitTest.php`

**Interfaces:**
- Produces: the two migrations gain `conversation_participants.last_notified_message_id` (`INT UNSIGNED NULL`) and an index `(sender_id, created_at)` on `messages`; its `down()` removes both. `MessageRateLimiter::MESSAGES_PER_MINUTE = 30`, `THREADS_PER_HOUR = 10`, `REPORTS_PER_DAY = 20`; `check(string $kind, int $userId, ?DateTimeImmutable $now = null): void` with `$kind` in `message|thread|report`, throwing `RateLimitedException` (extends `RuntimeException`; `kind(): string`; `message()` is the friendly sentence, e.g. "You're sending messages too fast. Wait a moment and try again.") when the count of that user's rows in the window is already at the limit (`messages.sender_id/created_at`; `conversations.created_by/created_at` for type `direct` and `group`; `message_reports.reporter_id/created_at`). Services call `check` **after** deciding the action will create a row and before inserting; controllers catch `RateLimitedException` and answer 429.

- [ ] **Step 1: Write the failing tests.** Migration test: both exist after `up`, gone after `down`. `MessageRateLimiterTest`: 30 messages inside a minute pass the 31st fails; the 31st is allowed once the oldest is over 60 s old (pass `$now`); other users unaffected; 10 threads/hour (direct and group both counted); 20 reports/day. `MessagesRateLimitTest` (HTTP): 31st send is 429 with the JSON above and writes no row; a retried existing `client_uuid` at the limit returns the stored message, not 429; the 11th new thread is 429; starting an existing thread at the limit is 200; the 429 body carries no message text.
- [ ] **Step 2:** lint-only note in the ledger.
- [ ] **Step 3: Implement** the migration, limiter and exception; call `check('message', …)` in `MessageService::send` after the idempotent-retry and policy checks and before the insert; `check('thread', …)` in `startDirect` only on the branch that inserts a new conversation (look up the `direct_key` first) and in `GroupService::create` before the insert; map the exception to 429 in the three controllers.
- [ ] **Step 4:** `php -l` each file.
- [ ] **Step 5: Commit** `git add app/Database/Migrations/2026-11-22-000001_AddMessageSafetyColumns.php app/Services/Messaging/MessageRateLimiter.php app/Services/Messaging/RateLimitedException.php app/Services/Messaging/MessageService.php app/Services/Messaging/ConversationService.php app/Services/Messaging/GroupService.php app/Controllers/Messaging/MessageController.php app/Controllers/Messaging/ConversationController.php app/Controllers/Messaging/GroupController.php tests/database/MessagingMigrationsTest.php tests/database/MessageRateLimiterTest.php tests/feature/MessagesRateLimitTest.php && git commit -m "feat(messages): rate limit sends, new threads and reports"`.

---

### Task 2: Blocks

**Files:**
- Create: `app/Services/Messaging/BlockService.php`, `app/Controllers/Messaging/BlockController.php`
- Modify: `app/Config/Routes.php` (`messages/api` group)
- Test: `tests/database/BlockServiceTest.php`, `tests/feature/MessagesBlockRoutesTest.php`; add the routes to `MessagesRoutesTest::everyRoute`

**Interfaces:**
- Consumes: `MessagingContextResolver::activeRole`, `::institutionOf`, `::isActiveParticipant`; `MessagingPolicy` UNBLOCKABLE roles (`instructor`, `institutional_admin`).
- Produces: `BlockService::block(User $user, int $blockedId): bool` (false when the target is self, unknown, another institution, or an unblockable role; idempotent; audit `messages.user_blocked` only when a row is created), `unblock(User, int): bool` (idempotent; audit `messages.user_unblocked` only when a row is removed), `blockedBy(User $user): list<array{id: int, name: string}>`. Routes `POST messages/api/blocks` `{user_id}` → `{"ok": true}`/shared 404, `POST messages/api/blocks/(:num)/remove` → `{"ok": true}`, `GET messages/api/blocks` → `{"blocked": [...]}`. `ConversationPresenter` rows for a direct thread gain `other_user_id` (already selected) so the UI can offer Block, and `can_block` (bool: other person is blockable and not already blocked by the viewer).

- [ ] **Step 1: Write the failing tests.** `BlockServiceTest`: block a classmate (row has the blocker's institution; audit row `messages.user_blocked` with target id and no text); blocking twice writes one row and one audit; unblock removes it, once; blocking an instructor, an institutional admin, yourself, an unknown id, someone from another institution returns false and writes nothing; after a block the blocked student cannot `startDirect` with the blocker (null), cannot `send` in the existing direct thread (`not_allowed`), the blocker still can post, and both can still read; the blocked person remains in a shared course chat and can post there; unblocking restores everything; a parent thread: parent blocking a student is impossible (not startable), a student blocking another student leaves an unrelated third thread alone. Feature: the three routes (flag off, signed out, system admin, CSRF are covered by the extended `everyRoute`), a foreign user id answers the shared 404 identical to a nonexistent one.
- [ ] **Step 2:** lint-only note.
- [ ] **Step 3: Implement** `BlockService`, controller, routes, the presenter's `can_block`.
- [ ] **Step 4:** `php -l`.
- [ ] **Step 5: Commit** `git add app/Services/Messaging/BlockService.php app/Controllers/Messaging/BlockController.php app/Config/Routes.php app/Services/Messaging/ConversationPresenter.php tests/database/BlockServiceTest.php tests/feature/MessagesBlockRoutesTest.php tests/feature/MessagesRoutesTest.php && git commit -m "feat(messages): let people block each other in direct threads"`.

---

### Task 3: Reports and their review

**Files:**
- Create: `app/Services/Messaging/ReportService.php`, `app/Services/Messaging/ReportReviewService.php`, `app/Controllers/Messaging/ReportController.php`
- Modify: `app/Services/Messaging/MessageCipher.php` (none expected: reuse `encrypt`/`decrypt`), `app/Config/Routes.php`
- Test: `tests/database/ReportServiceTest.php`, `tests/database/ReportReviewServiceTest.php`, `tests/feature/MessagesReportRoutesTest.php`; add the report-creating route to `MessagesRoutesTest::everyRoute`

**Interfaces:**
- Consumes: `MessageCipher::encrypt(string, int $conversationId, int $senderId)` / `decrypt` (the snapshot is encrypted with `(conversation_id, reporter_id)` as the binding, the note likewise); `ConversationService::find`; `MessageRateLimiter::check('report', …)`; `MessagingPolicy::canModerate`.
- Produces:
  - `ReportService::REASONS = ['harassment','spam','inappropriate','other']`, `NOTE_MAX = 500`, `SNAPSHOT_CONTEXT = 5`.
  - `ReportService::report(User $reporter, int $conversationId, int $messageId, string $reason, ?string $note): bool` — false for a conversation that is not the reporter's, a message not in it, the reporter's own message, a removed message, an unknown reason, a note over 500 characters, or a message already reported by this reporter (one open or reviewed report per reporter and message); otherwise inserts a `message_reports` row (`institution_id` of the conversation, `snapshot_enc` = encrypted JSON `{messages: [{id, sender_id, sender_name, body, created_at}], reported_id}` of the message and up to 5 earlier visible ones, ordered oldest first, each decrypted at report time and skipping undecryptable ones), audits `messages.report_created` (target = report id, new = `{message_id, conversation_id, reason}`) and returns true.
  - `ReportReviewService::list(User $admin, string $status = 'open'): list<array{id: int, reason: string, status: string, created_at: string, conversation_type: string, conversation_name: string, reporter_name: string}>` and `::view(User $admin, int $reportId): ?array{id, reason, note: ?string, status, created_at, conversation_type, snapshot: list<array{…}>, reported_id: int, removed: bool, can_remove: bool}` (null for a non-admin, an admin of another institution, or an unknown id), `::review(User $admin, int $reportId, string $status, bool $removeMessage): bool` (`$status` in `dismissed|actioned`; removing is honoured only when `can_remove`, i.e. a `course`/`group` conversation and `MessagingPolicy::canModerate('institutional_admin', type, false)`; audits `messages.report_reviewed` (target report id, new = `{status, removed}`) and, when it removed, `messages.message_removed` with ids; a report already reviewed returns false). `can_remove` is false once the message is removed.
  - Routes (JSON): `POST messages/api/conversations/(:num)/messages/(:num)/report` `{reason, note}` → `{"ok": true}` or 404 (429 on the limit); admin-only `GET messages/api/reports?status=` , `GET messages/api/reports/(:num)`, `POST messages/api/reports/(:num)/review` `{status, remove_message}`; non-admins get the shared 404 on the admin routes.

- [ ] **Step 1: Write the failing tests** for every refusal in the Review Focus list and Global Constraints: own message, foreign conversation, wrong conversation/message pair, twice, removed message, bad reason, long note; snapshot holds the message and 5 before it (and fewer when there are fewer); the admin of the institution sees decrypted text, a non-admin and an admin of another institution get null; remove-then-view still shows the original text with `removed: true`; a moved `snapshot_enc` (copy another report's value into this row) makes `view` return an empty snapshot rather than text; direct-thread report: `can_remove` false and `review(..., removeMessage: true)` leaves the message intact; course-chat report: review with removal blanks the message for participants (`MessageService::page` shows the tombstone) and writes both audit rows with no text; reviewing twice returns false; `list` filters by status and never contains message text or note; the 21st report in a day is rate limited.
- [ ] **Step 2:** lint-only note.
- [ ] **Step 3: Implement** the services, controller and routes. Snapshot names come from `ConversationPresenter::names`.
- [ ] **Step 4:** `php -l`.
- [ ] **Step 5: Commit** `git add app/Services/Messaging/ReportService.php app/Services/Messaging/ReportReviewService.php app/Controllers/Messaging/ReportController.php app/Config/Routes.php tests/database/ReportServiceTest.php tests/database/ReportReviewServiceTest.php tests/feature/MessagesReportRoutesTest.php tests/feature/MessagesRoutesTest.php && git commit -m "feat(messages): report a message and let the administrator review it"`.

---

### Task 4: The administrator's reports page

**Files:**
- Create: `app/Controllers/Messaging/ReportsPageController.php`, `app/Views/pages/messages/reports.php`
- Modify: `app/Config/Routes.php`
- Test: `tests/feature/MessagesReportsPageTest.php`

**Interfaces:**
- Consumes: `ReportReviewService` (Task 3).
- Produces: `GET messages/reports` (list, tabs Open / Dismissed / Actioned), `GET messages/reports/(:num)` (the snapshot, the reason and note, "Dismiss", "Mark as actioned", and "Remove the message and mark as actioned" only when `can_remove`), `POST messages/reports/(:num)/review` (form post, redirect back with a status message). Server-rendered with `layouts/app`, escaped text, CSRF form fields, behind `['messages', 'readonly']` plus a role check: only `institutional_admin` (anyone else gets 404). Link "Reports" shown to institutional admins on the Messages page header.

- [ ] **Step 1: Write the failing tests** (`RouteTestCase`): admin sees the list with reason and reporter but not any message text; opens a report and sees the snapshot text escaped (`<img onerror>` inert); each of the three buttons works and redirects; the remove button is absent for a direct-thread report; student, instructor, chairperson, dean, parent, system admin, another institution's admin get 404 on all three routes; flag off 404; missing CSRF refused.
- [ ] **Step 2:** lint-only note.
- [ ] **Step 3: Implement** the controller, view and routes; add the "Reports" link in `app/Views/pages/messages/index.php` for the admin via a `canReview` flag passed by `MessagesPageController`.
- [ ] **Step 4:** `php -l` each PHP file.
- [ ] **Step 5: Commit** `git add app/Controllers/Messaging/ReportsPageController.php app/Controllers/Messaging/MessagesPageController.php app/Views/pages/messages/reports.php app/Views/pages/messages/index.php app/Config/Routes.php tests/feature/MessagesReportsPageTest.php && git commit -m "feat(messages): add the administrator's reports page"`.

---

### Task 5: Notifications, email, quiet hours

**Files:**
- Create: `app/Services/Messaging/MessageNotifier.php`, `app/Services/Messaging/MessagePreferences.php`, `app/Controllers/Messaging/PreferenceController.php`, `app/Commands/NotifyMessages.php`
- Modify: `app/Services/Messaging/MessageService.php` (`send`), `app/Services/Monitoring/ScheduledJobs.php`, `deploy/crontab.txt`, `tests/unit/CrontabBlockTest.php`, `tests/database/ScheduledJobsTest.php`, `docs/monitoring.md`, `docs/server-setup.md`, `docs/deploy.md`, `docs/qa/project-inventory.md`, `app/Config/Routes.php`
- Test: `tests/database/MessageNotifierTest.php`, `tests/database/NotifyMessagesCommandTest.php`, `tests/feature/MessagesPreferenceRoutesTest.php`

**Interfaces:**
- Consumes: `NotificationService::send(int $userId, string $type, string $title, string $body, bool $urgent, string $dedupeKey, ?int $institutionId, ?string $link, ?DateTimeImmutable $now): bool`; table `message_preferences` (`user_id`, `quiet_start`, `quiet_end`, `email_alerts`); `conversation_participants.last_notified_message_id` (Task 1); `ConversationPresenter::names`.
- Produces:
  - `MessagePreferences::get(int $userId): array{quiet_start: ?string, quiet_end: ?string, email_alerts: bool}` (defaults: null, null, true), `save(User $user, ?string $quietStart, ?string $quietEnd, bool $emailAlerts): bool` (false for malformed `HH:MM`, for one of the two set without the other, or for quiet hours on a role other than instructor, chairperson, dean; `email_alerts` is allowed for everyone), `isQuiet(int $userId, DateTimeImmutable $now): bool` (handles spanning midnight, equal start/end = never quiet).
  - `MessageNotifier::notifyConversation(int $conversationId, ?DateTimeImmutable $now = null): int` — for each active, unmuted participant who has messages from others after `max(last_notified_message_id, last_read_message_id, first_visible_message_id - 1)` (not removed, not hidden by them, account active) and is **not** in quiet hours: create one notification (dedupe key `msg:{conversationId}:{maxMessageId}`), email it when `email_alerts` and no earlier notification for this thread was emailed in the last hour (look at `notifications.emailed_at` for dedupe keys `msg:{conversationId}:%`), and move `last_notified_message_id` to that max id; returns the number of people notified. Title "New message"; body "{sender name} sent you a message in {chat name}." (a direct thread: "{sender name} sent you a message."); link `messages/{id}`. Never any message text. The email goes through the same body/link and failure to email never loses the in-app notice.
  - `MessageService::send` calls `MessageNotifier::notifyConversation` after a successful new insert (outside its transaction, inside a try/catch that logs the conversation id only); a retried send does not.
  - `php spark messages:notify [--dry-run]` finds conversations with unnotified messages (`messages.id > last_notified_message_id` for some participant) and calls `notifyConversation` for each; heartbeat `messages:notify` (`Monitoring.messagesNotifyAt`, gap 45 minutes) in `ScheduledJobs::JOBS`, crontab `*/15 * * * *`, the docs tables and the exact-list assertions updated like slice 4 did. `--dry-run` counts and writes nothing.
  - Routes `GET messages/api/preferences` → `{"preferences": {...}, "quiet_hours_allowed": bool}`, `POST messages/api/preferences` `{quiet_start, quiet_end, email_alerts}` → same shape or 422 `{"error": "invalid"}`.

- [ ] **Step 1: Write the failing tests.** Notifier: a message to an unmuted participant creates one notification with the exact title/body/link and no message text, none for the sender, none for a muted thread, none for a deactivated account; five messages before a sweep produce one notification; quiet hours 22:00-07:00 at 22:30 create nothing and leave `last_notified_message_id` untouched, the same call at 07:05 creates exactly one; a person who already read up to the last message gets nothing; email is sent when `email_alerts` is on (use `FakeEmail`), not when off, at most once per hour per thread; an email failure keeps the notification; a parent message to an instructor in quiet hours is held; a retry of `notifyConversation` creates nothing more. Preferences: spanning midnight, equal times, malformed times, only one of the two, a student's quiet hours refused but `email_alerts` accepted. Command: sweeps conversations the hook missed, is idempotent, dry-run writes nothing, heartbeat recorded. Feature: the preference routes (extended `everyRoute` for the common refusals); a seeded phrase never reaches `notifications.body`, the emailed text or the 429/errors.
- [ ] **Step 2:** lint-only note.
- [ ] **Step 3: Implement** the services, hook, command, preference routes and all the schedule/docs/test-list updates.
- [ ] **Step 4:** `php -l` each file.
- [ ] **Step 5: Commit** `git add app/Services/Messaging/MessageNotifier.php app/Services/Messaging/MessagePreferences.php app/Controllers/Messaging/PreferenceController.php app/Commands/NotifyMessages.php app/Services/Messaging/MessageService.php app/Services/Monitoring/ScheduledJobs.php deploy/crontab.txt tests/unit/CrontabBlockTest.php tests/database/ScheduledJobsTest.php docs/monitoring.md docs/server-setup.md docs/deploy.md docs/qa/project-inventory.md app/Config/Routes.php tests/database/MessageNotifierTest.php tests/database/NotifyMessagesCommandTest.php tests/feature/MessagesPreferenceRoutesTest.php && git commit -m "feat(messages): notify people of new messages without the text, and hold them in quiet hours"`.

---

### Task 6: The key — re-encryption, health, the flag, and no text anywhere

**Files:**
- Create: `app/Commands/ReencryptMessages.php`
- Modify: `app/Services/Messaging/MessageCipher.php` (`usesCurrentKey`), `app/Services/Monitoring/SystemHealth.php`, `app/Services/Audit/AuditLogger.php` (redact pattern), the place the feature flags are switched on (find it: `app/Services/Flags/FeatureFlags.php` / `app/Controllers/Admin/FlagsController.php`), `docs/monitoring.md`
- Test: `tests/unit/MessageCipherTest.php` (new cases), `tests/database/ReencryptMessagesCommandTest.php`, `tests/database/MessagesKeyHealthTest.php`, `tests/database/MessagesNoTextCanaryTest.php`

**Interfaces:**
- Produces: `MessageCipher::usesCurrentKey(string $stored): bool` (true only when it decrypts with the current key alone, ignoring previous keys; false for garbage); `SystemHealth` gains check `messages` (label "Messages encryption") — UP when the flag is off everywhere and no messages exist, UP when the key is usable and no previous key was dropped, DEGRADED when `droppedPreviousKeys() > 0` or some stored values still use only a previous key... (cheap form: DEGRADED when a previous key is configured), DOWN when the flag is on anywhere (an enabled override or global) and `isUsable()` is false; detail names nothing secret. Enabling the `messages` flag while `! MessageCipher::isUsable()` is refused with a clear message at the point flags are switched on. `AuditLogger::SECRET_PATTERN` also matches `body`, `snapshot`, `note` keys so a stray message field is redacted. `messages:reencrypt [--dry-run]` as in Global Constraints, audit `messages.reencrypted` once per real run with counts, heartbeat none.

- [ ] **Step 1: Write the failing tests.** Cipher: `usesCurrentKey` true for a fresh value, false for a value encrypted with an old key that is configured as previous (still decrypts), false for garbage. Command: re-encrypts rows that use a previous key, leaves current-key rows byte-identical, handles all three columns, dry-run changes nothing and prints counts, an undecryptable row is counted and its id listed without content, a second run changes nothing, batches over 200 rows, exits non-zero when any row failed, refuses to run with no usable key. Health: the four states above; flag guard refuses to enable without a key and allows with one. Canary (`MessagesNoTextCanaryTest`): run send, mark read, hide, lock, remove, block, report, review with removal, notify, re-encrypt, rate-limit rejection with a unique phrase in the bodies; assert the phrase is in none of `audit_logs` (all columns), `notifications`, `message_reports.reason/note_enc` (ciphertext only), the captured email text, `writable/logs` lines written during the test, the 429 and 404 bodies; and that it **is** present in the participant's thread payload and the admin's report view.
- [ ] **Step 2:** lint-only note.
- [ ] **Step 3: Implement** all of the above.
- [ ] **Step 4:** `php -l` each file.
- [ ] **Step 5: Commit** `git add app/Commands/ReencryptMessages.php app/Services/Messaging/MessageCipher.php app/Services/Monitoring/SystemHealth.php app/Services/Audit/AuditLogger.php docs/monitoring.md tests/unit/MessageCipherTest.php tests/database/ReencryptMessagesCommandTest.php tests/database/MessagesKeyHealthTest.php tests/database/MessagesNoTextCanaryTest.php (plus the flag file named above) && git commit -m "feat(messages): rotate the key, report its health and prove no text leaks"`.

---

### Task 7: Block, report, and notification settings in the page

**Files:**
- Modify: `resources/js/pages/messages/api.js`, `resources/js/pages/messages/messages.js`, `app/Views/pages/messages/index.php`
- Create: `resources/js/pages/messages/safety.js`
- Test: `tests/js/pages/messages-safety.test.js`

**Interfaces:**
- Consumes: routes from Tasks 2, 3, 5; row fields `can_block`, `other_user_id`; the message `mine` flag; response `429 {error: "rate_limited", message}`.
- Produces: `api.block(userId)`, `api.unblock(userId)`, `api.report(conversationId, messageId, reason, note)`, `api.preferences()`, `api.savePreferences(prefs)`; in `safety.js`: `REASONS` (value → label), `describeFailure(result)` → the friendly sentence for 0/404/422/429. UI: a "Block {name}" button in a direct thread's header (when `can_block`; toggles to "Unblock" after success); "Report" under another person's message opening a small dialog (reason select, optional note ≤ 500, Send/Cancel); a "Notification settings" dialog from a gear button (email alerts switch for everyone; quiet start/end time inputs only when `quiet_hours_allowed`); a 429 on send shows its message in the composer note and keeps the draft; the existing "Message removed" and read-only states are untouched.

- [ ] **Step 1: Write the failing Vitest tests** (jsdom, api mocked): Block shown only when `can_block`, asks for a second click, calls `api.block`, then shows Unblock and disables nothing else; a refusal shows "That person can't be blocked."; Report opens the dialog, Send is disabled until a reason is chosen, calls `api.report` with the reason and trimmed note, closes on success and shows "Thanks, we'll look into it."; a 429 on report/send shows the friendly message and keeps the dialog/draft; preferences dialog loads values, shows the quiet-hour inputs only when allowed, saves valid values, shows "Check the times." on a 422; no message text is written with `innerHTML`.
- [ ] **Step 2:** `npx vitest run tests/js/pages/messages-safety.test.js`. Expected: FAIL (module not found).
- [ ] **Step 3: Implement** `safety.js`, the API methods, the header/menu/dialog wiring and the markup.
- [ ] **Step 4:** `npx vitest run tests/js/pages`, `npx eslint resources/js/pages/messages tests/js/pages/messages-safety.test.js`. Expected: PASS, no lint errors.
- [ ] **Step 5: Commit** `git add resources/js/pages/messages app/Views/pages/messages/index.php tests/js/pages/messages-safety.test.js && git commit -m "feat(messages): add block, report and notification settings to the page"`.

---

## Self-review

- **Spec coverage (slice 7 row, 3.3 rules 6-8, 5.1, 5.3, 6.3, 8.2/8.3):** block (Task 2, UI Task 7); report with encrypted snapshot (Task 3); admin report review and the admin's removal path, closing the open item from slices 4-6 (Tasks 3-4); rate limits 30/min, 10/hour, 20/day with 429 (Task 1); quiet hours, notification grouped per thread, email at most hourly, no text (Task 5); `messages:reencrypt` and the key health check, fail-closed flag (Task 6); audit entries for every action named in 5.3 (Tasks 2-6); "nothing in logs or emails contains message text" tested (Task 6 canary).
- **Deliberate deferrals:** report retention (spec section 10 open question: kept until reviewed, then indefinitely for now), SSE, Playwright TC-MSG-11/12 (slice 8), docs `docs/messages.md`, `docs/backup.md` key note and `docs/deploy.md` key setup (slice 8; only the job schedule docs are updated here).
- **Needs a decision (defaults):** the report reasons list and the 500-character note limit are mine; a muted thread suppresses notifications but not the unread count; blocking is one-directional (the blocker may still write); a report cannot be filed against your own message.
- **Type consistency:** `RateLimitedException` (Task 1) is caught by the three controllers and `ReportController` (Task 3); `can_block` (Task 2) is read by Task 7; `ReportReviewService` (Task 3) feeds the page (Task 4); `last_notified_message_id` (Task 1) is used by `MessageNotifier` (Task 5); `usesCurrentKey` (Task 6) is the only new cipher method.
