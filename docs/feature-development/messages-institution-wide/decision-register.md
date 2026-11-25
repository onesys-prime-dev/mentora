# Decision register

Format: ID, decision, status (approved / superseded), rationale, alternatives considered, date and question reference.
Inherited constraints from User Profile (not decided here, do not change without asking): D3, D4, D18, D20, D25, D28, D31, D32 in `../user-profile/decision-register.md`.

> 2026-11-23 repair note: D3-D24 and the S2 calls were approved in conversation but a script bug (an insertion anchor removed by the D2 edit) left them out of this file; only the question log survived. They were rewritten verbatim from the session record the same day, after the Stage 2 builder check spotted the gap. No decision content changed.

## D1: General start rule: open within the institution (approved 2026-11-23, Q1)
**Decision:** Anyone in an institution can start a conversation with anyone else in the same institution. Parents and the system admin are excluded from this (their rules are decided separately). Safety rests on blocking, reporting and rate limits rather than relationship facts.
**Rationale:** user's Teams direction; one simple rule; closes the D4 gap (a visible profile can be messaged).
**Alternatives:** B open among staff, bounded for students/parents (recommended by Claude); C open with message requests for non-related pairs; D widened relationship facts.
**Follow-ups:** parents' rules; whether the `studentDmEnabled` switch and any other institution switches survive; whether inst. admins stay unreachable; how safety tools scale to the wider reach; minors.

## D2: Parents: any staff, both directions, always about a child (approved 2026-11-23, Q2)
**Decision:** A parent can start a thread with any staff member (instructor, chairperson, dean, institutional admin) of the institution, and any staff member can start one with any parent. Every parent thread names one of the parent's linked children (`subject_student_id`) and turns read-only when that link ends (today's `linkLost` rule stays). Parents never message students or other parents. The read-only course-chat feed is unchanged.
**Rationale:** consistent with D1 and User Profile D3 (parents see all staff profiles); the child label keeps every parent thread tied to a reason and keeps the unlink safeguard.
**Alternatives:** A keep today's child-linked rules; C child-linked staff + dean + inst. admin; D parents as ordinary members, child label optional.
**Follow-ups:** parent with no linked children (S2-i); parent who is also staff (D4); staff-side abuse controls for parent threads.

