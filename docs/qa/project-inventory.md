# Mentora project inventory for QA test-case documentation

| | |
|---|---|
| **Date** | 2026-10-26 |
| **Branch** | `docs/qa-test-cases`, from `fix/approvals` at `27f2012` |
| **Purpose** | A verified inventory of what Mentora does, to write QA test cases from. This is the input to the test cases, not the test cases. |
| **Method** | Read-only. Eight parallel code audits (auth and security, system administrator, institution administrator and SIS, course content and quizzes, assignments / grading / dean / chairperson, student / parent / communications, database schema, frontend / tests / CI), then a second pass where the headline claims were re-checked directly against the code. Nothing was run: no tests, no migrations, no database. |
| **Not done** | No application code changed. No test cases written. No database touched. |

## 0. How to read this document

### 0.1 Evidence labels

Every finding carries one of these.

| Label | Meaning |
|---|---|
| **[V]** | **Verified** by me directly against the code in the second pass (a grep or a file read). Strongest. |
| **[A]** | **Agent-read.** An audit agent read the code and reported it. Not independently re-checked. |
| **[D]** | **Documented only.** A `docs/` file or a plan says so. The code was not confirmed. |

### 0.2 Status labels

| Status | Meaning |
|---|---|
| **IMPLEMENTED** | The code that does it was read. |
| **PARTIAL** | Part is implemented, or only the shell was read. The gap is stated. |
| **UNVERIFIABLE** | Cannot be established without running the app, a real SMTP / SIS / model provider, a server, or reading code nobody read. The reason is stated. |

### 0.3 Things that make this a snapshot, not a fixed truth

1. **Uncommitted work is in the tree.** 42 files are staged for "audit plan 4" (approvals, sign-off, one chairperson per sub-department). They are on disk, so this inventory describes them, but they are not committed. Plan 4's own checkboxes are all unticked even though the code and tests exist, and its documentation task (task 9) is not done. **[A]**
2. **The 2026-10-23 audit (`docs/audit-2026-10-23.md`) is older than the code.** Findings 1–4, 7–9 and 16 have commits or staged work. Findings 5, 10–13 and 15 and the cron install (decision D5) appear still open. Do not copy the audit's "what exists" statements without checking.
3. **Several `docs/` files are stale** (see section 13). Where docs and code disagree, this inventory states the code and notes the doc.
4. **`docs/test-cases/` already holds about 98 draft cases.** A new catalog must extend it, not duplicate or contradict it (section 10).

### 0.4 Reading order

Section 1 for the system, 2 for what I verified myself, 3–5 for roles / features / states, 6 for rules and limits, 7 for cross-layer workflows, 8 for dependencies and jobs, 9–10 for existing tests, 11–13 for missing / ambiguous / contradictory items, 14 for what needs more investigation.

---

## 1. System overview

### 1.1 Stack **[A]**

| Layer | Detail |
|---|---|
| Backend | PHP ^8.2 (CI tests 8.2 and 8.5; production deploys 8.5), CodeIgniter 4.7, Shield 1.3 for authentication |
| Database | MySQL / MariaDB. 104 migrations, 33 seed entries, 69 models (about 25 tables have no model and are written through the query builder) |
| Frontend | Server-rendered PHP views, Tailwind 4, Vite 8, vanilla ES modules, no framework. `data-page` on `<body>` loads `resources/js/pages/<page>/<page>.js` |
| Libraries | tiptap (rich text), chart.js, pdfjs-dist, sortablejs, emoji-mart; dompdf (PDF), phpspreadsheet (XLSX), phpword (Word), smalot/pdfparser |
| AI worker | A Python worker (`python-question-builder-service/qb`) polls MySQL-backed job tables, talks to the PHP app over loopback with a shared secret, and calls Groq's OpenAI-compatible API. A legacy Ollama HTTP service (`app/main.py`, port 8008) still exists |
| Storage | Local disk only (`writable/uploads`, `writable/exports`, `writable/backups`) |
| Hosting | One VPS (CloudPanel, nginx, PHP-FPM). GitHub Actions deploys over SSH |

### 1.2 Roles **[A][V on the list of seven]**

Seven Shield groups: `student`, `instructor`, `chairperson`, `dean`, `parent`, `institutional_admin`, `admin` (the system administrator).

- One account belongs to **exactly one institution** (database unique key on `institution_users.user_id`, decision D1). The system administrator belongs to none.
- A person may hold several roles and switches between them (`POST role/switch`). The **active role** decides which area they are in.
- A dean who holds other roles but has **no dean assignment** cannot act as dean.
- Area prefixes: `/student`, `/instructor`, `/chairperson`, `/dean`, `/parent`, `/institution`, `/admin`.

### 1.3 Tenancy **[A]**

Institution is the tenant. A real `institution_id` column exists on departments, academic terms, institution users, subscriptions, invitations, exports, SIS tables, parent links, AI jobs and a few more. Everything below (programs, courses, offerings, enrolments, assessments, grades) inherits its tenant by joining up the chain. **Nothing in the database forces those chains to agree** (e.g. an offering whose course is in institution A and term in institution B). Tenant checks are PHP-side.

### 1.4 Institution access states **[A]**

