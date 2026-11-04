# Messages — chat and message actions (design)

Status: **draft for approval** · Date: 2026-11-04 · Extends `2026-11-02-messages-design.md` (read that first; this file
uses its names, rules and conventions and only adds to them).

Items marked **(my call)** are decisions the author made without being asked; they are listed again in "Calls to
check" at the end. Everything else was asked for or agreed by the product owner.

---

## 1. Intent

Messages today lets people send, read, hide a message for themselves, report and block. It feels thin next to the
chat apps people already use. This adds the everyday actions of a modern messenger, reached the way people expect:
**right-click** (or long-press on a phone, or a "⋯" button) on a chat in the list, and on a message in a thread.

**On a chat in the list:** pin to top, mark as unread, mute, archive, block, delete (for me).
**On a message:** react, reply, copy, forward, pin, bump, remove for me, unsend, report.

Owner's mockup: `.superpowers/brainstorm/1317-1793781110/content/message-menus-v2.html` (a throwaway picture of the
menus, not code to reuse).

### Goals

- Every action above works on desktop (right-click), phone (long-press / "⋯") and keyboard (Menu key or Shift+F10 on
  a row or message; arrow keys, Escape closes, focus returns).
- **No action widens who can see what.** The rules of the original design (section 3 there) still hold: same
  institution, 404 for non-participants, policy in `MessagingPolicy`, no text in logs/audits/emails.
- Other people's reactions, pins and unsends appear in an open thread within one polling interval, not only after a
  reload.

### Non-goals

- Editing a sent message. Voice, video, attachments, GIFs/stickers. Custom emoji. Threads (replies are quotes only).
- Reactions or pins visible to parents in the read-only instructor feed.
- Starting a brand-new conversation by forwarding (forward only goes to chats the person already has).
- Search inside message text (still ciphertext).

### Anti-goals (a failure even if it "works")

- Forwarding or replying leaks a private message to someone who was not in that thread.
- "Unsend" leaves the text readable in the database or an API response.
- A muted chat still pings the person; an archived chat quietly swallows a new message so it is never seen.
- A student can pin, unsend or remove things in a chat they may not moderate.

---

## 2. What exists today (verified in the repo)

- Routes under `messages/api` (`app/Config/Routes.php` ~line 443): conversations (list, start, lock/unlock), messages
  (page, send, read, `hide` = delete for me, `remove` = moderator removal, `report`), blocks, groups, preferences.
- `conversation_participants` already has `is_starred` and `is_muted` (unused) and `last_read_message_id`;
  `messages` has `removed_at`, `removed_by`, `client_uuid`; `message_hidden` is delete-for-me.
- `ConversationService::listFor` pins course chats to the top; `MessageService` has `send`, `page`, `markRead`,
  `hideForMe`, `remove`; `ConversationPresenter` builds the list payload (`can_post`, `can_block`, `can_moderate`).
- Front end: `resources/js/pages/messages/{messages,render,api}.js`; page shell `app/Views/pages/messages/index.php`.
- Migrations are named `YYYY-MM-DD-NNNNNN_Name.php`; the newest Messages ones are `2026-11-21-00000N`. **New files
  must sort after the newest existing file in the repo** (check the migrations folder; use `2026-11-22-…` or later).

---

## 3. Behaviour

### 3.1 Actions on a chat (the list)

| Action | What it does | Where it is offered |
|---|---|---|
| **Pin to top / Unpin** | Pinned chats sort first (newest pin first), above course chats. Up to **5** pinned **(my call)**. | Direct and group chats. Course chats are already pinned and show a pin icon; they cannot be unpinned. |
| **Mark as unread** | Shows the unread dot again until the chat is opened. | Every chat. |
| **Mute / Unmute** | No in-app notification, no email, and the chat does not count in the Messages badge. The chat still shows its own unread dot in the list. | Every chat. |
| **Archive / Unarchive** | Moves the chat out of the main list into an **Archived** view (a row "Archived chats (n)" at the top of the list, with a Back button). A new message from someone else brings it back to the main list unless it is muted **(my call)**. | Every chat, course chats included. |
| **Block / Unblock** | The existing block (original design 3.3 rule 6). Just reachable from the menu. | Direct chats with someone who may be blocked. |
| **Delete chat (for me)** | Removes the chat from the person's list and hides the history up to now, for them only. A new message brings the chat back showing only newer messages. Nothing is deleted for anyone else. | Direct chats and staff groups. **Not** course chats (they cannot leave their class). |

Order of the list: pinned (by pin time) → course chats → everything else by last activity.

### 3.2 Actions on a message

A hover toolbar on a message shows **react · reply · ⋯**; right-click or long-press opens the full menu. The menu
starts with a row of quick reactions.

