# Institution-wide Messages: requirements and design

Status: **approved** 2026-11-23 (gate 2 → 3). Decisions referenced as D1-D24 and S2-a..i are in `decision-register.md`; "UP Dn" means `../user-profile/decision-register.md`. Amends `docs/superpowers/specs/2026-11-02-messages-design.md` §3.1, §3.2, §3.3 rules 6-8, §6.3.

## 1. Intent

Everyone inside one institution can find and message each other, Teams style (D1), with safety tools that scale to that reach. It closes the User Profile gap (UP D4): a person you can see is a person you can message, unless a safety rule says otherwise. Messages stops depending on the active role (D4), in step with the single-identity track.

### Goals
- One simple start rule: same institution, plus a short list of safety exceptions.
- Every role a person holds works at once; the thread decides the capacity (D4).
- Recipients get context and a fast exit on first contact (D6); abuse is limited by caps, restrictions and an automatic pause (D6-D8).
- Minors are protected without narrowing adults (D9).
- No conversation, message, attachment, block, report or preference is lost.

### Non-goals (this track)
- Cross-institution messaging; people in several institutions use their first institution only (D10).
- Retention or automatic deletion (D17, separate track).
- A browsable People directory (D15).
- Parent visibility of a child's DMs (D9 deferred).
- Admin reading of conversations beyond reported content (D7).
- New institution switches beyond D3, D14, minor flags (D19).
- The picker and settings **screens** until User Profile lands (D23); their behaviour is specified here.

### Anti-goals (a failure even if it works)
- Any Messages code reading `active_role` or `ActiveRoleService` after this track.
- A search result, button or error that reveals a block, a minor flag or a restriction to someone else.
- Students able to silence their own instructor automatically (D8).
- Any destructive data change without an approved migration plan.
- Two permission code paths (D24).

## 2. Constraints
- Policy stays pure and in one class; facts come from the resolver (D24, spec 3.3.2).
- "Not found, not forbidden" for threads you are not in (spec 3.3.3) stays.
- Message text stays encrypted; audit entries never carry text (D22).
- Single institution per person via `PlanLimits::institutionOf` (D10).
- Tests on scratch MariaDB :3307; Playwright may be NOT RUN locally.

## 3. Approaches (D24)
- **Chosen: A, rewrite the policy in place.** Ordered rules in `MessagingPolicy`; resolver builds facts; every `active_role` read removed; tests rewritten.
- B: new policy behind a per-institution flag. Rejected: two policies, active role kept alive.
- C: separate "who can start" service. Rejected: permissions in two classes; post rules need the same facts.

## 4. Functional requirements

### FR1 Starting a direct thread (D1, D2, D3, D4, D9, D21)
Evaluated in this order; the first refusal wins.
1. **Hard refusals:** self; different institution; either account deactivated; system admin on either side; impersonation session; sender's institution not writable (D11); sender restricted from new threads or sending (D7); sender paused (D8).
2. **Capacity (D4):** if the thread names a child, it is a parent thread: the sender or the recipient must hold the parent role and be linked to that child, and the other side must hold a staff role. Otherwise both sides act in their non-parent capacity: **staff** if they hold any staff role (instructor, chairperson, dean, institutional admin), else **student**. A person holding only the parent role can start only parent threads.
3. **Pair rules:**
   - parent ↔ student, parent ↔ parent: never (D2).
   - student ↔ student (both non-staff): follows the institution's scope (D3): off = refused; same course = needs a shared course; whole institution = allowed.
   - **minor recipient (D9):** allowed only if the sender teaches them, is the chairperson of their department or dean of their college, shares a course with them as a student, or is an institutional admin. Applies even if the minor also holds a staff role. "Their department" is the department of their home program (M5 ruling, 2026-11-27).
   - everything else in the institution: allowed (D1), including anyone → institutional admin (D21).
4. **Blocks (D5):** refused if the recipient has an **active** block on the sender. A block is inactive (paused) while the two share the protected link defined in FR4. Blocks by institutional admins never bind (D21).

