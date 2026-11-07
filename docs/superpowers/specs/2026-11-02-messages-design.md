# Messages — design

Status: **draft for approval** · Date: 2026-11-02 · Replaces the placeholder "Inbox" tile.

Read this whole file before writing code. It is written for a builder who has not seen the conversation behind it.
Items marked **(my call)** are decisions the author made without being asked; they are listed again in
"Calls to check" at the end. Everything else was decided by the product owner.

---

## 1. Intent

Mentora has one-to-many channels (announcements, course discussions, follow-ups, notifications) but no way for two
people to talk privately, and no standing chat for a class. **Messages** adds both:

1. **Course chats** — one group chat per course offering, created and kept in sync automatically.
2. **Direct messages** — private one-to-one threads between people who have a legitimate reason to talk.
3. **Staff groups** — small group chats that chairpersons and deans start for the instructors in their scope.

The feature is named **Messages**, not "Inbox": it should feel like personal, school-related messaging, not email.

### Goals

- Everyone who should be reachable is reachable, and **nobody can message a stranger**.
- A role matrix (section 3) that is enforced in one place and tested exhaustively.
- Message bodies are encrypted at rest; nobody, including administrators, can read someone's DMs by browsing.
- Works on a phone as well as a desktop.
- Feature-flagged, so it can ship dark and be turned on per institution.

### Non-goals (v1)

- End-to-end encryption (see 5.1 for why).
- Attachments, reactions, typing indicators, read receipts shown to the sender, message editing, forwarding.
- WebSockets or any push infrastructure. v1 polls.
- Search inside message bodies (they are ciphertext).
- Auto-translation, voice, video, email-reply-to-message.
- Messaging across institutions. An account belongs to exactly one institution, and messaging never crosses that line.

### Anti-goals (a failure even if it "works")

- A person reaches someone outside the rules by guessing an id.
- Plaintext message text appears in the database, a log, an audit entry, an email, or a backup.
- An administrator, a system administrator or an impersonating session reads a private thread.
- An instructor's day is swamped by notifications.

---

## 2. What exists today (verified in the repo)

- `app/Helpers/navigation_helper.php` (~line 109): an `Inbox` tile for student, instructor and chairperson with a
  `null` URL. Dean, parent, institutional admin and system admin have no such tile. Parent and system-admin tile
  lists are separate branches of the same function.
- Roles: `student`, `instructor`, `chairperson`, `dean`, `parent`, `institutional_admin`, `admin` (system admin).
- Stack: PHP 8.2+, CodeIgniter 4.7, Shield, MySQL/MariaDB, Vite + vanilla JS in `resources/js`, Vitest, Playwright.
- Who belongs to a course: `enrollments` (`offering_id`, `student_user_id`, `status`) and `instructor_assignments`
  (`offering_id`, `instructor_user_id`). Ask `App\Services\Courses\CourseMembership` (`canAccess`, `roleIn`).
- Scope of staff: `ChairpersonAccessService`, `DeanAccessService` (chairperson → department, dean → college;
  programs hang off `departments`). **Reuse these; do not re-derive scope with new SQL.**
- Parents: `parent_student_links` (`institution_id`, `parent_user_id`, `student_user_id`); `ParentAccessService`,
  `ParentChildFilter`, `ParentPortal`.
- Cross-cutting pieces to reuse: `AuditLogger::log($action, $actor, $details)` (with `redact`),
  `FeatureFlags::isEnabled($key, $institutionId)`, `RolePermissions::allows`, `NotificationService`,
  filters (`session`, `csrf`, `permission:`, `AccountStateFilter`, `ReadOnlyFilter`, `ImpersonationFilter`,
  `ClosedOfferingFilter`), `Config\Encryption`.
- Migrations live in `app/Database/Migrations`, named `YYYY-MM-DD-NNNNNN_Name.php`. The newest existing file is
  dated **2026-11-17**; **new files must sort after it** (use `2026-11-18-…` and upward) or they will run out of order.

