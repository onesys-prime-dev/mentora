# User Profile: requirements and design

Status: **draft for approval** (Stage 2), builder-checked 2026-11-23. Source of truth for every decision: `decision-register.md` (D1-D32). This document consolidates them; where they differ, flag it, do not pick silently.

Companion docs: `workflows.md` (step-by-step flows per role), `ui-ux-design.md` (screens, states, copy; approved mockups in `mockups/`), `existing-implementation-audit.md`.

## 1. Intent

Mentora has a read-only student profile, a parent profile, and nothing for the other five roles. Everyone shows the same placeholder photo, and names lead nowhere. The aim is one consistent profile and settings experience for all seven roles, in the student/instructor design language, that lets people in a school recognise and reach each other (Microsoft Teams style) while keeping official records authoritative and students' data protected.

Success looks like: every role has a working My Profile and Settings; any avatar in the app opens a profile; nobody sees a field D3 does not allow; official names stay in sync with the registrar; no existing account, profile row or preference is lost.

## 2. Goals, non-goals, anti-goals

**Goals:** see §4.

**Non-goals (confirmed out of scope):** account deletion, self-deactivation, personal data export; authenticator-app 2FA; a device/session list; per-category email switches or a master email switch; a photo approval mode; a report button; Help & Support content.

**Belongs to other tracks:** institution-wide Messages (D4); removing the role switcher and redesigning chairperson, dean and parent screens (D10, D11). This feature must work unchanged whether or not those tracks ship.

**Anti-goals (failure even if it works):**
- A student's contact details, or any student's profile, reachable by a parent who is not linked to them, or by anyone outside the institution.
- A profile field, photo or preference lost through a migration, a toggle or a redeploy.
- Screens that function but look like the plain admin layout (memory rule: UI/UX equal weight).
- The profile reading or depending on `active_role` (sole exception: the Message button, D32).

## 3. Constraints

- CodeIgniter 4 + Shield; PHP views with `layouts/app` and `app/Views/components/*`; Tailwind tokens in `resources/css/app.css` (brand `#0040A6`, Poppins / Parkinsans); JS modules in `resources/js`.
- Migrations are **additive only** (new nullable columns or new tables). No column dropped or renamed, no row deleted.
- SIS import (`SisImporter`) keeps overwriting legal name, email and student number.
- Existing behaviour reused, not rewritten: `PasswordResetService`, `AccountControl::signOutEverywhere()`, `AdminEmail2FA`, `InstitutionSecurity`, `MessagingPolicy::canStart`, `MessagePreferences`, `ParentPreferences`, `AuditLogger`, `FileModel` / `files`, `ImpersonationFilter`, `ReadOnlyFilter`, in-app notifications.
- Tests: PHPUnit (feature + unit) and Playwright e2e; local DB is the scratch MariaDB on :3307 (see Group Activities `test-strategy.md`).

## 4. Functional requirements

### FR1 Pages and navigation (D1, D21, D23, D29-C1, C5)
1. `GET /profile` for every signed-in role: the owner's own profile (FR4 content plus owner-only parts).
2. `GET /settings` for every role: Password, Two-step sign-in, Signed-in devices, Notifications, Recent activity, in that order; jump list on desktop, "Jump to" menu on phones.
3. Account menu for all roles: My Profile → `/profile`, Settings → `/settings`, Help & Support visible but disabled with "Coming soon". The header avatar keeps opening the account menu.
4. `student/profile`, `parent/profile` → 301 to `/profile`; Messages `GET /preferences` → 301 to `/settings#notifications` (its POST is retired once the form moves); the parent side-menu Profile item points to `/profile`.
5. System admin `/profile` shows name, email, role only; `/settings` complete.

### FR2 Who sees what (D3, D25, D28, D29-C4)
A single server-side visibility policy decides, for (viewer, subject), which fields are visible. Rules:

