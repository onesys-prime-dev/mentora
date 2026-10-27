# Parent Portal, Branch 1: Access Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A parent can sign in, land on `/parent/dashboard`, and is shown the right state (not linked, portal off, or their linked children), with no way to read or change anything else.

**Architecture:** A `ParentAccessService` answers "which children, which offerings" from `parent_student_links`, derived live (as `ChairpersonAccessService` does). A `ParentPortal` class answers "is the portal on for this institution" from the `parent.portal` feature flag AND an institution off-only setting. A `role:parent` + `readonly` route group of GET routes uses both. Branch 1 builds no child pages: the course workspace, dashboard content, notifications and invite changes are branches 2 to 5.

**Tech Stack:** CodeIgniter 4 + Shield (PHP 8.2), PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`, `PeopleFactory`), Vitest/ESLint/PHPStan as gates.

**Spec:** The grill-me requirements summary confirmed on 2026-10-21 (Appendix A below). Branch 1 of the six-branch build order (Q22).

## Global Constraints

- Branch `feat/parent-access`, created from `main` before the first edit. **No commits** (the user reviews and commits). Branch 2 is not started without approval.
- The parent role is monitor-only: the `parent` route group has **GET routes only**; any write is impossible.
- `children.view` **replaces** `courses.view` as the parent's only permission (editable in the roles matrix like the others).
- Parent is the **lowest priority** role when choosing the role a sign-in opens as.
- The portal is on only when the `parent.portal` flag is on for the institution AND the institution has not switched it off. The institution can only switch **off** (never on past the vendor's flag). When unreadable, **fail closed** (off).
- A parent sees only students linked in `parent_student_links` inside their own institution. Anything else answers "no" (404 at the route level, branch 3).
- Parents never use seats.
- UI: shared components and tokens only (`docs/ui-guide.md`); states are designed (loading, empty, error, filled); works at 360 px; wireframes below are **approved before any view is written** (Tasks 4 and 5).
- No new `phpstan-baseline.neon` entries. Test class names unique across `tests/unit`, `tests/feature`, `tests/database`. Test passwords in neutrally named constants.

## Review Focus

- A stale link: the student left the parent's institution (no `institution_users` row) → no access.
- A deactivated student: per Q9 the parent **keeps** access to history, so `canViewChild` stays true while the link and institution membership exist.
- A parent whose active role is another role (instructor + parent) opening `/parent/dashboard` → sent away by `role:parent`, no data.
- Any non-GET request to `/parent/...` → refused (no route, 404/405), and with a read-only institution too.
- Child ids that are `0`, negative, or another parent's child → false.
- A parent linked to students in two institutions → only the current institution's children (`PlanLimits::institutionOf`).
- The feature flag or setting read throws → portal reads as **off**.

---

## Wireframes (approval gate for Tasks 4 and 5)

**A. Not linked** (portal on, parent has no children in this institution). Same shell and components as `pages/dean/no-assignment.php`.
```
  (icon) Parent                        Your children's progress at a glance.
  +--------------------------------------------------------------+
  |                      (info icon)                             |
  |            You are not linked to a student yet               |
  |   Your school links a parent to their child. Once that is    |
  |   done, your child's courses and grades appear here.         |
  |   Ask your institution's administrator if this is a mistake. |
  +--------------------------------------------------------------+
```
**B. Portal off** (platform flag off, or the institution switched it off).
```
  (icon) Parent
  +--------------------------------------------------------------+
  |                      (lock icon)                             |
  |             The parent portal is switched off                |
  |   Your institution is not using the parent portal right      |
  |   now. Nothing has been lost; contact your school.           |
  +--------------------------------------------------------------+
```
**C. Linked (branch 1 landing; branch 2 replaces it with the real dashboard).**
```
  (icon) Parent                        Your children's progress at a glance.
  +--------------------------------------------------------------+
  | Your children                                                |
  |  Ann Lee         Student                                     |
  |  Ben Lee         Student                                     |
  |  Their courses and grades are coming to this page soon.      |
  +--------------------------------------------------------------+