---

## 3. Who may message whom

"Start" means open a new thread. Once a thread exists, both sides can reply for as long as the thread is open
(section 3.3). "Shared course" means both are active members (enrolled, or assigned as instructor) of the same
course offering that is not closed.

### 3.1 Direct messages — who can start a thread with whom

| From ↓ / To → | Student | Instructor | Chairperson | Dean | Parent | Inst. admin |
|---|---|---|---|---|---|---|
| **Student** | Yes — classmates in a shared course, if the institution switch is on | Yes — instructors of a course they are enrolled in | No | No | No | No |
| **Instructor** | Yes — students of own course | Yes — same college | Yes — own college's chairpersons | Yes — own college's dean | Yes — parents of students in own courses | No |
| **Chairperson** | Yes — students of own department | Yes — instructors of own department | Yes — other chairpersons in the same college | Yes — own college's dean | **Reply only** (a parent must start) | No |
| **Dean** | Yes — students of own college | Yes — instructors of own college | Yes — chairpersons of own college | Yes — other deans in the institution | No | No |
| **Parent** | No | Yes — instructors of the linked child's courses | Yes — the linked child's chairperson | No | No | No |
| **Inst. admin** | Yes | Yes | Yes | Yes | Yes | Yes |
| **System admin** | **No access to Messages at all** | | | | | |

Rows the owner confirmed explicitly: student↔student allowed; institutional admin may message everyone and gets
replies; the parent options "instructor only", "plus chairperson escalation" and "read-only course feed" all apply;
the chairperson/dean cells above are correct.

- Non-admins cannot start a thread *to* an institutional admin **(my call)** — admins reach out; people reply.
  Change this only if the owner asks.
- "Same college" for instructors and "other chairpersons in the same college" resolve through the department
  → college relation, via the existing access services.
- A person who holds two roles messages under their **active role** (`ActiveRoleService`).

### 3.2 Group chats

| Chat | Created by | Members | Who can post |
|---|---|---|---|
| **Course chat** | The system, when a course offering is created | The assigned instructor(s) and every enrolled student; kept in sync as enrollment and assignment change | Everyone in it. The instructor can **lock** it to announcements-only (students read, instructor posts). |
| **Staff group** | A chairperson (instructors of their department) or a dean (instructors and chairpersons of their college) | The creator plus the people they pick, from within their scope | Everyone in it |

- Parents are never members of a course chat. For each linked child, a parent sees a **read-only feed** of the
  instructor's posts in that child's course chats (no student messages, no reply box). To answer, the parent opens a
  direct thread with that instructor, labeled with the child's name (section 4, `subject_student_id`).
- Students who leave a course (drop, withdraw, removed) lose access immediately. A member sees messages from the time
  they joined, not earlier **(my call)**.
- When an offering closes or the institution goes read-only, its chat becomes read-only. Nothing is deleted.

### 3.3 Rules that apply to every route

1. **Same institution, always.** Every table carries `institution_id`, and every query filters on it.
2. **Policy lives in one class**, `App\Services\Messaging\MessagingPolicy`. Controllers and services never decide
   permissions themselves. It answers: `canStart(sender, recipient, context)`, `canPost(user, conversation)`,
   `canView(user, conversation)`, `canModerate(user, conversation)`, `canSeeParentFeed(parent, student, offering)`.
3. **Not found, not forbidden.** If you are not a participant, the thread does not exist for you (404). Never confirm
   that an id is real.
4. The student cannot see a parent↔instructor/chairperson thread, even though it is about them.
5. Parents never message students or other parents. Students never message parents.
6. **Blocks**: if A has blocked B, B cannot start a thread with A and cannot post in an existing direct thread with A.
   Blocking never removes anyone from a course chat; instructors and admins cannot be blocked **(my call)**, because
   a student must always be able to reach their instructor.
7. A deactivated or deleted account cannot send or receive; its history stays (see
   `DeletingAUserKeepsHistoryTest`). Impersonation sessions are denied on every Messages route.
