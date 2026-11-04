# Messages actions, slice 3: menus, reactions and Settings in the page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Right-click (or long-press, "⋯", keyboard) menus on chats and messages; reaction chips; pinned bar; reply quotes; the Archived view; and a Settings dialog (replacing Alerts) with "My alerts" and, for managers, "Chat controls".

**Architecture:** Small focused modules in `resources/js/pages/messages/`: pure item builders (`menu-items.js`), one DOM menu (`menu.js`), one gesture binder (`gestures.js`), reaction DOM (`reactions.js`), and the Settings dialog logic (`settings.js`). `messages.js` only wires them in. Everything the menus show is decided by the server flags from slices 1 and 2 (`can_*`, `controls`); the client never decides permission.

**Tech Stack:** Vanilla JS (Vite, Tailwind), Vitest + jsdom (`tests/js/pages/`), PHP view `app/Views/pages/messages/index.php`.

**Spec:** `docs/superpowers/specs/2026-11-04-messages-actions-design.md` (sections 3.1–3.4, 6). Mockups (throwaway pictures, not code): `.superpowers/brainstorm/1317-1793781110/content/message-menus-v2.html`, `settings-dialog-v3.html`. Plans 1 and 2 must be done first.

## Global Constraints

- Branch `feat/messages-actions-ui`, stacked on `feat/messages-actions-messages`. Local commits only; **no push, no PR** until told. `git status` before each `git add`; stage only your files.
- Run only fast, targeted checks: `npx vitest run tests/js/pages/<file>` and `npx eslint <files>`; rebuild with `npm run build` (PowerShell if Bash cannot run npm) before looking at the page. Do not run the PHP suite locally.
- Follow `docs/ui-guide.md`; reuse `resources/js/shared/dialog.js` for the dialog (the page already opens dialogs with `data-dialog-open` / `[data-dialog-close]`). Tailwind classes as the page already uses them; font sizes stay as on `refactor/messages-ui` (13px small, 15px body, 12px tiny).
- Keep these existing hooks unchanged because e2e tests use them: `data-role="prefs-open"`, `#messages-prefs`, `prefs-email`, `prefs-quiet`, `prefs-start`, `prefs-end`, `prefs-save`, `prefs-note`, `[data-role=rows] button[data-id]`, `data-role="messages"`, `data-role="unread-toggle"`.
- Menu hooks (for the e2e slice): menu `role="menu"` with `data-role="menu"`; items `role="menuitem"` with `data-action="<id>"`; reaction buttons `data-emoji="👍"` with `aria-label="React with <name>"`; pinned bar `data-role="pinbar"`; archived row `data-role="archived-open"`; Settings tabs `data-role="settings-tab-alerts"` / `settings-tab-controls`; each control `data-control="<switch>"` (`lock`, `reactions`, `replies`, `forwarding`, `bump`, `delete_everyone`) as `role="switch"` with `aria-checked`.
- The native browser menu is suppressed **only** on conversation rows and message bubbles, never in the composer, search box or any input.
- Every menu item has a text label; the menu closes on Escape, outside click, scroll, resize; focus returns to the trigger. Arrow keys move, Enter/Space selects, Home/End jump.
- Item labels (exact): chat — "Pin to top"/"Unpin", "Mark as unread", "Mute notifications"/"Unmute", "Archive chat"/"Unarchive", "Block", "Delete chat (for me)"; message — "Reply", "Copy", "Forward", "Pin message"/"Unpin message", "Bump (resend as a reminder)", "Delete for me", "Delete for everyone", "Report", "Remove" (moderators). Tombstones: "You deleted a message", "{Name} deleted a message".

## Review Focus

- A stale menu: the server refuses an action the client still showed (`switched_off`, 403) — show a short note and refresh the flags, never a blank failure.
- Long-press on a phone must not also trigger a click-open of the chat or select text; scrolling must cancel it.
- Right-click in the composer or search box keeps the browser's own menu.
- A reaction by another person appears in an open thread without reload (changes feed), and a chip the person pressed twice quickly does not double-count.
- A student sees Settings with only "My alerts" (no tabs); a manager with no chat open sees "Open a chat to change its controls".

---

### Task 0: Branch and API client

**Files:** modify `resources/js/pages/messages/api.js`; test `tests/js/pages/messages-api.test.js`.