### FR2 Posting and reacting in existing threads
Today's `canPost`/`canReact` rules stay (participant, account active, institution writable, offering open, lock, parent link lost), with these changes:
- Direct thread: refused when the other side has an active block on the poster (FR4).
- Student ↔ student thread that no longer qualifies under D3 (scope narrowed, or no shared course under "same course"): read-only.
- Thread with a minor that no longer qualifies under D9: read-only.
- Restricted "no sending" (D7): refused everywhere except course chats where the person is an assigned instructor (a restriction never stops teaching).
- Deactivated other participant: composer replaced by "This account is deactivated" (D11, UP D25).

### FR3 Drafts (D20)
- Message on a person opens a draft; nothing is stored until the first message is sent.
- Sending creates thread, participants and the first message in one transaction; `direct_key` insert-ignore resolves concurrent starts.
- An existing thread for the same pair (and child) opens instead of a draft.
- Unsent text is kept in browser storage per person; not synced.
- Empty legacy threads (no messages) are hidden from lists and counts, never deleted.
- The D6 limits and D8 counting see only threads created by a first message.

### FR4 Blocking (D5, D16, D21)
- Anyone can block anyone in their institution, except: an institutional admin; an instructor of a course the blocker is currently enrolled in (or a parent blocking an instructor of their linked child). The UI says "You can't block your instructor while you share a course."
- **Protected link:** instructor ↔ student who currently share a course (instructor assigned, student enrolled). While protected, a block in either direction exists but is inactive; it resumes automatically when the link ends. The block list shows paused blocks with that explanation.
- Institutional admins can block, but their blocks never bind anyone (D21).
- **Reset (D16):** blocks created before release by people holding the instructor or institutional admin role (which never took effect) move to a history table and are not applied. One audit entry per reset block. **BC-4 (Claude's call):** only blocks whose blocker, at migration time, holds no role other than instructor or institutional admin (those could never have bound anyone); every other block stays as a normal D5 block. Migration plan needs Stage 3 approval.
- Blocks never remove anyone from course chats or groups.

### FR5 First contact (D6, D18)
- "Talked before" for a pair = an existing direct thread between them, a shared course, or a linked child relation (parent ↔ that child's staff).
- Recipient side: on a thread whose first message was a first contact, a quiet centred system line (UI pick 1): "First message from [name] · [role] · you haven't talked before" with Block and Report pills. Always shown when the recipient is a minor (D9), even if they share a course.
- Sender side: a centred grey line where the conversation starts in the draft: "First message to [name]" (UI pick 1).
- **BC-1 (Claude's call):** first-contact status is fixed when the thread is created and stored on the thread; the recipient's first-contact line shows until they reply or block; once the recipient has replied, the pair counts as talked-to for D18.
- **Limits (D6, S2-b):** new threads (direct created by first message, and groups): students/parents 10/hour and 25/day; staff 20/hour and 60/day; institutional admins exempt. A multi-role person uses the staff limits if they hold a staff role.
- **Email setting (D18):** "Email me about messages from: everyone / people I've talked to", default everyone for all roles; sits with the existing email on/off and parents' per-child email choices.

### FR6 Reports, restrictions, pause (D7, D8, D22)
- Report flow and snapshot (5 around) stay. The Reports page is regrouped **by reported person** (UI pick 3): one case per person with open reports stacked, a pause marker with time left, "Dismiss all", "Remove reported messages", "Restrict…"; each report can still be decided on its own; Open / Dismissed / Actioned tabs stay.
- **Admin removal of a reported DM:** blanked for both, tombstone "Message removed by an administrator", audited.
- **Restriction:** admin chooses *no new conversations* or *no sending*; duration (preset options or until lifted); the person sees a plain notice in Messages naming the limit and end time; listed on the People screen; lift early; expiry automatic. One active restriction per person (a new one replaces the old).
- **Automatic pause:** counts reports where: message in a direct thread the reported person started, as a first contact (FR5), reporter shares no course with them, reporter distinct. 3 distinct reporters within 24h → no new threads. Not applied to institutional admins. Ends when an admin dismisses those reports or applies a restriction, or after 72h. Admin gets a notification when it starts; the person sees "You can't start new conversations while some reports are reviewed."
- **BC-2 (Claude's call):** the pause is a stored record; it ends when an admin has reviewed (dismissed or actioned) every report it counted, applies a restriction, or after 72h; a report already counted never counts toward a later pause.
- **BC-5 (Claude's call):** "no new conversations" (restriction or pause) also stops creating groups and adding members.
- Reporter identity is never shown to the reported person.

### FR7 Groups (D12, D13, S2-c, S2-g)
- Anyone except parents creates a group; every member must pass FR1 from the **actor** (creator, or owner when adding later), and must not have an active block on the actor. 50 members max, title 80 chars.
- **Minors in groups (D26):** a minor can be added only if every current member passes FR1 toward them; nobody can be added to a group containing a minor unless they pass FR1 toward that minor. Later flag, scope or block changes affect future additions only; existing groups are never made read-only automatically.
- Owner = creator. Owner can add, remove, rename, close. Any member can leave. Institutional admins can do anything the owner can.
- Owner leaves or is deactivated → ownership passes to the longest-standing staff member, else longest-standing member.
- Closed group: archived and read-only for everyone, never erased. A group down to one member closes.
- New members see from their join time; removed/left members lose access immediately.
- Being added: in-app notification at once; email per D18.
- Membership events show as system lines in the group and are audited (D22).
- Existing staff groups: `created_by` becomes owner (D16).
- Moderation: owner and institutional admins can remove messages in the group (replaces "chair/dean in their staff group").

### FR8 Quiet hours (D14, S2-d)
- Every person can set quiet hours (the `QUIET_ROLES` list is removed).
- Institution default quiet hours (D3/D14 settings page); applies to anyone who never set their own. A person can choose their own times or **none** (explicit "no quiet hours" is stored distinctly from "never set").
- Platform timezone (`GeneralSettings`).
- **BC-3 (Claude's call):** existing `message_preferences` rows with no quiet times read as "never set" (institution default applies); saved quiet times and email choices carry over unchanged.
- Held notifications/emails release at the end; messages and unread counts unaffected. Existing saved quiet hours carry over.

### FR9 Minors (D9, S2-f)
- Additive per-student `minor` flag, institution-scoped, set by institutional admins on the People screen (own "Mark as minor / Remove minor mark" action with a confirm, "Minor" badge in the list) and via CSV import; audited.
- Visible to admins and staff (not to students, never revealed by search results or errors).
- Changing it turns non-qualifying threads read-only (FR2).

### FR10 Institution settings (D3, D14, D19)
- Student-to-student scope: off / same course / whole institution (default whole institution). Existing `studentDmEnabled = false` maps to off; everything else to whole institution. Non-destructive (migration plan in Stage 3).
- Default quiet hours.
- Both on the existing institution settings page beside chat controls; audited.

### FR11 Account and institution states (D11)
- Read-only institution: block, unblock, report, mute, archive, mark read still work; new threads, posts, reactions, group changes refused.
- Past institution: everything read-only including safety actions.
- Impersonation and the system admin: no Messages access (unchanged).

### FR12 Discovery (D15, S2-e, S2-i) — behaviour now, screens after User Profile (D23)
- Picker suggestions before typing: recent conversations; own instructors / own students; classmates in shared courses; own department's chair and college's dean; parents: their children's instructors grouped by child.
- Search: name; email only where the viewer may see that email (UP D3).
- Results include only people FR1 allows; never greyed.
- Paged search, no fixed 200-candidate stop.
- Staff + parent: choose "about a child" (pick child) or not.
- Parent with no linked child: picker explains they cannot start a conversation.
- Profile popover/modal Message button = FR1 (UP D20); UP D32's active-role exception ends when this ships (S2-a).

### FR13 Audit (D22)
Ids and outcome only: restriction placed/lifted/expired; pause started/ended/expired (system actor); admin DM removal; minor flag set/cleared; group created/member added/removed/renamed/closed/owner changed; D3 and D14 setting changes; one entry per D16 reset block. Plus today's events. Readable by the institution's admins via the existing audit screen.

## 5. Non-functional requirements
- **Security:** every rule enforced server-side in the policy; UI only mirrors it. 404 for threads you are not in. CSRF and existing filters unchanged.
- **Privacy:** no endpoint reveals another person's blocks, minor flag, restriction or pause. Search and errors use the same "not available" shape.
- **Performance:** picker search returns the first page in under 300 ms on a 5,000-member institution with indexes; the start check costs a bounded number of queries (no per-candidate N+1 beyond the page size).
- **Data preservation:** no table or column dropped; D16 history move and D3 setting mapping are reversible migrations with counts checks.
- **Accessibility:** first-contact lines and notices are announced (role="status"), Block/Report buttons keyboard reachable, WCAG AA contrast, same design language as student/instructor screens.
- **Maintainability:** no `active_role` in `app/Services/Messaging`, `app/Controllers/Messaging`, `resources/js/pages/messages` (checked by a test or grep in CI).

## 6. Acceptance criteria (summary; full list in `test-strategy.md`, Stage 3)
- AC1 Role-pair matrix: a policy unit test covers every pair of {student, instructor, chairperson, dean, inst. admin, parent, system admin} × {with/without shared course, minor, D3 scope, block active/paused, restricted, paused, deactivated, read-only institution}.
- AC2 Multi-role: staff+parent, student+instructor, minor+staff cases behave per D4/D9.
- AC3 No Messages code reads `active_role`.
- AC4 Drafts create nothing until send; concurrent first sends produce one thread.
- AC5 First-contact line appears/disappears per FR5; limits enforce per role.
- AC6 Restriction, removal and pause work end to end and are audited; a class cannot auto-pause its instructor.
- AC7 Group owner flows, ownership handover, close, 50-member limit.
- AC8 Quiet hours default/override/none; existing preferences unchanged.
- AC9 D16 reset and D3 mapping migrate with counts preserved and roll back cleanly.
- AC10 Existing conversations, messages, attachments, reports and preferences intact after every phase (regression suite green).

## 7. Retain / redesign / replace / retire

| Item | Call |
|---|---|
| `MessagingPolicy` class, pure shape, `canView`, `canModerate` (group part changes), `canManageControls`, `canPinMessage`, `canSeeParentFeed` | Retain |
| `MessagingPolicy::START` table, `UNBLOCKABLE` | Replace (FR1, FR4) |
| `MessagingContextResolver` fact building | Redesign (facts, no role) |
| `MessagingContextResolver::activeRole` and every caller | Retire |
| `StudentMessaging` on/off | Replace with three-way scope (FR10) |
| `BlockService` | Redesign (FR4) |
| `MessageRateLimiter` | Redesign thread limits (FR5) |
| `ReportService`, `ReportReviewService` | Retain, extend (FR6) |
| `GroupService` | Redesign (FR7) |
| `MessagePreferences::QUIET_ROLES` | Retire; add institution default + email setting (FR5, FR8) |
| `ParentPreferences`, `ParentFeedService`, course chats, chat controls, encryption, poller | Retain |
| `RecipientSearch` | Redesign (FR12) |
| `ConversationService::start` | Redesign (FR3) |
| `MessagesPageController` `canStartGroups` | Replace (anyone but parent-only accounts) |

## 8. Dependencies
- **User Profile (D23, D25):** the picker screen, avatars and the `/settings` move wait for its implementation. Built now on today's screens: the minor action and badge (People screen) and quiet hours for all + D18 email choice (Messages settings panel). Institution-wide reach (D1) switches on in the last backend phase, after every safety tool.
- **Single identity:** no dependency; this track removes Messages from its `active_role` inventory (coordination note in `open-questions.md`).
- **Spec amendment:** `2026-11-02-messages-design.md` §3.1-3.3 and §6.3 updated to point here when Stage 3 is approved.

## 9. Left to the builder
Exact restriction duration presets; system-line wording for groups; table and column names for restrictions, pauses, block history, minor flag and preference fields; query/index design for paged search; the first-contact line's exact markup within the approved mockups.