8. **Moderation**: an instructor may remove a message in their own course chat; a chairperson/dean in their staff group;
   the institutional admin in any chat of their institution. Removal blanks the message for everyone, keeps a
   tombstone ("Message removed") and writes an audit entry. Nobody can moderate a direct thread. A reported DM goes to
   the institutional admin (section 6).
9. **Delete** by a participant hides a message for that participant only. It is not a retraction.

---

## 4. Data model

MySQL/MariaDB, InnoDB, utf8mb4. All ids `INT(20) UNSIGNED` like the rest of the schema. Foreign keys with
`CASCADE`/`SET NULL` as in neighbouring migrations. Names are my proposal; keep them unless something in the repo
conflicts.

**`conversations`**
`id`, `institution_id`, `type` (`course` | `direct` | `group`), `offering_id` NULL (course chats),
`subject_student_id` NULL (parent threads: the child the thread is about), `title` NULL (group chats),
`created_by` NULL, `is_locked` BOOL, `direct_key` CHAR(64) NULL, `last_message_id` NULL, `last_message_at` NULL,
`created_at`, `updated_at`.
Unique: `offering_id` where `type='course'` (one chat per offering); `direct_key` (SHA-256 of
`min(user)|max(user)|subject_student_id|institution_id` — this makes "start a thread" idempotent: starting
a thread that exists returns it).

**`conversation_participants`**
`id`, `conversation_id`, `user_id`, `joined_at`, `left_at` NULL, `first_visible_message_id`, `last_read_message_id` NULL,
`is_starred` BOOL, `is_muted` BOOL.
Unique `(conversation_id, user_id)`. Index `(user_id, left_at)`. A person with `left_at` set has no access.

**`messages`**
`id`, `conversation_id`, `sender_id`, `body_enc` MEDIUMTEXT (ciphertext, see 5.1), `client_uuid` CHAR(36),
`removed_at` NULL, `removed_by` NULL, `created_at`.
Unique `(conversation_id, sender_id, client_uuid)` — a retried send does not duplicate. Index `(conversation_id, id)`.
**There is no plaintext column and no plaintext preview column.**

**`message_hidden`** — `message_id`, `user_id` (delete-for-me). Unique pair.

**`user_blocks`** — `institution_id`, `blocker_id`, `blocked_id`, `created_at`. Unique `(blocker_id, blocked_id)`.

**`message_reports`** — `id`, `institution_id`, `message_id`, `reporter_id`, `reason` (enum + short note, note is
encrypted), `snapshot_enc` (the reported message plus up to 5 before it, encrypted at report time so the evidence
survives later removal), `status` (`open` | `dismissed` | `actioned`), `reviewed_by`, `reviewed_at`, `created_at`.

**`message_preferences`** — `user_id`, `quiet_start` TIME NULL, `quiet_end` TIME NULL, `email_alerts` BOOL default true.
Quiet hours are offered to instructors, chairpersons and deans **(my call)**.

**Institution setting:** `student_dm_enabled` (default **on**). Store it with the institution's other academic/general
settings (`institution_academic_defaults` or the general-settings service — follow whichever the neighbouring
switches use). Changing it is an audit entry.

**Feature flag:** `messages`, default **off**. **Permission:** `messages.use`, offered to every role except `admin`
(add to `AuthGroups::APPLICABLE`, keep `ApplicablePermissionsTest` green, document in `docs/roles-and-permissions.md`).

---

## 5. Security and encryption

### 5.1 Encryption of message text

- **At rest, field level.** `messages.body_enc`, `message_reports.snapshot_enc` and the report note are encrypted by
  `App\Services\Messaging\MessageCipher`, a thin wrapper over CodeIgniter's encrypter (authenticated encryption).