| Subject → / Viewer ↓ | Staff (instructor, chair, dean, inst. admin) | Student | Parent | Deactivated anyone |
|---|---|---|---|---|
| Staff | all fields | all incl. contact and courses | name, photo, role only | basics only |
| Student | all staff fields | photo, names, pronouns, bio, program, year, section, college; **no** contact, **no** courses | **own linked parents only** (D3): name, photo, role | basics only |
| Parent | all staff fields | **own linked children only**: as staff see them **minus contact** | not visible (except self) | basics only |
| System admin | not a viewer here (uses admin screens) | | | |

- "Same institution" uses the existing single-institution resolver; nobody sees anyone outside it.
- **Several roles (D31):** the viewer's row is their highest role held (D12 order). For the subject, each field follows the role it belongs to: staff fields and staff roles per the Staff column, student fields (courses, contact as a student) per the Student column.
- "Basics" = photo or initials, names, roles, "Deactivated" badge; no contact, bio, staff fields or Message.
- The owner always sees all their own fields. Institutional admins additionally get admin actions (FR9).
- The system admin is never visible to institution members.

### FR3 Avatar entry point (D18, D19, D30)
1. One PHP component `components/avatar.php` (photo or initials; initials colour stable per person) and a matching JS renderer for JS-drawn lists (Messages).
2. Used everywhere a person appears **except gradebook rows**: header (menu only, C1), dashboards, announcements/replies/composer, Grade Work, assessment and learning-item headers, course info, dean monitor, Messages, discussions (threads and replies), course rosters/member lists, group member lists, People screens, audit log, and every `components/data-table` avatar at all widths.
3. Hover or keyboard focus → popover next to the avatar; click, Enter, or "See more" → full profile modal; touch devices skip the popover (tap opens the modal); Escape closes. Popover and modal content is fetched from the server on first use and cached for the page; a skeleton shows while loading.
4. A viewer who may not see the subject (FR2) gets a non-interactive avatar.

### FR4 Profile content (D7, D8, D9, D12, D20, D24)
- **Popover:** photo, preferred name (legal name beneath if different), pronouns, top role with detail; Message (only if `canStart`), See more.
- **Modal and `/people/{id}` page** (one shared partial): header (large photo, names, pronouns, every role with detail, ordered dean → chairperson → instructor → institutional admin → student → parent; parent role shown to staff only); About (bio); staff: office location, consultation hours, work phone; Contact: email + Copy; role details (student: program, year level, section, college, courses this term [staff, self, own parent only]; instructor: home department and courses this term; chair/dean: department/college led; institutional admin: institution); actions Message, Copy email; admin actions (FR9).
- Instructor home department: the admin-set value; if unset, the department they teach most in the current term, labelled "Teaches in …"; if neither, omitted.
- The profile never reads `active_role`, with one accepted exception (D32): the Message button uses `canStart`, which works from active roles, until the single-identity track removes the switcher.