| Action | Rule |
|---|---|
| **React** | 👍 ❤️ 😂 😮 😢 as the quick row, ➕ opens a small fixed palette of about 24 emoji **(my call)**. One reaction per person per message; choosing another replaces it; choosing the same one removes it. Chips under the message show counts, yours highlighted; pressing a chip lists who reacted. Allowed wherever the person can view the chat and it is not read-only, closed, or blocked — **including a course chat locked to announcements**, because a reaction is not a post **(my call)**. |
| **Reply** | The new message quotes the original (sender name + first 80 characters, decrypted on the server). The quote must be of a message in the same chat that the person can see. If the original is later removed or unsent, the quote shows "Message removed". Tapping a quote scrolls to the original when it is loaded. Requires `canPost`. |
| **Copy** | Browser only; no server call. |
| **Forward** | Pick up to **5** chats the person already has and can post in; a copy is sent to each, authored by the forwarder, labelled "Forwarded", **without the original sender's name** **(my call)**. **Not** available from a parent thread about a child (`subject_student_id` set), and not for removed, unsent or hidden messages. Uses the normal send path, so blocks, locks, read-only and rate limits all apply per target. **A moderator can turn forwarding off for a chat** (see 3.3); while it is off nobody can forward messages *out of* that chat, but they can still forward *into* it. |
| **Pin message** | Shows the message in a bar at the top of that chat for everyone in it ("📌 Pinned · … · 1 of 2"). Up to **3** per chat **(my call)**. Who may pin: direct chats and staff groups — any participant; course chats — only the instructor/moderators. Pinning and unpinning write an audit entry (ids only). |
| **Bump** | Own messages only. Sends a fresh copy of the message at the bottom, marked "Bump" (a reminder), and notifies like any new message. Bumping a bump bumps the original. At most one bump per original message per hour **(my call)**. Requires `canPost`. |
| **Remove for you** | The existing `hide` (delete for me). |
| **Unsend for everyone** | Own messages only, within **15 minutes** of sending **(my call)**. The text is erased server-side (the stored body is replaced by an encrypted empty body) and the message becomes a tombstone "You unsent a message" / "{Name} unsent a message", distinct from a moderator's "Message removed". A message that has been **reported** keeps its report snapshot (original design 4, `snapshot_enc`). Writes an audit entry (ids only). |
| **Report** | Existing, other people's messages only. |
| **Remove (moderators)** | Existing `remove`; shown instead of Report where the person may moderate. |

### 3.3 Turning forwarding off (the owner's decision)

Instructors, deans and chairpersons can switch **forwarding off**, and back on, for these chats (confirmed by the
owner):

- the **instructor** in the course chats they teach;
- a **chairperson** in the course chats of their sub-department and in the staff groups they created;
- a **dean** in the course chats of their department and in the staff groups they created;
- the **institutional administrator** in any chat of their institution **(my call; the owner did not mention
  them)**, as with the other moderation powers (original design 3.3 rule 8).

Scope for chairpersons and deans comes from the existing access services (`ChairpersonAccessService`,
`DeanAccessService`): a course chat is in scope when its offering belongs to their sub-department / department.
Do not write new scope SQL. This is a separate decision from `canModerate` (which does not give chairpersons and
deans power over course chats), so add a `canToggleForwarding` decision to `MessagingPolicy` with its own table of
tests; it must not widen who can lock, remove or pin. While off, "Forward" is hidden for everyone in that chat (including moderators) and the server
refuses it. It does not stop copy-and-paste, and the interface should not claim it does. A short notice in the chat
header ("Forwarding is off") tells members why. Switching it is an audit entry (ids only), like lock/unlock.

### 3.4 Rules that apply to all of it

1. Every action resolves "is this person an active participant, may they do this here" through `MessagingPolicy`
   (add the new decisions there, with the same table-driven tests). Not a participant → 404, identical to a missing id.
2. Personal list actions (pin chat, mute, archive, mark unread, delete chat) change only the caller's own
   `conversation_participants` row.
3. Reactions, pins, unsends, reply quotes and forwards are **never** exposed through the parent read-only feed.
4. Notifications and emails still carry no message text. A reaction notifies the message's author at most once per
   message ("{Name} reacted to your message"), and never in a muted chat. A bump notifies like a new message.
5. Writes follow the existing `readonly` filter and impersonation denial; the feature flag still gates everything.
6. Rate limits: sends, bumps and forwards share the existing 30 messages/minute budget; reactions get their own
   **60 per minute** **(my call)**.

---

## 4. Data model (additions)

Names are a proposal; keep them unless something in the repo conflicts. All ids `INT UNSIGNED` like the rest.