- **Own key.** Use a dedicated key (`messages.encryptionKey` in `.env`, ≥ 32 random bytes, hex), not the app's general
  `encryption.key`, so a leak of one does not expose the other. Build the encrypter from a `Config\Encryption`
  instance that uses this key. The key is never committed; `.env` and the deployment secret store carry it.
- **Rotation.** Support `previousKeys` for decrypting old rows. Provide `php spark messages:reencrypt --dry-run` to
  re-encrypt rows to the current key in batches.
- **Binding to the row.** Encrypt a small envelope, `{"c": conversationId, "s": senderId, "b": body}`; on decrypt,
  verify `c` and `s` equal the row's values. This stops someone with database write access from moving a ciphertext
  into another conversation or attributing it to another sender.
- **Fail closed.** If the key is missing or invalid the `messages` flag cannot be enabled, sending is refused, and the
  health endpoint reports it. A row that fails to decrypt renders as "Message unavailable" and logs the **message id
  only**.
- **In transit.** HTTPS only, with secure/HttpOnly/SameSite cookies. Check `docs/deploy.md` and `app/Config/App.php`
  for how HTTPS is enforced today and extend `docs/deploy.md` with the new key.
- **Backups** contain ciphertext only. `docs/backup.md` must say that restoring messages needs the messages key, and
  that the key is stored separately from the backup.
- **Why not end-to-end.** Instructors must be able to moderate a course chat, parents see an instructor feed, and a
  school needs to act on a report. All of that requires the server to be able to read. Encryption at rest with a
  separate key protects against database leaks, stolen backups and curious administrators; it does not hide messages
  from the running application. **(my call — state this plainly to users in the docs.)**
- **Search** is by person and course name only, never by text.

### 5.2 Access control

- Every route sits behind `session`, `csrf`, `permission:messages.use`, the feature flag, `AccountStateFilter` and
  an impersonation denial. Writes also pass `ReadOnlyFilter`.
- Every service method takes the acting user and calls `MessagingPolicy`; there is no "trusted" internal path that
  skips it, except the system jobs that create and sync course chats.
- **Insecure direct object reference is the main risk.** Every thread and message id in a URL or body is resolved
  through "is this user an active participant" before anything else (404 otherwise). Recipient ids are resolved through
  `canStart`, never trusted.
- The system administrator has no Messages route. An impersonation session is refused on every Messages route.
- **No browsing.** There is no admin screen that lists message text. The only admin view of text is a report's
  snapshot, visible to the institutional admin of that institution.

### 5.3 Input, output and abuse

- Bodies are **plain text**, 1–4000 characters, trimmed; control characters stripped. Output is HTML-escaped; links
  are made clickable by escaping first, then linkifying `http(s)` only, with `rel="noopener noreferrer nofollow"`.
- Rate limits (CodeIgniter throttler, keyed by user): 30 messages per minute; 10 *new* threads per hour; 20 reports
  per day. Over the limit returns 429 with a friendly message.
- `client_uuid` makes sends idempotent. The server ignores any client-supplied sender, institution or timestamp.
- **Logging.** Audit entries (via `AuditLogger`) for: block/unblock, report created/reviewed, message removed by a
  moderator, student-DM switch changed, course chat locked/unlocked, key re-encryption run. Entries carry ids and
  actions, **never** message text. Add the message fields to the `redact` list if it is key-based.
- **Notifications and email carry no message text** — only "you have a new message from X in Y" with a link.
  Emails respect `email_alerts` and quiet hours; failure to email never loses the in-app notice (same rule as
  `docs/notifications.md`).
- **Retention.** Messages are kept while the institution exists. Account deletion keeps history, shown under the
  person's retained display name, as the rest of the app does.

---

## 6. Behaviour and experience

### 6.1 Layout

- **Navigation:** replace the `Inbox` tile (`key: inbox`) with `Messages` (`key: messages`, URL `messages`) for every
  role except system admin. Dean, parent and institutional admin gain the tile. Unread count badge on the tile.