## D3: Student-to-student scope is a three-way institution setting (approved 2026-11-23, Q3)
**Decision:** Replace the on/off `studentDmEnabled` switch with one setting: **off / same course only / whole institution**, default **whole institution**. Existing "off" values stay off; everything else reads as whole institution. When the setting narrows, existing student-to-student threads that no longer qualify stay readable and become read-only (today's behaviour for "off"). The profile Message button follows the setting (User Profile D32).
**Rationale:** default honours D1; institutions with minors can narrow deliberately; reuses the shared-course fact.
**Alternatives:** A keep on/off; C no switch; D per-pair settings grid.
**Follow-ups:** data migration of the stored value (non-destructive mapping, needs Stage 3 approval); audit of setting changes (exists today via the settings store).

## D4: The thread picks the hat; no active role in Messages (approved 2026-11-23, Q4)
**Decision:** A thread that names a child is a **parent thread**, open to anyone who holds the parent role and is linked to that child. Every other thread uses the person's **non-parent roles**: staff if they hold any staff role (instructor, chairperson, dean, inst. admin), otherwise student. The D3 student-to-student setting applies only when **both** people hold no staff role. Blocking, quiet hours and group creation use "holds any staff role". No saved or session role is read for either side. Replaces spec §3.1 "messages under their active role" and retires User Profile D32's exception (S2-a).
**Rationale:** every role works at once without a switcher; the child label already marks the parent capacity; fits the single-identity track.
**Alternatives:** A any role allows; B highest role (D12) decides; D keep active role until single identity.
**Accepted consequence:** a student who also teaches is staff to every classmate, so D3 does not narrow them.
**Follow-ups:** picker for a staff+parent person (about a child or not) is a UI item for Stage 2; coordination note for the single-identity track.

## D5: Blocking protects only the teaching link and admins (approved 2026-11-23, Q5)
**Decision:** Nobody can block an instructor they currently share a course with, or an institutional admin, and an instructor's block does not bind their own current students. Every other pair can block both ways (student ↔ unrelated instructor, staff ↔ staff, staff ↔ parent), except that a parent cannot block the instructors of their own child. Protection follows the shared-course fact, not role labels. An existing block that becomes protected (the two start sharing a course) pauses and resumes when they no longer share it; the block list explains paused blocks.
**Rationale:** keeps the must-never-break link and the admin route; gives everyone else a working defence under D1.
**Alternatives:** A keep instructors/admins unblockable; C anyone except admins; D mute instead of block for staff.
**Follow-ups:** wording for "you can't block your instructor while you share a course"; harassment inside a course goes to reporting/admin. Admin's own blocks: D21.

## D6: First-contact notice plus role-tiered limits (approved 2026-11-23, Q6)
**Decision:** (1) **First-contact notice:** when someone writes to a person they have never had a direct thread with, the recipient sees a banner at the top of the thread ("First message from [name], [role]. You can block or report.") with one-tap Block and Report; the sender sees a one-line notice before sending. No banner when the pair shares a course, a linked child, or an existing direct thread. (2) **Role-tiered new-thread limits** with a daily cap: roughly students/parents 10/hour + 25/day, staff 20/hour + 60/day, institutional admins exempt; exact numbers S2-b.
**Rationale:** context and a fast exit for the recipient without the friction of message requests (Q1 C was rejected); caps stop mass outreach.
**Alternatives:** A today's limits only; B tiered limits only; C message requests for non-related pairs.
**Follow-ups:** whether Block from the banner also offers Report; "talked before" = an existing direct thread, shared course or linked child.

## D7: Admin restrictions, DM removal and an automatic pause (approved 2026-11-23, Q7)
**Decision:** From a report the institutional admin can (1) **remove a reported direct message** (blanked for both, "Message removed by an administrator", audited; amends spec 3.3 rule 8 "nobody moderates a direct thread"); (2) **restrict the sender**: *no new conversations* or *no sending at all*, for a set time or until lifted, shown to the person in a plain notice, audited, listed on the People screen. Plus (3) an **automatic pause**: when several distinct people report the same sender within a window, the sender cannot start new conversations until an admin reviews (rules in D8). Admins still read only reported content.
**Rationale:** a proportionate step between nothing and deactivation; the pause stops overnight waves before an admin looks.
**Alternatives:** A today's review only; B restriction + removal without auto-pause (Claude's recommendation); C full admin oversight.
**Follow-ups:** a restriction never blocks posting in course chats a person teaches (proposed in FR2); restriction expiry; reporter anonymity stays.

## D8: Automatic pause rules (approved 2026-11-23, Q8)
**Decision:** A report counts toward the pause only when it is about a **direct message** in a thread the **sender started** as a **first contact** (the D6 banner case) and the reporter **does not share a course** with the sender. Applies to every role except institutional admins. Default: **3 distinct reporters in 24 hours** (tunable). The pause stops new conversations only (replies and course chats keep working), notifies the admin, shows the person a plain notice, ends when an admin dismisses the reports, and **expires after 72 hours** if no admin acts (reports stay open). Several reports from one reporter count once.
**Rationale:** targets cold-outreach spam that D1 enables; the shared-course exclusion stops a class brigading its own teacher, for every role equally.
**Alternatives:** A fixed threshold for all; B students/parents only, staff escalated; D reporter-history weighting.

## D9: Minor flag with narrower rules (approved 2026-11-23, Q9)
**Decision:** Add a per-student **minor** flag (set by institutional admins, and importable). A minor can be messaged only by staff who teach them or lead their department/college (chairperson, dean), by students who share a course with them, and by institutional admins; parents per D2 are unaffected (they message staff, not students). The D6 first-contact banner always shows for minors. Minor rules win over D4 when receiving (S2-f). When the flag changes, threads that no longer qualify stay readable and become read-only (as D3). The flag is visible to admins and staff only, never to students.
**Rationale:** Mentora serves colleges and schools; protects minors without taking D1 from adults. Parent visibility of DMs deferred until a school asks.
**Alternatives:** A no extra rules; B parents see who their child talks to; D B + C.
**Follow-ups:** where the flag is set (S2-f); adult students turning 18 is a manual admin change.