Stored: `pending`, `active`, `read_only`, `past`, plus soft-deleted. Access follows the **dates** immediately through `EffectiveStatus` (decision 6.1), not the nightly job. A read-only or past institution refuses every non-GET request except a short own-account list (reminders; the parent's two profile forms). The system administrator is never restricted.

### 1.5 Demo data **[A]**

All accounts end `@mentora.test`, institution 1 ("SBU") unless noted. Demo passwords are in the seeders and in `docs/test-cases/README.md`.

| Account | Role(s) | Notes |
|---|---|---|
| student1–5 | student | student1 ahead and all graded; student2 a partly graded quiz and a resubmitted assignment; student3 being graded and late; student4 quiz in progress and a missing assignment; student5 "clean slate" |
| instructor1, instructor2 | instructor | instructor1 teaches IT101-A and B; instructor2 teaches IT102-A and IT201-A |
| chairperson1 | chairperson | chairs SBU / CBM / IT. **Seeded without `active_marker`** (see GAP-DB-01) |
| chairperson2 | chairperson + instructor | has the role but **no chair assignment** |
| dean1 | dean (GEN) | |
| dean2 | dean (CBM) + instructor | teaches nothing |
| parent1 | parent | linked to student1 and student5 |
| parent2 | parent | linked to nobody |
| institution.admin | institutional_admin | |
| admin | admin | platform, no institution |

- Institutions 2–5 have **no users**. Tenant-isolation tests need a second tenant created by hand.
- IT101-A is the showcase course. IT101-B is deliberately empty.
- No seed creates a subscription, `role_permissions`, or feature-flag overrides. Seeded institutions are `active` with no subscription.
- The seed is date-relative. Re-seeding erases the database.
- Seed order depends on hard-coded ids (`InstitutionUsersSeeder` links user ids 1–9). Run only on empty tables.

---

## 2. Verification log (what I checked myself)

These were re-checked directly in the second pass. They are the firmest facts in this document.

| ID | Finding | How checked |
|---|---|---|
| **V1** | `auditRetentionDays` (default 730, editable 30–3650) is defined in `app/Config/Platform.php:20` and `app/Services/Admin/PlatformSettings.php:33`. **No other file in `app/` reads it.** There is no archive job. The setting is a no-op. | grep over `app/` |
| **V2** | `CourseMembership::roleIn` and `CourseRoleConflicts` **do not exist**, though decision 9.1 and the design spec call for them. | grep, no match |
| **V3** | **No late-submission penalty, deduction or grace** exists. Only the `allow_late_submission` and `is_late_submission` flags. | grep for penalty, no match |
| **V4** | **No workflow installs or checks cron jobs.** The only `cron` in `.github/workflows` is the hourly uptime check's own schedule. Decision D5 (deploy installs and verifies the jobs) is not implemented. | grep |
| **V5** | `parentsNotifyAt` (a heartbeat the plans say `parents:notify` records) **is not in `app/`**. The job is not monitored. | grep, no match |
| **V6** | Five PHP files are **0 bytes**: `app/Models/Users/UserModel.php`, `app/Services/Auth/LoginService.php`, `app/Services/User/UserService.php`, and two view components, `app/Views/components/app-menu.php` and `filter-dropdown.php`. | `find -size 0` |
| **V7** | Security headers are off: `secureheaders` is commented out in `app/Config/Filters.php:125`; `CSPEnabled = false` in `app/Config/App.php:201`. | grep |
| **V8** | `QuizAttemptService::startAttempt` counts attempts and then `insert`s an `In Progress` attempt with **no transaction and no duplicate-key handling**. The visibility and availability checks are skipped for `inGroup('instructor')`. A page open is a GET that creates the attempt. | read of the code |
| **V9** | `AssessmentController` / `AssessmentService` have `publish`, `unpublish`, `archive`, `restore`, `duplicate` and `statistics` methods with **no route** referencing them. | grep of `Routes.php` |
| **V10** | **Foreign-key argument order is reversed** in 7 columns across 6 migrations: `addForeignKey(..., 'SET NULL', 'CASCADE')`. CodeIgniter's signature is `($onUpdate, $onDelete)`, so this reads ON UPDATE SET NULL / **ON DELETE CASCADE**. Columns: `dean_assignments.assigned_by`, `grade_override_requests.endorsed_by` / `returned_by` / `decided_by`, `grade_signoffs.reopened_by`, `department_announcements.ended_by` (and `learning_resources.file_id` per the schema agent). **Effect to confirm on a copy database:** deleting the actor user deletes those rows. | grep of migrations; effect not run |
| **V11** | Six pairs of migrations **share a timestamp**: `2026-07-17-000009`, `2026-10-24-000001`, `2026-11-05-000001`, `2026-11-08-000001`, `2026-11-09-000001`, `2026-11-10-000001`. Order within a tie is by class name. | `ls` |
| **V12** | No seeder sets `active_marker` for the chairperson. After the staged migration the seeded chair has marker NULL, so the database uniqueness does not cover that row. | grep of `app/Database/Seeds` |
| **V13** | The newest migration is `2026-11-15-000001_OneChairpersonPerSubDepartment`, staged, not committed. Whether it has run anywhere is unknown. | `ls`, git |
| **V14** | Roles: the seven groups exist. | agent + config read |

Not re-checked, but the single most important to confirm at runtime: **A1**, whether successful logins are written to `auth_logins` when Shield's attempt recording is "failures only" (lockout resets on a success row). Tests assert it works; the config suggests it may not (see AMB-AUTH-01).

---

## 3. Roles, permissions and access control

### 3.1 Permission matrix (defaults) **[A]**

Source: `AuthGroups::DEFAULT_MATRIX`, overlaid by the `role_permissions` table (institution_id 0 = platform layer; an institution's own rows sit on top and are stored only where they differ from the platform value). Checked by `RolePermissions::allows()` through the `permission:` route filter.

| Role | Default permissions |
|---|---|
| admin | institutions, subscriptions, users, roles, flags, settings, audit (each `.view` / `.manage`), `monitoring.view`, backup / integrations (`.view` / `.manage`), `impersonation.start`. Locked: cannot be edited, has no academic permission |
| institutional_admin | `courses.view`, `grades.approve`, `grades.signoff`, `structure.manage`, `terms.manage`, `offerings.manage`, `enrolments.manage`, `people.view`, `people.manage`, `reports.view`, `institution.settings`, `institution.audit`, `institution.impersonate`, `sis.run`, `institution.roles` |
| dean | `courses.view`, `department.monitor`, `grades.approve`, `grades.signoff`, `followups.manage`, `department.announce` |
| chairperson | `courses.view`, `courses.manage`, `grading.manage`, `announcements.manage`, `discussions.manage`, `program.monitor`, `followups.manage` |
| instructor | `courses.view`, `courses.manage`, `grading.manage`, `announcements.manage`, `discussions.manage` |
| student | `courses.view` |
| parent | `children.view` |

Rules **[A]**:
- Only permissions listed in `AuthGroups::APPLICABLE` for a role can be set. Others show "Not used by this role" and a stored row is ignored.
- Every role keeps at least one permission.
- The admin role is locked; nothing can be granted to or taken from it.
- The system administrator edits platform defaults (`/admin/roles`, `roles.manage` + password check). The institution administrator edits only the **academic** permissions of dean / chairperson / instructor / student / parent (`/institution/roles`, `institution.roles` + password check; refused while read-only).
- Every change is audited as `roles.permission_changed`, and a change that cannot be audited is undone.
- Changing `grades.approve` for dean or institutional_admin re-targets waiting override requests.
- The Roles pages warn where **no one** can approve or sign off for a department (`GradeAuthority::gaps`).

### 3.2 Filters (what enforces what) **[A]**

| Alias | Enforces | Applied |
|---|---|---|
| csrf | token on every non-safe request; "This page was open too long. Reload it and try again." | global, except `internal/ai/*` |
| maintenance | timezone; 503 + `Retry-After: 300` for everyone but admins and open paths | global |
| loginlockout | refuses `POST login` for a locked email | global |
| impersonation | view-only, expiry, ends on deactivation or logout | global |
| accountstate | deactivated, signed-out-everywhere, idle timeout | global (skipped while impersonating) |
| role | active role must equal the area's role; a wrong role is **redirected to `/`**, not 403 | role groups |
| group (Shield) | `admin` membership | `/admin` |
| permission | the two-layer matrix | many routes |
| readonly | no writes in a read-only / past / deleted institution | role groups |
| reauth | password re-typed within 300 s (`ReauthService::WINDOW_SECONDS`); 5 wrong passwords end the session | dangerous POSTs |
| coursemember:{role} | belongs to the offering as that role, else **404** | course pages and `api/courses` |
| institutioncourse / deancourse | read access to the offering, else 404 | institution and dean mirror routes |
| assessmentscope | nested ids (assessment, submission, question, rubric…) belong to the offering | `api/courses` groups |
| closedoffering | no writes to a non-active offering | student / instructor / chair course and calendar APIs |
| coursepermission[:perm] | permission per course part; the area decides the role | instructor and chair `api/courses` |
| chairpersoncourseaudit | audits every successful chair write as `course.chairperson_action` | chair `api/courses` |
| parentchild / parentportal | child is the parent's (and in the course); portal switched on | parent routes |
| ajaxredirect | redirect becomes `{redirect}` JSON for AJAX | `/admin` |
| aiworker | loopback only, `X-Worker-Secret` | `internal/ai` |
| session (Shield) | signed in | notifications, `files/{id}`, ui-kit |

Defined but inert: `forcehttps` is a no-op while `forceGlobalSecureRequests=false`; `honeypot`, `invalidchars`, `secureheaders`, `cors` are defined and not applied (V7).

### 3.3 Reauth (password re-entry) coverage **[A]**

Behind reauth: impersonation start; password-reset send; user update / roles; institution restore and purge; global flag change; platform settings; audit settings; backup request; plan edit / retire / reinstate; integrations run and grade send; term close and reopen; people deactivate / roles / reset-password / bulk deactivate; permission toggles (both layers); institution security settings; CSV upload apply; sign-off and program sign-off (dean and institution); view-as.

**Not behind reauth** (while counterparts are): institution `delete` and `suspend`; `users/{id}/deactivate`, `reactivate`, `sign-out`; bulk reactivate; per-institution flag change; integrations `test` and `preview`; invitations; sign-off **reopen**. Whether this is intended is AMB-AUTH-05.

---

## 4. Feature inventory by area

Each area lists: rules and status, then existing tests. Routes are abbreviated; the full table is `app/Config/Routes.php` (995 lines; the 2026-10-23 audit counted 662 routes).

### 4.A Authentication and session security

| Feature | Status | Key files |
|---|---|---|
| Email + password sign-in; registration and magic links **off** (`allowRegistration=false`, `allowMagicLinkLogins=false`) | IMPLEMENTED | `app/Config/Auth.php`, Shield routes, `Auth\RedirectController` |
| Post-login landing by active role; a user with no usable role gets **403** "Your account does not have a role that can use this site yet." | IMPLEMENTED | `RedirectController::index`, `ActiveRoleService` |
| Sign-out is **POST only** (a link cannot sign out) | IMPLEMENTED | `POST /logout` |
| Emailed 2FA code. Always for `admin`; platform / institution may add other roles. Outside development, a mail failure **stops** sign-in | IMPLEMENTED | `app/Auth/Actions/AdminEmail2FA.php` |
| Lockout: N failures since the last success lock the email for M minutes; the right password is also refused; 0 attempts = off | IMPLEMENTED | `LoginLockoutFilter`, `LoginLockout`, settings `lockoutAttempts` / `lockoutMinutes` |
| Password policy: minimum length (platform 8–64, an institution may only raise it) + Shield composition / nothing-personal / dictionary checks. `PwnedValidator` disabled | IMPLEMENTED | `PasswordPolicy` |
| Account states: deactivate, reactivate, "sign out everywhere"; a session started in the same second as the sign-out is ended; a session with no recorded start is ended | IMPLEMENTED | `AccountControl`, `AccountStateFilter`, table `user_account_controls` |
| Idle timeout (5–120 min; the effective value is per user's institution) | IMPLEMENTED | `AccountStateFilter` |
| Invitation acceptance (public). Token hashed; pending / accepted / revoked / expired; 404 unknown, 410 used / replaced / expired / institution deleted | IMPLEMENTED (controller); service PARTIAL | `InvitationController`, `InvitationService` |
| Password reset (admin-initiated only; link valid 1–168 h, one use, ends all other sessions) | IMPLEMENTED | `PasswordResetService`, `PasswordResetController` |
| Role switching | IMPLEMENTED | `POST role/switch` |
| Impersonation / view-as (system admin and institution admin) | IMPLEMENTED | `Impersonation`, `ImpersonationFilter` |
| Maintenance mode | IMPLEMENTED | `MaintenanceFilter` |
| Admin-account safeguards (no placeholder email domains outside development; demo admin seeder refused in production; admin password ≥ 12) | IMPLEMENTED | `AdminAccountPolicy`, `admin:create` |

Impersonation rules **[A]**: reason 5–500 chars; not yourself, not another admin, not a deactivated or role-less person; an institution admin may view only their own institution's people and never another institutional admin; time limit `impersonationMinutes` (5–240); **every non-GET request is refused** (403 JSON `impersonation_view_only`) except `impersonation/stop`; ends on expiry, target deactivation, admin account block, or logout; audited `impersonation.started` / `.ended`. Decision 3.2 says even *opening a page* must save nothing; only non-GET is enforced by the filter (GAP-AUTH-07).

Tests **[A]**: `AuthFlowTest`, `AuditAuthEventsTest`, `AdminTwoFactorTest`, `AdminTwoFactorPagesTest`, `SecurityPoliciesTest`, `SecuritySettingsTest`, `AccountStateTest`, `InvitationLinkTest`, `PasswordResetLinkTest`, `AdminReauthTest`, `InstitutionReauthTest`, `ReauthServiceTest`, `ImpersonationTest`, `InstitutionViewAsTest`, `RoleSwitchTest`, `RouteAccessTest` (sweeps the whole route table), `CsrfProtectionTest`, `PostFormsCarryATokenTest`, e2e `auth-login.spec.js` (TC-AUTH-01..04).

### 4.B Authorization (course-level and read-only)

See 3.2. Tests **[A]**: `CourseMembershipTest` (reads the real route table), `AssessmentRoutesScopeTest`, `ClosedOfferingTest`, `CoursePermissionFilterTest`, `ReadOnlyModeTest`, `ReadOnlyOwnThingsTest`, `PermissionRouteTest`, `InstitutionPermissionRouteTest`, `PermissionsTakeEffectTest`, `ParentAuthorizationMatrixTest`, `ParentPrivacyCanaryTest`.

`coursepermission` rules **[A]**: pin / lock a discussion → `discussions.manage`; announcements → `announcements.manage`; grades and submissions → `grading.manage`; anything else → `courses.manage`; replies and reactions need none. Deleting someone else's post / marking an answer is enforced in `DiscussionService` (unread).

### 4.C System administrator (`/admin`)

Group filter `group:admin` + `ajaxredirect`; every route has its own `permission:`.

| Feature | Rules | Status |
|---|---|---|
| **Institutions** list / create / edit / suspend / delete / restore / purge / archive / audit | Code uppercased, `^[A-Z0-9][A-Z0-9_-]{1,49}$`, immutable. Starts `pending`, goes to onboarding. **Suspend** means status `past` (there is no `suspended` status) and only from active / read_only. **Delete** is soft; refused if `active` or if a subscription is still running; exact name typed. **Restore** keeps the previous status. **Purge**: only a deleted institution; exact name; audit entry written first with counts; hard delete with DB cascade; user accounts are **kept** | IMPLEMENTED |
| **Scheduled purge** | `institutions:purge-expired [--dry-run]`: soft-deleted rows older than `deletedInstitutionRetentionDays` (default 90); actor label "system (scheduled purge)" | IMPLEMENTED |
| **Onboarding** (3 steps) | Subscription draft held in session; final step runs in one transaction (subscription, invitation, status, `institution.onboarded`); invitation email sent **after** commit; end date must be today or later | IMPLEMENTED |
| **Subscriptions & plans** | One subscription per institution; start / renew / correct (correction never edits plan or dates); renewal end must be later; history rows never edited; period ≤ 10 years; amount up to 10 digits + 2 decimals; currency default PHP. Plans: name unique 3–100, up to 50 known feature keys, limits `seat_limit` / `storage_limit_mb` / `ai_generation_limit` (NULL = unlimited); never deleted, retired plans cannot be chosen. **No payment provider; billing is record-keeping** | IMPLEMENTED |
| **Limit enforcement** | Seats (students only) on invite / reactivate / add role; storage on upload; AI generations counted from the current term start (or 1 Jan); warning at 90%, full at 100% | IMPLEMENTED |
| **Users** | `admin` is never assignable; a person keeps at least one role; cannot deactivate yourself or the last active system admin; SIS-managed people cannot be edited; exports capped at 10,000 rows with CSV-formula defusing | IMPLEMENTED |
| **Feature flags** | `sis.sync` (default off), `ai.question_generation` (on), `parent.portal` (on). Resolution: global off = kill switch → institution override → plan grants → global on / code default. A note ≤ 500 chars is **required** for on / off; `ends_on` cannot be in the past | IMPLEMENTED |
| **General settings** | System name, timezone (IANA), maintenance mode + message, announcement, expiry timeline, backup alert hours; saved atomically | IMPLEMENTED |
| **Security settings** | 2FA roles, lockout, session timeout, password length, invitation / reset validity (defaults in 6.1) | IMPLEMENTED (service PARTIAL) |
| **Audit log** | Append-only through the model; secrets redacted; export capped at 50,000 rows, formulas defused, itself audited; IP shown only if `auditShowSourceIp` | IMPLEMENTED; **retention is a no-op (V1)** |
| **Backups** | Web request only inserts a `requested` row; `php spark backup:run` takes it; `mysqldump --single-transaction \| gzip`; success = exit code + non-empty file; newest 14 kept; a scheduled one is added if none succeeded in 23 h | PARTIAL against its own decisions (GAP-OPS-03) |
| **Monitoring** | Database (≥ 1000 ms degraded), storage writable, disk (≥ 90% degraded, ≥ 97% down), AI worker, jobs, backups. **The jobs check watches only `subscriptions:sync`** | PARTIAL |
| **Admin home** | "Needs attention": subscription watch, limits at warning / full, backup alert, health rows | IMPLEMENTED |
| **Health** `GET /health` | public; `SELECT 1`; 200 `{status:ok}` or 503 `{status:degraded}`; works under maintenance | IMPLEMENTED |

Tests **[A]**: `InstitutionPurgeTest` (22), `InstitutionServiceTest`, `OnboardingServiceTest`, `OnboardingRollbackTest`, `SubscriptionServiceTest`, `SubscriptionLifecycleTest`, `EffectiveStatusTest`, `PlanServiceTest`, `PlanLimits*Test`, `UserAdminServiceTest`, `FeatureFlag*Test`, `GeneralSettingsTest`, `AuditLogTest` (20), `BackupServiceTest` (12), `MysqldumpRunnerTest`, `SystemHealthTest`, `UsageReportTest`, `Admin*PageTest`, e2e `system-admin.spec.js` (TC-SA-01..12).

### 4.D Institutional administrator (`/institution`)

Group filter `role:institutional_admin`; writes sit in a `readonly` subgroup with a per-area permission. Export and view-as are deliberately outside `readonly`.

| Feature | Rules | Status |
|---|---|---|
| **Structure** (departments, sub-departments, programs) | Code required, ≤ 50, starts alphanumeric, letters / digits / `.` `-` `_`; name 3–255; code unique within its parent; established year 1800 → current year; **cannot switch off a node with active children, courses, or offerings in an active term**; nothing created under an inactive parent; programs may be archived | IMPLEMENTED |
| **Chairperson assignment** (staged) | **Exactly one active chair per sub-department.** A second needs `replace=1` confirmation; replacement runs in one transaction, keeps history, notifies the old chair, audits revoke then assign. A SIS-managed chair cannot be replaced by hand. A person may chair several sub-departments. Waiting `awaiting_chair` requests pass to the new chair implicitly (the list reads live) | IMPLEMENTED (staged) |
| **Dean assignment** | One dean per department and per dean (DB-enforced via `active_marker`); a chairperson cannot be made dean; SIS lock; the first dean in an institution triggers `announceNowNeeded`. **There is no institution-area screen to assign or end a dean**: only the SIS import, a seeder, and role removal / deactivation touch deanships | service IMPLEMENTED; **screen MISSING (MISS-INST-01)** |
| **Courses catalog** | Code unique per program; title 3–255; units > 0 and ≤ 99.9; never deleted; cannot be switched off with offerings in an active term; SIS lock on code / title / units while a connection is enabled | PARTIAL (rules from docs + tests) |
| **Terms** | Name 2–100; year `YYYY-YYYY` (second = first + 1) or `YYYY`; end after start; length ≤ 800 days; name + year unique per institution; overlaps allowed with a **warning**; completed / archived cannot be edited; active → upcoming refused if enrolments exist | IMPLEMENTED |
| **Term close / reopen** (password required) | Close: active offerings → `completed`, `enrolled` places → `completed`, term → `completed`, snapshot of ids stored, audited in the transaction. Reopen: needs a snapshot, **blocked if any offering was copied from the term**; restores only rows still `completed`; term becomes `active` regardless of dates. **Close checks no sign-off** (docs: "Not yet") | IMPLEMENTED |
| **Offerings** | Course must be active, term upcoming / active; section letters / digits / space / `.` `-` `_` ≤ 50, stored uppercase; capacity 1–`MAX_CAPACITY` (docs: 1000); (course, term, section) unique **including archived**; capacity cannot drop below enrolled; archive refused while students are enrolled; instructors only on active offerings, SIS lock on unassign | IMPLEMENTED |
| **Copy offerings** | Target ≠ source, upcoming / active; all-or-nothing in one transaction; an existing section in the target refuses the whole run; dates shift by the difference of the terms' start dates; copies the content tree, grading scheme, optionally instructors; does **not** copy enrolments, submissions, grades, announcements, discussions; blocks reopening the source term | IMPLEMENTED (`copyOne` not read) |
| **Enrolment** | Offering must be active; pasted list capped at 500, split on lines / commas, resolved by student number or email; student must be an active member with the student role; SIS-managed places skipped; already enrolled / completed skipped; dropped / withdrawn reinstated on the same row; over capacity refused unless ticked; statuses enrolled / dropped / withdrawn / completed | IMPLEMENTED |
| **People & invitations** | Assignable roles: institutional_admin, dean, chairperson, instructor, student, parent; last admin protected; no self-deactivation; a student takes a seat; role removal ends chair / dean assignment | PARTIAL (structure only) |
| **CSV upload** (people, courses, enrolments, staff) | Refused while a SIS connection is **enabled**; ≥ 1 non-empty file; per file ≤ 10 MB and ≤ 50,000 rows; token 32 hex, valid 3600 s, scoped to the institution folder; preview writes nothing and equals apply; apply needs password; files removed after apply, kept after a failed apply | IMPLEMENTED |
| **Parent links & portal switch** | Link parents to students; the portal is on only if the Mentora flag `parent.portal` is on **and** the institution has not switched it off | PARTIAL |
| **Profile & branding** | Colours must reach contrast 4.5 (primary against its tint, accent against white); logo PNG / JPEG / WebP ≤ 1 MB, 32–2000 px a side, no SVG, counts toward storage; code read-only | IMPLEMENTED |
| **Academic defaults** | Passing mark, grade scale, default categories (sum 100), attention thresholds (inactivity 14 d, behind 50%, overdue grading 7 d, high load units); institution value else platform value; offerings that already have a scheme keep it | PARTIAL |
| **Announcement banner & security** | Banner ≤ 300 chars, plain text, no `<` `>`, optional end date. Security overrides accept **only stricter** values; a value equal to the platform's is not stored | IMPLEMENTED |
| **Reports / audit / export / home / read-only course view** | Reports reuse Monitor Program numbers, CSV defused and audited; audit scoped to the institution, no source addresses; export allowed when read-only / past, kept 7 days, a failed audit discards it | PARTIAL for export and reports |
| **Approvals** (staged) | `/institution/approvals` lists `awaiting_institution` override requests; approve / reject need `grades.approve` | IMPLEMENTED (staged) |
| **Sign-off** (staged) | `/institution/signoff`; password required to sign off (not to reopen); an admin signs off only where the department has no dean with `grades.signoff`, holds the permission, and is **not an instructor of that section** | IMPLEMENTED (staged) |

Tests **[A]**: `StructureServiceTest` (18), `InstitutionStructureTest` (16), `OneChairpersonTest`, `StructureChairReplaceTest`, `ChairAssignmentServiceTest`, `DeanAssignmentServiceTest` (12), `TermServiceTest` (14), `TermCloseServiceTest` (8), `OfferingServiceTest` (19), `OfferingCopyServiceTest` (14), `EnrolmentServiceTest` (13), `CsvUploadServiceTest` (12), `InstitutionReauthTest` (16), `InstitutionApprovalsPageTest` (4), `InstitutionSignoffPageTest` (3), plus a "read-only institution can look but not change" test per page; e2e `institution-structure.spec.js` (37 tests: TC-STR / CRS / TRM / OFF / ENR / PPL / PAR / UPL / CPY / BRD / ACD / SEC / RPT / VCV / SUB / AUD / VAS / HOM) and `institutional-admin.spec.js` (IAA-01..07).

### 4.E SIS integration

| Piece | Detail | Status |
|---|---|---|
| Connector | Only a **file connector** is registered. Inbound: `<sis.path>/<institution id>/inbound/{students,instructors,courses,enrollments,staff_assignments}.csv`, optional `.sig` (HMAC-SHA256). Outbound: `outbound/grades-<key>.csv` with `course_external_id, student_external_id, percentage, remarks`. No HTTP SIS. Credentials sealed with `encryption.key` | IMPLEMENTED |
| Inbound gates | institution exists → flag `sis.sync` on → connection saved → (real run) connection enabled + effective status `active` + an earlier successful non-test inbound run (a preview counts). An unreadable file fails the run before any write; bad rows are counted and skipped; an unexpected exception rolls back the whole run | IMPLEMENTED |
| Import order | people → courses → enrolments → staff assignments, one transaction; a dry run rolls back; the audit row is written inside the transaction | IMPLEMENTED |
| Staff assignments | go through the dean / chair / offering services; what the SIS stops listing is ended **only if it was SIS-supplied**; nothing is ended if rows were unreadable; instructors ended only in the active term. Staged: the SIS now **replaces** a hand-made chair; a second chair for one sub-department in one file is refused | IMPLEMENTED (staged) |
| Per-row validation | required column empty, value > 255 chars, bad email, units outside 0–99.99, status not enrolled / dropped / withdrawn / completed, bad staff role, repeated key | IMPLEMENTED |
| Grade export | final grades only, new / changed since `sis_grade_exports`; batch key `g<institution>-<24 hex>`; 3 attempts; on failure nothing is marked sent and admins are notified. **Where the institution uses approval, only signed-off sections are sent**; with no dean a finalized grade is enough | IMPLEMENTED (staged) |
| Ownership locks | While a connection is enabled, SIS-owned courses, enrolments, people names / emails, chair / dean assignments and instructor unassign are read-only | IMPLEMENTED |
| Commands | `sis:sync [--institution=ID] [--dry-run]`, `sis:grades` | IMPLEMENTED; **schedule not installed (V4)** |

Tests **[A]**: `SisImporterTest` (30+), `SisImporterRollbackTest`, `SisStaffImportTest`, `SisStaffAssignmentsTest`, `SisGradeExporterTest`, `SisGradeExporterRollbackTest`, `SisConnectionServiceTest`, `FileConnectorTest`, `SisCsvParserTest`, `SisMapperTest`, `AdminSisImportTest`, `InstitutionIntegrationsTest`. **No end-to-end test for SIS.**

### 4.F Course content: modules, units, items, resources

Hierarchy module → unit → item. Item types handled: reading, link, video, pdf, quiz, assignment, discussion. **[A]**

- Status for modules, units, items: `draft | published | inactive`; new ones start `draft` at the end of their parent. A student sees an item only if the item, its unit and its module are all `published`.
- Titles required. Item type is **not whitelisted** in `storeLearningItem`; only the database column can stop an odd value (GAP-CONT-04).
- A quiz or assignment item auto-creates its assessment; a discussion item auto-creates a thread. Link items accept web links only.
- Deleting goes through `ContentDeletionService` (transaction, dependents removed, unused files purged after commit, order renumbered).
- **Uploads**: video ≤ 500 MB, MIME mp4 / webm / ogg / quicktime / x-m4v (server-detected, not the client extension), YouTube and embeds supported; PDF ≤ 25 MB, MIME exactly `application/pdf`, `allow_download`, `display_mode`; replacing a PDF purges the old file when nothing else points at it. The plan storage cap applies. **No virus scan, no ffmpeg or transcoding, no thumbnails** (no ffmpeg / soffice / pandoc anywhere in `app/`).
- **Prerequisites**: `prerequisite_mode` none / previous / custom (custom with an empty list becomes none; previous with no earlier item is refused). A student is locked until the prerequisites are complete; instructors see everything.
- **Completion**: a trackable video (uploaded or YouTube) needs ≥ **90%** (`VIDEO_COMPLETE_PERCENT`); quiz / assignment need a submission that is not In Progress or Cancelled; discussion needs the student to have posted a reply; reading / link / pdf can always be completed. Completed stays completed. Video progress clamps to 0–100 and keeps the maximum.
- Reordering prunes prerequisites that now come later.
- **Resources tab**: three levels (offering / module / unit), search, sort, type filter, list / grid; students see published content only.
- The course page is a tabbed single-page app: overview, announcements, modules, resources, discussions, assessments, grades. **There is no People tab and no course-settings page in the course UI** (MISS-CONT-01).

Tests **[A]**: `ModuleServiceTest`, `ModuleServiceSectionGuardTest`, `LearningProgressServiceTest`, `ResourceServiceTest`, `ContentDeletionServiceTest`, `ResourceRoutesTest`, e2e `modules.spec.js` (TC-MOD-01, 02). **No test with a real video / PDF upload, the exact 25 / 500 MB boundaries, a wrong MIME, or the 90% boundary.**

### 4.G Assessments: quizzes and assignments

| Feature | Rules | Status |
|---|---|---|
| Lifecycle | `Draft \| Published \| Closed \| Archived`. Status changes only via PATCH update or the module status chip (which syncs the assessment). **`Closed` is read everywhere but no service writes it** (AMB-ASM-01). Publishing syncs the learning item (Published → `published`; Draft / Archived → `draft`) and the grade item | IMPLEMENTED |
| Totals | Quiz `total_points` = sum of questions; assignment with a rubric = rubric total; the form value is ignored. `passing_score = total × passing_percentage / 100` | IMPLEMENTED |
| Availability for a student | in order: Closed; not Published; before `available_from`; after `available_until`; past `due_date` unless `allow_late_submission` | IMPLEMENTED |
| **Server validation** | None found for: publishing with zero questions, `available_from < due_date`, `attempt_limit ≥ 1`, `duration > 0`, question text / type / points / choices | **MISSING (MISS-ASM-01)** |
| Quiz attempt start | A GET creates an `In Progress` attempt; an open attempt is resumed; enrolled (`enrolled` or `completed`) required; prerequisite lock blocks; attempts used = submissions not In Progress (**Cancelled counts**); default `attempt_limit` 1; answer keys stripped before the student page | IMPLEMENTED (race: V8) |
| Answer saving | own, `In Progress`, question belongs to the quiz and is Active; refused after `started_at + duration×60 + 60 s` ("Time is up"); one row per (submission, question), last write wins. **No save path for File Upload questions in `QuizAttemptService`** | IMPLEMENTED |
| Submit & scoring | Auto: Multiple Choice and Short Answer with a key. Single choice all-or-nothing; multiple-answer needs the exact set (no partial credit); short answer normalised, case-insensitive unless `case_sensitive`, ≤ 10 accepted answers, no fuzzy match. Status after submit: all auto → `Graded`; mixed → `Partially Graded`; nothing auto → `Submitted`. Submit after time is up is **accepted** and scores only what was saved | IMPLEMENTED |
| Result policy | Quiz "highest" attempt (tie → later); assignment "latest" | IMPLEMENTED |
| Rescoring | After any question create / update / delete / import, finished attempts are re-summed, the grade item `max_points` and the gradebook recalculated; `In Progress` untouched. Editing / deleting questions is **not blocked** while attempts exist; imports require `acknowledge_responses` | IMPLEMENTED |
| Question bank | Multiple Choice (single / multiple / true-false variants), Short Answer, Essay, File Upload (the schema also has `Discussion`); short answer `accepted_answers` JSON ≤ 10, `case_sensitive`; a question picker copies from other assessments | IMPLEMENTED |
| **Import** | Extensions docx rtf csv xlsx txt pdf html htm xml dat zip tst (by **extension only**); file ≤ 5 MB, paste ≤ 200,000 chars, ≤ 300 questions; `.tst` refused; scanned PDF refused ("no selectable text"). Preview writes nothing and holds a token (1 h, bound to person + quiz, single use); apply sends only the token; undo window **10 min**; audited. Errors: empty text, unknown type, points out of range, MC < 2 choices or no correct one, short answer with no key. Warnings: >1 correct, duplicate choice text, bad answer letter. Similar-question flag at overlap ≥ 0.85 | IMPLEMENTED |
| **Export** | docx / rtf (round-trip with a machine-readable line), aiken, gift, csv, xlsx, template-xlsx, printable `test-pdf` / `test-docx` / `test-rtf` (versions, shuffle, key-only, paper) | IMPLEMENTED |
| Assignment submission | Types file / text / link per setting; link must be http(s); file ≤ **20 MB**; **no file-type whitelist**; blocking order: Closed, not Published, before available_from, after available_until, past due without late allowed, attempts exhausted, no submission type on. `is_late_submission` is set after `due_date`; **no penalty (V3)**. Each submission is a new attempt; the unique key stops a race. The enrolment check matches the **course, not the offering** (AMB-ASG-03) | IMPLEMENTED |

Tests **[A]**: `AssessmentServiceTest`, `AssessmentResultServiceTest`, `QuizAttemptServiceTest` (thorough: creation / resume, limit, cancelled counts, closed / not open / past, prerequisite lock, instructor preview, timer incl. the 60 s grace, scoring, double submit, gradebook sync), `AssessmentRescoreServiceTest`, `QuestionServiceTest`, `QuestionImportExportTest`, `QuestionImportHardeningTest` (+ formats / packages / plain / printed-exam), `PrintableTestExportTest`, `AssignmentSubmissionServiceTest` (29), e2e `quizzes.spec.js` (TC-QUIZ-01..03), `assignments.spec.js` (TC-ASG-01, 02), `assessment-editor.spec.js` (no TC ids).

### 4.H AI question builder

| Piece | Detail | Status |
|---|---|---|
| Start (`POST {o}/generate-questions`) | Assessment must belong to the offering; flag `ai.question_generation` on (fails **open** if the flag cannot be read); plan limit via `PlanLimits::generationBlock`; source is always the learning unit the quiz sits in, only **reading and PDF** items; combined source text capped at 400,000 chars; inputs `count` (default 10; **no PHP upper bound found**), `type_mix`, `points_per_question`, options (difficulty, level, language ≤ 40, focus ≤ 200, choices 3–5) | IMPLEMENTED |
| Two modes | `questionBuilder.useJobs=true`: job queued in `ai_jobs`, browser polls, `sync()` applies the finished job. **Default `useJobs=false`**: PHP calls the legacy HTTP service (`POST /generate`, 120 s timeout, 409 = busy) in a loop. PDF scans / images use `POST /extract-text` (OCR) on the legacy service **even in jobs mode** | IMPLEMENTED; end to end UNVERIFIABLE here |
| Job states | `queued → running → completed \| failed \| cancelled`; atomic claim (3 tries); stale after 120 s without a heartbeat → re-queued ≤ 2 times then `worker_lost`; worker gone after 90 s; stalled shown after 90 s; an unreviewed set is not reopened after 14 days; digest cache 30 days per institution | IMPLEMENTED |
| Review tray | Only the batch creator may act (another teacher of the same course cannot). Edit: text required ≤ 5000, type one of four, points 0–1000, ≤ 8 choices, choice ≤ 1000. Accept only `pending`. **Accept is not atomic** (check then update), so two simultaneous accepts could add the question twice. Accept-all with "only clear" leaves out math mismatch, solver disagrees, ungrounded, duplicate, or source similarity ≥ 0.6. Replace (instruction ≤ 200) and top-up re-check flag and plan limit | IMPLEMENTED |
| Internal API | `POST internal/ai/{claim,heartbeat,progress,complete,fail}`; 503 if no secret, 403 if not loopback, 401 on a wrong `X-Worker-Secret` (`hash_equals`); CSRF-exempt; a job is changed only by the worker that holds it | IMPLEMENTED |
| Python worker | Stages ingest → digest → plan → write → check → finish. Deterministic gates drop (never repair) a question: duplicate overlap 0.75, short answers ≤ 8 words, choice-length outlier 2.5, "all/none of the above", positional references. Math check (sympy), blind-solve verification, one regeneration, seeded choice shuffle. Governor + model fallback. `QB_BACKEND=fake` for smoke tests. Ingest ≤ 25 MB, ≤ 150 pages, ≤ 30 OCR pages | PARTIAL (structure and constants read; pipeline not read in full) |

Tests **[A]**: `QuestionGenerationServiceTest`, `QuestionGenerationSafetyTest`, `AiJobGenerationTest`, `AiJobServiceTest`, `LearningUnitContentExtractorTest`, `AiWorkerEndpointsTest` (**6 failures in the 2026-10-23 audit** because it depends on the local `.env` secret, finding 14; fixed or not is unknown), Python `pytest` (15 files, run in CI), manual eval harness (never in CI; 6 golden cases of a 25–30 target; no baseline recorded). **No test of the PHP ↔ Python contract.**

### 4.I Gradebook, grades, overrides, finalization and sign-off

| Piece | Rules | Status |
|---|---|---|
| **Calculation** | Category % = Σ earned / Σ possible over **graded** items × 100, 2 dp; ungraded left out, not zero; excused (`is_missing=1`) always left out. With periods, period % is the weighted average of that period's categories that have data. Final % = Σ(entry % × weight) / Σ(weight) over entries that have data and weight > 0, 2 dp: **weights are normalised** when they do not total 100. Uncategorised items do not count; with periods on, categories with no period are ignored. At finalization **missing work counts as 0** | IMPLEMENTED |
| Bands & remarks | First band, highest first, with `percentage >= minimum_percentage`, else the last band. Remarks: null → `Incomplete`; ≥ passing → `Passed`; else `Failed`. Before finalization the display tag is "On track" / "At risk"; `INC` and `Withdrawn` override. **Default scale** (a placeholder; `docs/samples/README.md` asks the supervisor whether an official scale exists): 1.00 (97–100), 1.25 (94–96.99), 1.50 (91–93.99), 1.75 (88–90.99), 2.00 (85–87.99), 2.25 (82–84.99), 2.50 (79–81.99), 2.75 (76–78.99), 3.00 (75–75.99), 5.00 (0–74.99); passing 75; categories Quizzes 50 / Assignments 50 | IMPLEMENTED |
| Effective grade | override, else frozen `final_percentage` if finalized, else calculated | IMPLEMENTED |
| Scheme | Created on first use from the institution / platform template. **Publishing requires weights to total exactly 100** (tolerance 0.001; with periods, period weights total 100 and each period's categories total 100). Changes refused while finalized | IMPLEMENTED |
| Manual scores, excused, INC | Manual score 0..max or null to clear; excused stores `is_missing=1`; INC needs a reason and an optional `Y-m-d` finish-by date. **INC and override are allowed after finalization** (deliberate for the no-dean path) | IMPLEMENTED |
| **Final-grade override** | If the institution has any active dean (`GradeAuthority::usesApproval`), the call **creates a request**; otherwise it applies at once (0–100, reason required, writes `grade_history`). `request()` does not check finalization | IMPLEMENTED |
| **Override request chain** (see 5.1) | States `awaiting_chair`, `awaiting_dean`, `awaiting_institution`, `returned`, `approved`, `rejected`, `withdrawn`. Nobody decides their own request. Claim / move are conditional UPDATEs, so only one actor wins. Approve is claimed first and restored if the engine fails | IMPLEMENTED (staged) |
| Finalization | Blockers: scheme not Published, publish problem, ungraded handed-in work, open override requests. A transaction freezes each student, inserts `grade_finalizations`, audits, announces ready, tells parents. **Reopen is refused while a sign-off is current**; the message says "dean" even when an administrator signed off. Only the instructor can finalize; instructor and chairperson can reopen; no dean / admin route finalizes | IMPLEMENTED |
| **Sign-off** | States `blocked`, `ready`, `signed_off`. Dean: access to the offering and `grades.signoff` as dean (a dean **may** sign off a section they teach, D3). Institution admin: only where the department's dean is absent or lacks `grades.signoff`, holds the permission, and is **not an instructor of the section** (D4). Single and whole-program sign-off (signs the ready ones, reports the rest). Sign-off needs the password; reopen needs a reason, not a password. Sign-offs stay valid when the signer changes | IMPLEMENTED (staged) |
| SIS gate | `usesApproval` institutions send only signed-off sections | IMPLEMENTED (staged) |
| Offline grade sheet | Row 1 meta prefix + signature; row 2 column keys; row 3 headers; data from row 4. Only manual-item columns are editable. Preview writes nothing; refuses a missing prefix, a signature that no longer matches (scheme / curve / other section), reordered columns; row errors are skipped (not enrolled, listed twice, locked cell changed, score out of range); `EX` = excused (refused for quiz / assignment items). **Apply is refused when grades are finalized.** CSV has a BOM and plain values; XLSX has live formulas | IMPLEMENTED |
| Curves | preview / apply / undo; locked when finalized; one live adjustment per target | PARTIAL (service not read) |
| Reports | class gradebook, summary, student report, "mine" (PDF); audited `grades.report_exported` | PARTIAL |

Tests **[A]**: `GradingEngineServiceTest` (~36), `GradingSchemeServiceTest`, `GradeFinalizationServiceTest` (~30), `GradeSheetTest` (~50), `GradeAdjustmentServiceTest`, `GradeWorkServiceTest` (~34), `RubricServiceTest` (~24), `GradeOverrideRequestServiceTest` (~37), `OverrideRequestFlowTest` (14), `OverrideRequestRaceTest` (4), `AdministratorDecidesOverridesTest` (9), `AdministratorSignsOffTest` (9), `GradeSignoffServiceTest` (~23), `GradeAuthorityTest`, `GradeAuthorityGapsTest`, `SisGradeExporterTest`; JS: `roster-view`, `curve-dialog`, `override-dialog`, `grade-sheet-preview`, `report-menu`, `scheme-editor`; e2e `gradebook.spec.js` (TC-GBK-01..05), `grading.spec.js` (TC-GRD-01..03).

### 4.J Discussions

Anyone enrolled, the assigned instructor, or a chair with access may view and post; dean and institution admin are read-only. Thread title required ≤ 255 (create refuses an overlong title; **edit truncates**); thread body not required; reply body must be non-empty; **no length limit on bodies**; only the author edits a thread; delete by the author or a moderator; a locked thread refuses replies; attachments images / PDF / Office ≤ 25 MB with a MIME whitelist; one reaction per person per post (toggle); mark answer by the thread author or a moderator; unread tracking; filters all / pinned / mine / unanswered / modules / unread; page size 10; a student's reply completes a discussion item. `reply_count` is read-then-write, not atomic. **[A]**

Tests **[A]**: `DiscussionServiceTest` (~40), `DiscussionRoutesTest` (~26). No e2e. No test for a chairperson posting / moderating or attachment rejection.

### 4.K Dean and chairperson areas

**Dean** (`/dean`, `role:dean`, `readonly`) **[A]**:
- Dashboard attention items in priority order: overrides to decide, sign-off ready, sign-off blocked, overdue follow-ups, overdue grading per sub-department, inactive courses, at-risk students.
- Access is derived live from the active department assignment; no assignment shows a "no assignment" page.
- Approvals (approve / reject, with a required reason on reject), sign-off, follow-ups, department announcements, teaching load, department report (CSV + print), Monitor Department, chairperson review (read-only audit of chair writes in courses the chair does not teach; the "reviewed" mark is the dean's), read-only course views (GET only).
- **Department announcements**: `active | ended`, optional `ends_on`, audience everyone / staff / students, scope department / sub-department / program, in-app only (never emailed).

**Chairperson** (`/chairperson`) **[A]**:
- Reach is every offering under the sub-departments the person actively chairs, derived live.
- Same course write routes as the instructor, plus `chairpersoncourseaudit`; finalization is **not** routed (only show and reopen).
- Override requests: `GET chairperson/override-requests`, `POST N/endorse`, `POST N/send-back` (comment required for send-back).
- Follow-ups (create and pass down need `followups.manage`), Monitor Program (`program.monitor`).
- Suspected gap: `AssessmentFeedbackService::postMessage` uses `inGroup('instructor')`, so a chairperson posting feedback on a submission may be treated as a student and refused (**not run**; GAP-GRD-02).

**Follow-ups** **[A]**: a dean may ask chairpersons and instructors; a chairperson may ask instructors; subject 1–200, message ≤ 2000, reply ≤ 2000, due date today or later; a student reference must be in the course; one open request per sender / recipient / subject; overdue = open past the due date, or 7 days after creation if no date; a chair may pass a dean's request down; done by either side, once.

Tests **[A]**: `DeanDashboardServiceTest`, `DeanAttentionRulesTest`, `DeanAccessServiceTest`, `DeanApprovalsPageTest`, `DeanSignoffPageTest`, `DeanCourseViewTest`, `ChairpersonAccessServiceTest`, `ChairpersonReviewServiceTest`, `FollowUpServiceTest`, `MonitorDepartmentServiceTest`, `TeachingLoadServiceTest`, `DepartmentAnnouncementServiceTest`; e2e `dean.spec.js` (TC-DEAN-01..11), `chairperson.spec.js` (5, no TC ids), `monitor-program.spec.js` (3, no TC ids).

### 4.L Student, parent portal and communications

**Student** **[A]**: dashboard widgets profile-card, key-metrics, upcoming-tasks (cap 8), my-courses (progress = completed / total items), announcements (newest 8), reminders (6 soonest pending), quick-access; profile page (read-only: name, email, student number, names — never emails — of parents who currently have access; "No parent can see your progress." when none); courses; calendar; schedule; grades; Announcements app.

**Announcements app** (`GET {area}/announcements`) **[A]**: four levels in fixed order — platform, institution (plain text ≤ 300), department (dean's posts), course (newest 30); `?q=` (≤ 100 chars, every word must match, case-insensitive), `?level=` validated with a fallback; counts taken before the search; a load failure shows an error, not a 500. **No page test.**

**Course announcements** **[A]**: create by instructor / chairperson only, subject required, message free text; update / delete by the author who is still an instructor of the offering; pin by any instructor; attachments ≤ 25 MB each, ≤ 10 each, png jpeg gif webp pdf doc docx xls xlsx ppt pptx; replies (non-empty, no length cap) and reactions (one per user, emoji ≤ 32 chars) for any viewer except a student who dropped the course; feed pinned first then newest, page size 10. **There are no draft / scheduled / published states**: a post is live the moment it is saved (AMB-COM-01). The calendar form can set `created_at` to today or later; that post is visible in the feed immediately and appears on the calendar on that date.

**Calendar** **[A]**: event types assessment, announcement, reminder (class sessions excluded). Students see only `Published` assessments whose module is published and whose item and unit are published; instructors / chairs see everything except `Archived`, drafts marked muted; range inclusive; invalid `from` / `to` → 400; reminders belong to the owner only (404 otherwise) and still work in a read-only institution; a reminder naming a closed offering is blocked. **Schedule**: static weekly grid Mon–Sun, 30-minute rows.

**Notifications** **[A]**: states unread / read only. `send()` is idempotent per (user, `dedupe_key`); urgent also emails (`emailed_at` only on success; a failed email leaves the in-app row). `/notifications` shows the newest 50; the bell feed 7 at a time (`?before=<id>`, limit clamped 1–50). `POST notifications/read` with a numeric `id` marks one; **any non-numeric id marks everything read** (own notifications only). **The bell panel never marks anything read.** Daily triggers (via `subscriptions:sync`): backup failed / stale, subscription expiring / grace / read_only / expired, plan limits 90% in-app / 100% urgent, term ending, no instructor once a term has started, invitations expiring, SIS failure. New course announcements notify **parents only**.

**Parent portal** **[A]**:
- Access is read live from `parent_student_links`; only the parent's current institution; the student must still be an active member and not deleted; a deactivated student stays reachable, a deleted one does not; an offering is visible in any enrolment status (dropped / completed stay visible); a child who is also staff has no grades / assessments / calendar.
- Filters give **404** for an unlinked child or a course the child is not in. A wrong role is redirected to `/`.
- Portal off: pages show `portal-off`; API paths get 403 JSON; the profile and password link still work (intended).
- Dashboard: no children → "not linked"; current = enrolment `enrolled` AND offering `active`, else "past terms"; standing shown only when the scheme is Published; attention order at-risk, missing, final grade (final within 14 days); deadlines within 14 days.
- Course view: 4 tabs only (overview, announcements, assessments, grades); announcements latest 20 pinned first, no replies / reactions / attachments; assessments title / type / due / status label, **no score**; grades only if the scheme is published; PDF report only once final, no item scores or class mean, audited.
- Profile: per-child email checkbox (default on); password link to the parent's own address.
- **`parents:notify`** (every 15 min per the docs): each event once per parent via dedupe key; final grade, INC, withdrawn and at-risk are emailed if the child's switch is on; "work not handed in" is one summary per parent per child per day; a course announcement is in-app only, within the last 3 days **with no upper bound** (a future-dated post notifies at once); copy never contains a grade, percentage or score. The final-grade event is also sent instantly on finalization.

**File serving** (`GET files/{id}`, `session` filter) **[A]**: unauthorised, missing or inactive → **404** (never 403). Owner always; institution logo → any member; institution admin read-only course resources and announcement attachments only (never student work); instructors who teach / chair the offering; students enrolled (`enrolled` or `completed`) with item / unit / module all published and no unmet prerequisite; submission files → the student or that offering's instructors; AI sources → instructors only; announcement and discussion attachments → instructors or enrolled students. **Parents can never fetch a file.** Range requests ≤ 2 MB per slice, 416 when unsatisfiable; inline only for png jpeg gif webp pdf video audio; `nosniff`; `private, max-age=3600`.

Tests **[A]**: `ParentAccessServiceTest` (22), `ParentAuthorizationMatrixTest`, `ParentDashboardServiceTest` (14), `ParentNotifierTest` (31), `ParentsNotifyCommandTest` (7), `AnnouncementServiceTest` (33), `AnnouncementRoutesTest` (11), `CalendarEventServiceTest` (15), `CalendarRoutesTest`, `ScheduleServiceTest` (14), `NotificationsTest` (20), `NotificationPagesTest` (12), `FileAccessServiceTest` (19), `FileRouteTest` (8), `StudentProfilePageTest` (11), `DashboardServiceTest` (28), `DashboardQueryBudgetTest`; e2e `parent.spec.js` (TC-PRT-01..10), `announcements.spec.js` (TC-ANN-01, 02).

---

## 5. State machines and data model

### 5.1 Grade override request chain (staged, plan 4) **[A]**

States: `awaiting_chair`, `awaiting_dean`, `awaiting_institution` (the three **open** states), `returned`, `approved`, `rejected`, `withdrawn`. One open request per student per offering (database unique key on `open_marker`).

**First step** (`OverrideRequestChain::first`): an instructor's request goes to `awaiting_chair` if there is an active chair other than the requester. Otherwise it goes to `awaiting_dean` if there is a dean who holds `grades.approve` as dean and is not the requester, else `awaiting_institution`. A chairperson's request skips the chair step.

| Action | By | From | To | Rules |
|---|---|---|---|---|
| endorse | the active chair of the section's sub-department, acting as chairperson, not the requester | `awaiting_chair` | `awaiting_dean` or `awaiting_institution` | optional comment |
| send back | same chair | `awaiting_chair` | `returned` | comment **required** |
| resubmit | the requester | `returned` | chain restarts | |
| withdraw | the requester | any open state | `withdrawn` | |
| approve | dean with `grades.approve` (department) for `awaiting_dean`; institution admin (same institution, `grades.approve`) for `awaiting_institution` | those states | `approved` | never your own; applies the grade through the engine; records `after_signoff` if signed off |
| reject | same deciders | same states | `rejected` | reason **required** |

Other behaviour: first decision wins and all eligible admins are notified; admins cannot send back; a dean who loses `grades.approve` (or arrives / leaves) causes `retargetDepartment` / `retargetInstitution` and an audit `grade.override_rerouted`; "why it waits" text for a stuck request (one admin who is the requester, or nobody holding the permission). Chair replacement works because the chair list reads live.

### 5.2 Other state machines

| Entity | States and transitions **[A]** |
|---|---|
| Institution | stored `pending → active`; `active ⇄ read_only → past` by dates (`SubscriptionLifecycle::sync` only moves **down**, daily); `active \| read_only → past` by manual suspend; renewal can move back up (`statusAfterRenewal`). Effective status = the worse of stored and date-derived (`EffectiveStatus`). Soft delete needs non-active, no running subscription, typed name; restore keeps the old status |
| Subscription (derived) | `active → expiring` (within `expiryWarningDays` of end, inclusive) `→ grace` (to end + `expiryGraceDays`, full access) `→ read_only` (to + `expiryReadOnlyDays`) `→ expired`. Warning 0 disables `expiring` |
| Academic term | `upcoming ⇄ active`; `active \| upcoming → completed` by close; `completed → active` by reopen. `archived` is checked but **no writer found** |
| Offering | `active → completed` (term close), `active ⇄ archived` (reactivate needs term upcoming / active). Mutations need `active` |
| Enrolment | `enrolled`, `dropped`, `withdrawn`, `completed`; dropped / withdrawn / completed can return to `enrolled` on the same row. The code that *sets* `dropped` / `withdrawn` outside the admin screen was not located |
| Assessment | `Draft ⇄ Published`, `* → Archived`. **`Closed` has no writer** |
| Submission | `In Progress → Submitted → Partially Graded → Grading → Graded`; assignments go straight to `Submitted`; `reopen` returns `Graded → Grading`. **`Returned` and `Cancelled` are read but have no writer** |
| Learning completion | `not_started → in_progress → completed` |
| Finalization / sign-off | finalize (open `grade_finalizations` row) → sign-off (open `grade_signoffs` row). Reopen records `reopened_*` and clears `open_marker`, keeping history |
| Grade adjustment (curve) | `live → undone` (one live per target) |
| Follow-up | `open → done`; overdue derived |
| Department announcement | `active → ended`; also derived by `ends_on` |
| AI batch / job | batch `pending → generating → completed \| failed`; job `queued → running → completed \| failed \| cancelled` with re-queue on stale heartbeat. The two cancel paths differ: `AiJobGeneration` writes `failed` + "Cancelled." while `AiJobService::CANCELLED` exists |
| Backup | `requested → running → succeeded \| failed`. A crashed `running` row has no timeout and **blocks** further backups |
| SIS run | `running → succeeded \| failed` (trigger manual / scheduled / test) |
| Invitation (derived) | pending / accepted / revoked / expired. **Password reset (derived)**: pending / used / revoked / expired |
| Chair assignment | `active → revoked` (reactivation reuses the row and overwrites `assigned_at`); `expired` exists in the enum with no writer. **Dean assignment**: `active → ended` |
| Notification | unread → read |

### 5.3 Data model and integrity **[A]** unless marked

Hierarchy: `institutions` → `departments` → `sub_departments` → `programs` → `courses` → `course_offerings` (+ `academic_terms`) → `enrollments`, `instructor_assignments`, `course_schedules`, announcements, discussions, `course_modules → learning_units → learning_items → learning_resources(files)`, `grading_schemes → periods / categories / items`, `gradebook_records`, `grade_finalizations`, `grade_signoffs`, `grade_override_requests`, `grade_adjustments`, `sis_grade_exports`, `follow_ups`, `reminders`. `assessments` hang off **`courses`** (shared by every section) and tie to a section only through `module_id → course_modules.offering_id`.

Enforced **in the database**: one institution per account; one active dean per department and per dean; one active chair per sub-department (after the staged migration, for rows carrying the marker); one open override request per student per offering; one open sign-off and finalization per offering; one live adjustment per target; one open follow-up per combination; one submission per (assessment, student, attempt); one enrolment per (offering, student); one score per (grade item, student); one gradebook record per (offering, student); one reaction per (announcement, user); one subscription per institution; unique institution code, program code per sub-department, course code per program, section per (course, term); notification dedupe per (user, key); parent-child FK cascades.

**PHP-only, no database backing** (probe each): capacity (two enrolments can race past it); attempt limits; offering tenant consistency (course in institution A, term in B); enrolment before submission; grade weights sum to 100; grade-band overlap / gaps; date ordering on terms, assessments and schedules (`end_time > start_time`); percentage ranges (`progress_percent` 0–255, `passing_percentage` > 100); `student_number` uniqueness; prerequisite cycles and cross-offering prerequisites; parent-link role / institution validity; chair and dean holding the role in the same institution; JSON column shapes; audit append-only (model only; a raw query or cascade can alter it).

**Schema oddities worth testing**: foreign-key argument order (V10); `strictOn` is **false** in `Config/Database.php` (MySQL silently truncates long strings and coerces invalid ENUMs to `''`); duplicate migration timestamps (V11); destructive migrations (`2026-09-28-000002` drops `gradebook_records`; `2026-09-30-000002` moves then drops `assessment_submissions.feedback`; `2026-11-13` deletes extra institution memberships; `2026-11-14-000002` deletes inapplicable permission rows; `2026-09-24-000006` copies seed files into `writable/uploads`); `AssessmentSubmissionModel::$allowedFields` still lists the dropped `feedback`; `ChairpersonAssignmentModel::$allowedFields` lacks `active_marker`; the staged migration's `down()` then re-`up()` fails with a duplicate-column error; enum casing is inconsistent (assessments / submissions / grade scheme capitalised, terms / offerings / enrolments lowercase); free-text status columns (no enum): dean assignments, override requests, follow-ups, department announcements, `ai_jobs.kind` / `.status`.

Tables with **no model** (so model validation does not protect them): notifications, password_resets, user_account_controls, backups, institution_exports, sis_connections / runs / links / staff_assignments / grade_exports, follow_ups (+ replies), parent_child_preferences, parent_alerted_items, department_announcements, chairperson_action_reviews, grade_finalizations, institution_academic_defaults, learning_item_prerequisites, plus Shield's `auth_*`. Only 12 models declare validation rules.

---

## 6. Business rules, settings and limits

### 6.1 Platform settings (`PlatformSettings::DEFINITIONS`) **[A]**

| Key | Default | Bounds | Per-institution override |
|---|---|---|---|
| `auditRetentionDays` | 730 | 30–3650 | no. **Has no effect (V1)** |
| `deletedInstitutionRetentionDays` | 90 | 1–3650 | no |
| `impersonationMinutes` | 30 | 5–240 | no |
| `backupAlertHours` | 24 | 1–720 | no |
| `expiryWarningDays` | 30 | 0–365 | yes (stricter only) |
| `expiryGraceDays` | 14 | 0–365 | yes |
| `expiryReadOnlyDays` | 30 | 0–365 | yes |
| `invitationValidityDays` | 7 | 1–60 | no |
| `passwordResetValidityHours` | 24 | 1–168 | no |
| `passwordMinLength` | 8 | 8–64 | yes (raise only) |
| `sessionTimeoutMinutes` | 120 | 5–120 | yes (shorter only) |
| `lockoutAttempts` | 5 | 0 = never, max 50 | yes (fewer only; an institution cannot choose 0 when the platform is positive) |
| `lockoutMinutes` | 15 | 1–1440 | yes (longer only) |
| `twoFactorRoles` | admin | admin always included | yes (adds roles) |
| `systemName` | Mentora | ≤ 80 | no |
| `supportContact` | empty | ≤ 200 | no |
| `timezone` | UTC | IANA | no |
| `maintenanceMode` / `maintenanceMessage` | off / text | message ≤ 500 | no |
| `announcement` | empty | ≤ 500 | no |
| `auditShowSourceIp`, `institutionAnnouncement(+Until)`, `parentPortalEnabled` | | | per-institution / UI toggles |

Defaults for lockout (5 / 15) come from the docs; the value in code was not shown to the agent.

### 6.2 Limits and thresholds (one place) **[A]**

| Rule | Value |
|---|---|
| Reauth window / wrong attempts | 300 s / 5 |
| Video upload / PDF upload | 500 MB / 25 MB |
| Assignment file | 20 MB, no type check |
| Announcement / discussion attachment | 25 MB each; announcements ≤ 10 per post |
| Question-import file / paste / questions | 5 MB / 200,000 chars / 300 |
| Import preview token / undo window | 3600 s / 600 s |
| CSV upload file / rows / token | 10 MB / 50,000 / 3600 s |
| Enrolment paste | 500 names |
| Term length | ≤ 800 days |
| Logo | PNG / JPEG / WebP, ≤ 1 MB, 32–2000 px |
| Brand contrast | ≥ 4.5 |
| Quiz timer grace | +60 s server grace for saving; submit after expiry still accepted |
| Video "complete" | ≥ 90% |
| Parent dashboard windows | deadlines 14 d; final grade 14 d; missing work 7 d; announcement 3 d |
| AI source text | 400,000 chars; PDF ≤ 25 MB, ≤ 150 pages; stale job 120 s; ≤ 2 re-queues |
| Plan limit states | warning 90%, full 100% |
| Exports | users CSV ≤ 10,000 rows; audit CSV ≤ 50,000; institution export kept 7 days |
| Backups kept | newest 14 (`mentora-*.sql.gz`); scheduled one added if none succeeded in 23 h; pre-deploy dumps newest 10 |
| Notification lists | page 50; bell 7 per fetch (1–50) |
| Dean attention thresholds | inactivity 14 d, behind 50%, overdue grading 7 d |
| Follow-up | subject 1–200; message / reply ≤ 2000; overdue after 7 d if no date |
| Account | `admin:create` password ≥ 12; password min 8–64 |
| Idle / session | 5–120 min; session expiry 7200 s; remember-me 30 days |
| CSRF token expiry | 7200 s |

---

## 7. Cross-layer workflows

Each is a trace the test cases should follow end to end: browser → route / filter → controller → service → table.

**W1. Sign-in (all roles, plus 2FA for admin and any chosen role)**
`GET /login` (Shield view `pages/auth/login.php`) → `POST login` (CSRF, `LoginLockoutFilter`) → Shield session auth → `AdminEmail2FA` action (code emailed, logged in development) → `AuditAuthListener` (`auth.login`, `signed_in_at`) → `GET /` → `RedirectController::index` → `ActiveRoleService::resolve` → role dashboard. Failure paths: wrong password (no email enumeration), locked email, 2FA mail failure outside development (blocks sign-in), no usable role (403), deactivated / idle / signed-out-everywhere (next request is ended, JSON 401 for AJAX).

**W2. Invitation → account**
System admin or institution admin invites (`UserAdminService::invite` / `PeopleService`) → seat check for students (`PlanLimits::seatBlock`) → `institution_invitations` row + hashed token → email → `GET invitation/{token}` → `POST invitation/{token}` (password policy of the *inviting* institution) → account + role + `institution_users` row → `login`. Failure paths: expired, replaced, used, revoked, institution deleted (410), unknown (404), weak password (link still works), mismatch.

**W3. Institution onboarding**
`POST admin/institutions` (`pending`) → onboarding step 2 (subscription draft in session) → step 3 (`OnboardingService::complete`, one transaction: subscription, invitation, status from dates, `institution.onboarded`) → invitation email **after** commit (failure leaves the institution onboarded, invitation resendable) → W2 for the first institutional administrator.

**W4. Course content → quiz → gradebook**
Instructor builds module → unit → item (`ModuleController`, `ModuleService`), quiz item auto-creates the assessment → builds questions (`QuestionService`, import, or AI W10) → publishes via status chip (`syncLinkedAssessment`, `GradeItemSyncService::syncFromAssessment`) → student sees it only if module, unit, item, assessment are all published and prerequisites are met → `GET .../assessments/{a}/take` creates the attempt (V8) → `POST .../answer` (timer + 60 s grace) → `POST .../submit` (auto-score; status Graded / Partially Graded / Submitted) → `GradebookSyncService::syncItemScore` + `LearningProgressService::completeOnSubmission` → grade engine recalculates if the scheme is Published. Editing questions afterwards triggers `AssessmentRescoreService`.

**W5. Assignment → grading**
Student `POST .../assignment-submissions` (type, size, window, attempt checks) → `Submitted` → instructor `PATCH start-grading` → `PATCH grade` (clamped; rubric criteria clamped) → `Graded` + feedback message → student reply allowed only after grading → gradebook sync. `reopen-grading` returns to `Grading` and the previous graded attempt counts again.

**W6. Finalize → sign-off → SIS**
Instructor finalizes (`GradeFinalizationService`: blockers, freeze, audit, notify dean / admin and parents) → sign-off `ready` → dean (or, with no eligible dean, an institution admin who does not teach the section) signs off with password → `SisGradeExporter` sends only signed-off sections when the institution has any dean (`usesApproval`), else any finalized grade → `sis_grade_exports` records what was sent. Reopen path: reopen sign-off (reason) → reopen grades → re-finalize → re-sign.

**W7. Final-grade override request**
Instructor / chair `PATCH grades/roster/students/{s}/override` → request created if the institution has a dean, else applied directly → chain per 5.1 → decision page `dean/approvals` or `institution/approvals` → engine applies → audit + notification. Race: two deciders → one wins, the other sees "That request was just acted on".

**W8. Term close**
`GET institution/terms/{id}/close` (preview) → `POST .../close` (password) → one transaction: offerings and enrolled places to `completed`, term to `completed`, snapshot, `structure.term_closed` → offerings become read-only for instructors / students (`closedoffering`). Reopen only if no offering was copied from the term.

**W9. SIS import**
CSV in the inbound folder (or `institution/people/upload` preview → apply) → `SisImporter::run` gates → one transaction people → courses → enrolments → staff → `sis.import_run` audit → ownership locks switch on while the connection is enabled → `sis:sync` cron (not installed, V4).

**W10. AI question generation**
`POST {o}/generate-questions` → flag + plan-limit checks → text extraction (PHP; OCR via the legacy service for scans) → batch row `generating` → (jobs mode) `ai_jobs` row, worker `claim` → stages with progress / heartbeat → `complete` → `sync()` applies once → tray (edit / accept / replace / top-up / accept-all / discard) → accepted questions go through `QuestionService::createQuestion` → total recalculated and attempts rescored. Notification when a job settles unwatched.

**W11. Parent notification**
Finalization or `parents:notify` cron → `ParentNotifier` (portal on, link live, dedupe key) → in-app row, email if the child's switch is on (never for course announcements) → parent opens `children/{id}/...` (404 filters) → read-only tabs.

**W12. Subscription lapse → read-only**
End date passes → `EffectiveStatus` immediately restricts writes (`ReadOnlyFilter` → 403 JSON `institution_read_only` or a redirect with a message; banners) → nightly `subscriptions:sync` updates the stored status and sends notifications. Renewal lifts the restriction on the next request.

**W13. Backup**
`POST admin/backups` (password) → `requested` row → `backup:run` (cron, every 5 min) → `mysqldump | gzip` → `succeeded` / `failed` → system status and an urgent notification (up to a day late, sent only by `subscriptions:sync`).

**W14. Institution purge**
Suspend → delete (typed name, no running subscription) → retention (default 90 d) → `purge` (password, typed name) or `institutions:purge-expired` (02:30) → audit first, then hard delete with cascade; accounts and uploaded files **remain** (GAP-OPS-01).

---

## 8. External dependencies and scheduled jobs

### 8.1 Dependencies **[A]**

| Dependency | Used for | Verification possible here? |
|---|---|---|
| SMTP (Gmail app password as stopgap; `Config\Email` defaults to `mail`, port 25; `fromEmail` is a hard-coded Gmail address) | admin 2FA codes, invitations, resets, urgent notifications, parent emails. If mail is on defaults outside development an admin **cannot sign in** | No: needs real SMTP. Local `.env` has real SMTP configured, so local QA can send real mail unless pointed at a sink (`E2E_MAIL_SINK`) |
| Groq (OpenAI-compatible, `https://api.groq.com/openai/v1`; default models `openai/gpt-oss-120b`, `qwen/qwen3.8-27b`, `openai/gpt-oss-20b`) | question generation; **source text is sent to the provider** | No: needs a key and the worker |
| Python worker + MySQL job queue (`ai_jobs`, `ai_worker_heartbeats`, `ai_source_digests`) | AI generation | No |
| Legacy Ollama service (`app/main.py`, port 8008, `qwen2.5:3b-instruct`) and Tesseract | default (non-jobs) generation and OCR of scans | No |
| `mysqldump` / `mysql` binaries | backups, deploy dump | Partly (a fake dumper exists in tests) |
| SIS file folder (`sis.path`, default `writable/sis`) | inbound / outbound CSV | Yes (file connector) |
| Local disk | all uploads, exports, backups | Yes |
| GitHub Actions + SSH | CI, deploy, hourly uptime check | No |
| PHP libs: PhpWord, PhpSpreadsheet, dompdf, smalot/pdfparser | Word / Excel / PDF import and export | Yes |
| Browser libs: tiptap, pdfjs, sortablejs, chart.js, emoji-mart | editors, viewers, charts | Yes (browser) |
| Payment provider | **none** | n/a |

### 8.2 Scheduled jobs (`docs/server-setup.md:155-162`) **[A][V4]**

| Command | Schedule in docs | Does |
|---|---|---|
| `backup:run` | `*/5 * * * *` | takes requested backups; adds a daily one |
| `subscriptions:sync [--dry-run]` | `15 2 * * *` | moves institutions down the ladder, sets `Monitoring.subscriptionSyncAt`, **runs every notification trigger** |
| `institutions:purge-expired [--dry-run]` | `30 2 * * *` | purges expired deleted institutions |
| `parents:notify [--dry-run]` | `*/15 * * * *` | parent sweep |
| `sis:sync` | `0 3 * * *` | inbound import |
| `sis:grades` | `30 3 * * *` | outbound grade export |
| `admin:create`, `mail:test` | manual | create a system admin (password ≥ 12); send one test mail |

**None of these are installed by the repo.** `docs/server-setup.md` says no crontab exists for either site user on the current servers, and the deploy workflow has no step that installs or checks one (V4). Consequence if not installed: subscriptions never lapse, backups never run, parent emails never go (only the instant final-grade message), SIS never syncs, purge never happens, and **no notification fires** because every trigger hangs off `subscriptions:sync`. Only that one job is monitored (and `parentsNotifyAt` is not recorded, V5).

---

## 9. Existing automated test coverage

### 9.1 Counts **[A]** (figures disagree across sources; treat as orders of magnitude)

| Suite | Files | Notes |
|---|---|---|
| `tests/unit` | 72 | no database |
| `tests/database` | 171 | needs MySQL; `DatabaseTestCase` refuses a database name without "test" |
| `tests/feature` | 119 | HTTP route tests |
| `tests/session` | 1 | CI4 stub |
| `tests/js` (Vitest) | 43 | jsdom |
| `tests/e2e` (Playwright) | 15 specs + 10 support files | Chromium desktop only; 110 `test(` calls |
| Python (`pytest`) | 15 files | in CI; the eval runner is manual |

PHPUnit total: `docs/testing.md` says 1,735 (stale), the 2026-10-23 audit says 7,138 (6 failures, all `AiWorkerEndpointsTest`), a method count gives ~4,837 before data providers. Vitest: the audit says 374 (1 flaky). Coverage floor 86.5% (`.github/coverage-floor`), diff coverage 80% on PRs, **no JS coverage threshold**.

### 9.2 How tests run

PHP: `composer test` (MySQL on 127.0.0.1, root, no password, database `mentora_test` created by hand). JS: `npm test`. E2E: `npm run build`, `npx playwright install chromium`, `npm run e2e` (drops and recreates `mentora_e2e` every run, migrates, runs the demo seeder, port 8089, workers 1, retries 0). CI (`.github/workflows/ci.yml`): PHP lint 8.2 / 8.5, composer audit, PHPStan level 5, PHPUnit on 8.2 (coverage) and 8.5 with MySQL 8.4, assets (npm audit, lint, Vitest, build), e2e, gitleaks, Python pytest, and a single `ci-passed` gate. Local artifacts `test-results/.last-run.json` (`passed`) and `playwright-report/` (Oct 8) are git-ignored and say nothing about the current tree.

### 9.3 Where there are **no** tests **[A]**

JS: modules authoring (sortable, publish toggle, reading / video / pdf editors), announcement composer, discussions UI, resources UI, quiz-taking UI and timer, grading UI, rubrics UI, calendar beyond read-only, institution settings, login.js. HTTP: real file / video / PDF upload; the Vite dev branch; the PHP ↔ Python worker contract. Server: cron install and behaviour. E2E missing for: discussions, calendar, resources, SIS import / export, backups, feature flags, plans, AI generation, view-as quiz attempt, notifications, password reset, invitation acceptance (except via SA-05), file upload, video, mobile or other browsers. Specific unit-level gaps are in section 14.

---

## 10. The existing QA catalog (`docs/test-cases/`)

A new catalog must extend this one. **[A]**

- **Files**: `README.md` (rules) plus `p1-critical-journeys.md`, `p2-dean.md`, `p2-gradebook.md`, `p2-institutional-administrator.md`, `p2-parent.md`, `p2-system-administrator.md`. The catalog is marked **DRAFT**: cases were drafted from code, seeds and tests, not from a requirements document (there is none). Expected results are unconfirmed until a person who knows the intended behaviour confirms each.
- **ID scheme**: `TC-<AREA>-<NN>`, never reused. Fields: ID, Role, Data, Steps, Expected, Already automated (PHPUnit refs), Playwright (`planned` / `no` / file), Basis, Review (`Draft` or `Confirmed by <name>, <date>`). Priorities P1 (release blockers) / P2 / P3.
- **Counts of IDs**: P1 17 (AUTH-01..05, ANN-01..02, MOD-01..02, QUIZ-01..03, ASG-01..02, GRD-01..03, mostly confirmed on 6–7 Oct 2026); dean 11; gradebook 5; institutional administrator 43 (STR 6, CRS 3, TRM 3, OFF 3, ENR 3, PPL 4, PAR 1, UPL 2, CPY 1, BRD 3, ACD 1, SEC 1, RPT 1, VCV 1, SUB 1, AUD 1, VAS 1, HOM 1, IAA 6; IAA-07 is in the spec, not the doc); parent 10; system administrator 12. About **98** in all.
- **Documented areas**: auth, announcements, module progress, quizzes, assignments, grading, dean, gradebook, institution admin (broad), parent, system admin.
- **Not documented**: chairperson (end-to-end tests exist with no TC ids), discussions, calendar and reminders, resources and files, notifications, student profile, instructor authoring beyond MOD (assessment editor, question import / export, rubrics, AI generation), SIS, backups, feature flags, plans, the roles matrix (platform and institution), password reset and invitation acceptance (beyond SA-05), impersonation of a quiz, subscription lifecycle, read-only mode, plan limits, privacy and security (CSRF, XSS), accessibility beyond the dean / IAA / SA axe checks, dark mode (only IAA-07), responsive layouts, and everything added by plan 4 (administrator approvals and sign-off, one chair per sub-department).
- **Known inconsistencies in the catalog itself**: the README lists only eight area codes though the files use many more; its status text says only P1 cases exist; `p2-dean.md` predates plan 4 (it says the dean decides every request and the chain is chair → dean); SA-05 / SA-06 appear in the institution file only as cross-references; TC-DEAN-09's title was cut off in the agent's grep (verify); the parent doc says student5 is linked to "Roberto Diaz Cruz" while the seeder names only parent1 / parent2 by email (confirm they are the same account).
- **Tests without TC ids**: `assessment-editor.spec.js`, `chairperson.spec.js`, `monitor-program.spec.js`.

---

## 11. Missing requirements (no code and no document found)

IDs are stable references for the test-case work. "Decision" refers to `docs/audit-2026-10-23-decisions.md`.

| ID | What is missing |
|---|---|
| MISS-AUTH-01 | Self-service "forgot password" and "change my password" (the login view links to magic-link, which is off) |
| MISS-AUTH-02 | HTTP security headers: CSP, `X-Frame-Options`, `X-Content-Type-Options`, HSTS; secure-flag cookies; forced HTTPS (V7) |
| MISS-AUTH-03 | Rate limiting on public token endpoints (`invitation/*`, `reset-password/*`) and on reauth beyond the per-session counter |
| MISS-AUTH-04 | Lockout notification or an admin "unlock" (the admin can only wait or turn lockout off) |
| MISS-AUTH-05 | The "allowed sign-in methods" setting (docs say "Not covered") |
| MISS-AUTH-06 | Compromised-password check (`PwnedValidator` disabled) |
| MISS-AUTH-07 | Session listing / concurrent-session limits |
| MISS-INST-01 | A screen to assign or end a dean from the institution area |
| MISS-CONT-01 | A People tab and a course-settings page inside the course UI |
| MISS-CONT-02 | Virus scan, transcoding, thumbnails and duration extraction for uploads |
| MISS-ASM-01 | Server-side validation of question create / update, assessment dates, duration, attempt limit, and "publish needs ≥ 1 question" |
| MISS-ASM-02 | Content sniffing on question import (extension only) and OCR for scanned PDFs (refused instead) |
| MISS-ASG-01 | Late-submission penalty, deduction or grace window (V3) |
| MISS-ASG-02 | Allowed file types for assignment uploads (20 MB cap only) |
| MISS-QZ-01 | Second tab resumes the same attempt (Decision 15.1): `startAttempt` raises a duplicate-key error (V8) |
| MISS-QZ-02 | View-as / instructor-as-student must not start a real attempt (Decisions 3.1 / 3.2, 9) (V8) |
| MISS-QZ-03 | A student answer path for File Upload questions in quizzes |
| MISS-GRD-01 | Role per course via `CourseMembership::roleIn` replacing `inGroup('instructor'/'student')` (Decision 9.1) (V2) |
| MISS-COM-01 | Draft / scheduled / published states for course announcements |
| MISS-COM-02 | A notification to students / instructors when a course announcement is posted (parents only today) |
| MISS-COM-03 | Marking notifications read from the bell panel; paging beyond 50 on `/notifications` |
| MISS-OPS-01 | Audit retention / archiving (V1) |
| MISS-OPS-02 | Purge deleting files, orphaned accounts, profiles, notifications, reminders and export zips (Decisions 10.1–10.4) |
| MISS-OPS-03 | Backup read-back verification, good-only pruning, off-server and encrypted copy, uploads backup, stuck-`running` timeout (Decisions 11.1, 11.2, 11.4, U3) |
| MISS-OPS-04 | Missed-job alerting for all six jobs, on the admin home and by email (Decisions 6.2, U2, D11); `parentsNotifyAt` (V5) |
| MISS-OPS-05 | Deploy installing and verifying the cron jobs (Decision D5) (V4) |
| MISS-OPS-06 | Background institution export (Decision 12.x) and the seventh job |
| MISS-OPS-07 | A class-schedule entry UI and the sixth SIS dataset for class times (Decisions 13, D8); no entry UI is mentioned in the code read |
| MISS-AI-01 | Atomic accept of a generated question; recorded evaluation baseline; a 25–30 case golden set; a named reviewer; a PHP ↔ worker contract test |
| MISS-DOC-01 | Plan 4 task 9: docs for Approvals, Sign-off, one chair, and the gap warning; a `p2-chairperson` catalog |
| MISS-DB-01 | Constraints a tester should probe (see 14.2): cross-institution offering, enrolment of non-students, `student_number` uniqueness, range checks, duplicate settings / responses rows, prerequisite cycles |
| MISS-HTTP-01 | An HTTP SIS connector (only the file connector exists) |

---

## 12. Ambiguous expected results (need a product / owner decision)

These are cases where the code does *something* but the intended result is not stated. A test case for them would record today's behaviour as `Draft` and flag the decision.

### Authentication and platform
| ID | Question |
|---|---|
| AMB-AUTH-01 | Are successful logins written to `auth_logins` when Shield's recording is "failures only"? Lockout resets on a success row; tests assert it works, the config suggests otherwise. Confirm at runtime |
| AMB-AUTH-02 | A wrong role is silently redirected to `/` (no 403 and no message). Intended UX? |
| AMB-AUTH-03 | Maintenance mode: should a signed-in institution admin reach `/` or their dashboard? Today only `admin` and impersonators do |
| AMB-AUTH-04 | `last_seen_at` refreshes on every non-blocked request, including AJAX polling, so a polling page may keep the session alive against the doc's "a page left open does not keep a session alive". Remember-me (30 days) versus `sessionTimeoutMinutes`? |
| AMB-AUTH-05 | Which sensitive actions need the password (3.3)? Delete / suspend / deactivate / reopen sign-off do not; restore / purge / sign-off do |
| AMB-AUTH-06 | An institution cannot choose lockout 0 when the platform is positive. Documented only in validation messages |
| AMB-AUTH-07 | 2FA role matching uses all groups, not the active role: a multi-role person gets a code if any role is selected |
| AMB-AUTH-08 | Navigation tiles for permission-gated pages other than the two Monitor pages stay visible without the permission (route refuses) |
| AMB-AUTH-09 | Maintenance open-path match is a loose regex (`(^\|/)login(/\|$)`): any path with a segment `login` / `health` / `auth` anywhere is open |
| AMB-OPS-01 | "Suspend" sets `past`, the same as lapsed. Does a renewal with a future end date un-suspend a manually suspended institution? (`statusAfterRenewal` says yes) |
| AMB-OPS-02 | Restore returns the old status even if the dates now say otherwise |
| AMB-OPS-03 | Backup freshness alert (`backupAlertHours` 24) can fire after one missed run because the daily backup is taken after 23 h; the urgent notification can be up to a day late |
| AMB-OPS-04 | `parentPortalEnabled` (setting) and `parent.portal` (flag) overlap. The code read says both must be on; confirm |
| AMB-OPS-05 | Reauth window is a constant (300 s); docs say "a setting once the store is in use" |
| AMB-OPS-06 | Lowering a plan's limits below current usage; retroactive effect on existing students |

### Institution and academic structure
| ID | Question |
|---|---|
| AMB-INST-01 | Close a term with unsigned or unfinalized grades: allowed. Intended? |
| AMB-INST-02 | Reopening a term while another is active: no warning (starting one warns) |
| AMB-INST-03 | What happens to `awaiting_chair` requests when a chair is **removed** (not replaced)? Plain removal sends no notice |
| AMB-INST-04 | Does re-enrolling through the Add dialog refresh the gradebook "Withdrawn" tag like the status change does? (`add()` does not call `syncEnrolment`) |
| AMB-INST-05 | A dean role granted on the People page with no department assignment: role without authority |
| AMB-INST-06 | CSV upload previews that are never applied are cleaned only at the next preview |
| AMB-INST-07 | Concurrent chair assigns: friendly error or a raw database error? |

### Courses, assessments, grading
| ID | Question |
|---|---|
| AMB-ASM-01 | `Closed` assessment status: read everywhere, written nowhere. Derived from dates? |
| AMB-ASM-02 | May a quiz with zero questions be published and opened by students? |
| AMB-ASM-03 | What happens to in-progress attempts when a quiz is Closed / Draft / Archived or its questions are edited (rescore skips In Progress)? |
| AMB-ASM-04 | Should `available_until` / `due_date` cut off an attempt already running? (only duration + 60 s does today) |
| AMB-ASM-05 | Should submit after time is up be accepted? (accepted, scoring only what was saved) |
| AMB-ASM-06 | How is a `Cancelled` attempt created, and by whom? It counts against the attempt limit; no setter found |
| AMB-ASM-07 | Multiple-answer: no partial credit; short answer: exact match after normalisation only. Intended? |
| AMB-ASM-08 | True/False storage and import mapping not verified; is File Upload a quiz question type or assignment-only? |
| AMB-ASM-09 | `attempt_limit` 0 or negative; what does "unlimited" mean? |
| AMB-ASM-10 | AI: `count` upper bound; do failed / cancelled generations, top-up and replace consume quota? What should a "needs a look" question show when verification is unavailable? |
| AMB-ASG-01 | Late penalty: is one expected? (V3) |
| AMB-ASG-02 | Weights not totalling 100 are normalised in the engine but publishing requires exactly 100: which governs a scheme published then edited? |
| AMB-ASG-03 | The submit enrolment check matches the **course**, not the offering: may a student enrolled in another section submit? |
| AMB-ASG-04 | Should reopen of a sign-off require the password (sign-off does)? |
| AMB-ASG-05 | Should `request()` for an override be refused while finalized? (accepted today; direct override after finalization is deliberate) |
| AMB-ASG-06 | Should `submitGrade` / `reopenGrading` be refused after finalization? (the frozen value protects the result) |
| AMB-ASG-07 | Should a chairperson posting feedback count as an instructor? |
| AMB-ASG-08 | Grade bands use the minimum only; stored `maximum_percentage` gaps (96.99 → 97) are never checked; a score of 96.995 resolves to 1.25 |
| AMB-ASG-09 | A dean who loses `grades.approve` / `grades.signoff` counts as "no dean" for that duty; where the re-route is triggered from the permission-change code was not verified |
| AMB-ASG-10 | `gradebook_records.remarks`: `Incomplete` (not yet calculated) versus `INC` (formal incomplete) coexist |
| AMB-ASG-11 | The default grade scale and passing mark are placeholders; is there an official transmutation table? (`docs/samples/README.md`) |

### Communications
| ID | Question |
|---|---|
| AMB-COM-01 | A calendar-posted announcement with a future date is visible in the feed, dashboard, Announcements app and parent tab immediately, on the calendar only on its date, and notifies parents at once. Intended? |
| AMB-COM-02 | `notifications/read` with a malformed id marks **everything** read |
| AMB-COM-03 | Course feed duplicates for a multi-role author: `retrieveForOffering(s)` joins `auth_groups_users` without a group filter (chairperson2, dean2 are seeded multi-role). Only the parent tab de-duplicates |
| AMB-COM-04 | At-risk parent notification is told once per child per course, ever (no re-arm after recovery) |
| AMB-COM-05 | Notifier side of "link made in another institution" is untested |
| AMB-COM-06 | Should completed / closed offerings still appear on the schedule and calendar? |
| AMB-COM-07 | Parent notification for a student whose enrolment later changes; old links give 404 |
| AMB-COM-08 | Reaction wording "Pick a reaction." for empty or over-32-char emoji; allowed set not restricted |

### Data
| ID | Question |
|---|---|
| AMB-DB-01 | `assessments.weight_percentage` (legacy) versus grading-scheme weights: which governs? |
| AMB-DB-02 | `assessment_settings.browser_lockdown`, `require_password` / `password`: no enforcement or hashing visible |
| AMB-DB-03 | `files.status='uploading'` is the default and no cleanup of abandoned uploads is visible |
| AMB-DB-04 | `notifications.institution_id` NULL meaning; `follow_ups.student_id=0` meaning "no student" without a FK |
| AMB-DB-05 | `grading_schemes.institution_id` versus `institution_academic_defaults`: which wins over the platform default |
| AMB-DB-06 | Instructors and chairs carry a `program_id` in `user_profiles`, meaningless for them; `active_role` is free text |
| AMB-DB-07 | `departments.established_year` validated only by `exact_length[4]` (0000 / 9999 pass) |

---

## 13. Documentation contradictions (docs versus code)

The code is the stated truth where they differ.

| ID | Document | Says | Code |
|---|---|---|---|
| DOC-01 | `docs/institutional-admin-area.md` (Chairpersons) | several chairs per sub-department | exactly one (staged), confirmed replacement |
| DOC-02 | same (whole doc) | no Approvals or Sign-off sections; no gap warning | pages and warning exist (staged) |
| DOC-03 | same (People) | deans "not yet"; role list omits dean | `dean` is assignable |
| DOC-04 | same (Enrolment) | omits "has already completed this course" skip | present |
| DOC-05 | same (Terms) | a completed term "cannot be edited or reopened from here" | Reopen flow works (only the edit forms refuse) |
| DOC-06 | `docs/sis-integration.md` | four inbound files; nothing about sign-off gating | `staff_assignments` is a fifth; export is gated by sign-off |
| DOC-07 | `docs/institution-onboarding.md` "Not built yet" | no institutional-administrator area; seat limit not enforced | both exist |
| DOC-08 | `docs/security-settings.md` | per-institution overrides "not covered"; lockout / timeout platform-wide | implemented per user and per institution |
| DOC-09 | `docs/course-access.md` | omits the dean's course read access; says delete-others / mark-answer need `discussions.manage` | `deancourse` exists; the filter gates only pin / lock |
| DOC-10 | `docs/notifications.md` | lists only subscription / limit events; says backup and sync-failed "arrive later"; no bell panel | backup, term, SIS, AI, follow-up, override, sign-off, chair, department notifications exist; bell feed exists |
| DOC-11 | `docs/demo-script.md` | profile, notifications, announcements tile, parent and chair pages "not built yet" | all exist |
| DOC-12 | `docs/testing.md` | "1,735 tests", "twenty" Playwright tests, a Playwright checklist item; retired `staging` branch | much larger suite; main is the trunk |
| DOC-13 | `docs/test-cases/README.md` | P1 only; eight area codes | six files, many more codes |
| DOC-14 | `README.md` | workflow mentions a `staging` branch | retired |
| DOC-15 | `docs/audit-2026-10-23.md` | CSRF off, nine permissions inert, dean routes only `role:dean` | CSRF on; permissions enforced (decisions doc is current) |
| DOC-16 | `docs/audit-2026-10-23-decisions.md` 8.2 | "password" is an own-account change allowed when read-only | no self-service password change route exists |
| DOC-17 | `docs/audit-2026-10-23-decisions.md` 10.x / 11.x | purge deletes files and accounts; backups proven readable | not implemented (MISS-OPS-02/03) |
| DOC-18 | `docs/superpowers/plans/2026-10-24-audit-plan-4-approvals.md` | every checkbox unticked; command test in `tests/unit` | code and tests exist; the command test is in `tests/database` |
| DOC-19 | `python-question-builder-service/README.md` | legacy HTTP service "removed once nothing calls it"; material stays in-house | PHP still calls it (default mode, OCR); source text goes to Groq |
| DOC-20 | `docs/question-builder-eval.md` | "today" is the Ollama 3B model; no baseline | the worker uses Groq models |
| DOC-21 | `docs/samples/README.md` | question-bank sample is docx / rtf; sample scheme 40/40/20 | many formats supported; shipped default is 50/50 |
| DOC-22 | `docs/test-cases/p2-dean.md` TC-DEAN-04 | the dean decides | an institution admin decides where there is no eligible dean |
| DOC-23 | `docs/test-cases/p1-critical-journeys.md` TC-ANN-02 | "UI to confirm" that a student cannot post | server refusal confirmed; the student group has no POST route |
| DOC-24 | `docs/notifications.md` / plan | `parents:notify` records `Monitoring.parentsNotifyAt` | it does not (V5) |

---

## 14. Gaps, risks and further investigation

### 14.1 Defect candidates and test gaps (grouped; each is a probe, not a confirmed bug unless marked [V])

| ID | Area | What to probe |
|---|---|---|
| GAP-DB-01 | Schema | **[V]** Seeded chairperson has `active_marker` NULL, so the unique key does not cover it. A second active chair can be inserted by any path that bypasses `ChairAssignmentService`. Also `ChairpersonAssignmentModel::$allowedFields` lacks `active_marker`, the status enum value `expired` has no writer, and reactivating overwrites `assigned_at` |
| GAP-DB-02 | Schema | **[V]** Deleting a user cascades to dean assignments, override requests, sign-offs and department announcements; deleting a file deletes its learning resource (V10). Confirm on a copy database |
| GAP-DB-03 | Schema | Non-strict MySQL: over-long strings are truncated and invalid enums become `''` when written through the query builder |
| GAP-DB-04 | Schema | Migration order within six duplicate timestamps; destructive migrations on a copy with data; `down()` / re-run of the staged migration |
| GAP-AUTH-01 | Auth | Uppercase / whitespace email variants versus the lockout identifier; failed 2FA codes are not counted anywhere |
| GAP-AUTH-02 | Auth | Maintenance filter is tested only through the settings toggle: open paths, admin pass-through, 503 JSON, `Retry-After` |
| GAP-AUTH-03 | Auth | Session fixation / regeneration on login; concurrent sessions; remember-me interaction with `signed_in_at`; secure cookie flag (a `.env` override may exist) |
| GAP-AUTH-04 | Auth | `ReauthController` for a dean who is not an institution admin (home redirect) |
| GAP-AUTH-05 | Auth | Rate limit on sending resets (none seen); audit of `user.password_reset_sent` |
| GAP-AUTH-06 | Auth | `POST role/switch` JSON bodies beyond the three cases in `RoleSwitchTest` |
| GAP-AUTH-07 | Auth | **Decision 3.2**: while viewing as someone, any GET with a side effect (quiz open, notification mark-read) saves state. `ImpersonationFilter` blocks only non-GET |
| GAP-OPS-01 | Ops | **[A]** Purge leaves uploaded files, orphaned accounts and export zips on disk (MISS-OPS-02) |
| GAP-OPS-02 | Ops | Subscription changes: lowering plan limits below usage; "running" means `end_date >= today` only |
| GAP-OPS-03 | Ops | Backups: size > 0 and exit code only; pruning counts all files by name; a crashed `running` row blocks further backups; `RunBackup` command class itself untested |
| GAP-OPS-04 | Ops | Reauth missing on delete / suspend / deactivate / per-institution flag change / integrations test and preview |
| GAP-OPS-05 | Ops | Workflows themselves (CI, deploy, uptime) have no tests; `uptime.yml` runs only the anonymous smoke test (no job-heartbeat check); the CI does not use a filled `.env` (audit 14.2) |
| GAP-CONT-01 | Content | Real video / PDF upload; exact 25 / 500 MB; wrong MIME; plan storage cap hit; prerequisite cycles; read-only mirrors of modules for dean / institution admin |
| GAP-CONT-04 | Content | Learning item `item_type` is not whitelisted by `storeLearningItem` |
| GAP-ASM-01 | Quiz | **[V]** Concurrent start (duplicate-key error) and concurrent submit (double gradebook sync); view-as / instructor-as-student consuming or bypassing the window (V8) |
| GAP-ASM-02 | Quiz | Fractional points; client timer and auto-submit (not read); File Upload question answering |
| GAP-ASM-03 | Quiz | **[V]** Six assessment actions have no routes (publish, unpublish, archive, restore, duplicate, statistics): dead code or missing wiring (V9) |
| GAP-ASM-04 | Import | The exact 5 MB / paste limits; HTTP-level extension rejection; export `format` and printable options through the controller; a real Word-authored docx |
| GAP-GRD-01 | Grades | `GradeFinalizationController`; the chairperson's finalize boundary beyond one route test; locks around `submitGrade` after finalization |
| GAP-GRD-02 | Grades | A chairperson posting feedback may be refused (`inGroup('instructor')`); not run |
| GAP-GRD-03 | Grades | Reopen error message says "dean" even when an administrator signed off (`SIGNED_OFF_MESSAGE`, `GradeSignoffService::tell`, `SisGradeExporter` docblock) |
| GAP-GRD-04 | Grades | Discussions: chairperson posting / moderating, attachment MIME / size rejection, concurrent `reply_count` |
| GAP-COM-01 | Comms | No page test for `AnnouncementsController` (`?q`, `?level`), the student dashboard page, the schedule route, `ReminderService` validation, a reminder tied to a closed offering, the 50-item cap, `sendToMany` alone |
| GAP-COM-02 | Comms | No test for a parent opening `files/{id}` (expected 404), an enrolled student with `completed` status, a student reading another student's submission via an announcement attachment, or the institution-logo rule |
| GAP-INST-01 | Institution | Chair-assign race; revoke notifications; term reopen against an active term; closing a term with open sign-offs; `add()` reinstatement and gradebook sync; approvals page refusing a non-admin (only 4 tests); no SIS / approvals / sign-off end-to-end |
| GAP-AI-01 | AI | **[A]** `AiWorkerEndpointsTest` depends on the local `.env` secret (6 failures in the audit; fixed?); double accept; plan-limit refusal, top-up and replace limits; truncation path; OCR fallback; default `useJobs=false` path; notifications |

### 14.2 Constraints a tester should probe (priority order)

1. Seeded chair has no marker; a second active chair via a non-service writer.
2. User deletion cascades (V10).
3. Offering whose term and course belong to different institutions; SIS and copy paths.
4. Enrolment of a user from another institution or a non-student; submission without enrolment.
5. Capacity overrun under concurrency.
6. Duplicate `student_number`.
7. Out-of-range values: `progress_percent` 0–255, `passing_percentage` > 100, negative `units`, `capacity` 0, `end_time` before `start_time`, `end_date` before `start_date`.
8. Over-long strings and invalid enum values through the builder.
9. Duplicate `assessment_settings` rows, duplicate `assessment_question_responses` per question, two rubrics for one question.
10. Grade weights not totalling 100; overlapping or gapped bands; a scale with no 0% band.
11. Prerequisite cycle / self-reference / cross-offering prerequisite.
12. Duplicate multi-role rows in the announcement list.
13. Two active terms in one institution.
14. Orphans: parent links to deleted users, `institutions.logo_file_id`, `copied_from_offering_id`, SIS ids.
15. Soft-deleted institution: children remain and a restored institution brings its status back.
16. The staged migration's `down()` and re-run.

### 14.3 Areas no agent read in depth (treat as UNVERIFIABLE until read)

`PeopleService`, `InvitationService`, `ParentLinkService`, `AcademicDefaults`, `InstitutionExport`, `InstitutionReports`, `InstitutionSecurity` / `SecuritySettings` bodies, `CourseCatalogService`, `OfferingCopyService::copyOne` / `copyScheme`, `GradeReportService`, `GradeAdjustmentService`, `GradeItemSyncService`, the dean monitor / teaching-load / department-report calculations, `GradeOverrideRequestService` and `GradeSignoffService` bodies, `ModuleService` (2,546 lines, partly skimmed), `FileAccessService` internals, `DiscussionService` moderation rules, the Python pipeline and provider code, the Views and most `resources/js` page modules, `docs/CONTRIBUTING.md`, `docs/deploy.md`, `docs/server-setup.md`, `docs/ci-cd.md`, `docs/mail.md`, `docs/admin-home.md`, `docs/monitoring.md`.

### 14.4 Cannot be established from code alone

Whether the staged migration has run on any database; whether crontab is installed on staging or production; whether real SMTP delivers; whether Groq / the worker produce good questions (no baseline); the runtime behaviour of any claim marked [A] without a test; actual PHPUnit / Vitest / Playwright results on this tree (not run); whether `AiWorkerEndpointsTest` still fails with a local `.env`; the A1 login-recording question (AMB-AUTH-01).

### 14.5 Suggested order for writing the test cases

1. **Confirm the intended behaviour** of the ambiguous items that gate many cases: AMB-ASM-01..06, AMB-ASG-01..05, AMB-COM-01, AMB-INST-01..03, AMB-AUTH-05. These decide expected results.
2. **Run the existing suites once** (with a test database) to establish today's pass / fail baseline before writing around them.
3. **Extend the catalog** by area, in the existing `TC-<AREA>-<NN>` scheme, starting where there are none: plan 4 (administrator approvals, sign-off, one chair), chairperson, discussions, calendar / reminders, notifications, student profile / announcements app, question import / export, rubrics, AI generation, read-only mode, impersonation, roles matrix, password reset / invitation, SIS, backups, flags, plans, privacy / security, responsive and dark mode.
4. **Reuse the "Already automated" convention**: for each case, list the PHPUnit / Vitest / Playwright test that proves the rule underneath (section 4), and mark gaps from section 14.1 as manual.
5. **Keep every new case `Draft`** until a person who knows the intended behaviour confirms the expected result, as the catalog's own rules require.