- **Desktop:** two panes — conversation list on the left, thread on the right. **Mobile:** list, then tap into the
  thread with a back button. Follow `docs/ui-guide.md` and the screenshots in `docs/ui-guide/` (list, dialog, mobile
  variants) and reuse the existing shared JS components in `resources/js/shared`.
- **List:** filter chips **All / Courses / Direct / Groups** and an **Unread** toggle. Course chats are pinned at the
  top, labelled with the course code. Parent threads show the child's name. Each row: name, last message (decrypted
  on the server), time, unread dot.
- **Thread:** messages oldest→newest, load older on scroll up (cursor by message id, 30 per page), composer at the
  bottom, "Message removed" tombstones, an overflow menu (delete for me, report, block; moderators also remove).
- **New message:** a person picker that lists **only people the policy allows**, searchable by name. Selecting a
  person who already has a thread opens it.
- **Empty states** for no conversations, no results, and a locked chat ("Only the instructor can post here").
- **Parent feed:** inside the parent's child → course view, a read-only "From your child's instructor" list, with a
  "Message the instructor" button.
- Accessibility: keyboard reachable, visible focus, labels on icon buttons, live region for new messages.

### 6.2 Updating without push **(my call)**

Short polling: the open thread asks for messages after the last id it has every 5 seconds while the tab is visible
(Page Visibility API), backing off to 30 seconds when idle or hidden; the list refreshes every 15 seconds; the unread
badge every 30 seconds. Endpoints return 304 or an empty list cheaply. If polling load proves a problem, the
endpoint contract allows swapping to SSE later without changing the UI.

### 6.3 Quiet hours and notifications

- A new message creates an in-app notification (grouped per thread, not per message) unless the thread is muted.
- During the recipient's quiet hours, notifications and emails are **held** and released at the end; the message is
  still in the thread and the badge still counts it. Parent messages respect an instructor's quiet hours.
- Email sends at most one alert per thread per hour.

### 6.4 Course-chat lifecycle

- **Create** when an offering is created (hook where `CourseService` creates offerings). **Join/leave** on enrolment
  changes (`EnrolmentService`) and instructor assignment changes. All of it is idempotent.
- `php spark messages:sync-course-chats [--dry-run]` creates missing chats and repairs membership for every active
  offering. Safe to run repeatedly. It also backfills offerings that exist today and is scheduled like the other sync
  commands.
- Course copy (`copied_from` offerings) does **not** copy messages.

---

## 7. Implementation plan

Work as **stacked branches**, one per slice below, each ending in a green, reviewable state. The flag `messages` is
off until slice 7, so nothing is user-visible earlier. Slices 1–2 are the foundation everything else rests on, so
finish and test them first.

