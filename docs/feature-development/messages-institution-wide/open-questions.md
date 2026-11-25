# Open questions

Topics still to cover in discovery (ticked as decided):

- [x] Q1 overall permission model (D1: open within institution)
- [x] Per role pair (D1, D2, D3, D9, D21; system admin excluded, D11)
- [x] Student-to-student DMs and the existing `studentDmEnabled` switch (D3)
- [x] Parent contact rules (D2)
- [x] Group conversations: who creates, who can be added (D12, D13)
- [x] Institution boundary; people in several institutions (D10: first institution only)
- [x] Multi-role people without `active_role` (D4)
- [x] Deactivated people; read-only / suspended institutions; impersonation (D11)
- [x] Blocking, unblockable roles (D5); per-person mute not adopted
- [x] Reporting and admin review (D7; auto-pause details Q8)
- [x] Rate limits and spam; first-contact notice (D6; numbers in Stage 2)
- [x] Quiet hours and `MessagePreferences::QUIET_ROLES` (D14)
- [x] Moderation (D7, D8), retention (D17: separate track), audit (D22)
- [x] Minors and parent visibility (D9)
- [x] Institution switches (D19)
- [x] People search / directory; suggested contacts (D15); popover/modal Message button follows canStart (User Profile D20/D32)
- [x] Keeping existing conversations and preferences (D16; old blocks reset to history, migration needs Stage 3 approval)
- [ ] UI: new-message flow, picker, states, first-contact notice, responsive, notifications → **Stage 2 mockups** (behaviour fixed by D6, D15, D18, D20)

## Raised during discovery: settled 2026-11-23 by S2-a..i (see register)

## Builder check (2026-11-23): sorted
**Answered already:** register gap (repaired 2026-11-23).
**Claude's calls (in requirements.md, marked BC-n):**
- BC-1 first contact is fixed when the thread is created (stored on the thread); the recipient's banner shows until they reply or block; once the recipient has replied, the D18 "people I've talked to" test counts the pair as talked-to.
- BC-2 the pause is a stored record; it ends when an admin reviews (dismisses or actions) every report it counted, applies a restriction, or after 72h; reports already counted never count again.
- BC-3 existing `message_preferences` rows with no quiet times read as "never set", so the institution default applies; email choices unchanged; saved quiet times carry over.
- BC-4 D16 resets only blocks whose blocker, at migration time, holds no role other than instructor or institutional admin (narrow reading: those blocks could never have bound anyone). Others stay as normal D5 blocks.
- BC-5 "no new conversations" (restriction or pause) also stops creating groups and adding members.
**Theirs (asked one at a time):**
- T1 (answered: D25) can the wider start rule reach users before the safety tools and a way to set the minor flag exist? (affects D23 and phase order)
- T2 (answered: D26) group bypass: an adult who passes D12 can put people together who could not message each other directly (e.g. a minor with non-classmates); and what happens to a group when D3/D9/blocks stop qualifying.

## Follow-up tracks recorded
- Institution retention policy (D17).
- People in several institutions (D10 known gap, app-wide).
- Parent visibility of a minor's DMs (D9 deferred).

## Coordination notes
- Tell the single-identity track: Messages decided D4 (thread picks the hat, no active role). That track can drop Messages from its `active_role` inventory once D4 ships.

## Stage 2 consistency check against User Profile (D1-D32), 2026-11-23
- **C1 (UP D15):** UP moves Messages email alerts + quiet hours into `/settings` → Notifications "unchanged in behaviour" (quiet hours staff only). Our D14 (everyone + institution default) and D18 (first-contact email setting) amend that section once both ship. Settled by D23 (settings screens wait for UP).
- **C2 (UP D32):** settled by S2-a.
- **C3 (UP D3 vs D3/D9 here):** a minor, or a student in a "same course"/"off" institution, keeps an institution-wide visible profile with no Message button for most students. Accepted by D9/D3 (button follows `canStart`, UP D20).
- **C4 (UP D19/D30, avatars):** D15 picker rows show photos via the UP avatar component and its JS renderer. Settled by D23.
- **C5 (UP D22):** minor flag (D9) goes in the People screen's edit-person dialog, next to UP's home department field. Accepted as S2-f.
- Consistent, no action: UP D2/D3 parent visibility vs D2; UP D25 vs D11; UP D29 C4 vs D10; UP D16 vs D22; UP D6 "no report button" (profile only).

## Conflicts to raise
- (resolved) Spec §3.1 active role → replaced by D4.
