# Role-based workflows

Approved 2026-11-23 (gate 2 → 3). Rules in `requirements.md` (FR numbers); screens in `ui-ux-design.md` (pick numbers).

## W1 Start a direct conversation (any role except system admin)
1. Press **+ New** (or Message on a profile popover/modal, UP D20).
2. Pick a person. Today's picker until User Profile lands; then suggestions + search (FR12, D23).
3. The draft opens; nothing is saved (FR3). A first contact shows the grey "First message to [name]" line (pick 1).
4. Send creates the conversation. If one exists, it opens instead.
- **Refused** (FR1): the person simply isn't offered. A direct link or a stale button gives the generic "You can't message this person" with no reason, so no block, minor flag or restriction is revealed.
- **Restricted or paused:** + New is greyed out with the notice card (pick 2).

## W2 Student
- Can message any staff member and admins; other students per the institution's D3 setting; minors only as classmates (FR1).
- Can create groups of people they could message (FR7, D26); owns them (pick 4).
- Can block anyone except their current instructors and admins (FR4).

## W3 Instructor / chairperson / dean
- Can message anyone in the institution except minors outside their teaching, department or college (FR1, D9), and parents only about a child (D2).
- Students they teach can't block them, and their blocks don't stop those students (FR4).
- Staff+parent: picks "about a child" or not when starting (D4).

## W4 Parent
1. Picks a staff member, then which linked child the conversation is about (D2). The child label is required.
2. No linked child: the picker says they can't start a conversation (S2-i).
- Can't message students or parents, and can't create or join groups.
- Can't block their child's instructors (FR4).

## W5 Recipient of a first contact
1. Notification as usual. Email follows their D18 choice (default everyone).
2. Opens the conversation and sees the grey first-message line with **Block** and **Report** (pick 1).
3. Replying, or blocking, removes the line (BC-1).

## W6 Report → review → action (institutional admin)
1. A member reports a message (existing flow, 5-message snapshot).
2. If 3 people with no shared course report someone's first-contact direct messages within 24 hours, an automatic pause starts. The admin is notified, and the person sees the notice (FR6).
3. The admin opens **Reports**, which is grouped per reported person (pick 3). They either decide each report, or use **Dismiss all** / **Remove reported messages** / **Restrict…** (no new conversations / no sending, plus a duration).
4. The pause ends when every report it counted has been reviewed, a restriction is applied, or 72 hours pass (BC-2). Everything is audited (FR13).

## W7 Group lifecycle
1. **+ New group** opens today's dialog (name, add people). Members are checked per FR7 and D26.
2. Owner: "⋯" → **Group settings** → Members (add, remove) / Details (rename, close) (pick 4). Members: Details → Leave.
3. If the owner leaves or is deactivated, ownership passes to the longest-standing staff member, otherwise the longest-standing member. A group left with one member closes.

## W8 Institution admin settings
- Institution settings → **Messages** tab: student scope switch (Off / Classmates only / Whole institution) and default quiet hours (pick 5). Audited.
- People → Actions → **Mark as minor** / **Remove minor mark**, with a confirm. A "Minor" badge appears in the list (pick 7). CSV import column.

## W9 Personal settings (everyone)
- Messages → **Settings**: Email (Off / Everyone / People I've talked to), quiet hours (School default / My own / None), blocked people with "Paused" badges (pick 6).

## W10 Account and institution states
- **Read-only institution:** read, block, unblock, report, mute, archive and mark read still work. No new conversations, posts, reactions or group changes (FR11).
- **Past institution:** read only.
- **Deactivated person in a conversation:** "Deactivated" badge, and the reply box is replaced by "This account is deactivated".
- **Impersonation and system admin:** no Messages access.