Working agreements (the owner's, and they apply to you):
- Follow `docs/CONTRIBUTING.md` and `docs/testing.md`. Match neighbouring code's naming, comment density and idiom.
- Branch from a freshly synced `main`; stack each slice on the previous one. Commit locally as you go. **Do not push
  or open a pull request until told to.** When told, push and open the PR by hand with the repository's PR template
  (not `scripts/create-pr.sh`).
- `git status` before staging; stage only your own files — the owner's IDE edits may be in the tree.
- Run fast, targeted tests while working (the file or class you changed). The full suite runs in CI.

| # | Branch | Delivers | Done when |
|---|---|---|---|
| 1 | `feat/messages-foundation` | Migrations and models (section 4). `messages` flag, `messages.use` permission (+ docs). `MessageCipher` and the key config. `MessagingPolicy` complete with the full matrix. Navigation: Inbox → Messages for all roles but system admin, with `url` set and the badge hook stubbed. | Policy matrix test and cipher tests green; migrations run up and down; no UI yet. |
| 2 | `feat/messages-direct` | `ConversationService` / `MessageService`: start (idempotent), send (idempotent), list, page, mark read, hide-for-me, unread counts. Routes + JSON controllers. Filters wired. | Feature tests prove access rules and IDOR protection; flag off → 404. |
| 3 | `feat/messages-ui` | Views and `resources/js/pages/messages.js`: list, thread, composer, new-message picker, empty states, polling, mobile layout. | Vitest for the JS; manual pass on desktop and phone width; matches the UI guide. |
| 4 | `feat/messages-course-chats` | Auto-create and sync (hooks + command), lock, moderator removal, tombstones, read-only on closed offerings. | Enrol/drop/assign/close scenarios covered; command idempotent; backfill proven on seeded data. |
| 5 | `feat/messages-parents` | Parent direct threads labelled by child, the escalation to the chairperson, chairperson reply-only, the read-only instructor feed in the parent portal. | Parent matrix and "student cannot see it" tests green. |
| 6 | `feat/messages-groups-admin` | Staff groups for chairpersons and deans; institutional-admin messaging and replies; the `student_dm_enabled` institution switch. | Switch off blocks new student↔student threads but leaves existing ones readable and blocks posting **(my call)**. |
| 7 | `feat/messages-safety` | Block, report (snapshot), admin report review, rate limits, quiet hours, notifications and email, `messages:reencrypt`, health check for the key, audit entries. Flag may now be enabled. | Abuse and rate-limit tests green; nothing in logs or emails contains message text (tested). |
| 8 | `feat/messages-e2e-docs` | Playwright specs, `docs/test-cases/p2-messages.md`, `docs/messages.md`, updates to `docs/roles-and-permissions.md`, `docs/deploy.md`, `docs/backup.md`, `docs/notifications.md`. | E2E green locally; docs read cleanly for a new operator. |

What is left to the builder: exact class and method signatures, view markup, SQL, JS structure and the wording of
user-facing text, provided they follow the repo's conventions and the rules above.

---

## 8. Testing

The suite follows the repository's layers (`docs/testing.md`). Database tests extend
`Tests\Support\DatabaseTestCase` and use the people factories in `tests/_support`. Frontend tests are Vitest under
`tests/js`; browser tests are Playwright under `tests/e2e` with case ids in test names.

### 8.1 Unit (no database)

- **`MessagingPolicy` matrix** — a data provider covering **every ordered pair of the seven roles** plus each context
  (shared course or not, same college or not, linked child or not, switch on/off, blocked or not, locked chat).
  The expected values are the table in 3.1, written out as data so a reviewer can compare them to this document.
  This is the most important test in the feature.
- `MessageCipher`: round trip; ciphertext differs for the same text twice; tampered ciphertext fails; a ciphertext
  moved to another conversation/sender fails the envelope check; a previous key still decrypts; missing key fails
  closed; no plaintext appears in the stored string.
- Body validation: empty, whitespace, over-length, control characters, emoji, right-to-left text.
- `direct_key` is stable and order-independent.
- Linkify/escape helper: `<script>`, `javascript:` links, and look-alike URLs.

### 8.2 Database (services)

- Migration tests in the style of the existing `Create…MigrationTest` files (up, down, constraints).
- Start thread: allowed, refused, idempotent, parent thread keyed by child, two children → two threads with the
  same instructor.
- Send: stored encrypted (read the raw row and assert it is not the plaintext), idempotent on `client_uuid`,
  refused when blocked, locked, left, closed offering, read-only institution, or deactivated account.
- Read state and unread counts, delete-for-me, pagination boundaries, `first_visible_message_id` for late joiners.
- Course chat lifecycle: create on offering creation; join on enrol; leave on drop/withdraw; instructor change;
  closing makes it read-only; `messages:sync-course-chats` creates missing chats, repairs drift, is idempotent and
  `--dry-run` writes nothing.
- Parent feed shows only instructor posts of the linked child's courses and never another family's.
- Moderation and reports: removal tombstones, snapshot survives removal, only the institution's admin can read it,
  every action writes an audit row with **no text**.
- `student_dm_enabled` off/on behaviour; institution isolation (a user in institution A cannot see or start with
  anyone in B, even with valid ids).

### 8.3 Feature (HTTP through the filters)

- Every route: signed out → login; wrong role → refused; flag off → 404; read-only institution → write refused;
  impersonating → refused; missing CSRF → refused.
- **Authorization sweep (IDOR):** user A calls every thread and message endpoint with ids belonging to user B's
  threads, to deleted threads, to other institutions; every response is 404 and identical to a nonexistent id.
- Recipient picker returns only allowed people and no one else, including no searching by email/phone.
- Rate limits return 429 and recover.
- Response bodies, logs and notification/email payloads for a seeded secret string never contain that string
  (except the thread payload to a legitimate participant).
- XSS: a stored `<img onerror>` message renders inert in the thread, list preview and notification.
- System administrator reaches no Messages route.

### 8.4 Frontend (Vitest, jsdom)

Composer (disable when empty/over length, retry with same `client_uuid`), polling schedule and back-off with
visibility changes, optimistic send and failure state, list filters and unread toggle, tombstone rendering,
locked-chat empty state, picker behaviour.

### 8.5 End to end (Playwright) — case ids go in `docs/test-cases/p2-messages.md`

| Id | Journey |
|---|---|
| TC-MSG-01 | Student opens Messages, sees the pinned course chat, posts, instructor sees it |
| TC-MSG-02 | Instructor locks the course chat; student sees the locked state and cannot post |
| TC-MSG-03 | Student starts a DM with a classmate; the switch off removes the option |
| TC-MSG-04 | Student cannot find or reach a chairperson, dean or parent |
| TC-MSG-05 | Enrol a new student → joins the chat; drop them → loses access at once |
| TC-MSG-06 | Parent messages the child's instructor; thread is labelled with the child; student never sees it |
| TC-MSG-07 | Parent escalates to the child's chairperson; chairperson replies; chairperson cannot start with a parent |
| TC-MSG-08 | Parent sees the read-only instructor feed and no student messages |
| TC-MSG-09 | Chairperson creates a staff group of instructors; dean cannot add a stranger |
| TC-MSG-10 | Institutional admin messages anyone and receives a reply |
| TC-MSG-11 | Block a classmate; report a message; admin reviews the snapshot |
| TC-MSG-12 | Quiet hours hold a notification; the badge still counts |
| TC-MSG-13 | Phone-width layout: list → thread → back |
| TC-MSG-14 | System administrator sees no Messages entry; direct URL is refused |

### 8.6 Manual checks before turning the flag on

Run `messages:sync-course-chats --dry-run` then for real on a copy of production; confirm in phpMyAdmin that
`messages.body_enc` is unreadable; grep the logs and a captured email for a test phrase; restore a backup to a
scratch database with and without the key; rotate the key with `messages:reencrypt` and confirm old threads open.

### 8.7 Definition of done (per slice)

New tests for the slice are green locally; PHPStan passes (`phpstan.neon`, no new baseline entries); ESLint passes;
no unrelated files staged; docs for the slice updated. CI runs the whole suite on the pull request.

---

## 9. Calls to check (made by the author, not the owner)

1. Non-admins cannot start a thread to an institutional admin; they reply.
2. Server-side encryption at rest with a dedicated key, **not** end-to-end.
3. Short polling instead of WebSockets.
4. New participants see history only from when they joined.
5. Instructors and admins cannot be blocked; blocking affects direct threads only.
6. Quiet hours offered to instructors, chairpersons and deans; email alerts at most hourly per thread.
7. With the student-DM switch off, existing student↔student threads stay readable but become read-only.
8. No attachments, edits or body search in v1.
9. Dean → dean messaging allowed within the institution; chairperson → chairperson within the same college.
10. The `messages` flag ships off and is enabled per institution after slice 7.

## 10. Still needs the owner's answer

None block the build. Defaults above apply until answered:
- Should institutional admins also be reachable first by others (for example a parent asking the school office)?
- How long should reports and their snapshots be kept? (Default: until an admin dismisses or actions them, then 1 year.)
