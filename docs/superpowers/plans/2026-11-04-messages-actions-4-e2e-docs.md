# Messages actions, slice 4: end-to-end cases and documentation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Playwright journeys TC-MSG-15 to TC-MSG-26 for the new actions, and the documents an operator and a tester need.

**Architecture:** Extend `tests/e2e/messages.spec.js` and `tests/e2e/support/messages.js` (the helpers `people`, `enableMessages`, `openMessagesAs`, `rowFor`, `rowById`, `thread`, `sendMessage`, `startThreadWith` already exist); keep the cases in order on one database as the file's header describes. Docs: `docs/test-cases/p2-messages.md`, `docs/messages.md`, `docs/roles-and-permissions.md`.

**Tech Stack:** Playwright (chromium), Markdown.

**Spec:** `docs/superpowers/specs/2026-11-04-messages-actions-design.md` (section 8.5 table of case ids). Plans 1–3 must be done first.

## Global Constraints

- Branch `feat/messages-actions-e2e-docs`, stacked on `feat/messages-actions-ui`. Local commits only; **no push, no PR** until told.
- Case ids and titles exactly as in the spec: TC-MSG-15 pin a chat, 16 mute, 17 archive, 18 delete a chat for me, 19 react, 20 reply, 21 pin a message, 22 forward, 23 bump, 24 delete for everyone, 25 phone width and keyboard, 26 Settings and Chat controls. Each test name starts with its id.
- Use the UI hooks listed in plan 3's Global Constraints (`data-role="menu"`, `data-action`, `data-emoji`, `data-role="pinbar"`, `data-role="archived-open"`, `data-role="settings-tab-controls"`, `data-control`). Open menus with `click({ button: "right" })`; never `force: true` (a forced click once landed on an open notifications panel and hung TC-MSG-12). Close dialogs and panels before the next step.
- State a case needs and a person cannot create on screen (time passing for the 15-minute window) is set with `runSql` from `tests/e2e/support/database.js`, as the existing cases do. Leave the shared database as found (see the file's existing clean-up pattern).
- Run only the messages spec locally: `npx playwright test tests/e2e/messages.spec.js`. CI runs the whole suite.
- Docs follow the existing style of each file: plain sentences, no marketing words; every claim true of the code on this branch.

## Review Focus

- Cases are order-independent enough that a failure in one does not skip the rest for a reason unrelated to the feature (the earlier TC-MSG-12 timeout skipped 13 and 14).
- A reaction made by one browser appears in the other without a reload within the polling interval (use `expect(...).toBeVisible({ timeout: 15000 })`, not a reload).
- Context menus close before the next action; no step depends on hover state a CI browser may not have.

---

### Task 0: Branch

- [ ] **Step 1:** `git status` clean; `git switch -c feat/messages-actions-e2e-docs`.

### Task 1: Chat action journeys (TC-MSG-15 to 18)

**Files:** modify `tests/e2e/messages.spec.js`, `tests/e2e/support/messages.js` (add `chatMenu(page, rowText)` that right-clicks the row and returns the `[data-role=menu]` locator, and `menuItem(menu, action)`).

- [ ] **Step 1:** Write the four tests with these assertions:
  - **15:** a student starts a DM with a classmate; right-click it → `pin`; it is the first row above the course chats and shows 📌; right-click → `unpin`; order returns.
  - **16:** right-click a DM → `mute`; the other person sends a message; no notification arrives (open the notifications panel and Escape it) and the Messages tile badge does not count it, but the row still has `[data-unread]`; `unmute` restores both.
  - **17:** right-click → `archive`; the row disappears; `[data-role=archived-open]` shows "Archived chats (1)"; open it, the row is there; the other person sends a message and the chat is back in the main list.
  - **18:** right-click a DM → `delete` (confirm); the row is gone for this person and still present for the other; a new message from the other brings it back and the thread shows only that new message; a course chat's menu has no "Delete chat" item.
- [ ] **Step 2:** `npx playwright test tests/e2e/messages.spec.js -g "TC-MSG-1[5-8]"` passes locally (needs the local e2e database as the existing cases do). Commit `test(messages): chat action journeys`.

### Task 2: Message action journeys (TC-MSG-19 to 24)

**Files:** modify `tests/e2e/messages.spec.js`, `tests/e2e/support/messages.js` (add `messageMenu(page, text)` right-clicking the bubble containing `text`).

- [ ] **Step 1:** Write the tests:
  - **19:** a reacts 👍 to b's message from the menu's reaction row; b sees the chip "👍 1" without reloading; a presses the chip again and it disappears for both.
  - **20:** Reply from the menu; the strip shows the quoted text; send; the bubble shows the quote; clicking the quote scrolls to and highlights the original.
  - **21:** in a DM either side pins a message and both see `[data-role=pinbar]`; in the course chat a student's menu has no "Pin message" while the instructor's does.
  - **22:** forward a message to a second chat; it arrives marked "Forwarded"; Forward is absent in a parent thread's menu (use the TC-MSG-06 thread).
  - **23:** Bump on own message → a new bubble with the same text marked "Bump" at the bottom; a second Bump on the same original within the hour shows the refusal note; other person's messages offer no Bump.
  - **24:** Delete for everyone on own message → both see "You deleted a message" / "{Name} deleted a message"; the original text is absent from the DOM and from the JSON of `page.request.get` on the conversation's messages endpoint; other people's messages offer no "Delete for everyone"; after `runSql("UPDATE messages SET created_at = created_at - INTERVAL 20 MINUTE WHERE …")` (the person's own message id) the option is gone.
- [ ] **Step 2:** `npx playwright test tests/e2e/messages.spec.js -g "TC-MSG-(19|2[0-4])"` passes. Commit `test(messages): message action journeys`.

### Task 3: Phone, keyboard, and Settings (TC-MSG-25 and 26)

- [ ] **Step 1:** Write:
  - **25:** at 390×844 viewport, long-press (`page.touchscreen` is not available for hold: dispatch `touchstart`, wait 600 ms, `touchend` with `page.dispatchEvent` on the bubble) opens `[data-role=menu]`; at desktop width focus a bubble, press `Shift+F10`, the same menu opens, `ArrowDown`+`Enter` selects an item, `Escape` closes and focus returns to the bubble; right-click inside the composer does not open the app menu.
  - **26:** the instructor opens Settings → "Chat controls" for the IT101 chat, turns off Forwarding, Reactions and Delete for everyone; the student reloads, opens a message menu, and sees no Forward, no reaction row, no "Delete for everyone" but still "Delete for me"; the server refuses a stale call (`page.request.post` to `…/react` returns 403); a chairperson and a dean (use `people.chairperson`, `people.dean` if present in `support/messages.js`, else add them) can change the same chat's controls only for a course in their scope; the student's Settings has no tab bar and no "Chat controls".
- [ ] **Step 2:** The whole spec passes locally: `npx playwright test tests/e2e/messages.spec.js`. Commit `test(messages): phone, keyboard and settings journeys`.

### Task 4: Documentation

**Files:** modify `docs/test-cases/p2-messages.md` (add TC-MSG-15 to 26 in the file's existing table/format with preconditions, steps, expected results), `docs/messages.md` (sections "What people can do" and "What each person can set": the chat and message actions, who may use them, Settings and Chat controls, the limits 5 pinned chats / 3 pinned messages / 5 forward targets / 15 minutes / 1 bump per hour / 60 reactions per minute, that forwarding off does not stop copy-paste, that "Delete for me" is always available, that a deleted-for-everyone message's text is erased), `docs/roles-and-permissions.md` (who manages Chat controls: instructor in own course chats; chairperson and dean in course chats of their sub-department/department and staff groups they created; institutional administrator in any chat of their institution).

- [ ] **Step 1:** Write the three document changes, copying numbers from the spec, not from memory.
- [ ] **Step 2:** `git status` shows only these files; commit `docs(messages): chat and message actions`.

### Task 5: Slice check

- [ ] **Step 1:** `git log --oneline -6` shows the commits above; `git status` clean of your files. Do not push. Tell the owner the whole stack (four branches) is done locally and list anything not finished, then wait for the instruction to push and open pull requests.