## D10: One institution per person in Messages (approved 2026-11-23, Q10)
**Decision:** Keep today's behaviour: Messages uses the person's **first** institution membership (`PlanLimits::institutionOf`). A person in several institutions cannot see or message the others. Documented as a known limitation; no cross-institution messaging.
**Rationale:** matches the rest of the app; nothing crosses an institution boundary.
**Alternatives:** B all institutions at once; C shared "current institution" for a future app-wide track (Claude's recommendation); D disable Messages for multi-membership people.
**Known gap:** a person teaching at two schools cannot reach the second one in Messages (same limit as profiles).

## D11: Account and institution states (approved 2026-11-23, Q11)
**Decision:** Keep today's rules (deactivated accounts cannot send or receive, history kept; read-only/past institutions stop posting; impersonation and the system admin have no Messages access), plus: (1) in a **read-only** institution, safety and personal actions still work (block, unblock, report, mute, archive, mark read); new threads, posts and reactions stop; in a **past** institution everything is read-only, including safety actions; (2) a thread with a deactivated person shows the User Profile D25 "Deactivated" badge and the composer is replaced by "This account is deactivated"; (3) impersonation stays closed.
**Rationale:** people can always protect themselves; consistent with D25; support sessions never see private messages.
**Alternatives:** A keep exactly; C impersonation sees the conversation list (no text); D full impersonation with audit.

## D12: Anyone except parents can create groups (approved 2026-11-23, Q12)
**Decision:** Any student or staff member can create a group conversation. Every member must be someone the creator could start a direct thread with (so D3 and D9 apply member by member); a person who has blocked the creator cannot be added. The creator moderates their group (institutional admins can moderate any group, as today). Parents cannot create or join groups (their threads are always about a child, D2). Course chats are unchanged. Member limit 50 (S2-c), title 80 characters. Group creation counts toward the D6 new-thread limits.
**Rationale:** Teams parity including student study groups; one rule ("anyone you could DM") and no new permission table.
**Alternatives:** A chair/dean staff groups only; B any staff creates (Claude's recommendation); D student groups need a staff member.
**Follow-ups:** member management (D13); reporting inside groups must reach admins (D7).

## D13: Creator-owned groups (approved 2026-11-23, Q13)
**Decision:** The owner (creator) can add members (D12 rule), remove members, rename the group, and **close** it (closed = archived and read-only for everyone, never erased). Any member can leave. When the owner leaves or is deactivated, ownership passes to the longest-standing staff member in the group, otherwise the longest-standing member. Institutional admins can do anything the owner can. New members see messages from the time they joined. Removed or departed members lose access immediately (as course chats). A group left with one member closes.
**Rationale:** one accountable person, matches D12's "creator moderates", no data destroyed.
**Alternatives:** B every member equal; C owner + co-owners; D fixed membership plus leave.
**Follow-ups:** audit entries for membership changes (D22).

## D14: Quiet hours for everyone, with an institution default (approved 2026-11-23, Q14)
**Decision:** Every person (student, parent, staff) can set personal quiet hours; `QUIET_ROLES` goes. The institution sets **default quiet hours** (e.g. 21:00-07:00) that apply to anyone who has not set their own; people can change or clear them. Behaviour unchanged: notifications and emails are held and released at the end; the message and unread badge are unaffected. No admin bypass in v1. No sender-side "in quiet hours" notice. Existing saved quiet hours carry over.
**Rationale:** protects students and minors by default; one rule for all.
**Alternatives:** A staff only; B everyone, no default; D C + sender-side notice.
**Follow-ups:** timezone (S2-d); how "cleared" is stored distinctly from "never set" (Stage 3).

## D15: Search plus suggestions in the picker; no directory (approved 2026-11-23, Q15)
**Decision:** The new-message picker shows **suggestions before typing** (recent conversations, own instructors/students, classmates in shared courses, own department's chair and dean; for parents, their children's instructors grouped by child) and **search by name** across the institution. Each result shows photo, name, top role and a detail (program/year or department). People the viewer cannot message (D3, D9, blocks) are left out, never greyed, so no block or minor flag is revealed. Search must page properly instead of stopping at a fixed 200-candidate scan. No browsable People directory in this track.
**Rationale:** the common case is one tap; the whole institution stays reachable by typing; no browsable list of every student.
**Alternatives:** A name search only; C full directory; D B + C.
**Follow-ups:** email search (S2-e); ranking of suggestions; staff+parent "about a child" choice (D4).

## D16: Old non-binding blocks are reset into history (approved 2026-11-23, Q16)
**Decision:** Blocks made by instructors and institutional admins before this track (which never took effect) are **moved to a history table and not applied**; everyone starts fresh under D5. Nothing is erased: the rows are kept in history. Other existing data follows the new rules on release: staff-group creators become owners (D13), chair→parent reply-only threads become normal threads (D2), `studentDmEnabled` off → "off" (D3), saved quiet hours carry over (D14), Messages stops reading `active_role` (D4).
**Rationale:** user's choice: a clean start under the new blocking rules rather than suddenly activating forgotten blocks.
**Alternatives:** A apply everything silently; B apply with one-time notices (Claude's recommendation); C grandfather old state.
**Data safety:** this is a data move; the exact migration (history table, reversible down(), counts check) must be written in Stage 3 and approved before it runs.
**Notices:** none (S2-h).

## D17: Keep everything; retention is a separate track (approved 2026-11-23, Q17)
**Decision:** No retention or automatic deletion in this track. Messages keeps everything as today (hide-for-me, tombstones, closed chats read-only, history survives deleted users). Institution retention policy is recorded as a **separate follow-up track** with its own discovery, legal input and approval.
**Rationale:** the first destructive Messages feature deserves its own review; bundling makes both harder.
**Alternatives:** B institution retention period with deletion; C text-only expiry with placeholders; D personal clear-history.

## D18: Recipient setting for email about first contacts; default everyone (approved 2026-11-23, Q18)
**Decision:** A personal setting: **"Email me about messages from: everyone / people I've talked to"**, default **everyone for all roles**. "Talked to" = an existing direct thread, a shared course, or a linked child (same test as D6). In-app notifications are unchanged (one per thread unless muted; quiet hours hold them, D14). Course chats unchanged. Sits alongside the existing email on/off switch and parents' per-child email choice.
**Rationale:** each person decides; no role-based defaults.
**Alternatives:** A same as any message; B first contacts in-app only until reply; C B but staff first contacts still email (Claude's recommendation).
**Follow-ups:** group-add email (S2-g); how the new setting combines with the existing email on/off in the settings UI.

## D19: No new institution switches beyond D3, D14 and minor flags (approved 2026-11-23, Q19)
**Decision:** Institutions control only: the student-to-student scope (D3), default quiet hours (D14), per-student minor flags (D9), plus the existing chat controls. Messages on/off stays with the system admin's per-institution feature flag. Settings live on the existing institution settings page next to chat controls; every change audited; narrowing turns non-qualifying threads read-only.
**Rationale:** short settings page, fewest combinations; D3 already narrows student groups.
**Alternatives:** B student-groups switch; C parent-reach switch; D inst. admin turns Messages on/off.

## D20: A direct thread is created by the first message (approved 2026-11-23, Q20)
**Decision:** Clicking Message (picker, profile popover/modal) opens a **draft**: the thread view with the composer ready, nothing saved. Sending the first message creates the thread, participants and message together. If a thread with that person (and child label) exists, it opens instead. Unsent draft text is kept in the browser only (per person, not across devices). Existing empty threads are hidden from lists, not deleted. `direct_key` insert-or-ignore keeps handling two people starting at once. Limits (D6) and the pause (D8) count only real first messages.
**Rationale:** no empty threads for recipients; curiosity clicks cost nothing; matches Teams/Slack.
**Alternatives:** A created on click; C created on click, hidden until a message; D confirm before creating.

## D21: Institutional admins are reachable by everyone and cannot block (approved 2026-11-23, Q21)
**Decision:** Anyone in the institution (parents included, per D2) can start a thread with an institutional admin; the old spec call "non-admins cannot start a thread to an admin" is retired. An admin's own block binds nobody (admins use D7 restrictions and the D8 pause instead). Closes the D5 open item.
**Rationale:** the front office is always reachable; admins have stronger, audited tools; a block in a one-admin institution would cut someone off.
**Alternatives:** B admin blocks bind like anyone's; C keep the old unreachable rule; D shared "Institution office" inbox.

## D22: Audit every new Messages action (approved 2026-11-23, Q22)
**Decision:** Audit (ids and outcome only, never text): restrictions placed/lifted/expired (D7), automatic pauses started/ended (D8, expiry as a system action), admin removal of a DM (D7), minor flag set/cleared (D9), group created / member added / removed / renamed / closed / ownership changed (D13), D3 and D14 setting changes, and the D16 reset (**one entry per reset block**). The affected person sees only what limits them (restriction/pause notice, removal tombstone, group system messages). Messages audit entries readable by institutional admins of that institution via the existing audit screen.
**Rationale:** full record for child-safety and harassment disputes; consistent with today's audit style.
**Alternatives:** B safety/admin actions only; C A + personal safety history; D today's plus restrictions only.

## D23: Build order: rules first, UI after User Profile (approved 2026-11-23, Stage 2 S1)
**Decision:** Build the policy, safety and data phases now (they do not depend on User Profile). Hold the user-facing picker and settings screens until User Profile's implementation lands, then reuse its avatar component (UP D19/D30), `/settings` Notifications section (UP D15) and People edit-person dialog (UP D22).
**Rationale:** most of D1-D22 is backend; avoids building the settings screen and avatar rows twice.
**Alternatives:** wait for all of User Profile; build alongside with temporary UI.
**Consequence:** the base branch for the backend phases is still to be asked before the first branch (brief rule).

## D24: Build approach: rewrite the policy in place (approved 2026-11-23, Stage 2 S2)
**Decision:** `MessagingPolicy` stays one pure class; its role×role table becomes ordered rules (hard refusals → which hat (D4) → pair rules (parent/student, D3 scope, D9 minors) → blocks (D5)). `MessagingContextResolver` builds facts instead of a role (holds staff role, is parent, minor, shares course, leads department/college, restricted, paused). Every `active_role` read in Messages is replaced. Old policy tests are rewritten. No feature-flag dual path.
**Rationale:** one place decides (spec 3.3.2); no active role left; no duplicated policy.
**Alternatives:** B new policy behind a per-institution flag; C separate reach service.
**Accepted risk:** one switch-over with no toggle back; mitigated by nothing being deleted.

## D25: Safety first, wider reach last; small additions to today's screens (approved 2026-11-23, Stage 2 builder check T1; refines D23)
**Decision:** Phases keep today's reach until the safety tools exist: policy rewrite (today's reach preserved) → blocking, limits, first-contact banner, reports/restrictions/pause, minor flag, groups, quiet hours → the switch to institution-wide reach (D1) in the **last backend phase**. The minor flag goes on **today's** People screen as its own "Mark as minor / Remove minor mark" action with a confirm and a list badge (refined by mockup pick 7, 2026-11-23; originally "a checkbox in the edit-person dialog"). Quiet hours for everyone (D14) and the D18 email choice go into **today's** Messages settings panel; UP D15 moves the panel to `/settings` later.
**Rationale:** everyone is protected from the moment reach widens; no period where minors cannot be flagged.
**Alternatives:** B hold the reach switch until User Profile lands; C widen early with CSV-only minor flags.
**Refines D23:** the minor checkbox and the two settings additions are built now on today's screens; the picker (D15) and full settings move still wait for User Profile.

## D26: Groups check minors against every member (approved 2026-11-23, Stage 2 builder check T2; refines D12)
**Decision:** A minor can be added to a group only if **every current member** could start a direct thread with them (FR1), and nobody can be added to a group that contains a minor unless they could start a direct thread with that minor. D3 scope and blocks are checked only against the person adding (D12 as written). Later changes (a member flagged minor, D3 narrowed, a new block) affect **future additions only**; existing groups are never made read-only automatically; the owner and admins can remove members and reports work in groups.
**Rationale:** closes the D9 bypass through groups while keeping adult/study groups flexible.
**Alternatives:** A every member checked against every member for all rules; C actor-only for everything (D12 as written).
**Accepted consequence:** someone who blocked a person can still share a group with them (leave or report, as with course chats).

## Stage 2 calls by Claude (proposed 2026-11-23; user reviewed, accepted all but S2-c, which they changed)
- **S2-a (UP D32):** D32's active-role exception ends when this track ships, not when single identity ships (D4 needs no active role).
- **S2-b (D6 numbers):** students/parents 10 new threads/hour + 25/day; staff 20/hour + 60/day; institutional admins exempt. Groups count.
- **S2-c (D12): changed by the user 2026-11-23:** group member limit raised to **50**; 80-character title limit stays.
- **S2-d (D14):** quiet hours use the platform timezone (`GeneralSettings` `timezone`, platform-wide; institutions have none).
- **S2-e (D15):** search by name; also by email only where the viewer may see that email under UP D3 (staff emails for all; student emails for staff).
- **S2-f (D9):** minor flag on the People screen (own action + badge, mockup pick 7; originally the edit-person dialog) and a CSV import column; a minor who also holds a staff role sends as staff (D4) but is protected as a minor when receiving.
- **S2-g (groups):** being added to a group gives an in-app notification at once; email follows D18.
- **S2-h (D16):** no notices for the carry-over items or the block reset.
- **S2-i (D2):** a parent with no linked child cannot start a thread; the picker explains why.

## Question log
- Q1 (2026-11-23): overall permission model → A (D1).
- Q2 (2026-11-23): parents' reach → B (D2).
- Q3 (2026-11-23): institution switches for student-to-student → B (D3).
- Q4 (2026-11-23): which role counts for a multi-role person → C (D4).
- Q5 (2026-11-23): who can block whom → B (D5).
- Q6 (2026-11-23): first-contact and spam controls → D (D6).
- Q7 (2026-11-23): admin actions on reports → D (D7).
- Q8 (2026-11-23): automatic pause safeguards → C with DM-only and 72h expiry (D8).
- Q9 (2026-11-23): minors and parent visibility → C (D9).
- Q10 (2026-11-23): people in several institutions → A (D10).
- Q11 (2026-11-23): account and institution states, impersonation → B (D11).
- Q12 (2026-11-23): who creates group conversations → C (D12).
- Q13 (2026-11-23): group membership after creation → A (D13).
- Q14 (2026-11-23): quiet hours → C (D14).
- Q15 (2026-11-23): finding people to message → B (D15).
- Q16 (2026-11-23): existing data under the new rules → D (D16).
- Q17 (2026-11-23): retention → A (D17).
- Q18 (2026-11-23): notifications for first contacts → D with default everyone (D18).
- Q19 (2026-11-23): institution controls → A (D19).
- Q20 (2026-11-23): when a direct thread is created → B (D20).
- Q21 (2026-11-23): institutional admins reachable / their blocks → A (D21).
- Q22 (2026-11-23): audit coverage → A (D22).
- Gate 1 → 2 approved by the user 2026-11-23.
- Gate 2 → 3 approved by the user 2026-11-23 (requirements, workflows, UI/UX + mockups).
- Gate 3 → 4 approved by the user 2026-11-23 (plan incl. the Phase 2 block reset and Phase 5 setting copy).
- Stage 2 S1 build order → rules first (D23). Builder check T1 → A (D25). T2 → B (D26). S2 approach → A (D24). S2 calls reviewed, S2-c changed to 50.
