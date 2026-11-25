# User Profile Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development with superpowers:test-driven-development. Steps use checkbox (`- [ ]`) syntax. **Claude never commits.** At the end of every phase: run the full verification, report, give a commit message, STOP and wait for the user to say `done committing`. Then branch from the previous phase branch.

**Goal:** One profile (`/profile`, `/people/{id}`, avatar popover and modal) and one Settings page for all seven roles, with institution-wide visibility rules, photos, correction requests and self-service security.

**Architecture:** A pure `ProfilePolicy` decides which fields a viewer sees from a `ViewContext` that `ProfileContextResolver` builds from the database; `ProfileReader` turns the allowed fields into a view model drawn by one partial (modal, page, popover). Avatars are one PHP component plus one JS renderer; a shared script fetches server-rendered popover and modal HTML on demand (D30). Settings reuse `AccountControl`, `PasswordResetService`, `AdminEmail2FA`, `MessagePreferences`, `ParentPreferences`, `AuditLogger`.

**Tech Stack:** CodeIgniter 4 + Shield (PHP 8), MySQL/MariaDB, Tailwind v4, vanilla JS + Vite, PHPUnit (`tests/database`, `tests/feature`, `tests/unit`), Vitest (`tests/js`), Playwright (`tests/e2e`).

**Spec:** `requirements.md` (FR1-FR10, AC1-AC10), `workflows.md`, `ui-ux-design.md`, `mockups/`, `decision-register.md` (D1-D32), `existing-implementation-audit.md`, all in this folder.