**`conversation_participants`** add: `pinned_at` DATETIME NULL, `archived_at` DATETIME NULL, `marked_unread`
TINYINT default 0, `cleared_before_message_id` INT UNSIGNED default 0. Existing `is_muted` is used as is;
`is_starred` stays unused (pinning needs an order, so it uses `pinned_at`).

**`messages`** add: `reply_to_message_id` NULL (FK to `messages`, SET NULL), `forwarded` TINYINT default 0,
`bumped_from_message_id` NULL (FK to `messages`, SET NULL), `unsent_at` DATETIME NULL, `pinned_at` DATETIME NULL,
`pinned_by` NULL (FK to `users`, SET NULL), `changed_at` DATETIME NULL (set when a reaction, pin or unsend touches
the row, so polling can find it).

**`conversations`** add: `forwarding_disabled` TINYINT default 0.

**`message_reactions`**: `id`, `message_id` (FK, CASCADE), `user_id` (FK, CASCADE), `emoji` VARCHAR(16),
`created_at`. Unique `(message_id, user_id)`. Index `(message_id)`. The allowed emoji set lives in one PHP constant
and is enforced on write (never trust the client).

No plaintext columns are added; reply quotes and forward bodies are always derived from the encrypted body.

---

## 5. API (additions under `messages/api`, all behind the existing group filters)

Exact verbs, paths and payload shapes are the builder's to choose within the repo's style. The set needed:

- Chat state: pin / unpin, archive / unarchive, mute / unmute, mark unread, delete-for-me; and for moderators, forwarding off / on (beside lock / unlock). `GET conversations`
  gains `archived=1` (the Archived view) and returns per chat: `pinned`, `archived`, `muted`, `marked_unread`,
  `can_pin_chat`, `can_delete`, `forwarding_disabled`, `can_toggle_forwarding`.
- Messages: send accepts optional `reply_to`; forward (message id + up to 5 conversation ids); bump; unsend;
  pin / unpin message; react / remove reaction; and a way to fetch **messages changed since a time/cursor** for
  polling (reactions, pins, unsends by others).
- Message payloads gain: `reactions` (`[{emoji, count, mine}]`, plus who on request), `reply` (quote or null),
  `forwarded`, `bumped`, `pinned`, `unsent`, `can_unsend`, `can_pin`, `can_forward`, `can_bump`.
- Conversation payload gains `pinned_messages` (id, short preview) for the bar.

---

## 6. UI

- **One context-menu component** (`role="menu"`, arrow keys, Escape, focus return, closes on scroll/resize),
  opened by `contextmenu`, by long-press (~500 ms) on touch, by a "⋯" button, and by the keyboard. It positions
  itself inside the viewport. The native browser menu is suppressed **only** on rows and messages, never in the
  composer or on text fields.
- Visuals follow the mockup and `docs/ui-guide.md`; reuse the shared JS pieces; font sizes follow the Messages page
  as it is on `refactor/messages-ui` (the owner enlarged them).
- Reaction chips under messages; a pinned bar above the thread with next/previous when there are several; an
  "Archived chats (n)" row; a 📌 on pinned chats; muted chats show a muted icon; "Forwarded", "Bump" and
  "Unsent" labels on messages.
- Phone: long-press opens the same menu as a bottom sheet.
- Accessibility: every menu item has a text label; reactions have `aria-label`s ("React with thumbs up"); a live
  region announces new reactions on the open thread politely.

---

## 7. Implementation plan

