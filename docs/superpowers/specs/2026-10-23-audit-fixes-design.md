# Audit fixes: design

Source of truth for behaviour: `docs/audit-2026-10-23-decisions.md` (findings 1–16, U1–U5). The audit itself: `docs/audit-2026-10-23.md`. This document says how to build it. It is not a task plan.

Mentora is in development; no real institution uses it. Existing data that breaks a new rule is fixed automatically by migration, never kept for review (the decisions' standing rule).

Marked **[decided]** = the user decided it in this design session. **[my call]** = settled by the designer from the code; the builder may revisit without changing what gets built.

---

## 0. Decisions made in this design session (additions to the decisions file)

| # | Decision | Affects |
|---|---|---|
| D1 | One account belongs to exactly one institution. The system admin belongs to none. A parent with children at two schools uses two accounts. | 8.1, 10.2, 10.3, U1 |
| D2 | Each role is offered only the permissions that do something for it, on both permission pages. | 7.1, U1 |
| D3 | A dean may sign off a section they teach. | 2.x |
| D4 | An institution admin may not sign off a section they teach; another institution admin must. One-admin institution: it waits, with the 1.6-style message. | 2.1, 2.4 |
| D5 | The deploy installs the six scheduled jobs itself, then verifies them; it fails only if that fails. | **Replaces 6.3's wording** |
| D6 | New permission `discussions.manage` ("Moderate course discussions": pin, lock, delete others' posts, mark the answer). Default: instructor, chairperson. Academic, so institutions can change it. | 7.1, 7.4, U1 |
| D7 | Student + staff in the same course: refused within the same term; and a person may never enrol as a student in a course they have ever taught or chaired. A former student may teach it later. | 9.2, 9.3 |
| D8 | A sixth SIS dataset, class times, per offering. | 13.2 |
| D10 | Past roles: a former student may later become chairperson of that program; a former dean or institution admin may never enrol as a student in a course of the department/institution they led (they could open its quizzes); someone whose enrolment ended in a past term may later be made instructor, chair or dean. | 9.4, 9.5, D7 |
| D11 | Missed-job email: one email when a job goes quiet, one when it recovers; the admin home warning stays in between. | 6.2, U2 |
| D9 | The class-times file is the whole timetable (like staff assignments): a section in it gets exactly its rows and is locked; a SIS-owned section missing from a present file loses its SIS times (stays SIS-owned, locked, zero meetings); a never-SIS section is untouched; no file at all changes nothing. | 13.2 |

Out of scope, recorded: **auditing a course** (an ungraded, uncounted enrolment kind, e.g. a retired professor). Not built; the 9.3/D7 rule stays.

---

## 1. Shared foundations (build first)

These are used by several findings. Build order matters: F1 → F2 → F3 before findings 1, 2, 7.

### F1. One institution per account (D1)

- **Code:** remove the system admin's "add to another institution" (`UserAdminService.php:330-358`, its route, its button). Invitations (`InvitationService.php:208, 364`) and the SIS import (`SisImporter.php:349`) already only create new accounts.
- **Database:** unique key on `institution_users.user_id`. Migration first deletes extra memberships, keeping the oldest row whose institution is not soft-deleted (the one `PlanLimits::institutionOf` already treats as the institution, `PlanLimits.php:125-136`, which skips deleted institutions at `:131`).
- **Simplifies:** `InstitutionAccess::work` (one row, `InstitutionAccess.php:44-74`); `Impersonation`'s "shared with another institution" rule (docblock and code, `Impersonation.php:140-142`) becomes unreachable and is deleted; 10.2/10.3 checks become unnecessary.
- **Test:** adding a second membership fails at the database; the service no longer offers it.

### F2. Per-institution permissions (U1, D2, D6)

**Storage [my call]:** add `institution_id INT UNSIGNED NOT NULL DEFAULT 0` to `role_permissions`; `0` rows are the platform layer (today's rows), others an institution's layer. Not nullable, because MySQL lets a unique key hold many `NULL`s and the platform layer would lose today's uniqueness (`CreateRolePermissionsTable.php:56`). Unique `(institution_id, role, permission)` replaces the current key. `PermissionMatrix::storedOverrides()` (`:85-98`, read by `AuthGroups::__construct`, `:163`) filters to `institution_id = 0`, so institution rows never leak into the platform matrix. Institution rows store only differences from the *current* platform value; setting a box back to the platform value deletes the row (U1b: untouched boxes follow later default changes).

**Resolution [my call]:** a custom user entity `App\Entities\User extends CodeIgniter\Shield\Entities\User`, returned by a new `App\Models\Users\UserModel extends CodeIgniter\Shield\Models\UserModel` (`$returnType` set; the existing `app/Models/Users/UserModel.php` is empty and becomes it), wired in as `Auth::$userProvider` (`Auth.php:29, 438`, today Shield's own). Every `model(UserModel::class)` call that names Shield's class keeps working, because Shield's factories resolve the provider. The entity overrides `can()`: Shield's matrix (`setting('AuthGroups.matrix')`, platform layer, already built by `PermissionMatrix::apply`) with the user's institution layer applied on top via the same `PermissionMatrix::apply`. Because it lives on the entity, `$user->can()`, the `permission:` route filter and checks about *other* users (e.g. "does this dean have `grades.approve`") all agree. Memoise per user per request; never across requests (a change takes effect on the next click, as `PermissionMatrix` promises today). Type hints across the app use the Shield class; the subclass satisfies them.

**Applicable permissions per role (D2) [my call, derived from what each screen checks]:**

| Role | Offered (academic) | Who controls |
|---|---|---|
| student | `courses.view` | platform + institution (fixed in practice: last permission can't be removed) |
| parent | `children.view` | same |
| instructor | `courses.view`, `courses.manage`, `grading.manage`, `announcements.manage`, `discussions.manage` | platform + institution |
| chairperson | instructor's five + `program.monitor`, `followups.manage` | platform + institution |
| dean | `courses.view`, `department.monitor`, `grades.approve`, `grades.signoff`, `followups.manage`, `department.announce` | platform + institution |
| institutional_admin | its institution-admin permissions + `courses.view`, `grades.approve`, `grades.signoff` (7.3); `program.monitor` removed (7.5) | platform only (U1a) |
| admin | locked | nobody |

Encoded once as an `APPLICABLE` map beside `AuthGroups::DEFAULT_MATRIX`. `PermissionMatrix::set` refuses a non-applicable pair; `apply` ignores stored rows for non-applicable pairs (same defence-in-depth as unknown permissions today). Migration deletes stored rows that become non-applicable.

**Permission list changes:** add `discussions.manage` (D6) and `institution.roles` ("Change the academic permissions of the institution's roles", institutional_admin default) **[my call]** to gate the new page. Remove `monitoring.manage` (7.7). `followups.manage` added to chairperson defaults (7.6). `grades.approve`, `grades.signoff` added to institutional_admin defaults (7.3).

**Institution admin page (U1a–U1f):** `institution/roles`, same grid component and look as the system admin's Roles page, limited to the five roles and their applicable permissions. Each box shows whether it follows the platform default or is the institution's own, with "reset to default". Safeguards (U1c): `reauth` filter (password re-check), audit entry `roles.permission_changed` with `institution_id`, and the "at least one permission" rule evaluated on the institution's effective set. Also covered by `readonly` (a read-only institution can't change it).

**Warning (U1f, 7.3):** "No one can approve overrides / sign off grades in <department>", worked out per department of an institution that uses approval (has any dean assignment): the department has no dean holding the permission (no dean, or deans lack it) and no institutional admin holds it. Per department, because deans holding it while admins don't still strands every dean-less department. Shown on the institution page (for that institution) and on the system admin's Roles page (listing affected institutions, and when the platform defaults alone would cause it).

### F3. Who decides: one resolver (findings 1, 2, 7.2, 7.3, U1e, U5)

A small service **[my call]** `GradeAuthority` answering, for an offering:
- `deanFor(departmentId, permission)`: the active dean if they hold the permission in that institution, else null (7.2, U1e: a dean without it counts as "no dean").
- `institutionAdmins(institutionId, permission)`: active institutional admins holding the permission (7.3).
- `usesApproval(institutionId)`: true when the institution has any active dean assignment, regardless of permission (2.2 keeps its meaning; U1e).

Replaces `GradeOverrideRequestService::deanId` and `appliesTo`, `GradeSignoffService::appliesTo/mayDecide`, and `SisGradeExporter::signedOffSections`' own dean query (`SisGradeExporter.php:240-250`), so the three can never disagree again.

### F4. CSRF everywhere (finding 4)

See finding 4. Every new form and fetch call in this design depends on it, so it lands early.

---

## 2. Findings

### Finding 1: override requests with no dean

**Now:** the chain already routes to `awaiting_institution` when there is no dean or the dean asked (`OverrideRequestChain.php:37-52`), and `retargetDepartment` already moves requests when a dean is assigned or leaves (`GradeOverrideRequestService.php:490-530`, called from `DeanAssignmentService.php:102, 143`). What is missing is anyone who can act on `awaiting_institution`.

**Approach:**
- `approve`/`reject` accept an institutional admin for `awaiting_institution` requests in their institution. `decidable()` gains the admin branch: active role `institutional_admin`, same institution, `can('grades.approve')`, not the requester (1.5). `claim()` takes the expected status (`awaiting_dean` or `awaiting_institution`) so "first decision wins" (1.4) is the same conditional update it is today (`:677-692`). On grade-change failure the request goes back to the status it came from, not hard-coded `awaiting_dean` (`:418-420`).
- No send-back for admins (1.3).
- Notify all institutional admins with `grades.approve`, except the requester, when a request arrives at `awaiting_institution` (`tellDeanIfWaiting` generalised).
- 1.6: when no admin other than the requester holds `grades.approve`, the request still waits and the requester's message and `routeText` (`:335-357`) say it needs a second administrator. Same when no role holds the permission at all (7.3).
- **Screen:** `institution/approvals`, reusing the dean's Approvals list/filters/decision dialog (`OverrideApprovalFilters`, dean views) scoped to the institution's `awaiting_institution` requests, plus the grade breakdown (`breakdown()`). Matches the existing design language.
- `deanFor` (F3) replaces `deanId`, so a dean who loses `grades.approve` routes to the admin. Re-routing runs `retargetDepartment` for every department of the institution when that institution's dean or admin `grades.approve` changes, and for every institution when the platform default for either role changes (it is idempotent, `:490`), as it runs today on dean changes.
- Wording: approve/reject/reopen messages name the role that acted ("the dean" / "the administrator"), replacing the fixed "the dean" (`GradeOverrideRequestService.php:433-441, 470-478`; `GradeSignoffService.php:205`).

**Risks:** approval writes the grade through `GradingEngineService::overrideFinalGrade` with the admin as actor; check that service accepts a non-dean actor (and the audit `after_signoff` flag still works).
**Tests:** admin approves/rejects `awaiting_institution`; admin can't decide own request; two admins race → one wins; dean assigned → request moves to dean; dean loses permission → moves to admin; one-admin requester → waits with message.

### Finding 2: no-dean departments never reach the SIS

**Approach:**
- `GradeSignoffService::mayDecide` (`:341-352`): a dean with `grades.signoff` for their department (may sign their own section, D3); or an institutional admin with `grades.signoff`, when the section's department has no dean holding it (`deanFor`), and not a section they teach (D4).
- `signOffProgram` (`:144`) and `board`/`counts` (`:248, 293`) get the admin variant: the admin's board lists sections of departments without an effective dean. Screen: `institution/signoffs`, reusing the dean's sign-off board views. Reopen with reason and `reauth` as for the dean (2.4).
- 2.2 unchanged: `usesApproval` false → no sign-off needed, finalized grades go out (`SisGradeExporter.php:199-213`).
- "Ready for sign-off" notice: `announceReady` (`GradeSignoffService.php:211-227`) today stops when the department has no dean; it notifies the institutional admins holding `grades.signoff` instead (except one who teaches the section, D4).
- 2.3: when the first dean of an institution is assigned (`DeanAssignmentService`), notify institutional admins with the list of finalized-but-unsent sections that now need sign-off. The exporter already checks at send time.
- 2.5: existing sign-offs untouched on any change of signer.
- D4 one-admin case: the section shows "needs a second administrator to sign off".

**Tests:** admin signs off in no-dean department; refused own section; dean signs own section; SIS export includes admin-signed sections; first dean assigned → notification lists unsent sections.

### Finding 3: view-as uses up an attempt

**Approach:**
- `QuizAttemptService::startAttempt` (`:33-110`): when a view-as session is active (session key `impersonator_id`, `Impersonation`), never insert. The quiz take page (`Routes.php:104`, a GET that goes straight to `startAttempt`; no landing view exists today) gets a new **view-as state** of the take view **[my call]**: availability, attempts used, past results; an in-progress attempt shown read-only with its remaining time and saved answers; no unseen questions (3.1). Same look as the take page, with the existing view-as banner.
- 3.2 absolute rule **[my call]**: every GET-time write on the viewed person's behalf is skipped while viewing as: quiz start, learning-progress "viewed" marks, notification "mark read on open", Shield's `last_active` stamp on the viewed person (`Auth.php:174 recordActiveDate`, `SessionAuth.php:56`; turned off for the request when `impersonator_id` is set), and any others the inventory finds. The audit log is not on the person's behalf: its entries carry the administrator as impersonator (`AuditLogger.php:123`) and keep being written (start, stop, expiry/deactivation stops in `ImpersonationFilter.php:44-59`, audited downloads such as `Routes.php:425`).
  - Considered and dropped: a database-level read-only session as a safety net. Too many legitimate writes happen during view-as (audit entries, the filter's own stops), so it would need a second connection and exemptions; the crawl test below gives the guarantee instead.
- One rule for both view-as kinds (system and institution admin): both go through `Impersonation` and `ImpersonationFilter`.

**Tests (the guarantee):** a feature test that, while viewing as a student, parent, instructor, chairperson and dean, requests every GET route of that area (including `notifications/feed`) and asserts every table's contents are unchanged except `audit_logs`; it fails on any new GET write added later. Quiz view-as state shows no questions and creates no submission.

### Finding 4: CSRF

**Approach:**
- Turn on `csrf` in `Filters::$globals['before']` (`Filters.php:107-109`) with `except: ['internal/ai/*', 'internal/scheduler-check']` (the second is new, finding 6). Both are already secret-authenticated.
- Token per session, not per request: change `Security::$regenerate` from `true` (`Security.php:74`) to `false` **[my call]**. The app has 13 fetch modules and multi-tab use, and per-request rotation breaks the second tab and parallel fetches. A session regenerate at login keeps session data (`Session::regenerate`, CI4), so with `csrfProtection = 'session'` the token would *not* rotate by itself: the token is explicitly regenerated on Shield's login event.
- Forms: 141 `<form>` tags, 119 `csrf_field()` today. Add the field to the rest (a view test lists any POST form without it).
- Fetch: `<meta name="csrf-token">` in the layouts; one shared helper in `resources/js/shared/` that adds `X-CSRF-TOKEN` to non-GET same-origin requests; the 13 modules (`resources/js/pages/**/api.js`, `calendar.js`, `submission.js`, `dialog.js`, `notifications-menu.js`, `role-switch.js`, `report-menu.js`) use it.
- 4.2: a CSRF filter subclass: browser request → redirect back `withInput()` with "This page was open too long. Reload it and try again."; fetch/JSON → 403 `{error:'csrf_expired', message}` that the shared helper shows in the page's existing error toast, keeping the form's contents. File inputs cannot be kept ("where possible").
- 4.3: sign-out becomes a POST button. Three links today: `layouts/partials/header.php:297`, `auth/two-factor-show.php:54`, `auth/two-factor-verify.php:61`. Shield's GET `logout` route is replaced by a POST route.
- 4.4: replace the vacuous assert (`tests/unit/ComponentViewsTest.php:263`; the audit cites `:240`) with a feature test: a POST without a token is refused; it fails if `csrf` is removed from globals.

**Risk:** the largest blast radius in this design (every write). Land alone, early, with the Playwright suite run.

### Finding 5: dean and institution lesson links crash

**Approach:** the dean and institution lesson deep links (`Routes.php` ~`:369`, ~`:747`) send three route arguments (offering, course slug, lesson) into `ModuleController.php:46-49`, which takes `(int, int)`; the slug lands in an `int` parameter and crashes. Point them at the instructor's handler shape instead, `CourseController::view/$1/$2/modules` (`Routes.php:130`), which takes the slug as a string and opens the course page on the lesson. Open the course page on that lesson, read-only (5.1); drafts shown with a "Draft" badge for read-only viewers (5.2); deleted lesson / other course's lesson / course out of reach → the existing friendly not-found page (5.3, `#240`).
**Test:** each of the three not-found cases and the happy path, for dean and institution admin. Keep the route checker as a test so a route whose argument count or types don't fit its handler can't come back.

### Finding 6: scheduled jobs

**6.1 [my call]:** effective institution status = the worse of (stored status, status from the subscription dates via `SubscriptionLifecycle::statusOf`, `SubscriptionLifecycle.php:38-50`). One method on `SubscriptionLifecycle`, used by `InstitutionAccess` and the 21 other readers of the stored status. The nightly job still writes the change and its audit entry (`:121-140`) and still never lifts a manual suspension.

**6.2 / U2:**
- Each of the six commands records its last successful run (`Monitoring.<job>At`, as `subscriptions:sync` already does, `SyncSubscriptions.php:45`), and `SystemHealth::jobs` (`SystemHealth.php:242-247`) checks all six against a per-job allowed gap **[my call]**: backup 15 min, parents:notify 45 min, the nightly four 26 h. SIS jobs count only when an institution has SIS enabled; otherwise they still run (6.4) and report a no-op run.
- Admin home warning lists stale jobs until they run again (6.2).
- U2 sender: new endpoint `POST internal/scheduler-check`, guarded like `AiWorkerFilter` (shared secret header, 503 when unconfigured) but not loopback-only, because it is called from GitHub. It checks the heartbeats and emails all system admins once when a job goes stale and once when it recovers (D11; deduped by notification key per stale period). `uptime.yml` calls it each hour after the smoke test, with the secret from the environment's GitHub secrets. If the site is down, the existing GitHub issue fires instead.

**6.3 (D5):** a deploy step writes a marked block (`# BEGIN mentora jobs` … `# END`) into the site user's crontab from a template in the repo (the six lines of `docs/server-setup.md:153-161`, with the site path and PHP binary of that environment), replacing only that block, then runs `crontab -l` and fails if the six lines aren't present. **Can't verify from the repo:** whether CloudPanel's Cron Jobs screen rewrites the crontab and drops lines it didn't create. Check on staging before relying on it; if it does, the step must go through CloudPanel instead.

**6.4:** all six always installed; SIS commands already skip institutions without a connection (`docs/sis-integration.md:131`).
**6.5:** dropped; purge runs normally.

**Note:** finding 12 adds a seventh job (`exports:run`); 6.4's "six" becomes seven, and it gets a heartbeat too.

### Finding 7: Roles-page permissions

Built on F2.
- **7.4 + D6 enforcement:** route-group `permission:` filters inside the course API groups (`Routes.php:147-202` instructor, `:490+` chairperson): content routes (modules, units, items, resources) need `courses.manage`; gradebook/grading/finalize need `grading.manage`; announcement create/edit/delete/pin need `announcements.manage`; discussion pin/lock/delete-others/mark-answer need `discussions.manage`. GETs stay open with `courses.view`. Replies and reactions need none.
- UI: the course page receives the four flags and hides the matching edit controls (not just greys them), so a role without the permission sees the student-like read-only view of that tab (UI parity with existing read-only views of dean/admin).
- **7.5:** `program.monitor` filter on the chairperson Monitor Program routes (`Routes.php:477`); removed from institutional_admin defaults.
- **7.6:** `followups.manage` filter on create/pass-down routes; reply/close need none.
- **7.7:** `audit.manage` filter on the audit-log settings routes; `monitoring.manage` removed from the list and its stored rows deleted.
- **7.2/7.3:** via F3.
- **7.8:** dropped.
**Test:** for each permission, a role with it can and a role without it gets 403 on the write route and no control on the page; an institution override changes the outcome for that institution only.

### Finding 8: read-only

- With F1, a person has one institution, so 8.1 is "that institution decides". `InstitutionAccess` uses the effective status from 6.1.
- 8.2: `ReadOnlyFilter` (`ReadOnlyFilter.php`) gets an allow-list of own-account write routes: password change, notification read state, the parent's email choices, personal reminders (today inside role groups, e.g. `Routes.php:140`). 8.3: same for past/suspended (already the same filter).
- The export request (finding 12) already sits outside the `readonly` group on purpose (`Routes.php:670-676`), so it keeps working in read-only and past institutions. Nothing to add; the queued design must keep it there.
**Test:** in a read-only institution each allowed route works and an institution write is refused.

### Finding 9: course role decides

- **9.1:** `roleIn(User, offeringId): ?string` added to the existing `CourseMembership` (beside `canAccess`, `canAccessAs`, `CourseMembership.php:31, 39`), returning ('student' | 'instructor' | 'chairperson' | null), and the 26 `inGroup('instructor'|'student')` checks in 19 files (list: `CourseController`, `CalendarEventService`, `AnnouncementService`, `AssessmentFeedbackService`, `AssignmentSubmissionService`, `QuizAttemptService`, `CourseService`, `CourseViewer`, `DepartmentAnnouncementService`, `DiscussionService`, `LearningProgressService`, `ModuleService`, `ResourceService`, `ScheduleService`, `DashboardService`, `FileAccessService`, three module views) use it.
- **9.2–9.5 + D7:** one guard `CourseRoleConflicts` called by every writer of enrolments and staff roles: enrolment service and CSV upload, instructor assignment, chair assignment, dean assignment, institutional-admin role grant, and the SIS importer (enrolments, staff assignments). Refuses: student + instructor/chair of the same course in the same term; enrolment in a course the person has ever taught or chaired; enrolment by a chair in their program's courses, a dean in their department's courses, an institution admin in their institution's courses; and the reverse grants. Past roles follow D10: a past dean/admin role over the course blocks enrolment forever; a finished enrolment from a past term does not block a staff role. SIS rows that conflict fail with the reason (single bad rows don't stop the run).
- **9.6/9.7:** migration removes conflicting enrolments, keeps the staff role.
**Test:** each refusal from each entry point; migration removes the enrolment.

### Finding 10: purge

With F1 every account in the institution belongs only to it.
- Order **[my call]**: collect the storage keys of every file owned by the institution's accounts and the institution's export zips; in one transaction delete the institution (existing cascade, `InstitutionService.php:240-298`) and its accounts (Shield's tables cascade from `users`; profiles, notifications, reminders, files rows); after commit delete the files on disk. A disk delete that fails is logged and retried by the next purge run from a recorded list; a stray file on disk is less harmful than a row pointing at nothing.
- 10.5: audit entries kept; they have no foreign keys and carry `actor_label` (`CreateAuditLogsTable.php:27-35`).
- 10.2/10.3 checks unnecessary (F1). Keep a defensive assert that no purged account has another membership.
**Test:** after purge: no accounts, file rows, disk files or export zips remain; audit entries remain.

### Finding 11: backups

- **11.1:** `MysqldumpRunner` (`app/Services/Backup/MysqldumpRunner.php:20-67`) checks every `gzwrite` and `gzclose` result and mysqldump's exit code, then re-opens the file and reads it to the end, requiring mysqldump's closing `-- Dump completed` line. Any failure: status failed, file deleted, system admins warned and emailed. `backup:run` sends that alert itself, straight away, as an urgent notification (emailed). Today's backup alert lives in `NotificationTriggers` (`:80-89`), which runs only from the nightly `subscriptions:sync`, so it could be up to a day late.
- **11.2:** `BackupService` rotation (`KEEP_FILES = 14`, `:24, 193-200`) counts and deletes only proven-good backups.
- **11.3:** read-back only.
- **U3:** after a good backup, `backup:run` runs a configured off-site command (`backup.offsiteCommand` in `.env`) **[my call]** with the dump path and the uploads folder, records success/failure on the backup row, and warns + emails admins on failure. The tool and bucket are set up on the server (U3d), not in the app; the recommended setup is rclone (no root needed) with an encrypted remote to a non-Hostinger provider, an application key that can write but not delete, and a 30-day lifecycle rule (U3b, U3c). The app treats the copy as good only when the command exits 0 after its own verification. Production only (U3a): the command is unset on staging. `docs/server-setup.md` gains the required step and the go-live check (U3d).
**Test:** a fake runner that writes a truncated file → failed, deleted, alert; rotation never deletes a good backup on a failing run; failing off-site command → alert.

### Finding 12: export at scale

- Table `institution_exports` (`CreateInstitutionExportsTable.php:20-30`): status gains `queued` and `running`; `started_at`; a nullable `open_marker` with unique `(institution_id, open_marker)` (the project's existing pattern for "only one open", as `dean_assignments.active_marker`), so a second request is refused by the database (12.3).
- `ExportController::create` (`:44`) only queues. New command `exports:run` (every minute, seventh job) takes queued exports one at a time, marks a stale `running` (no progress for 30 min) as failed **[my call]**.
- `InstitutionExport` (`:64-82, 190-215`) streams: keyset pages by id (e.g. 5,000 rows) written straight to the CSV files inside the zip, no `getResultArray()` of whole tables. Target 12.1: 20,000 students × 5 years × ~50 items.
- Notify only the requester, in app and by email, on ready or failed (12.4). Others see status on the export page.
- Allowed in read-only and past institutions (see 8).
**Test:** a generated data set at the 12.1 scale exports under a fixed memory limit (`memory_get_peak_usage` assertion); a second request while one is open is refused; only the requester is notified.

### Finding 13: class schedules

- **13.1:** a weekly-meetings editor on the institution admin's offering form (day, start, end, room, delivery mode; add/remove rows), saved to `course_schedules` (columns exist, `CreateCourseSchedulesTable.php`). Validation: end after start; delivery mode one of the column's three values, onsite, online, hybrid (`CreateCourseSchedulesTable.php:61-67`).
- **13.4:** on save, warnings (not errors) for a room used by another section at an overlapping time in the same term, and an instructor teaching two overlapping sections. The SIS import reports the same clashes as warnings in the run log.
- **13.5/U4:** times are platform time zone; nothing stored about zones.
- **D8/D9 SIS:** dataset `class_schedules` in `SisCsvParser::SPECS` (`SisCsvParser.php:30-38`): required `course_external_id`, `day_of_week`, `start_time`, `end_time`; optional `section` ("A" when none, like enrolments), `room`, `delivery_mode`. A new tracking table (like `sis_staff_assignments`, `SisStaffAssignments.php`) records which offerings the SIS owns. Each run with the file present replaces the timetable of every offering in it, clears the SIS times of SIS-owned offerings missing from it, leaves never-SIS offerings alone; no file → nothing. Lock: the offering form shows SIS-owned times read-only with "From the SIS" (reusing `SisOwnership`'s lock presentation). As with SIS-owned staff assignments, the lock holds only while the institution's SIS connection is enabled; disabling it hands the times back to the admin **[my call]**. Preview counts show "removed" so an empty file is visible before a real run. `docs/sis-integration.md` documents the dataset.
**Test:** the four D9 cases; clash warnings; lock on the form.

### Finding 14: tests depend on `.env`

- The five files that set `$_SERVER`/`putenv` (`tests/feature/AiWorkerEndpointsTest.php`, `MysqldumpRunnerTest.php`, `InstitutionCsvUploadTest.php`, `AiGenerateModalViewTest.php`, `tests/_support/FakeBackupRunner.php`) set the value in `$_ENV` as well (CI4 `env()` reads `$_ENV` first), and restore it after. A small test helper does both.
- CI (`ci.yml`) adds a second PHPUnit run with a filled-in sample `.env` (14.2).

### Finding 15: two tabs

- 15.1: `startAttempt`'s insert (`QuizAttemptService.php:100-110`) catches the duplicate-key error from `uq_submission_attempt` and re-reads the in-progress attempt.
- 15.2: unique `(submission_id, question_id)` on responses (missing today; the code checks then inserts, `:210-238`) and an upsert; the status check and the write happen inside one transaction with the submission row locked (`SELECT … FOR UPDATE`), and submit takes the same lock, so no save lands after submit. Migration removes duplicate response rows (keep the newest).
**Test:** two concurrent starts → one attempt; save after submit refused; two saves → one row, last wins.

### Finding 16: one chair, atomic actions

- **16.0:** `chairperson_assignments` gets `active_marker` with unique `(sub_department_id, active_marker)` (the dean pattern, `CreateDeanAssignmentsTable.php:18, 30`); the old unique `(sub_department_id, chairperson_user_id)` (`RescopeChairpersonAssignmentsToSubDepartment.php:30`) is dropped so history rows can repeat. Update the rule at `ChairAssignmentService.php:18-19` (the decisions file cites `:11-12`).
- **16.0b:** assigning a chair to a filled sub-department asks for confirmation, ends the old assignment (history kept), notifies the old chair.
- **16.0c / U5:** waiting `awaiting_chair` requests stay with the sub-department and simply notify the new chair (they are addressed by sub-department, not person, `listForChair`). Requests already past the chair don't move.
- **16.0d:** migration keeps the most recently assigned active chair per sub-department, ends the rest.
- **16.1:** `endorse`, `sendBack` (`GradeOverrideRequestService.php:210-282`) and `withdraw` (`:186-203`) become conditional updates on the expected status (as `claim()`), checking affected rows; the loser is told "That request was just acted on", one notification only.
- **U5:** notify the one chair; no chair or chair is requester → skipped (already, `OverrideRequestChain.php:37-52`), and the requester's message says the endorsement step was skipped.
**Test:** second chair assignment ends the first; race between endorse and withdraw → one wins, one notification.

---

## 3. Dependencies and order

1. F4 CSRF (finding 4), alone.
2. F1 one institution; 6.1 effective status; finding 8.
3. F2 permissions + F3 authority; then findings 7, 1, 2.
4. Finding 9 (course role + conflicts), then 3 (view-as) and 15 (quiz concurrency): all touch `QuizAttemptService`.
5. Finding 16 (chair), U5.
6. Findings 6, 11, U3, 12 (jobs, backups, exports: deploy and cron).
7. Findings 5, 10, 13, 14.

## 4. Conflicts with the decisions file

- **6.3**: replaced by D5 (deploy installs, then verifies).
- **6.4**: "all six jobs" becomes seven with `exports:run` (finding 12).
- **8.1, 10.2, 10.3**: unchanged in effect, but D1 makes their multi-institution cases impossible; the code for them is removed rather than built.
- **13.2**: extended by D8/D9 (the SIS dataset is built now).
- **9.3**: refined by D7 (term rule).
- The decisions file should be updated with D1–D11 before implementation.

## 5. Cannot be verified from the repository

- Whether CloudPanel rewrites the site user's crontab (D5).
- Whether Hostinger VPS snapshots are on; the chosen off-site provider and its delete-protection settings (U3).
- Whether any school's SIS can export class times, and in what shape (D8).
- Real production load beyond the 12.1 target.
- That `GradingEngineService::overrideFinalGrade` accepts an institutional admin as actor without other role checks (finding 1): to confirm when building.
- The full list of GET-time writes (finding 3): produced by the crawl test, not by reading.