## Global Constraints
- Migrations are additive only (new nullable columns, new tables), named `2026-12-02-0000NN_*`, reversible. No column dropped or renamed, no row deleted or rewritten.
- The profile never reads `active_role` (sole exception: the Message button through `MessagingPolicy::canStart`, D32).
- Every profile read goes through `ProfilePolicy`; a hidden field is never in the HTML, JSON or a cached fragment for that viewer. A subject the viewer may not see answers 404 (not 403), so ids cannot be probed.
- Role order everywhere in the profile: `RoleRank::ORDER = ['dean', 'chairperson', 'instructor', 'institutional_admin', 'student', 'parent']`.
- Limits: preferred first name 2-50 chars `/^[\p{L}][\p{L} .'\-]*$/u`, at most 3 changes per 24 h; pronouns custom ≤ 30; bio ≤ 300 plain text; office ≤ 120; consultation hours ≤ 200; work phone ≤ 40; correction reason ≤ 500; one open correction per field per person; pending email change expires after 48 h; photo ≤ 5 MB, JPEG/PNG/WebP by content, stored at 256 and 64 px; password attempts: 5 per 15 min per user; recent activity window 90 days.
- Every security or admin action calls `AuditLogger::log('profile.<action>', $actor, $details)`; owner edits of public fields log without old/new values (D16).
- Writes to profile content and correction requests are refused in a read-only institution (`readonly` filter); `/settings` writes are allowed (add their paths to `ReadOnlyFilter`'s own-things list).
- UI: `layouts/app`, `components/page-head`, `components/dialog`, tokens only (`bg-brand`, `bg-brand-tint`, ...), rounded-2xl cards, rounded-full buttons; loading, empty, error, filled states; 280-2560 px; keyboard and touch per `ui-ux-design.md`. Copy in sentence case, verbs as in `ui-ux-design.md`.
- Tests run on the scratch MariaDB (see `test-strategy.md`). Playwright runs only where a phase says so and the e2e DB is available.

## Decisions made in this plan (user may overrule)
- **Storage (my call):** new nullable columns on `user_profiles`: `preferred_first_name`, `pronouns`, `bio`, `office_location`, `consultation_hours`, `work_phone`, `photo_file_id`, `home_department_id`, `two_factor_opt_in` (tinyint default 0), `checklist_dismissed_at`. New column `institutions.profile_photos_enabled` (tinyint default 1). New tables `profile_correction_requests` and `pending_email_changes`. Preferred-name change counting reads the audit log (no extra table).
- **Photos toggle location (my call):** a "Profile photos" switch on the existing Institution settings → Profile page.
- **2FA opt-out re-check (my call):** the form carries a password field checked with `ReauthService::confirm` (students and parents have no `reauth` page).
- **Rate limiting (my call):** CodeIgniter's built-in `Throttler` service, keyed per user and action.
- **Phase order:** foundation → own profile → viewing others → photos → settings → corrections and admin → avatar rollout → hardening. Each phase ships a working increment.

## Review Focus
1. A parent changes the id in `/people/{id}` or `/people/{id}/card` to a non-linked student or another institution's user: 404, no name in the body.
2. A user who holds instructor + parent, or student + instructor (D31), sees exactly the fields their highest role allows, and a dual-role subject leaks no student field to students.
3. A photo is opened by URL after the institution turned photos off, after the owner removed it, or by a viewer FR2 forbids: refused; turning photos back on restores the same file.
4. A preferred name made of symbols, emoji, a different surname, or 60 characters; or changed a fourth time in a day: refused with the exact rule in the message.
5. Password change from one browser while another is signed in: the other is signed out at its next request, the current one is not (`signed_in_at` refreshed after `signOutEverywhere`).
6. Email correction approved but the link is opened after 48 h, twice, or by a signed-in different user: the email does not change.
7. A deactivated person's avatar: basics only, no Message, no contact, even for staff (admins use People).

---

## File structure (new unless noted)
- Migrations `app/Database/Migrations/2026-12-02-0000NN_*.php`
- `app/Services/Profiles/`: `RoleRank`, `ViewContext` (readonly value object), `ProfilePolicy`, `ProfileContextResolver`, `ProfileReader`, `AvatarData`, `ProfileEditService`, `ProfilePhotoService`, `ProfileModeration`, `CorrectionRequestService`, `EmailChangeService`, `PasswordChangeService`, `TwoFactorPreference`, `SecurityActivity`
- `app/Controllers/Profiles/`: `ProfileController`, `PersonProfileController`, `AccountSettingsController`, `EmailChangeController`; `app/Controllers/Institution/ProfileRequestsController.php`
- Views: `app/Views/components/avatar.php`; `app/Views/pages/profile/` (`index.php`, `person.php`, `partials/profile-body.php`, `partials/popover.php`, `partials/dialogs/*.php`, `partials/checklist.php`); `app/Views/pages/settings/index.php` + `partials/*.php`; `app/Views/pages/institution/partials/people-requests.php`
- JS: `resources/js/shared/avatar.js` (renderer), `resources/js/shared/person-card.js` (popover/modal), `resources/js/pages/profile/*.js` (dialogs, crop), `resources/js/pages/settings/*.js`
- Modify: `app/Config/Routes.php`, `app/Filters/ReadOnlyFilter.php`, `app/Auth/Actions/AdminEmail2FA.php`, `app/Views/layouts/partials/header.php`, `app/Helpers/navigation_helper.php`, `app/Models/Users/UserProfileModel.php`, `app/Views/components/data-table.php`, `app/Services/Sis/SisImporter.php`, CSV people import, `UserAdminService`, `PeopleService`, People and Users views, every avatar site listed in FR3.2.

---

## Phase 1: Foundation (schema, rules, avatar component)
**Increment:** new columns and tables exist; the visibility rules and role order are implemented and tested; every existing placeholder photo is replaced by the avatar component showing initials. No new pages yet.

### Task 1.1: Schema
**Files:** migrations for the `user_profiles` columns, `institutions.profile_photos_enabled`, `profile_correction_requests(id, user_id, institution_id NULL, field ENUM first_name|middle_name|last_name|suffix|email|student_number, old_value, new_value, reason, status ENUM pending|approved|rejected|expired, routed_to ENUM institution|platform, decided_by NULL, decision_note NULL, decided_at NULL, timestamps, INDEX(status, institution_id))`, `pending_email_changes(id, user_id, request_id NULL, new_email, token_hash CHAR(64) UNIQUE, expires_at, confirmed_at NULL, created_at)`; extend `UserProfileModel::$allowedFields`. Test: `tests/database/ProfileSchemaTest.php`.
- [ ] Tests: `test_existing_profile_rows_survive_migration` (seed a row, migrate, values identical), `test_new_columns_default_null_or_zero`, `test_migrations_roll_back`. Run, see fail. Implement. Run, see pass.

### Task 1.2: RoleRank, ViewContext, ProfilePolicy
**Files:** the three classes. Test: `tests/unit/Profiles/ProfilePolicyTest.php`, `RoleRankTest.php`.
**Interfaces:** `RoleRank::sort(list<string> $roles): list<string>`, `RoleRank::highest(list<string> $roles): ?string`. `ViewContext(bool $isSelf, bool $sameInstitution, ?string $viewerRank, list<string> $subjectRoles, bool $subjectActive, bool $viewerIsParentOfSubject, bool $viewerIsChildOfSubject, bool $viewerIsInstitutionAdmin, bool $subjectIsSystemAdmin)`. `ProfilePolicy::fields(ViewContext $c): list<string>` over the keys `photo, names, pronouns, roles, bio, staff_info, email, student_details, courses, children, parents, admin_actions, deactivated_badge`; `[]` = not visible.
- [ ] Tests: one data-provider row per FR2 cell (staff/student/parent viewer × staff/student/parent/deactivated subject), plus self, system admin subject, other institution, parent→own child (no `email`), student→own parent, D31 cases (instructor+parent viewer = staff row; student+instructor subject: student viewer gets `staff_info` but not `email`/`courses`), `admin_actions` only for institutional admin. Run, see fail. Implement as a table, no DB. Run, see pass.

### Task 1.3: ProfileContextResolver, ProfileReader, AvatarData
**Files:** the three services; modify `UserProfileModel::retrieveUserProfile` to select the new columns. Test: `tests/database/ProfileReaderTest.php`.
**Interfaces:** `ProfileContextResolver::for(User $viewer, int $subjectId): ?ViewContext` (null when the subject does not exist); institution via `PlanLimits::institutionOf`. `ProfileReader::build(int $subjectId, list<string> $fields): array{id:int, display_name:string, legal_name:string, pronouns:?string, roles:list<array{role:string, detail:?string}>, ...only allowed keys}`; instructor department = `home_department_id`, else the department with most current-term offerings labelled `teaches_in`, else none. `AvatarData::forMany(list<int> $userIds): array<int, array{id:int, name:string, initials:string, colour:string, photo_url:?string}>` in one query (photo_url null until Phase 4).
- [ ] Tests: built model never contains a key outside `$fields`; display name = preferred first + legal last; roles sorted by `RoleRank`; teaches-in fallback; `forMany` issues one query for 40 ids. Implement. Pass.

### Task 1.4: Avatar component and header/dashboard swap
**Files:** create `components/avatar.php` (params `person` from `AvatarData`, `size` xs|sm|md|lg|xl, `interactive` bool default false), `resources/js/shared/avatar.js` (`renderAvatar({id, name, initials, colour, photoUrl}, size): HTMLElement`); modify `header.php`, `dashboard/partials/profile-card.php`, `dashboard/partials/chairperson/profile-card.php` to use it. Test: `tests/feature/AvatarComponentTest.php`, `tests/js/shared/avatar.test.js`.
- [ ] Tests: initials from first and last word; colour stable for an id and from the fixed set; `alt="Photo of {name}"` only when a photo exists; header still opens the account menu (C1). Implement. Pass. Existing header/dashboard tests still pass.

**Phase 1 done when:** new tests + full `phpunit` + `vitest` pass; existing profile rows unchanged (Task 1.1 test); header and dashboard render initials.

---

## Phase 2: My profile (owner)
**Increment:** every role opens `/profile` from the account menu and edits the optional fields; legacy student/parent profile URLs redirect.

### Task 2.1: Routes, controller, page
**Files:** `ProfileController::index`; routes `GET profile` (`session` filter) ; `pages/profile/index.php`, `partials/profile-body.php` (shared later by modal/page), `partials/checklist.php`; header account menu: My Profile → `/profile` for every role, Help & Support disabled "Coming soon", Settings stays inert until Phase 5 links it; `navigation_helper.php` parent Profile item → `profile`; 301s from `student/profile`, `parent/profile` (parent notifications/password-link routes keep working until Phase 5). Test: `tests/feature/ProfilePageTest.php`.
- [ ] Tests: each of the 7 roles gets 200 on `/profile`; system admin sees name/email/role only (C5); student sees "Parents who can see your progress"; parent sees their children; locked official fields show "Managed by your school"; old URLs 301 to `/profile`. Implement. Pass.

### Task 2.2: Edit dialogs
**Files:** `ProfileEditService::update(User $owner, string $section, array $input): void` with sections `identity` (preferred_first_name, pronouns), `about` (bio), `staff` (office_location, consultation_hours, work_phone; staff roles only); `ProfileController::update($section)` `POST profile/(identity|about|staff)` with `readonly` filter; dialogs `partials/dialogs/{identity,about,staff}.php`; JS `resources/js/pages/profile/dialogs.js`. Test: `tests/database/ProfileEditServiceTest.php`, `tests/feature/ProfileEditRoutesTest.php`.
- [ ] Tests: every limit in Global Constraints incl. the 4th preferred-name change in 24 h; student posting `staff` refused; pronoun list values and Custom; empty values clear the field; audit row `profile.updated` without old/new; read-only institution refuses; CSRF missing refused. Implement. Pass.

### Task 2.3: Checklist and preview
**Files:** `POST profile/checklist/dismiss`; preview toggle renders `profile-body` with the `ViewContext` of a student viewer of the same institution. Test: add to `ProfilePageTest`.
- [ ] Tests: staff checklist lists photo, consultation hours, office, pronouns, bio when missing; student list excludes staff items; dismissed stays hidden; preview hides `email`/`courses` for a student subject. Implement. Pass.

**Phase 2 done when:** new + full suites pass; `/profile` matches `mockups/profile-and-modal.html` left side at 360/768/1440 px (screenshots in the report).

---

## Phase 3: Seeing other people
**Increment:** clicking or hovering an avatar (where the component is interactive) opens the popover and modal; `/people/{id}` works.

### Task 3.1: Endpoints
**Files:** `PersonProfileController::show($id)` (`GET people/(:num)` page), `card($id)` (`GET people/(:num)/card`, popover HTML), `panel($id)` (`GET people/(:num)/panel`, modal HTML), all `session` filter; views `pages/profile/person.php`, `partials/popover.php`; Message button when `MessagingPolicy::canStart` allows (via `MessagingContextResolver::forStart`). Test: `tests/feature/PersonProfileRoutesTest.php`.
- [ ] Tests: AC2 matrix through HTTP for all three endpoints (assert hidden values absent from the body); 404 for other institution, parent→non-linked student, nonexistent id, system admin subject; deactivated shows the badge, no Message and no contact even to staff; Message absent when `canStart` refuses; admin row (Remove photo, Clear field, Open in People) only for the subject's institutional admin. Implement. Pass.

### Task 3.2: person-card.js
**Files:** `resources/js/shared/person-card.js` (delegated on `[data-person-avatar]`: hover/focus after 300 ms → fetch card, cache per id; click/Enter/See more → fetch panel into `components/dialog`; touch → panel directly; Escape closes; focus returns to the avatar; retry on error); `components/avatar.php` `interactive=true` adds `data-person-avatar`, `role="button"`, `aria-haspopup="dialog"`, `aria-label="Open {name}'s profile"`. Test: `tests/js/shared/person-card.test.js`.
- [ ] Tests: one fetch per id per page; error state with retry; touch skips popover; Escape closes and restores focus. Implement. Pass.

### Task 3.3: First interactive sites
**Files:** discussions `thread-row.php`, `thread-body.php`, `reply.php`; announcements `announcement-card.php`, `reply.php`; course info instructor. Test: extend their feature tests.
- [ ] Tests: avatars on those pages carry `data-person-avatar` for visible people and none for invisible ones. Implement. Pass. Playwright `tests/e2e/profile-card.spec.js` (hover, click, keyboard, 360 px, axe) if the e2e DB is available.

**Phase 3 done when:** new + full suites pass; popover and modal match the mockup.

---

## Phase 4: Photos
**Increment:** owners upload, replace and remove photos; admins remove or upload for someone; institutions turn photos off and on.

### Task 4.1: ProfilePhotoService and serving
**Files:** `ProfilePhotoService::upload(int $subjectId, UploadedFile $file, User $actor): void`, `remove(int $subjectId, User $actor): void`, `url(int $subjectId, int $size): ?string`; follow `InstitutionProfileService::setLogo` (getimagesize, re-encode with CI4 Image/GD to strip metadata, store through `FileModel`); route `GET people/(:num)/photo/(64|256)` checks `ProfilePolicy` field `photo` and the institution switch; `AvatarData` fills `photo_url`. Test: `tests/database/ProfilePhotoServiceTest.php`, `tests/feature/ProfilePhotoRoutesTest.php`.
- [ ] Tests: PNG renamed `.jpg` accepted by content, a text file renamed `.png` refused; > 5 MB refused; output has no EXIF; both sizes stored; replace keeps one current file; Review Focus 3 cases; audit rows (`profile.photo_removed_by_admin` with actor). Implement. Pass.

### Task 4.2: Upload dialog with crop, institution switch, admin actions
**Files:** `partials/dialogs/photo.php`, `resources/js/pages/profile/photo-crop.js` (square crop on a canvas, no new dependency); `POST profile/photo`, `POST profile/photo/remove`; Institution settings Profile page switch (`InstitutionProfileService::saveProfile` gains `profile_photos_enabled`); `ProfileModeration::removePhoto/uploadPhoto` with `POST institution/people/(:num)/photo[/remove]` (`permission:people.manage`); owner notice (bell + email + activity). Test: `tests/feature/ProfilePhotoFlowTest.php`, `tests/js/pages/profile/photo-crop.test.js`.
- [ ] Tests: Off hides every photo URL and keeps files; On restores; photo control explains Off; admin of another institution refused. Implement. Pass.

**Phase 4 done when:** new + full suites pass.

---

## Phase 5: Settings
**Increment:** `/settings` with Password, Two-step sign-in, Signed-in devices, Notifications, Recent activity; old preference pages redirect.

### Task 5.1: Page and password
**Files:** `AccountSettingsController::index`, `GET settings`; `pages/settings/index.php` + partials; header Settings link; `PasswordChangeService::change(User $user, string $current, string $new, string $confirm): void` (Shield password check, `InstitutionSecurity::forUser()['passwordMinLength']`, Throttler 5/15 min, then `AccountControl::signOutEverywhere` and set `session(AccountStateFilter::SIGNED_IN_AT)` to now + 1 s, audit `profile.password_changed`, email notice); `POST settings/password`; `POST settings/password-link` → `PasswordResetService::send`. Test: `tests/database/PasswordChangeServiceTest.php`, `tests/feature/SettingsPasswordTest.php`.
- [ ] Tests: wrong current refused; too short per institution refused; 6th attempt throttled; Review Focus 5 (two sessions); email notice queued; link still works. Implement. Pass.

### Task 5.2: Two-step sign-in and sign-out elsewhere
**Files:** `TwoFactorPreference::status(User $u): array{on:bool, required:bool, email_masked:string}`, `optIn(User $u): void`, `optOut(User $u, string $password): void`; modify `AdminEmail2FA::appliesTo` to `required || two_factor_opt_in`; `POST settings/two-factor`, `POST settings/sign-out-others`. Test: `tests/database/TwoFactorPreferenceTest.php`, extend `AdminEmail2FA` tests.
- [ ] Tests: opt-in makes `appliesTo` true; required user cannot opt out; opt-out needs the right password; sign-out-others keeps current session; both audited and emailed. Implement. Pass. Existing 2FA tests unchanged.

### Task 5.3: Notifications and recent activity
**Files:** settings partial reusing `MessagePreferences::get/save` and `ParentPreferences`; `POST settings/notifications`; 301 `GET preferences` → `settings#notifications`; remove the old Messages preferences page link in favour of Settings; parent `profile/notifications` and `profile/password-link` POST routes 301/redirect into Settings; `SecurityActivity::recent(int $userId, int $days = 90): list<array{action:string, label:string, at:string}>` from `audit_logs`; `ReadOnlyFilter` own-things list gains `settings/*`. Test: `tests/feature/SettingsNotificationsTest.php`, `tests/database/SecurityActivityTest.php`.
- [ ] Tests: AC8 (values identical before and after); quiet hours only for instructor/chair/dean; parents see per-child switches; activity shows only own security events within 90 days; read-only institution still saves settings. Implement. Pass.

**Phase 5 done when:** new + full suites pass (incl. existing Messages and parent preference tests); Settings matches `mockups/settings.html`.

---

## Phase 6: Corrections and admin tools
**Increment:** correction requests end to end, email verification, People Requests tab, home department, field clearing, email-change notices.

### Task 6.1: CorrectionRequestService and EmailChangeService
**Files:** both services. `CorrectionRequestService::submit(User $owner, string $field, string $newValue, string $reason): int`, `approve(int $id, User $admin, ?string $note): void`, `reject(int $id, User $admin, string $note): void`, `pendingFor(User $admin): list<array>`, `routeOf(User $owner): string` (institution, or platform when the owner is the only active institutional admin or none exists); `EmailChangeService::start(int $userId, string $newEmail, ?int $requestId): void` (token, 48 h, old-address notice), `confirm(string $token, ?User $signedIn): bool`; admin approval of non-email fields writes `user_profiles`/student number with the existing uniqueness check from `SisImporter::numberTaken` logic. Test: `tests/database/CorrectionRequestServiceTest.php`, `EmailChangeServiceTest.php`.
- [ ] Tests: one open request per field; admin cannot decide own request; routing rules (FR7.2 + no-admin institution); reject needs a note; approval audited and notified (bell + email); student number taken refused; Review Focus 6; SIS-linked flag exposed for the warning. Implement. Pass.

### Task 6.2: Owner dialog, Requests tab, escalations, email link
**Files:** `partials/dialogs/correction.php`; `POST profile/corrections`; `Institution\ProfileRequestsController` (`GET institution/people/requests`, `POST .../(:num)/approve|reject`, `permission:people.manage`, `readonly`); `people.php` tab + badge + `partials/people-requests.php`; platform Users screen escalation list (`Admin\UsersController`); `EmailChangeController::confirm` `GET email-change/(:segment)` (public, token is the proof, like `reset-password`). Test: `tests/feature/ProfileCorrectionFlowTest.php`.
Owner side shows "Waiting for your school" on a field with an open request. A system admin's `/profile` edits their own identity fields directly (`POST profile/identity-official`, admin group only, audited and emailed, FR7.6).
- [ ] Tests: W4 end to end through HTTP; "Waiting for your school" shown; badge count; foreign institution request 404; system admin sees only escalations; system admin direct edit audited, any other role refused on that route. Implement. Pass.

### Task 6.3: Home department, clearing, email notices
**Files:** People edit dialog + `PeopleService::update` gain `home_department_id` (department of the same institution only); `SisImporter` and CSV people import map `home_department` when present; `ProfileModeration::clearField(int $subjectId, string $field, User $admin)` with `POST institution/people/(:num)/clear` and modal admin row; `UserAdminService::update` / `PeopleService::update` / importers send the old-address notice on email change (D26). Test: extend `PeopleServiceTest`, `SisImporterTest`, CSV upload tests; `tests/database/ProfileModerationTest.php`.
- [ ] Tests: home department replaces "Teaches in"; foreign department refused; import mapping; clear writes audit with actor and notifies owner; admin/import email change sends the notice; existing import tests unchanged. Implement. Pass.

**Phase 6 done when:** new + full suites pass (incl. existing SIS, CSV and People tests); screens match `mockups/requests-and-variants.html`.

---

## Phase 7: Avatar rollout (D19)
**Increment:** interactive avatars everywhere a person appears except gradebook rows.

### Task 7.1: data-table person avatars
**Files:** `components/data-table.php`: optional row key `person` (`AvatarData` entry) renders `components/avatar` in the primary cell at all widths; callers `institution/people.php`, `institution/audit.php`, `admin/audit-log.php`, `admin/institutions/audit.php`, Users screen. Test: extend data-table and those pages' feature tests.
- [ ] Tests: rows with `person` show the avatar at md+ and in phone rows; rows without are unchanged. Implement. Pass.

### Task 7.2: Remaining PHP sites
**Files:** announcements composer, Grade Work header, assessment `view-header.php`, `learning-item-header.php`, `dean/monitor-college.php`, `dashboard/partials/at-risk-students.php`, group member lists (`groups/partials/*`), course rosters (`institution/roster.php`, course member lists). Not `grades/roster.php`. Test: extend each page's feature test.
- [ ] Tests: each site has interactive avatars; `grades/roster.php` has none. Implement. Pass.

### Task 7.3: Messages (JS-drawn)
**Files:** `resources/js/pages/messages/render.js` uses `renderAvatar` with `interactive`; Messages JSON adds `avatar` (`AvatarData`) per participant. Test: extend `tests/js/pages/messages/*`.
- [ ] Tests: participants render interactive avatars; existing Messages tests pass. Implement. Pass. Regenerate responsive screenshot baselines (Actions → Responsive → update_snapshots) and list changed screens in the report.

### Task 7.4: Preferred name in social places (FR5)
**Files:** helper `person_display_name(array $profileRow): string` (preferred first + legal last, else legal) in `app/Helpers/navigation_helper.php`; use it where names are shown in discussions, announcements, Messages (`ConversationPresenter`), group lists, course rosters, header, popover/modal. Official places keep the legal name: `GradeSheetExportService`, `SisGradeExporter`, gradebook (`grades/roster.php`), institution exports. Test: `tests/unit/Profiles/DisplayNameTest.php`, extend the touched pages' tests and the export tests.
- [ ] Tests: social pages show "Jun Dela Cruz" for preferred "Jun"; grade sheet export and SIS export still show "Juan Dela Cruz". Implement. Pass.

**Phase 7 done when:** new + full suites pass; responsive smoke passes; baseline changes listed.

---

## Phase 8: Hardening and documentation
**Increment:** proof that AC1-AC10 hold; docs updated.

### Task 8.1: Security sweep
- [ ] Route sweep test `tests/feature/ProfileRouteSecurityTest.php`: every new route × (anonymous, each role, other institution, impersonation, read-only institution, missing CSRF) returns the expected status.
- [ ] Run `security-review` on the branch diff; fix findings with tests.

### Task 8.2: Journeys and docs
- [ ] Playwright `tests/e2e/profile.spec.js` W1-W13 at 360/768/1440 with axe (if the e2e DB is available; otherwise record NOT RUN).
- [ ] Update `existing-implementation-audit.md` (retired items), `docs/messages.md` (preferences moved), user-facing help text where pages are referenced.

**Phase 8 done when:** full `phpunit`, `vitest`, responsive smoke and (if runnable) Playwright pass; AC1-AC10 each mapped to passing tests in `test-strategy.md`.

---

## Branches
`feat/user-profile` (docs; the user commits it first) → `feat/user-profile-p1-foundation` → `-p2-my-profile` → `-p3-people` → `-p4-photos` → `-p5-settings` → `-p6-corrections` → `-p7-avatars` → `-p8-hardening`, each from the previous committed phase.
