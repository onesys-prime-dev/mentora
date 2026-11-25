# Decision register

Format: ID, decision, status (approved / superseded), rationale, alternatives considered, date.

## D1: Scope and structure (approved 2026-11-23, Q1)
**Decision:** One shared profile page for all 7 roles (student, instructor, chairperson, dean, parent, institutional admin, system admin), with sections that depend on the role, **plus** a separate shared "Account settings" page for security and preferences, linked from the header's Settings item.
**Rationale:** one design and one test surface; security built once; separates what others may see from what only the owner controls; gives the inert Settings item a purpose.
**Alternatives:** a separate page per role (drift, duplicated security); a single page with no settings split; phased student/instructor first (dead links for the other roles longer).
**Follow-ups:** how multi-role users see sections; fate of `student/profile` and `parent/profile` routes.

## D2: Viewing other people's profiles (approved 2026-11-23, Q2; **superseded by D3** at the user's request, same day)
**Decision:** Names across the app open a small profile card (photo, name, role, role details) for people the viewer shares a scope with: students see their instructors and classmates; instructors see students in offerings they teach; chairs/deans see people in their department/college; parents see their children's instructors. One institution setting controls whether students see each other's photos (instructors always see their students' photos).
**Rationale:** context where names already appear; reuses existing scope rules; the institution decides the minor-sensitive case.
**Alternatives:** private only (dead names); per-field owner controls (many settings and tests, defaults do the work anyway); institution controls for every field (no individual opt-out, larger settings tab).
**Follow-ups:** exact card fields per role; where names become clickable; admins' view of profiles; whether a student can see classmates' contact info.

## D3: Institution-wide profile visibility (approved 2026-11-23, Q2b; supersedes D2)
**Decision:** Teams-style. Within one institution, clicking a person's name opens a modal with their full profile. Rules on who can see what:
- Staff profiles (instructor, chairperson, dean, institutional admin): all fields, visible to everyone in the institution.
- Student profiles: photo, name, program, year visible to everyone in the institution except parents; contact fields visible to staff only.
- Parents: see staff profiles only (their linked children are reached through the parent portal); parent profiles visible to staff and to their own linked children.
- System admin: not a member of any institution; neither visible nor a viewer here.
**Rationale:** user wants the Teams model; field limits protect students (possibly minors) and keep parents, as outside users, from browsing students.
**Alternatives:** all fields to everyone (minors' privacy); B without the parent limit; C plus a per-student photo opt-out.
**Open:** whether D2's institution switch for student photos survives (not decided by D3).

## D4: Messaging scope stays out of this feature (approved 2026-11-23, Q4)
**Decision:** This feature does not change Messages permissions. The profile modal shows a Message button only when `MessagingPolicy::canStart` allows the pair. Opening Messages to the whole institution is a **separate follow-up feature track** with its own discovery, spec amendment (`docs/superpowers/specs/2026-11-02-messages-design.md` §3.1) and branch chain; when it lands, the button widens automatically.
**Rationale:** keeps each approved spec and PR separate; messaging widening needs its own abuse/blocking/student-DM review.
**Alternatives:** keep as is with no follow-up; widen inside this feature; staff-to-staff widening inside this feature.
**Known gap until the follow-up ships:** a user can see a profile they cannot message.

## D5: Identity field ownership (approved 2026-11-23, Q3)
**Decision:** First, middle, last name, suffix, email and student number are read-only for the owner. The owner can submit a **correction request** (field, new value, reason) to the institutional admin, who approves or rejects; approval writes the value and is audit-logged. For SIS-linked people the admin sees a warning that the next import reverts the change unless the SIS is fixed too. Separately, an optional **preferred name** the owner edits freely: shown in social places (profile modal, discussions, messages, groups); official places (grade sheets, exports, SIS, gradebook) keep the legal name. An institutional admin can clear a misused preferred name.
**Rationale:** authoritative records, typo path, audit trail; chosen names supported without touching official data; moderation because D3 makes names institution-wide.
**Alternatives:** admin-only with no request path; owner-editable when not SIS-linked (unmoderated, inconsistent).
**Follow-ups (D5):** email change specifics (verification of the new address on approval?); who handles requests from institutional admins themselves and from people with no institutional admin; preferred-name length/character rules; exact list of "social" vs "official" places.

## D6: Profile photos (approved 2026-11-23, Q5 + Q5b)
**Decision:** Self-uploaded photos, published immediately, removable by institutional admins (Q5 option B), under an institution setting (option E) with **two modes**: On (default) and Off.
- Off hides photos (initials shown) but **keeps** the files; turning On restores them.
- Upload: owner crops square in the browser; server accepts JPEG/PNG/WebP only, size cap, content-sniffed type, re-encodes (strips EXIF/GPS), stores 256px and 64px through the existing `files` table. Replace and remove supported.
- Schema: additive nullable `user_profiles.photo_file_id` only.
- Admin: "Remove photo" on the profile modal and the People screen, audit-logged; owner sees "Your photo was removed by your school". Admins may upload on someone's behalf.
- Rendering: one shared avatar component (photo or initials avatar, colour stable per person) replaces the hard-coded placeholder (header, dashboard cards, modal); obeys the setting and D3 visibility.
- Serving: through an authorised controller, never a public guessable URL.
- No report button in this feature.
**Rationale:** real faces with low friction; institution keeps an off switch; no data loss on toggling; approval mode can be added later without rework.
**Alternatives:** initials only; approval for everyone; approval for students only; a third "require approval" mode (Q5b, declined for now).
**Supersedes:** the open D2 student-photo switch question (closed: replaced by this setting).

## D7: Extra profile fields (approved 2026-11-23, Q6)
**Decision:** Everyone: preferred name (D5), pronouns, short plain-text bio (~300 chars). Staff only (instructor, chairperson, dean, institutional admin): office location, consultation hours (free text), optional work phone. No personal mobile, emergency contact, birthdate or address. All new fields optional, owner-editable, clearable by an institutional admin. Additive nullable columns only.
**Rationale:** answers "where/when can I see my instructor"; no sensitive personal data on possible minors; the SIS remains the student record.
**Alternatives:** minimal (pronouns + bio); add personal mobile for all; full student record (SIS duplication, privacy load).
**Follow-ups:** pronouns free text or a list; whether parents get any extra field.

## D8: Role details on the profile (approved 2026-11-23, Q7)
**Decision:** Read-only, derived facts:
- Student: program, year level, class section, college. Enrolled courses shown only to staff and to the student themself (not to classmates).
- Instructor: **home department**, a new admin-set value (People screen; SIS/CSV import maps it when the source has it). Until set, fallback = the department they teach most in the current term, labelled "Teaches in …". Plus courses this term.
- Chairperson / dean: department / college they lead (from existing assignment tables).
- Parent: nothing beyond name and role shown to staff; linked children on the parent's own profile only.
- Institutional admin: institution.
**Rationale:** the current "first course ever" guess (`UserProfileModel::teachingHomeColumns`) is wrong for cross-department staff; a real value is stable and reusable by other scope logic.
**Alternatives:** derive from current-term load only; show no department for instructors.
**Follow-ups (D8):** storage shape (nullable `home_department_id` on `user_profiles` vs a table); whether `teachingHomeColumns` callers switch to the new value; import mapping details.

## D9: Profile ignores the active role; all roles, highest first (approved 2026-11-23, user direction during Q8)
**Decision:** The profile (own page and others' modal) never reads `active_role`. It lists every role the person holds, ordered highest role first, each with its D8 details. D3 still applies (a parent role is shown only to staff).
**Rationale:** the user doubts the role switcher will survive (extra step, extra UI to learn, causes questions like Q8); the profile must work identically with or without it.
**Alternatives considered (Q8):** active role only; all roles with the active role first.
**Follow-ups:** exact role ranking; whether to remove the switcher app-wide (Q9: `active_role` is read in 52 files / 105 places).

## D10: Role switcher removal is a separate track (approved 2026-11-23, Q9)
**Decision:** Removing the role switcher (and `active_role` from 52 files) is a separate feature track ("single identity, no role switcher") with its own discovery, spec and branch chain. This feature stays switcher-independent per D9.
**Rationale:** app-wide refactor touching dashboards, grading, Messages, impersonation; needs its own review and rollback unit.
**Alternatives:** an early phase of this feature; hide-only (pins highest role, chairs lose instructor views).
**User addition (same message):** the user wants the chairperson, parent and dean screens redesigned to follow the student/instructor design language (dashboards and other screens). Placement asked as Q10.

## D11: Chair/dean/parent screen redesign joins the switcher track (approved 2026-11-23, Q10)
**Decision:** The redesign of chairperson, dean and parent screens (dashboards and other pages) to the student/instructor design language belongs to the D10 track, renamed **"single identity + role screen redesign"**, covering chair, dean and parent. This feature only guarantees its own profile/settings screens use that design language for all roles.
**Rationale:** removing the switcher rebuilds those dashboards anyway; design them once.
**Alternatives:** inside this feature; a third separate track; split parent out.

## D12: Role ranking (approved 2026-11-23, Q11)
**Decision:** dean → chairperson → instructor → institutional admin → student → parent. Defined as **one constant owned by this feature** (profile only); `ActiveRoleService::preferredRole` is not touched here; the switcher track may adopt the constant later.
**Rationale:** the card leads with the academic title; extends today's dean/chair-first, parent-last order.
**Alternatives:** admin on top; one constant shared app-wide now (crosses into the D10 track).

## D13: Password change (approved 2026-11-23, Q12)
**Decision:** In Account settings, for every role: an in-app form (current password, new, confirm) validated against `InstitutionSecurity` `passwordMinLength` and the platform floor, with a strength meter; the current-password check is rate-limited; on success every other session is signed out, the change is audit-logged, and an email notice is sent. Below it, a "Forgot your current password? Email me a link" fallback reusing `PasswordResetService`. The parent-only password-link button on `parent/profile` is retired into this shared page.
**Rationale:** fast, expected, works without email; fallback covers forgotten passwords; one flow for all roles.
**Alternatives:** emailed link only; form only; form gated by the `ReauthService` dialog.
**Implementation note (fact, 2026-11-23):** sessions are file-based (`Config/Session.php` FileHandler), so "sign out other sessions" needs a per-user "sessions valid since" timestamp checked per request plus clearing remember-me tokens. **Correction (same day):** this already exists as `App\Services\Users\AccountControl::signOutEverywhere()` (used by admin sign-out and `PasswordResetService`); reuse it, adapted to keep the current session.

## D14: Two-factor sign-in and sessions (approved 2026-11-23, Q13)
**Decision:** Account settings shows 2FA status (on/off, and "required by your school" when the role is in the institution's `twoFactorRoles` or a platform role, with the masked email codes go to). Any user may **opt in** to emailed 2FA; they cannot turn off a school requirement; turning their opt-in off needs re-authentication. `AdminEmail2FA::appliesTo` becomes "role requires it OR user opted in" (one additive flag). Plus a **"Sign out of all other devices"** button reusing the D13 mechanism.
**Out of scope (possible later follow-ups):** authenticator-app (TOTP) 2FA with backup codes; a device/session list (needs DB sessions).
**Rationale:** real self-protection on the existing code path at minimal cost.
**Alternatives:** status only; TOTP; device list.

## D15: Notification preferences (approved 2026-11-23, Q14)
**Decision:** Account settings gets a "Notifications" section holding the existing preferences, unchanged in behaviour: Messages email alerts (all) and quiet hours (instructor, chairperson, dean; `MessagePreferences`), and the parent per-child email choices (`ParentPreferences`, shown only for parents). The old Messages `GET/POST preferences` routes redirect to the new section; Messages links into Settings.
**Out of scope (later follow-up):** per-category email switches (announcements, grades, deadlines, groups) and a master switch.
**Rationale:** one place for "how Mentora contacts me" without touching other features' send paths.
**Alternatives:** add category switches; leave in place and link; a master switch.

## D16: Audit logging and owner-visible activity (approved 2026-11-23, Q15)
**Decision:** Through the existing `AuditLogger`:
- Logged with detail: password change, 2FA opt-in/out, "sign out everywhere", correction request submitted/decided, admin photo removal/upload, admin clearing a field or preferred name, home department set.
- Logged **without old/new values**: owner edits to preferred name, photo, pronouns, bio (and the D7 staff fields).
- Owner sees "Recent security activity" (last 90 days) in Account settings, read from those audit entries: password changed, 2FA changes, signed out everywhere, admin actions on their account, correction decisions. Security events also email the owner.
**Rationale:** moderation trail without retaining removed content; quiet, useful log.
**Alternatives:** security/admin actions only; full old/new values for everything.

## D17: Correction routing and email verification (approved 2026-11-23, Q16)
**Decision:**
- An institutional admin's own correction request goes to **another** institutional admin of the same institution; if none exists, to the platform's system admins (small queue view for them).
- A system admin edits their own identity fields directly; audit-logged and emailed.
- An approved **email** change does not take effect until the user clicks a verification link sent to the **new** address; the **old** address is notified; the pending change expires after 48 hours.
**Rationale:** nobody approves themselves; sign-in identity cannot be changed silently by a typo or hijack.
**Alternatives:** system admin requests to another system admin (single-admin deadlock); no email verification; email not correctable by request.
**Follow-up:** whether admin-initiated email edits on the People/Users screens also adopt the verification step.

## D18: The avatar is the entry point (approved 2026-11-23, user direction on Q17)
**Decision:** Names are **not** clickable. The avatar (photo or initials, D6) is the trigger:
- **Hover** the avatar → a small popover summary next to it, with a **"See more"** link that opens the full profile modal.
- **Click** the avatar directly → the full profile modal opens.
**Accessibility requirements recorded with it (not a product choice; flag if you disagree):** keyboard focus on the avatar shows the same popover and Enter opens the modal; on touch screens (no hover) a tap opens the modal directly; the popover is dismissable with Escape.
**Alternatives considered (Q17):** names clickable everywhere; names clickable in social places; avatars only without a hover summary.
**Open:** modal actions and the `/people/{id}` page (proposed in Q17, not yet confirmed); popover contents.

## D19: Avatars everywhere a person appears, except gradebook rows (approved 2026-11-23, Q18)
**Decision:** One shared avatar component (D6 + D18 behaviour) wherever a person is shown: header, dashboards, announcements/replies/composer, Grade Work, assessment and learning-item headers, course info, dean monitor, Messages, discussions (threads and replies), course rosters/member lists, group member lists, People screens, audit log, and every `components/data-table` avatar role at all widths (not only phone rows). **Excluded:** gradebook rows.
**Consequences:** touches views owned by other features; responsive screenshot baselines must be regenerated; visual regression checks per touched screen.
**Rationale:** consistent entry point; gradebook density kept.
**Alternatives:** existing avatars only; social places only; social places + data-table.

## D20: Popover, modal and `/people/{id}` (approved 2026-11-23, Q19; closes D18's open items)
**Decision (all within D3 visibility):**
- **Popover:** photo, preferred name (legal name beneath if different), pronouns, top role + detail (D12/D8); actions Message (if `canStart`, D4) and See more.
- **Modal:** header (large photo, names, pronouns, all roles with details); About (bio); staff fields (office, consultation hours, work phone); Contact (email + copy, student email staff-only); role details (student program/year/section, courses per D8; instructor courses this term); actions Message, Copy email; institutional-admin-only actions Remove photo, Clear field, Open in People.
- **Full page `/people/{id}`:** same content, same rules, rendered from **one shared partial** with the modal; used for links from notifications/emails, no-JS fallback, small phones.
**Alternatives:** modal only; own profile page only.

## D21: Own profile and Account settings layout (approved 2026-11-23, Q20)
**Decision:**
- **`/profile`:** the owner's D20 profile view with a pencil per editable section opening a small dialog (photo upload/crop, preferred name + pronouns, bio, staff info). Locked official fields show a lock and "Request a correction" (D5). A "Preview as: Student / Staff" toggle shows the D3 view.
- **`/settings`:** one scrolling page of section cards in this order: Password (D13), Two-step sign-in (D14), Sign out of other devices (D14), Notifications (D15), Recent security activity (D16). Desktop: a left jump list; phones: stacked cards with a collapsed "Jump to" menu.
- Design language: `layouts/app`, `page-head`, white rounded-2xl cards, dialogs full-screen on phones, all four states. Final visual mockups in Stage 2.
**Alternatives:** single edit form; inline editing; tabbed settings.

## D22: Admin side lives on the People screen (approved 2026-11-23, Q21)
**Decision:**
- `institution/people` gets a **Requests** tab (count badge); each correction request opens a dialog to approve / reject with a reason.
- **Home department** (D8) is a field in the existing edit-person dialog; imports map it when the source has it.
- Remove photo / upload on behalf / clear field: in the person's row menu **and** in the D20 modal, both calling one service.
- Bell notification to admins on each new request; bell + email to the requester on decision, linking to `/profile`.
- System admins: the same Requests list, filtered to D17 escalations, on the platform **Users** screen.
**Alternatives:** plus a dashboard card; a separate menu page.

## D23: Legacy pages and account menu (approved 2026-11-23, Q22 option B)
**Decision:**
- `student/profile` and `parent/profile` permanently redirect (301) to `/profile`; the parent side-menu "Profile" item points to `/profile`. Messages `preferences` redirects per D15.
- Student's `/profile` keeps an **owner-only** card "Parents who can see your progress" (same copy as today); never shown to others.
- Account menu for all roles: My Profile → `/profile`, Settings → `/settings`; **Help & Support stays visible but disabled, labelled "Coming soon"**.
**Alternatives:** remove Help & Support; make it a mailto to the institution contact email.

## D24: Preferred name and pronouns rules (approved 2026-11-23, Q23)
**Decision:**
- **Preferred name** replaces the **first name only** (shown as preferred first + legal last); legal name always visible beneath it in the modal. 2-50 chars; letters, spaces, hyphens, apostrophes, periods only; trimmed; a few changes per day max (rate limit).
- **Pronouns:** pick-list (she/her, he/him, they/them, prefer not to say) + "Custom…" free text up to 30 chars; optional; hidden when empty.
**Alternatives:** full free-text display name; pronouns free text only.

## D25: Deactivated people (approved 2026-11-23, Q24)
**Decision:** The avatar still opens the popover/modal and `/people/{id}`, showing a **"Deactivated"** badge and only photo/initials, name and roles; contact, bio, staff fields and the Message button are hidden. Institutional admins still see everything via the People screen.
**Alternatives:** greyed initials with no popover/modal; unchanged full profile.

## D26: Admin-initiated email changes (approved 2026-11-23, Q25; closes the D17 follow-up)
**Decision:** Email changes made by admins (People screen, Users screen) and by CSV/SIS imports stay **immediate**, but always send the D17 notice to the **old** address and are audit-logged (D16).
**Alternatives:** verification for on-screen admin edits only; verification for everything including imports (breaks the SIS sync model).

## D27: Profile completeness checklist (approved 2026-11-23, Q26)
**Decision:** A small checklist card on the owner's `/profile` listing missing self-editable fields (role-specific lists: staff include consultation hours and office; everyone photo, pronouns, bio). Each item opens its D21 edit dialog. The card disappears when all are done or when dismissed (dismissal stored as one additive flag). No percentage.
**Noted for the D11 track:** a one-time dashboard nudge for staff missing consultation hours.
**Alternatives:** none; dashboard nudge now; percentage meter.

## D28: Parents open their own children's profiles (approved 2026-11-23, Stage 2 S1; exception to D3)
**Decision:** A parent hovering/clicking the avatar of **their own linked child** gets the popover and modal as staff see it (including enrolled courses, which the portal already shows) but **without contact fields**. Other students remain invisible to parents.
**Alternatives:** avatar inert for parents.

## D29: Stage 2 consistency calls C1-C5 accepted (approved 2026-11-23)
C1 header avatar keeps opening the account menu; C2 impersonation already read-only (no work); C3 read-only institution: own security and notification settings usable, profile content and correction requests refused; C4 "same institution" uses the existing single-institution resolver; C5 system admin `/profile` shows name, email, role only. Details in `open-questions.md` history.

## D30: Build approach: server-rendered fragments fetched on demand (approved 2026-11-23, Stage 2 S2)
**Decision:** `components/avatar.php` (photo or initials) plus a matching small JS renderer for JS-drawn lists (Messages `render.js`). One shared delegated script handles hover/focus/click on any avatar and fetches server HTML: `/people/{id}/card` (popover) and the D20 profile partial (modal). One server-side visibility policy (pure, like `MessagingPolicy`) feeds the popover, modal and `/people/{id}` page. Loading skeleton on first hover; responses cached per page.
**Rationale:** one renderer for the profile (D20); nothing private reaches the browser unless allowed; no per-page cost.
**Alternatives:** JSON + client rendering (two renderers drift); inline data per avatar (N visibility checks per page, data in source).

## D31: Multi-role people in the visibility rules (approved 2026-11-23, builder check B1)
**Decision:** **Viewer:** their highest role held (D12 order) picks the FR2 row (an instructor who is also a parent views as staff). **Subject:** each part of the profile follows the role it belongs to: staff fields (office, hours, work phone, staff roles) are visible as for staff; student fields (courses, contact as a student) follow the student column. A student who also teaches shows office hours to classmates but not their student contact or course list.
**Rationale:** viewer: grants nothing their staff screens do not already show; subject: nothing about a student leaks because they also teach.
**Alternatives:** subject by highest role (exposes student contact to students); most-restrictive viewer role.

## D32: Message button follows the active role until the D10 track (approved 2026-11-23, builder check B2)
**Decision:** The popover/modal Message button shows exactly when `MessagingPolicy::canStart` allows it, which uses each person's active role. This is the **one accepted exception** to D9 / the "never reads `active_role`" anti-goal, until the single-identity track removes the switcher.
**Alternatives:** show when any role pair allows (button could promise a conversation Messages refuses).

## Implementation rulings (Stage 4, made by Claude on the user's "decide everything for me", 2026-11-24/25)
Each line: what was decided — why — what it costs if wrong.
- R1 Commits: per-task commits on each phase branch (user allowed Claude to commit). — review packages need them — squash before push if one commit per phase is preferred.
- R2 Avatar colours: 8 fixed colours, each ≥ 4.5:1 against white, chosen by id mod 8. — ui-ux "fixed accessible set" — a palette tweak.
- R3 FR2 field sets per viewer/subject written as an explicit table; deactivated people show basics only to viewers who could see them active; D31 email/courses follow the student column. — FR2 + D28/D31 + Review Focus 1/7 — one policy table edit.
- R4 Roles shown per viewer: the student role only with a student grant; the parent role only to staff or the person's own child. — D3/D9/D31 — one policy method.
- R5 A student's number is not part of what others see; only the owner's Official details show it. — FR2 lists program/year/section/college — add an owner-only field.
- R6 Legal name (with middle name) shows beneath the preferred name to everyone who may see names. — D24 — drop the middle name in display.
- R7 Parent profile page kept until Settings existed (Phase 5), then redirected. — each phase stays a working increment — none now.
- R8 Pronoun list = D24's four values + Custom ≤ 30; "prefer not to say" is stored but never displayed. — D24 — show it if wanted.
- R9 Preview = what an unrelated student of the same school sees ("Me / As a student sees it"); profile body split into Studies / Contact / Official details cards. — FR2 + mockup — layout tweak.
- R10 Modal admin row grew per phase: Open in People (needs people.view), photo actions and Clear a field (need people.manage); a deactivated person keeps Open in People for their own admin. — D20/D22/D25 — permission tweak.
- R11 Message button only when starting would really succeed (one shared check with Messages; two shared children → no button). — D32 rejected a button that fails — none.
- R12 Photos: one file id column (D6), 64 px copy found by name; square JPEG re-encode; served at /people/{id}/photo/{size} with no-cache + ETag; photos switch hides everywhere centrally. — D6, Review Focus 3 — storage tweak.
- R13 Settings: password throttle counts every attempt (5/15 min), reset link 3/15 min, sign-out-others 5/15 min, correction requests 10/hour; settings stay usable in a read-only school; quiet hours offered by any quiet role a person holds (not the active one). — D13/D14/D29-C3/NFR, D9 direction — limit values.
- R14 Email change: confirmation link page asks first, change happens on POST (mail scanners); a system admin's own name changes immediately, email via the link; anyone's own legal name/email can only change through the correction flow (People/Users self-edit locked). — D17/D26 — none.
- R15 Clearable fields: preferred name, pronouns, bio, office, consultation hours, work phone; home department only for instructor/chair/dean; imports map an optional home_department column; email-change and field-cleared notices go through the mail queue (D26 notice never muted). — D5/D8/D26 — field list.
- R16 Avatars: one gated lookup per page/response; system-admin pages show non-interactive avatars; dashboard at-risk avatar non-interactive (the row is a link); assessment byline = real author + date, learning item byline = "Instructor: {name}" (old hard-coded byline removed). — FR3/D19 — per-site tweaks.
- R17 Preferred names in social places only for viewers who may see the person (else legal); official exports and the gradebook keep legal names. — FR5/D24 privacy — per-site tweaks.
- R18 Remember-me: signing out everywhere/deactivation deletes remember tokens; a remember-me sign-in starts a fresh session clock (custom session authenticator); the current browser must sign in again after its session ends. — D13 security — a UX trade-off.
- R19 Responsive screenshot baselines are regenerated on CI only (not locally). — baselines are made on CI's Linux runner — the user runs the workflow.
- R20 Messages notification text (bell and email) is stored at send time with the name that recipient could see then; a later preferred-name or visibility change does not rewrite old notices. — FR2 + notifications are stored rows — none.
- Deferred (names only, no leak): the Messages places were done in F1 and the remaining pages in F2 (lists that show gated names now sort by them). Still open: a student's "classmates you can invite" list in their own group (legal names, no avatar); institution roster and section lists show gated names but keep their paged legal-name order — see test-strategy.md.