**Interfaces — Produces** (each returns `{ status, data }` like the existing methods): `conversations({ filter, unread, archived })` (adds `archived=1`), `chatAction(id, action)` for `pin|unpin|mute|unmute|archive|unarchive|unread|delete`, `react(id, messageId, emoji)`, `reactors(id, messageId)`, `forward(id, messageId, targets)`, `bump(id, messageId)`, `deleteEveryone(id, messageId)`, `pinMessage(id, messageId, pinned)`, `changes(id, since)`, `setControl(id, name, on)`; `send(id, body, uuid, replyTo)` gains an optional fourth argument.

- [ ] **Step 1:** `git switch -c feat/messages-actions-ui`. Write `messages-api.test.js` with a fake fetch asserting the method, path and JSON body of each call (paths from plan 2/1: `conversations/{id}/pin`, `…/messages/{mid}/react`, `…/messages/{mid}/delete-everyone`, `…/changes?since=`, `…/controls` with `{switch, on}`).
- [ ] **Step 2:** Implement. `npx vitest run tests/js/pages/messages-api.test.js` passes. Commit `feat(messages): api client for the new actions`.

### Task 1: Menu item builders (pure)

**Files:** create `resources/js/pages/messages/menu-items.js`; test `tests/js/pages/messages-menu-items.test.js`.

**Interfaces — Produces:**
- `chatMenuItems(conversation, handlers): Item[]` and `messageMenuItems(message, conversation, handlers): Item[]` where `Item = { id, label, danger?, separator?, onSelect } | { id: 'reactions', type: 'reactions', emojis, selected, onPick }`.
- Chat rules (from the conversation row flags): pin/unpin only when `can_pin_chat`; "Block" only when `can_block`; "Delete chat (for me)" only when `can_delete`; "Archive/Unarchive" and "Mute/Unmute" always; "Mark as unread" always.
- Message rules: reactions row only when `message.can_react` (emojis = `QUICK`, `selected` = the emoji with `mine`); Reply when `can_reply`; Copy always except tombstones; Forward when `can_forward`; Pin/Unpin when `can_pin`; Bump when `can_bump`; "Delete for me" always (except tombstones); "Delete for everyone" when `can_delete_everyone`; "Report" for other people's messages; "Remove" when `conversation.can_moderate` and the message is not a tombstone.
- A tombstone (`removed` or `deleted_everyone`) offers only "Delete for me".
- Exports `QUICK` (👍 ❤️ 😂 😮 😢) and `PALETTE` (the 24 in the spec order: 👍 ❤️ 😂 😮 😢 🙏 👏 🎉 🔥 💯 ✅ ❌ 😀 😍 🤔 😅 😭 😡 👀 💪 🙌 👌 🤝 ⭐).