### FR5 Owner editing (D5, D7, D21, D24, D27, D29-C3)
Owner edits via a pencil per section opening a small dialog:
- Photo (FR6); preferred name (replaces first name only; 2-50 chars; letters, spaces, `-`, `'`, `.`; trimmed; at most a few changes per day); pronouns (she/her, he/him, they/them, prefer not to say, or Custom ≤ 30 chars); bio (plain text ≤ 300 chars); staff only: office location, consultation hours (free text), work phone.
- All optional. Legal name, email, student number: locked with "Managed by your school" and "Request a correction" (FR7).
- Preferred name shows in social places (popover, modal, discussions, messages, groups, rosters, header); official places (grade sheets, exports, SIS export, gradebook) keep the legal name.
- Checklist card on `/profile` listing missing optional fields (role-specific); each item opens its dialog; hidden when complete or dismissed (dismissal persisted).
- "Preview as: Me / As a student sees it" toggle. *(Claude's call: follows the approved mockup, which replaced D21's "Student / Staff" wording; staff see everything the owner does, so a staff preview adds nothing.)*
- Students: owner-only card "Parents who can see your progress" (today's copy). Parents: owner-only list of linked children.
- Read-only institution: profile content edits and correction requests refused (existing `ReadOnlyFilter` behaviour); Settings stays usable.

### FR6 Photos (D6)
- Institution setting "Profile photos": **On** (default) / **Off**. Off shows initials everywhere but keeps every stored photo; On restores them.
- Upload: square crop in the browser; server accepts JPEG/PNG/WebP only, checks the real content type, enforces a size cap, re-encodes (removing metadata), stores 256px and 64px versions through `files`; replace and remove supported.
- Photos are served only through an authorised route applying FR2; never a guessable public URL.
- Institutional admins can remove a photo or upload one on someone's behalf (FR9).

### FR7 Correction requests (D5, D17, D22, D26)
1. Owner submits field (first/middle/last name, suffix, email, student number), new value, reason.
2. Routed to the institution's institutional admins; an institutional admin's own request goes to **another** institutional admin, or to the platform's system admins if none. *(Claude's call, builder check:)* any request from an institution that has no active institutional admin also goes to the system admins.
3. Admin approves or rejects (reason required on reject). For SIS-linked people the dialog warns the next import will revert the change unless fixed in the SIS.
4. Approval writes the value and is audit-logged. **Email:** approval sends a verification link to the new address; the change happens only when it is opened; the old address is notified; pending change expires after 48 hours.
5. Requester notified by bell and email on decision. Admins notified by bell on each new request.
6. A system admin edits their own identity fields directly (audit-logged and emailed).
7. Admin email edits (People, Users screens) and imports stay immediate but always notify the old address and are audit-logged.