```
**D. Institution settings, new "Parents" tab** (after Security; visible with `institution.settings`).
```
  Institution settings  [Profile][Branding][Academic][Announcement][Security][Parents]
  +--------------------------------------------------------------+
  | Parent portal                                  [ On | Off ]  |
  | Lets linked parents see their child's courses and grades,    |
  | read-only. Switching it off hides it from every parent       |
  | at once; nothing is deleted.                                 |
  | (lock-note, when Mentora has it off: "Switched off by        |
  |  Mentora for your institution. You cannot turn it on.")      |
  |                                       [Save parent portal]   |
  +--------------------------------------------------------------+
```
Saving shows a toast ("The parent portal is switched off"). Read-only institution: the control is disabled with the usual banner.

---

## File Structure

- Modify `app/Config/AuthGroups.php`: add `children.view`; parent gets only it.
- Modify `app/Services/Auth/ActiveRoleService.php`: `preferredRole()` ranks `parent` last.
- Modify `app/Config/FeatureFlags.php`: flag `parent.portal` (default on).
- Create `app/Services/Parents/ParentPortal.php`: is the portal on; set the institution's switch.
- Create `app/Services/Parents/ParentAccessService.php`: children and offerings a parent may reach.
- Create `app/Controllers/Parent/ParentDashboardController.php`, views `app/Views/pages/parent/{not-linked,portal-off,dashboard}.php`.
- Modify `app/Config/Routes.php`: `parent` group; settings tab routes.
- Modify `app/Controllers/Institution/InstitutionSettingsController.php`, `app/Views/pages/institution/settings.php`: Parents tab.
- Tests: `tests/unit/PreferredRoleOrderTest.php` (extend), `tests/database/ParentPortalTest.php`, `tests/database/ParentAccessServiceTest.php`, `tests/feature/ParentRoleRoutesTest.php`, `tests/feature/InstitutionParentPortalSettingsTest.php`; extend `RouteAccessTest` if it lists route groups.
- Docs: `docs/course-access.md` (a parent section) and `docs/institutional-admin-area.md` (the switch).

---

### Task 1: `children.view` permission and role priority

**Files:** Modify `app/Config/AuthGroups.php`, `app/Services/Auth/ActiveRoleService.php`; Test `tests/unit/PreferredRoleOrderTest.php`, `tests/database/PermissionMatrixTest.php` (only if it asserts parent's list).

**Interfaces:**
- Produces: permission string `children.view`; `ActiveRoleService::preferredRole(list<string>): string` now returns, in order, `dean`, `chairperson`, then the first non-`parent` group, then `parent`.

- [ ] **Step 1: Write failing tests** in `PreferredRoleOrderTest`: `['parent','instructor']` → `instructor`; `['parent','student']` → `student`; `['parent']` → `parent`; `['instructor','parent','chairperson']` → `chairperson` (existing order unchanged). In a database test: `config('AuthGroups')->matrix['parent']` equals `['children.view']`, and `children.view` is in `config('AuthGroups')->permissions`.
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/unit/PreferredRoleOrderTest.php` → FAIL.
- [ ] **Step 3: Implement.** Add `'children.view' => 'View the courses and grades of the students linked to the account'` to the permission catalogue (match its existing format); set `'parent' => ['children.view']` in `DEFAULT_MATRIX`. In `preferredRole`, after the dean/chairperson loop, return the first group that is not `parent`, else `$groups[0]`.
- [ ] **Step 4: Run** the unit test, `tests/database/PermissionMatrixTest.php`, `tests/database/ActiveRoleServiceTest.php`, `tests/feature/AdminRolesPageTest.php` → PASS (fix any assertion that hard-coded parent's old `courses.view`).

### Task 2: `parent.portal` flag and `ParentPortal`

**Files:** Modify `app/Config/FeatureFlags.php`; Create `app/Services/Parents/ParentPortal.php`; Test `tests/database/ParentPortalTest.php`.

**Interfaces:**
- Produces: `ParentPortal::enabled(?int $institutionId): bool` (false for `null`, false on any `Throwable`), `ParentPortal::institutionSwitchedOff(int $institutionId): bool`, `ParentPortal::setInstitutionOn(int $institutionId, bool $on, ?User $actor): void`.
- Setting key `parentPortalEnabled` stored per institution through `PlatformSettings` (same store and audit as `InstitutionAnnouncement`); absent = on.

- [ ] **Step 1: Write failing tests** (`ParentPortalTest`): default on; platform flag off globally → `enabled` false even if the institution says on; institution switched off → false; switched back on → true; flag off + institution on → false (institution can't override the vendor); `enabled(null)` false; a throwing flag lookup (unknown key stubbed via a subclass or an uninstalled table) → false; `setInstitutionOn` writes an audit entry (assert via `AuditLogModel`).
- [ ] **Step 2: Run** → FAIL (class missing).
- [ ] **Step 3: Implement** the flag `'parent.portal' => ['description' => 'The parent portal: linked parents see their child\'s courses and grades, read-only.', 'default' => true]` and `ParentPortal` composing `App\Services\Flags\FeatureFlags::isEnabled('parent.portal', $id)` with the setting. Register the new setting key wherever `PlatformSettings` declares valid keys/defaults (follow how `institutionAnnouncement` is declared).
- [ ] **Step 4: Run** `tests/database/ParentPortalTest.php tests/database/FeatureFlagsTest.php` → PASS.

### Task 3: `ParentAccessService`

**Files:** Create `app/Services/Parents/ParentAccessService.php`; Test `tests/database/ParentAccessServiceTest.php`.

**Interfaces:**
- Consumes: `PlanLimits::institutionOf(int $userId): ?int`; tables `parent_student_links`, `institution_users`, `enrollments`.
- Produces:
  - `children(User $parent): list<array{student_id: int, name: string, institution_id: int}>` (current institution only, sorted by name; includes deactivated students; excludes students no longer in the institution).
  - `canViewChild(User $parent, int $studentId): bool`.
  - `canViewOffering(User $parent, int $studentId, int $offeringId): bool` (child linked AND enrolled in that offering, **any** enrollment status).

- [ ] **Step 1: Write failing tests** with `PeopleFactory`/`CourseFactory`: linked child visible; unlinked student not; link in another institution not; student without `institution_users` row not; deactivated student still listed; two institutions → only current; `canViewChild` false for 0, -1, another parent's child, a non-parent user holding no `parent` group; `canViewOffering` true for enrolled, dropped and completed statuses, false for an offering the child is not in, false for an unlinked child.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** with query-builder joins (no stored list). Names from `user_profiles` as in `ParentLinkService`. Reject ids below 1 before querying.
- [ ] **Step 4: Run** the new test plus `tests/database/ParentLinkServiceTest.php` → PASS.

### Task 4: `parent` route group, landing states (UI: wireframes A, B, C)

**Gate:** wireframes A to C approved by the user before the views are written.

**Files:** Modify `app/Config/Routes.php`; Create `app/Controllers/Parent/ParentDashboardController.php`, `app/Views/pages/parent/{not-linked,portal-off,dashboard}.php`; Test `tests/feature/ParentRoleRoutesTest.php`.

**Interfaces:**
- Consumes: `ParentPortal::enabled`, `ParentAccessService::children`.
- Produces: `GET /parent` and `GET /parent/dashboard` in group `['filter' => ['role:parent', 'readonly', 'permission:children.view']]`. The controller picks the view: portal off → `portal-off`; no children → `not-linked`; else `dashboard` (names only, from wireframe C).

- [ ] **Step 1: Write failing tests** (`RouteTestCase`): a parent with children gets 200 and the names; with no children gets "not linked" copy; portal flag off globally → "switched off" copy and **no child name** in the body; institution switched off → same; instructor, student, chairperson, institutional admin, system admin and a logged-out visitor are redirected (no parent content); a user holding instructor+parent whose active role is instructor is redirected from `/parent/dashboard`; `GET /` for a parent redirects to `/parent/dashboard`; `POST`, `PUT`, `PATCH`, `DELETE` on `/parent/dashboard` are refused (404 or 405, none returns 200); a read-only institution still serves the GET.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the group and controller (copy `DeanDashboardController` shape; `'page' => 'dashboard'`). Views use `layouts/app`, `components/page-head` and `components/empty-state` like `pages/dean/no-assignment.php`; escape every name.
- [ ] **Step 4: Run** `tests/feature/ParentRoleRoutesTest.php tests/feature/RouteAccessTest.php tests/feature/RoleSwitchTest.php` → PASS.

### Task 5: Institution "Parents" settings tab (UI: wireframe D)

**Gate:** wireframe D approved by the user before the view is written.

**Files:** Modify `app/Config/Routes.php`, `app/Controllers/Institution/InstitutionSettingsController.php`, `app/Views/pages/institution/settings.php`; Test `tests/feature/InstitutionParentPortalSettingsTest.php`.

**Interfaces:**
- Consumes: `ParentPortal::{enabled,institutionSwitchedOff,setInstitutionOn}`.
- Produces: `GET institution/settings/parents`, `POST institution/settings/parents` (inside the existing `settings` group, so `permission:institution.settings` and `readonly` apply).

- [ ] **Step 1: Write failing tests:** administrator sees the tab with state "On"; saving Off stores it and the next parent request shows wireframe B; saving On again restores; with the platform flag off the form shows the lock note and POST On does not turn the portal on; another institution's setting is untouched; instructor/parent/dean get redirected; a read-only institution refuses the POST; the change writes an audit entry.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** following the announcement tab (`settingsTab`, `data-dialog-form`, the controller's `run()` wrapper that resolves the institution once). Use `components/lock-note` and the existing toast path.
- [ ] **Step 4: Run** the new test plus `tests/feature/InstitutionSettings*Test.php` → PASS.

### Task 6: Seats, docs and full verification

**Files:** Test `tests/database/PlanLimitsTest.php` (extend); Modify `docs/course-access.md`, `docs/institutional-admin-area.md`.

- [ ] **Step 1: Write a failing/guard test:** adding or having parent accounts does not change the seat count or block at the seat limit.
- [ ] **Step 2: Run, fix if red.** Document the parent access rule and the institution switch in the two docs (one short section each).
- [ ] **Step 3: Verify:** `vendor/bin/phpunit` filtered to the new and touched tests; full `tests/feature` and `tests/database` for roles, flags, settings and route access; `vendor/bin/phpstan analyse`; `npx eslint` (if JS touched); the guards `ServiceTestInventoryTest` and `TableTestInventoryTest`. Report real output.

---

## Self-review

- **Spec coverage:** Q11 (priority, `children.view`) → Task 1; Q18 (flag, off-only switch, explanation page) → Tasks 2, 4, 5; Q2/Q9 (link-only access, history kept) → Task 3; Q8 (seats) → Task 6; Q21 landing and Q22 scope → Task 4. Q10 (student's linked-parents list, audit of link changes) is branch 5; notifications branch 4; child routes and the `parentchild` filter branch 3 (kept out here: no route uses them yet).
- **Placeholder scan:** none; each step names tests and signatures.
- **Type consistency:** `ParentPortal::enabled(?int)`, `ParentAccessService::children(User)` are used the same in Tasks 4 and 5.
- **Proportion:** six tasks for a foundation of two small services and one route group.

## Appendix A: Agreed requirements (grill-me, confirmed 2026-10-21)

Monitor-only parent; sees only linked students (`parent_student_links`) in one institution; administrator invites (can name students at invite time, branch 5); no self-registration; seats not used. Workspace reuse with four tabs (Overview, Announcements, Assessments, Grades), child id in the route, GET-only, per-item scores/submissions/feedback/discussions never shown. At-risk/missing/overdue reuse institution thresholds. Notifications to every linked parent (grade finalized, INC/Withdrawn/At risk, new missing work in-app and email; announcements in-app only), no grades in emails, daily batch for missing work, per-child email toggle. Dropped and past-term courses stay visible read-only. `parent.portal` flag with an off-only institution switch and an explanation page. Student sees a read-only "Linked parents" list; PDF downloads and link changes audited. Menu: Dashboard, Calendar, Notifications, Profile. Build order: (1) access, (2) dashboard, (3) course view, (4) notifications and profile, (5) invite and student list, (6) tests and e2e.