- [ ] **Step 1: Failing tests** — a table of cases for both builders (course chat vs direct vs group; own vs other's message; each `can_*` false hides its item; tombstone; separators sit between the reaction row, the actions, and the destructive items).
- [ ] **Step 2:** Implement; vitest passes. Commit `feat(messages): menu item builders`.

### Task 2: The menu and the gestures

**Files:** create `resources/js/pages/messages/menu.js`, `resources/js/pages/messages/gestures.js`; test `tests/js/pages/messages-menu.test.js`.

**Interfaces — Produces:**
- `createMenu(doc = document): { open(items, { x, y, trigger }): void, close(): void, isOpen(): boolean }` — builds `<div role="menu" data-role="menu">` appended to `body`, positions inside the viewport (flip when it would overflow), renders the reaction row as buttons with the `data-emoji` hooks plus a "➕" that expands to `PALETTE`, handles keyboard (arrows, Home/End, Enter/Space, Escape, Tab closes), closes on outside pointerdown, scroll, resize, and returns focus to `trigger`.
- `bindMenuTriggers(container, { selector, onOpen, longPressMs = 500 }): () => void` — on `contextmenu` (prevent default only when the event target is inside `selector` and not inside input/textarea/[contenteditable]) calls `onOpen(el, { x, y, trigger })`; on `touchstart` starts a timer that opens after `longPressMs` unless `touchmove`/`touchend`/`touchcancel` first (and then swallows the following click); on `keydown` `ContextMenu` or `Shift+F10` on a focused match opens at the element's bounds; returns an unbind function.

- [ ] **Step 1: Failing tests (jsdom):** opens on contextmenu with default prevented; does **not** prevent default inside a textarea; long-press fires at 500 ms (fake timers) and is cancelled by touchmove; Shift+F10 opens; Escape closes and returns focus; arrow keys move focus and Enter calls `onSelect`; outside click closes; the reaction row buttons call `onPick` with the emoji; the menu stays inside a small viewport.
- [ ] **Step 2:** Implement; vitest passes; `npx eslint` clean. Commit `feat(messages): context menu and gestures`.

### Task 3: Reaction chips and reply quotes in the thread

**Files:** create `resources/js/pages/messages/reactions.js`; modify `resources/js/pages/messages/render.js` (`messageNode`); test `tests/js/pages/messages-reactions.test.js`.

**Interfaces — Produces:**
- `reactionChips(message, { onToggle, onShowWho }): HTMLElement | null` — `null` when no reactions; one `<button>` per emoji with count, `aria-pressed` for `mine`, `aria-label` like "2 reactions, 👍. You reacted", classes highlighting `mine`.
- `messageNode(message, { mine })` additionally renders: a quote block (`message.reply`) above the body with `data-reply-to`; the labels "Forwarded", "Bump" (small, muted); the tombstones; reaction chips under the bubble when the viewer's conversation allows them (chips are hidden, not deleted, when the `reactions` control is off: the server omits `reactions` then, so render whatever arrives).
- Bubble gets `tabindex="0"` and `data-id` (already) so the keyboard can reach it.

- [ ] **Step 1: Failing tests:** chips show counts and highlight `mine`; none for an empty list; quote text escaped (`<img onerror>` stays inert); tombstone text for `deleted_everyone`; "Forwarded" and "Bump" labels.
- [ ] **Step 2:** Implement; vitest + eslint pass. Commit `feat(messages): reaction chips, quotes and labels`.

### Task 4: Wire the chat menu, Archived view and pinned chats

**Files:** modify `resources/js/pages/messages/render.js` (`conversationRow`: 📌 on pinned, muted icon, archived styling), `resources/js/pages/messages/messages.js`, `app/Views/pages/messages/index.php` (Archived row above the list rows, `data-role="archived-open"`, with a Back control while viewing it).

**Behaviour:**
- Rows open the chat menu via `bindMenuTriggers(rowsEl, { selector: 'button[data-id]' })`; each row also gets a "⋯" button (visible on hover/focus, always on touch) with `aria-label="Chat actions"`.
- Selecting an item calls `api.chatAction`; on success reload the list; `limit` shows "You can pin up to 5 chats."; "Block" reuses the existing block flow (`api.block`/confirm already in `messages.js`).
- "Archived chats (n)" row (n from `archived_count`) switches `state.archived = true` and calls `api.conversations({ archived: true })`; a Back row returns.
- Muted rows show a muted icon; the Messages tile badge is already server-side.

- [ ] **Step 1:** Add a Vitest for the pure part: `conversationRow` shows the pin icon, muted icon and archived count labels (extend `tests/js/pages/messages-render.test.js` or create it).
- [ ] **Step 2:** Implement the wiring. `npx eslint`; vitest passes.
- [ ] **Step 3:** `npm run build`; open `/messages` as a student and check: right-click a direct chat shows the menu; pin moves it up; archive moves it to Archived and back. Commit `feat(messages): chat menu, pinned and archived chats`.

### Task 5: Wire the message menu, hover toolbar, pinned bar and changes polling

**Files:** modify `resources/js/pages/messages/messages.js`, `render.js`, `app/Views/pages/messages/index.php` (pinned bar `data-role="pinbar"` above `data-role="messages"`, reply-preview strip above the composer with a cancel button).

**Behaviour:**
- `bindMenuTriggers(messagesEl, { selector: '[data-id]' })` opens `messageMenuItems`; a hover toolbar (react · reply · ⋯) shows on bubbles on desktop; the ⋯ button opens the same menu (and is always visible on touch).
- Reply sets `state.replyTo` (shows the strip with the quoted sender and 80 chars, cancel clears it) and the next `submit` passes it to `api.send`.
- Copy: `navigator.clipboard.writeText(message.body)` with a fallback to a hidden textarea; toast "Copied".
- Forward: a small dialog listing the person's other chats (from `state.conversations`, excluding archived/closed), up to 5 checkboxes, "Forward"; show per-target failures from `results`.
- React: optimistic chip update, rollback on failure; pressing a chip toggles the person's own reaction; long-press or `onShowWho` opens "Who reacted" using `api.reactors`.
- Pin/Unpin message, Bump, Delete for everyone (confirm first), Delete for me (existing `hide`): call the api, then merge the returned message or reload the page of messages.
- Pinned bar shows `page.pinned` as "📌 Pinned · {text}" with "1 of N ▾" paging through them; clicking scrolls to that message if loaded.
- Polling: the existing thread poller also calls `api.changes(id, state.since)` each tick and merges changed messages by id (replace in `state.items`, re-render keeping scroll); store `server_time` as the next `since`.
- A 403 with `error: "switched_off"` (or any refused action) shows "That's turned off in this chat." and reloads the conversation row so the flags refresh.

- [ ] **Step 1:** Vitest for the pure bits only (extract to small functions in `reactions.js`/`render.js` if needed): optimistic reaction toggle reducer (add, replace, remove, rollback), merge-changed-messages-by-id preserving order, pinned-bar paging index.
- [ ] **Step 2:** Implement the wiring. `npx eslint`; vitest passes.
- [ ] **Step 3:** `npm run build`; check as two users (student and instructor in different browsers): react, reply, pin in a direct chat, forward, bump, delete for everyone. Commit `feat(messages): message menu, reactions, pins and live changes`.

### Task 6: Settings dialog (replaces Alerts)

**Files:** create `resources/js/pages/messages/settings.js`; modify `app/Views/pages/messages/index.php` (button text "Alerts" → "Settings", `aria-label="Settings"`, same `data-role="prefs-open"`; dialog `#messages-prefs` gains a tab bar and a "Blocked people" block and a "Chat controls" panel), `resources/js/pages/messages/messages.js`; test `tests/js/pages/messages-settings.test.js`.

**Interfaces — Produces:** `createSettings({ root, api, getConversation, onControlsChanged }): { sync(): void }` — `sync()` is called when the dialog opens or the open chat changes; it shows the tab bar only when the open chat has `can_manage_controls` **or** the person manages any chat (the server sends `can_manage_controls` per chat; with no chat open, managers are recognised by `state.canManageAny` = any row with the flag). Students and parents (never managers) get no tab bar: just "My alerts".
- "My alerts": existing email switch and quiet hours (still hidden for roles without quiet hours), plus "Blocked people (n)" listing `api.blocks()` with an Unblock button each (`api.unblock(id)`); if no one is blocked, "No one blocked."
- "Chat controls": the open chat's name at the top (or "Open a chat to change its controls."); one `role="switch"` per `data-control`, labels and helper text as in the mockup: "Only the instructor can post" (lock; shown for course chats only), "Reactions", "Replies", "Forwarding", "Bump", "Delete for everyone" (helper: "Delete for me is always available."). Toggling calls `api.setControl(id, name, on)`; on success update `conversation.controls` and call `onControlsChanged`; on failure revert the switch and show "Couldn't change that. Try again."

- [ ] **Step 1: Failing tests (jsdom):** a conversation without `can_manage_controls` and no managed chats renders no tab bar; a manager sees both tabs; no open chat → the placeholder text; toggling calls `setControl` with `{switch, on}` and reverts on a failed response; the lock switch shows only for `type === 'course'`.
- [ ] **Step 2:** Implement; vitest + eslint pass; `npm run build`.
- [ ] **Step 3:** Check as a student (Settings → only My alerts) and as an instructor (tabs; switch Forwarding off, then confirm the student no longer sees Forward). Commit `feat(messages): Settings with My alerts and Chat controls`.

### Task 7: Slice check

- [ ] **Step 1:** `npx eslint resources/js/pages/messages`; `npx vitest run tests/js/pages`; `npm run build`; `git status` shows only your files.
- [ ] **Step 2:** Check the page at desktop and phone width (menu positions, long-press, no horizontal scroll, filters still one line). Do not push. Report what is done and anything unfinished.