### FR8 Settings: security and notifications (D13, D14, D15, D16)
- **Password:** current, new, confirm; new password validated against the institution's minimum length and the platform floor; strength meter; current-password attempts rate-limited; on success: other sessions ended (current kept), audit-logged, email notice. "Forgot your current password? Email me a link" uses `PasswordResetService`. The parent-only password-link button is retired.
- **Two-step sign-in:** status (On/Off; "Required by your school" when the role is in the institution's `twoFactorRoles` or a platform role; masked email). Users may opt in; cannot turn off a requirement; turning their own opt-in off asks for the password. `AdminEmail2FA::appliesTo` = required OR opted in.
- **Signed-in devices:** "Sign out of all other devices" (keeps the current session).
- **Notifications:** existing Messages email alerts and quiet hours (instructor, chair, dean), existing parent per-child email choices (parents only); behaviour unchanged.
- **Recent activity:** last 90 days of the owner's security events from the audit log (password changed, 2FA changes, signed out everywhere, admin actions on the account, correction decisions). Security events also email the owner.

### FR9 Admin side (D6, D8, D22)
- `institution/people` gets a **Requests** tab with a count badge; Review dialog per FR7.
- Edit-person dialog gains **Home department**; SIS/CSV imports map it when the source has it.
- Row menu and the profile modal: Remove photo, Upload photo, Clear field (preferred name, pronouns, bio, staff fields), Open in People. One service behind both.
- Platform Users screen: Requests list filtered to escalations (FR7.2).

### FR10 Audit (D16)
- Logged with detail: password change, 2FA opt-in/out, sign-out-everywhere, correction submitted/decided, admin photo remove/upload, admin field clear, home department set, admin/import email change.
- Logged without old/new values: owner edits to preferred name, photo, pronouns, bio, staff fields.

## 5. Non-functional requirements

- **Security:** every new route behind auth; every profile read goes through the FR2 policy; every write checks ownership or the admin permission (`people.manage`); CSRF on all forms; impersonation stays read-only (existing filter); uploads validated server-side; rate limits on password checks, preferred-name changes and correction submissions.
- **Privacy:** no field outside FR2 is ever in a response, a page source or a cached fragment for that viewer; photos authorised per request.
- **Data preservation:** additive migrations only; existing `user_profiles`, `message_preferences` and parent preference rows untouched; Photos Off never deletes files.
- **Performance:** pages do no per-avatar profile queries; popover/modal fetched on demand and cached per page; the popover endpoint answers within the app's normal page budget.
- **Accessibility:** keyboard reachable avatars, visible focus, Escape closes, dialogs trap focus, contrast per the existing tokens, alt text on photos, touch fallback.
- **Responsive:** 280-2560px per the responsive spec; dialogs full-screen on phones; data-table avatars at all widths (screenshot baselines regenerated).
- **Design:** `layouts/app`, `page-head`, white rounded-2xl cards, rounded-full buttons; all four states (loading, empty, error, filled) for every new screen.

## 6. Acceptance criteria (feature level)

1. Each of the 7 roles opens `/profile` and `/settings` from the account menu; old student/parent profile and Messages preferences URLs redirect.
2. For every cell of the FR2 table, plus the D31 multi-role cases (instructor + parent viewer; student + instructor subject), there is a passing test proving the visible and hidden fields, for the popover, modal and `/people/{id}`.
3. A parent cannot open a non-linked student, by avatar or by URL; nobody can open someone from another institution.
4. Photo upload rejects a renamed non-image, strips metadata, and the photo URL refuses viewers FR2 forbids. Photos Off then On restores the same photos.
5. A correction for a SIS-linked student shows the warning; approval updates the record and the audit log; an email correction only takes effect after the new-address link.
6. Password change ends other sessions but not the current one, and sends the notice; the forgot link still works.
7. A user can opt in to two-step sign-in and gets a code at the next login; a school-required user cannot turn it off.
8. Existing message and parent email preferences show the same values in Settings as before the migration.
9. Avatars appear in every listed place except gradebook rows; hover/focus/click/touch behave per FR3.
10. No existing PHPUnit or e2e test regresses (known pre-existing failures excepted and listed).

## 7. Approaches (chosen in Stage 2, D30)

- **A. Server-rendered fragments fetched on demand (chosen).** PHP draws the profile once (one partial for modal and page); a small shared script fetches it on hover/click; one visibility policy. Keeps private data out of pages; no per-page cost.
- B. JSON + browser rendering: the profile drawn twice (PHP page, JS modal), which drifts.
- C. Inline data on every avatar: visibility computed for every person on every page; data in page source.

## 8. Retain / redesign / replace / retire

| Existing | Fate |
|---|---|
| `user_profiles` table and rows | **Retain**, extend with nullable columns |
| `UserProfileModel::retrieveUserProfile`, `current_user_profile()` | **Retain**, extend (photo, preferred name) |
| `UserProfileModel::teachingHomeColumns` ("first course ever") | **Replace** in the profile by D8's home department + current-term fallback; other callers (dashboard card) decided in the plan |
| `StudentProfileController`, `pages/student/profile.php` | **Replace** by `/profile`; route redirects |
| `ParentProfileController` + view (notifications, password link) | **Replace**: content split into `/profile` and `/settings`; routes redirect |
| `Messaging\PreferenceController` page | **Replace** by the Settings section; route redirects; `MessagePreferences` service retained |
| Hard-coded `placeholder/profile.png` (header, dashboard cards, others) | **Replace** by `components/avatar.php` |
| Inert account menu items | **Redesign** per FR1.3 |
| `PasswordResetService`, `AccountControl`, `AdminEmail2FA`, `InstitutionSecurity`, `ParentPreferences`, `AuditLogger` | **Retain**, small extensions only |

## 9. Left to the builder (decide in the plan, flag anything that changes behaviour)

- Table/column shapes: photo file id, preferred name, pronouns, bio, staff fields, home department, 2FA opt-in, checklist dismissal, correction requests, pending email changes.
- Photo size cap value and image library (CI4 Image / GD).
- Rate-limit numbers.
- Exact list of social vs official name places (start from FR5).
- Whether the dashboard profile cards and other `teachingHomeColumns` callers move to the new home department.
- Phase order and split.