Four stacked branches, local commits, **no push or pull request until the owner says so** (CI per PR is slow).
Branch from the current `refactor/messages-ui` (it carries the owner's UI tweaks and is unmerged), or from fresh
`main` if that branch has been merged by then. Follow `docs/CONTRIBUTING.md`, `docs/testing.md`; stage only your own
files (`git status` first); targeted tests only while working, CI runs the full suite; do not run PHPUnit/PHPStan
locally to finish a branch (owner's rule for Messages).

| # | Branch | Delivers | Done when |
|---|---|---|---|
| 1 | `feat/messages-actions-chats` | Migration for the participant columns; services and routes for pin / mute / archive / mark unread / delete-for-me; list ordering; Archived view data; badge ignores muted. | Service and feature tests (access, 404s, personal-only effect, limits) written. |
| 2 | `feat/messages-actions-messages` | Migration for message columns and `message_reactions`; reply, forward, bump, unsend, pin message, reactions; changed-since polling; policy additions with data-provider tests; audit entries; notification rule for reactions. | Policy matrix extended; unsend proven to erase text; forward blocked from parent threads; reaction/pin visible to others via polling. |
| 3 | `feat/messages-actions-ui` | Context menu, hover toolbar, reaction chips and palette, pinned bar, Archived view, quotes, labels, long-press, keyboard. | Vitest for the menu and reducers; manual pass at desktop and phone width. |
| 4 | `feat/messages-actions-e2e-docs` | Playwright cases (below), `docs/test-cases/p2-messages.md` additions, `docs/messages.md` and `docs/roles-and-permissions.md` updates. | E2E green locally; docs read cleanly. |

What is left to the builder: exact class/method names, SQL, route shapes, markup, the changed-since mechanism, and
user-facing wording, provided they follow the repo's conventions and the rules above.

---

## 8. Testing

Layers as in the original design (section 8). In addition:

- **Policy (unit, data provider):** every action × role × context (participant or not, own or other's message,
  moderator or not, locked, closed, read-only, blocked, parent thread, course vs direct vs group).
- **Services (database):** forwarding switch (who may toggle: instructor of that course, chairperson/dean for course chats in their scope and staff groups they created, institutional admin; a chairperson/dean outside the scope, a student and a parent cannot; forward out refused while off, forward in still allowed); pin limit (5 chats, 3 messages); archive returns on a new message unless muted; delete-for-me
  hides history only for the caller and resurfaces with newer messages only; mute excludes from badge and from
  notifications; reply across chats refused; forward to a chat the person cannot post in refused, per target;
  forward from a parent thread refused; bump limit; **unsend: raw `body_enc` no longer decrypts to the old text,
  15-minute limit, others' messages refused, report snapshot survives**; reaction replace/remove, unknown emoji
  refused, one reaction per person; reaction notice once, none when muted; institution isolation on every action.
- **Feature (HTTP):** IDOR sweep over every new endpoint (foreign conversation, foreign message, another
  institution → identical 404); flag off → 404; read-only institution → write refused; impersonation refused; parent
  feed shows none of reactions, pins, quotes.
- **Frontend (Vitest):** menu opens on contextmenu / long-press / keyboard, closes on Escape and outside click,
  items per context, reaction toggle, pinned bar paging, polling merges changed messages.
- **E2E (Playwright), continuing the ids in `docs/test-cases/p2-messages.md`:**

| Id | Journey |
|---|---|
| TC-MSG-15 | Right-click a chat: pin it and it moves above the course chats; unpin returns it |
| TC-MSG-16 | Mute a chat: no notification arrives and the badge ignores it; unmute restores both |
| TC-MSG-17 | Archive a chat, find it under Archived; a new message from the other side brings it back |
| TC-MSG-18 | Delete a chat for me: gone for me, still there for them; a new message shows only newer history |
| TC-MSG-19 | React to a message; the other person sees the chip without reloading; remove it |
| TC-MSG-20 | Reply with a quote; tapping the quote jumps to the original |
| TC-MSG-21 | Pin a message in a DM; the bar shows for both; a student cannot pin in a course chat, the instructor can |
| TC-MSG-22 | Forward a message to another chat; "Forwarded" shows; no forward from a parent thread |
| TC-MSG-23 | Bump your own message; it arrives as a reminder; a second bump within the hour is refused |
| TC-MSG-24 | Unsend within 15 minutes: both sides see the tombstone and the text is gone; others' messages offer no Unsend |
| TC-MSG-25 | Phone width: long-press a message opens the sheet; keyboard opens the same menu |
| TC-MSG-26 | Instructor turns forwarding off in a course chat: Forward disappears for students and the server refuses it; a chairperson and a dean do the same in a course chat of their scope and in their staff group; a student has no switch |

---

## 9. Calls to check (made by the author, not the owner)

1. Pinned chats sort above course chats; max 5 pinned chats; course chats cannot be unpinned.
2. Archived chats return on a new message unless muted; Archived view is a row at the top of the list.
3. Delete chat: direct chats and staff groups only, for the caller only, resurfaces on a new message.
4. Mute also removes the chat from the Messages badge count.
5. Forward: up to 5 existing chats, labelled "Forwarded", original sender's name not shown, never from parent threads.
6. Message pins: max 3 per chat; in course chats only the instructor/moderators may pin.
7. Unsend: own messages, 15-minute limit, text erased, distinct tombstone; reported messages keep their snapshot.
8. Bump: own messages, one per original per hour, notifies like a new message.
9. Reactions: one per person, quick row of 5 plus a ~24-emoji palette, allowed in locked chats, 60/minute, one notice per message.
10. Personal list actions follow the existing read-only-institution rule (blocked when read-only).
11. The migrations and endpoints are named by the builder; names above are proposals.
12. Forwarding switch: instructors (own course chats), chairpersons and deans (course chats in their scope and staff groups they created) per the owner; I added the institutional administrator for any chat in their institution.

## 10. Still needs the owner's answer

None block the build; the defaults above apply until answered:

- Should the institutional administrator also be able to turn forwarding off? (Default: yes, any chat of their institution.)
- Should the reaction list ("who reacted") be visible to everyone in a course chat of 40+ people, or only counts?
