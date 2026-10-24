# Audit Plan 3 — Per-Institution Permissions and Enforcement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permissions are set per institution on top of platform defaults (U1a–U1d). Each role offers only the permissions that do something for it (D2). There is a new `discussions.manage` (D6). Every permission on the Roles pages takes effect (7.1, 7.4–7.7).

**Architecture:**
- **Storage:** `role_permissions` gets an `institution_id` layer, where 0 is the platform default.
- **Checks:** one service answers "may this user do this", applying the user's institution layer on top of the platform matrix. The `permission:` route filter is replaced by one that asks it.
- **Course writes:** a path-rule filter maps each course API write to `courses.manage`, `grading.manage`, `announcements.manage` or `discussions.manage`. The course screens get the same four answers to hide what the person can't use.
- **Institution page:** the institution admin edits their institution's layer on a page that reuses the system admin's grid.

**Tech Stack:** CodeIgniter 4.7 + Shield (groups, `AuthGroups` matrix), MySQL, PHPUnit 10, Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-23-audit-fixes-design.md`, sections F2 and Finding 7. Decisions: `docs/audit-2026-10-23-decisions.md` 7.1, 7.4–7.7, U1a–U1d, D2 and D6.

## Global Constraints

- **Branch and commits:** branch `fix/permissions`, created from `fix/one-institution` at 937e1ab. No commits by Claude: each commit step stages files and states the message. Messages end `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Line endings:** CRLF files (`app/Config/Routes.php` and others) are edited with the Edit tool only, never `sed -i` or a Python text rewrite.
- **Moved to plan 4:** `grades.approve` and `grades.signoff` (7.2, 7.3), the "no one can approve" warning (U1e, U1f) and adding them to the institutional admin's defaults. They need the who-decides resolver (F3), whose only users are in plan 4. In this plan they stay exactly as they are today.
- **Who controls what (U1a):** the institution admin controls only `dean`, `chairperson`, `instructor`, `student` and `parent`, for their own institution. `institutional_admin` is platform-only (system admin). `admin` is locked.
- **Defaults (U1b):** institution rows store only differences from the platform value. Setting a box back to the platform value deletes the row, and untouched boxes follow later platform changes.
- **Safeguards (U1c):** a password check (`reauth`), an audit entry `roles.permission_changed` carrying `institution_id`, and a role keeps at least one permission in its effective set.
- **Academic permissions only (U1d):** platform and institution-administration permissions are never offered to the institution admin.
- **Applicable permissions (D2):** a single map. The pair a role isn't offered is refused by `set`, ignored by `apply`, and its stored rows are deleted by migration.

  | Role | Applicable |
  |---|---|
  | student | `courses.view` |
  | parent | `children.view` |
  | instructor | `courses.view`, `courses.manage`, `grading.manage`, `announcements.manage`, `discussions.manage` |
  | chairperson | the instructor's five + `program.monitor`, `followups.manage` |
  | dean | `courses.view`, `department.monitor`, `grades.approve`, `grades.signoff`, `followups.manage`, `department.announce` |
  | institutional_admin | its current institution-admin permissions + `courses.view`, `grades.approve`, `grades.signoff`, `institution.roles` |
  | admin | locked (its defaults) |

- **Permission list changes:**
  - Add `discussions.manage`, "Moderate course discussions": pin, lock, delete others' posts, mark the answer. Instructor and chairperson defaults.
  - Add `institution.roles`, "Change the academic permissions of the institution's roles". Institutional admin default.
  - Remove `monitoring.manage` (7.7).
  - Add `followups.manage` to the chairperson defaults (7.6).
  - Remove `program.monitor` from the institutional admin defaults (7.5).
- **Course write mapping (7.4, D6):**

  | Course API write | Needs |
  |---|---|
  | `announcements` create, edit, delete, pin, attachments | `announcements.manage` |
  | discussions pin, lock, mark answer, delete someone else's post or reply | `discussions.manage` |
  | `grades/…`, `submissions/…` (grading, feedback), the curve | `grading.manage` |
  | every other write in the course API (modules, units, items, assessments, rubrics, questions, generation, import, resources) | `courses.manage` |
  | replying, reacting, posting and editing one's own discussion | nothing |

  A role without the permission gets a 403 on the write, and doesn't see its controls.
- **Design language:** the institution page looks like the system admin's Roles page (same grid partial). A course tab without its permission looks like the dean's read-only view of that tab.
- **Known local failures and running suites:** the six `AiWorkerEndpointsTest` failures are known. The full suite is run as `vendor/bin/phpunit --no-coverage`. Never run PHP tests while Playwright runs.

## Review Focus

- **Two institutions:** institution A clears `grading.manage` for instructors. An instructor at B is unaffected, and A's own instructor gets 403 on a grade write and sees no grading controls, while still seeing the gradebook.
- **Default follows:** a box the institution never touched follows a later platform change. A box it set keeps its value.
- **Last permission:** an institution clearing a student's `courses.view` is refused, because that is the role's last permission.
- **Someone else's post:** an instructor without `discussions.manage` can still reply and delete their own post, but not delete a student's.
- **Chair without `courses.manage`:** they still can't edit content through the chairperson course API (the same rules apply to the chairperson group).

---

### Task 1: The institution layer in storage

**Files:**
- Create: `app/Database/Migrations/2026-11-14-000001_AddInstitutionToRolePermissions.php`
- Modify: `app/Services/Admin/PermissionMatrix.php:85-98` (`storedOverrides`), `:167-206` (`set`/`replaceRow`)
- Modify: `app/Models/Users/RolePermissionModel.php` (`allowedFields` += `institution_id`)
- Test: `tests/database/PermissionMatrixLayersTest.php`

**Interfaces:**
- Produces: column `role_permissions.institution_id INT UNSIGNED NOT NULL DEFAULT 0`, plus unique key `role_permission (institution_id, role, permission)`, which replaces the existing `role_permission (role, permission)`.
- Produces: `PermissionMatrix::storedOverrides(int $institutionId = 0): list<array{role, permission, granted}>`, the rows of one layer.

- [ ] **Step 1: Write the failing tests**

```php
public function testThePlatformMatrixIgnoresAnInstitutionsRows(): void
{
    db_connect()->table('role_permissions')->insert(['institution_id' => 7, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 0]);
    PermissionMatrix::flush();

    $this->assertContains('grading.manage', (new PermissionMatrix())->effective()['instructor']);
}

public function testTheSameBoxCanBeSetOnceForThePlatformAndOncePerInstitution(): void
{
    $db = db_connect();
    $db->table('role_permissions')->insert(['institution_id' => 0, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 0]);
    $db->table('role_permissions')->insert(['institution_id' => 7, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 1]);

    $this->assertSame(2, $db->table('role_permissions')->where(['role' => 'instructor', 'permission' => 'grading.manage'])->countAllResults());
    $this->assertFalse(@$db->table('role_permissions')->insert(['institution_id' => 7, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 0]));
}

public function testTheSystemAdministratorsChangeIsStoredOnThePlatformLayer(): void
{
    (new PermissionMatrix())->set('instructor', 'announcements.manage', false, CourseFactory::user('admin'));

    $this->assertSame(0, (int) db_connect()->table('role_permissions')->where(['role' => 'instructor', 'permission' => 'announcements.manage'])->get()->getRowArray()['institution_id']);
}
```

(If DB errors throw in tests, wrap the duplicate insert in `try/catch (DatabaseException)`, as `OneInstitutionPerAccountTest` does.)

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/PermissionMatrixLayersTest.php`
Expected: FAIL, because there is no `institution_id` column.

- [ ] **Step 3: Implement**

- Migration `up`: add the column, drop the index `role_permission`, add the unique index `role_permission` on `(institution_id, role, permission)`. `down` reverses it.
- `storedOverrides`: `where('institution_id', $institutionId)`.
- `set`/`replaceRow`: read and write rows with `institution_id = 0`.

- [ ] **Step 4: Migrate and run**

Run: `php spark migrate`, then `vendor/bin/phpunit --no-coverage tests/database/PermissionMatrixLayersTest.php tests/database/PermissionMatrixChangeTest.php tests/feature/PermissionRouteTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: room for each institution's own permission settings`

---

### Task 2: Only permissions that do something, and the list changes

**Files:**
- Modify: `app/Config/AuthGroups.php:73-153`:
  - add `discussions.manage` and `institution.roles` (descriptions as in Global Constraints);
  - remove `monitoring.manage`;
  - add `public const APPLICABLE` (the Global Constraints table);
  - update the defaults (the instructor and chairperson get `discussions.manage`, the chairperson gets `followups.manage`, the institutional admin loses `program.monitor` and gains `institution.roles`).
- Modify: `app/Services/Admin/PermissionMatrix.php`:
  - `apply` ignores a stored row whose pair is not in `APPLICABLE`;
  - `set` throws `InvalidArgumentException('That permission does nothing for that role.')` for one;
  - add `public static function applicable(string $role): list<string>`.
- Create: `app/Database/Migrations/2026-11-14-000002_RemoveInapplicablePermissionRows.php`. It deletes stored rows for pairs that aren't applicable, and every `monitoring.manage` row.
- Modify: `app/Views/pages/admin/roles.php`: a cell whose pair isn't applicable shows a muted "—" with `title="Not used by this role"`, with no form.
- Modify: wherever `monitoring.manage` is checked or listed (`grep -rn "monitoring.manage" app tests`).
- Test: `tests/database/ApplicablePermissionsTest.php`, plus the updates in `tests/feature/AdminRolesPageTest.php` (or whichever test renders `admin/roles`).

**Interfaces:**
- Produces: `AuthGroups::APPLICABLE` (`array<string, list<string>>`) and `PermissionMatrix::applicable(string $role): list<string>`.

- [ ] **Step 1: Write the failing tests**

```php
public function testARoleIsOfferedOnlyWhatDoesSomethingForIt(): void
{
    $this->assertSame(['courses.view'], PermissionMatrix::applicable('student'));
    $this->assertContains('discussions.manage', PermissionMatrix::applicable('instructor'));
    $this->assertNotContains('grades.approve', PermissionMatrix::applicable('instructor'));
}

public function testGrantingAPermissionThatDoesNothingIsRefused(): void
{
    $this->expectExceptionMessage('That permission does nothing for that role.');

    (new PermissionMatrix())->set('student', 'grading.manage', true, CourseFactory::user('admin'));
}

public function testAStoredRowThatDoesNothingIsIgnored(): void
{
    db_connect()->table('role_permissions')->insert(['institution_id' => 0, 'role' => 'student', 'permission' => 'grading.manage', 'granted' => 1]);
    PermissionMatrix::flush();

    $this->assertNotContains('grading.manage', (new PermissionMatrix())->effective()['student']);
}

public function testTheNewDefaults(): void
{
    $m = AuthGroups::DEFAULT_MATRIX;

    $this->assertContains('discussions.manage', $m['instructor']);
    $this->assertContains('discussions.manage', $m['chairperson']);
    $this->assertContains('followups.manage', $m['chairperson']);
    $this->assertNotContains('program.monitor', $m['institutional_admin']);
    $this->assertContains('institution.roles', $m['institutional_admin']);
    $this->assertArrayNotHasKey('monitoring.manage', config(AuthGroups::class)->permissions);
}
```

Roles page test: a student's `grading.manage` cell has no form and shows "Not used by this role". An instructor's `discussions.manage` cell has a form.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/ApplicablePermissionsTest.php`
Expected: FAIL, because `applicable` is undefined.

- [ ] **Step 3: Implement as listed under Files. Every default must be within its role's applicable list (assert it once in `testTheNewDefaults`).**

- [ ] **Step 4: Migrate and run**

Run: `php spark migrate`, then `vendor/bin/phpunit --no-coverage tests/database/ApplicablePermissionsTest.php tests/database/PermissionMatrixLayersTest.php tests/database/PermissionMatrixChangeTest.php`, plus the Roles page test.
Expected: PASS. A test that granted an inapplicable pair to exercise `set` is rewritten with an applicable one (ledger it).

- [ ] **Step 5: Stage**

Message: `feat: each role offers only the permissions that do something for it, and discussions get their own`

---

### Task 3: Checks read the person's institution

**Files:**
- Create: `app/Services/Auth/RolePermissions.php`
- Create: `app/Filters/PermissionFilter.php`, which replaces Shield's `permission` alias in `app/Config/Filters.php`
- Test: `tests/database/RolePermissionsTest.php`, `tests/feature/InstitutionPermissionRouteTest.php`

**Interfaces:**
- Consumes: Task 1's `storedOverrides(int $institutionId)`, Task 2's `APPLICABLE`.
- Produces:
  - `RolePermissions::matrixFor(int $institutionId): array<string, list<string>>`. It applies the institution's rows on top of the platform effective matrix using `PermissionMatrix::apply`. The `defaults` argument is the platform effective matrix, so untouched boxes follow it.
  - `RolePermissions::allows(User $user, string $permission): bool`. It takes the user's groups (`getGroups()`) and the user's institution (`PlanLimits::institutionOf`, or the platform matrix when none), plus Shield's per-user permissions (`$user->can()` covers those). Memoised per user id per request; `RolePermissions::flush()` clears it.
- Produces: the `permission` filter alias, which means `permission:<p>[,<p>…]` passes when `allows()` is true for any of them, with the same refusal responses as Shield's filter (read `vendor/codeigniter4/shield/src/Filters/PermissionFilter.php` and keep its redirect/403 behaviour).

- [ ] **Step 1: Write the failing tests**

```php
public function testAnInstitutionsChangeAppliesToItsOwnPeopleOnly(): void
{
    $a = PeopleFactory::institution('PERMA');
    $b = PeopleFactory::institution('PERMB');
    db_connect()->table('role_permissions')->insert(['institution_id' => $a, 'role' => 'instructor', 'permission' => 'grading.manage', 'granted' => 0]);

    $this->assertFalse((new RolePermissions())->allows(PeopleFactory::person('instructor', institutionId: $a), 'grading.manage'));
    $this->assertTrue((new RolePermissions())->allows(PeopleFactory::person('instructor', institutionId: $b), 'grading.manage'));
}

public function testAnUntouchedBoxFollowsALaterPlatformChange(): void
{
    $a = PeopleFactory::institution('PERMC');
    $teacher = PeopleFactory::person('instructor', institutionId: $a);

    (new PermissionMatrix())->set('instructor', 'announcements.manage', false, CourseFactory::user('admin'));
    RolePermissions::flush();

    $this->assertFalse((new RolePermissions())->allows($teacher, 'announcements.manage'));
}

public function testABoxTheInstitutionSetKeepsItsValueWhenTheDefaultChanges(): void
{
    $a = PeopleFactory::institution('PERMD');
    db_connect()->table('role_permissions')->insert(['institution_id' => $a, 'role' => 'instructor', 'permission' => 'announcements.manage', 'granted' => 1]);
    (new PermissionMatrix())->set('instructor', 'announcements.manage', false, CourseFactory::user('admin'));
    RolePermissions::flush();

    $this->assertTrue((new RolePermissions())->allows(PeopleFactory::person('instructor', institutionId: $a), 'announcements.manage'));
}

public function testSomeoneWithNoInstitutionUsesThePlatformMatrix(): void
{
    $this->assertTrue((new RolePermissions())->allows(CourseFactory::user('admin'), 'roles.manage'));
}
```

`InstitutionPermissionRouteTest` (`RouteTestCase`) mounts `['get', 'probe/grading', fn () => 'allowed', ['filter' => 'permission:grading.manage']]`. Institution A clears it, so A's instructor is refused and B's instructor is allowed.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/RolePermissionsTest.php tests/feature/InstitutionPermissionRouteTest.php`
Expected: FAIL, because the class is missing and the route filter ignores the institution.

- [ ] **Step 3: Implement as in Interfaces. `PermissionMatrix::set` and every write to `role_permissions` call `RolePermissions::flush()` as well.**

- [ ] **Step 4: Run them, and every existing permission test**

Run: `vendor/bin/phpunit --no-coverage tests/database/RolePermissionsTest.php tests/feature/InstitutionPermissionRouteTest.php tests/feature/PermissionRouteTest.php tests/database/PermissionMatrixChangeTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: a permission check reads the person's institution's settings on top of the platform's`

---

### Task 4: The institution admin's permissions page

**Files:**
- Create: `app/Services/Institutions/InstitutionRoles.php`
- Create: `app/Controllers/Institution/RolesController.php`, `app/Views/pages/institution/roles.php`
- Create: `app/Views/components/permission-grid.php`, the grid extracted from `app/Views/pages/admin/roles.php` (which then uses it). Inputs are the roles, the permissions by section, the effective and default matrices, the action URL, the locked role and the applicable map.
- Modify: `app/Config/Routes.php`, in the institution writing group with `readonly` (Edit tool):
  - `get('roles', 'Institution\RolesController::index', ['filter' => 'permission:institution.roles'])`
  - `post('roles/permissions', 'Institution\RolesController::toggle', ['filter' => ['permission:institution.roles', 'reauth']])`
- Modify: `app/Helpers/navigation_helper.php`, adding a "Roles and permissions" entry beside People, shown with `institution.roles`.
- Test: `tests/database/InstitutionRolesTest.php`, `tests/feature/InstitutionRolesPageTest.php`

**Interfaces:**
- Consumes: Task 3's `RolePermissions::matrixFor`/`flush`, Task 2's `applicable`.
- Produces:
  - `InstitutionRoles::ROLES = ['dean', 'chairperson', 'instructor', 'student', 'parent']`
  - `InstitutionRoles::ACADEMIC`: the permissions under "Inside an institution" in `AuthGroups`, minus the institution-administration ones (`structure.manage` … `sis.run`, and `institution.roles`)
  - `InstitutionRoles::set(int $institutionId, string $role, string $permission, bool $granted, User $actor): bool`

- [ ] **Step 1: Write the failing tests**

`InstitutionRolesTest`:
- `testAnInstitutionCanClearAnInstructorPermission`: `set($a, 'instructor', 'grading.manage', false, $admin)` stores a row with `institution_id = $a`. `allows` is false for A's instructor.
- `testSettingTheDefaultValueBackRemovesTheRow`: setting it back to true deletes the row.
- `testTheInstitutionalAdministratorRoleIsNotTheirs`: `'institutional_admin'` throws `'That role is set by the platform.'`. `'admin'` throws the same.
- `testOnlyAcademicPermissionsAreOffered`: `set($a, 'dean', 'people.manage', true, …)` throws `'That permission is not one an institution sets.'`.
- `testARoleKeepsAtLeastOnePermission`: `set($a, 'student', 'courses.view', false, …)` throws `'A role must keep at least one permission.'`.
- `testTheChangeIsAuditedWithTheInstitution`: the `audit_logs` row `roles.permission_changed` has `institution_id = $a`, `target_id = 'instructor'`.

`InstitutionRolesPageTest`:
- The admin sees five role columns and no `institutional_admin` column. A non-applicable cell shows "Not used by this role".
- A POST without a fresh password is redirected to reauth.
- With reauth, a POST changes the box and redirects back with the existing "Granted/Revoked … for …" message.
- An instructor GET is refused.
- A read-only institution's admin can view the page but a POST is refused.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/InstitutionRolesTest.php tests/feature/InstitutionRolesPageTest.php`
Expected: FAIL, because the class and routes are missing.

- [ ] **Step 3: Implement**

`set` mirrors `PermissionMatrix::set`: it stores the difference from the *platform effective* value, undoes the change if the audit entry fails, and calls `RolePermissions::flush()`. The page copy comes first and is exact: "What each role may do in your institution. A box you have not changed follows the platform's setting. Changes apply on the next click and need your password; every role keeps at least one permission." "Edited" marks a box that differs from the platform's.

- [ ] **Step 4: Run them**

Run: `vendor/bin/phpunit --no-coverage tests/database/InstitutionRolesTest.php tests/feature/InstitutionRolesPageTest.php tests/feature/AdminRolesPageTest.php`
Expected: PASS. The system admin's page is unchanged apart from the extracted partial.

- [ ] **Step 5: Stage**

Message: `feat: the institution admin sets the academic permissions of their institution's roles`

---

### Task 5: Course writes ask the right permission

**Files:**
- Create: `app/Filters/CoursePermissionFilter.php` (alias `coursepermission` in `app/Config/Filters.php`)
- Modify: `app/Config/Routes.php`. Add `coursepermission` to the filters of the instructor course API group (`:147`), the chairperson course API group (`~:490`) and the instructor calendar announcement route (`:142`, `announcements.manage`). Use the Edit tool.
- Modify: `app/Services/Courses/DiscussionService.php`. Deleting someone else's thread or reply needs `RolePermissions::allows($user, 'discussions.manage')`; the author's own delete is unchanged.
- Test: `tests/feature/CoursePermissionFilterTest.php`

**Interfaces:**
- Consumes: Task 3's `RolePermissions::allows`.
- Produces:
  - `CoursePermissionFilter::RULES`: ordered `list<array{string, ?string}>` of regex on the path after `…/api/courses/<id>/`, mapped to a permission. The first match wins, and `null` means none needed. Order:
    1. `#^(announcements|discussions)/(\d+/)?(reply|react)$#`, `#^(announcements|discussions)/replies/\d+$#` and `#^discussions(/\d+)?$#` (post/edit/delete own: the service checks others): `null`
    2. `#^discussions/\d+/(pin|lock|answer)$#`: `discussions.manage`
    3. `#^announcements#`: `announcements.manage`
    4. `#^(grades|submissions)/#` and `#^grades$#`: `grading.manage`
    5. anything else: `courses.manage`
  - `CoursePermissionFilter::required(string $path): ?string`. Safe methods (GET/HEAD/OPTIONS) need nothing from this filter.

- [ ] **Step 1: Write the failing tests**

- A data provider for `required()`:

  | Path | Needs |
  |---|---|
  | `modules` | `courses.manage` |
  | `assessments/4/questions` | `courses.manage` |
  | `rubrics/2` | `courses.manage` |
  | `announcements` | `announcements.manage` |
  | `announcements/3/pin` | `announcements.manage` |
  | `announcements/3/reply` | `null` |
  | `discussions/3/pin` | `discussions.manage` |
  | `discussions/3/react` | `null` |
  | `discussions` | `null` |
  | `grades/scheme` | `grading.manage` |
  | `submissions/9/grade` | `grading.manage` |
  | `grades/curve/preview` | `grading.manage` |

- Route tests through the real stack, using an institution A that cleared `grading.manage` for instructors:
  - A's assigned instructor gets 403 on `PATCH instructor/api/courses/{o}/grades/scheme` and 200 on `GET …/grades`.
  - B's instructor is not refused by this filter.
  - An instructor without `discussions.manage` gets 403 on `PATCH …/discussions/{t}/pin`, but can POST a reply and delete their own reply. Deleting a student's thread is refused with the service's message.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/feature/CoursePermissionFilterTest.php`
Expected: FAIL, because the class is missing.

- [ ] **Step 3: Implement as in Interfaces.** The refusal is a 403 JSON `{error: 'permission_denied', message: "Your institution doesn't let your role do this."}`, or a redirect back with that message for a browser request.

- [ ] **Step 4: Run them, and the course route suites**

Run: `vendor/bin/phpunit --no-coverage tests/feature/CoursePermissionFilterTest.php tests/feature/DiscussionRoutesTest.php tests/feature/CalendarRoutesTest.php`, plus `grep -l "api/courses" tests/feature -r`
Expected: PASS. Default permissions grant all four to instructors and chairpersons, so the existing tests are unaffected.

- [ ] **Step 5: Stage**

Message: `feat: each course write asks the permission it belongs to`

---

### Task 6: Course screens hide what the person can't use

**Files:**
- Create: `app/Services/Courses/CourseAbilities.php`
- Modify: `app/Controllers/Courses/CourseController.php`. Pass `courseCan` to the views, and add `'can' => $courseCan` to `window.coursesConfig` (`app/Views/pages/courses/view.php:140-156`).
- Modify: the course feature views and scripts. In the 25 view files and 8 scripts that branch on `isInstructor`, `role === 'instructor'` or `role === "instructor"` (list: `grep -rln "isInstructor\|=== 'instructor'\|role === \"instructor\"\|role !== \"instructor\"" app/Views/pages/courses resources/js/pages/courses`), change each branch that shows an **edit control** to its ability. A branch that chooses **what data** to show (the class gradebook versus one's own, drafts, class-wide progress) stays on the role.
- Test: `tests/feature/CourseAbilitiesViewTest.php`, `tests/js/pages/course-abilities.test.js`

**Interfaces:**
- Consumes: Task 3's `RolePermissions::allows`.
- Produces: `CourseAbilities::for(User $user, string $role): array{content: bool, grading: bool, announcements: bool, discussions: bool}`. It returns all false for a read-only viewer (`CourseViewer::isReadOnlyViewer`) and for a student. Otherwise it maps to `courses.manage`, `grading.manage`, `announcements.manage` and `discussions.manage`.
- Produces: `window.coursesConfig.can` with the same four keys.

- [ ] **Step 1: Write the failing tests**

`CourseAbilitiesViewTest` (`RouteTestCase`) works on an offering with one module, one assessment, one announcement and one discussion thread. For each ability, it clears the permission in the instructor's institution, then requests the tab, and asserts:
- **Content tab:** the module editor controls are absent. The module list is still present.
- **Grades tab:** the class roster is present, but the curve, override and scheme edit controls are absent.
- **Announcements tab:** the composer is absent, and the announcement is still shown.
- **Discussions tab:** pin, lock and mark-answer are absent, but the reply box is present.
- **Nothing cleared:** all controls are present.

Pick each control's existing `data-*` hook from the view as you go, and name it in the test.

`course-abilities.test.js`: mount each feature's script with `coursesConfig.role = "instructor"` and `can` set to false for that feature. The edit buttons the script renders aren't there.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/feature/CourseAbilitiesViewTest.php`, and `npx vitest run tests/js/pages/course-abilities.test.js`
Expected: FAIL, because the controls are still shown.

- [ ] **Step 3: Implement `CourseAbilities` and switch each edit-control branch.** Ledger any branch whose meaning was unclear, with the choice made.

- [ ] **Step 4: Run them, and the course page suites**

Run: the two commands above, plus `vendor/bin/phpunit --no-coverage tests/feature/DeanCourseViewTest.php tests/feature/InstitutionCourseViewTest.php`, plus `npm test`
Expected: PASS. The read-only views of the dean and admin are unchanged.

- [ ] **Step 5: Stage**

Message: `feat: a course tab without its permission shows what is there, without the controls`

---

### Task 7: The other permissions take effect

**Files:**
- Modify: `app/Config/Routes.php` (Edit tool):
  - `permission:courses.view` on the course page and course API routes of the student, instructor and chairperson groups (the dean and institution groups have it already);
  - `permission:program.monitor` on `monitor-program` and the `api/monitor-program` group (`:472-479`);
  - `permission:department.monitor` on `monitor-department` and its `api` (`:360-362`);
  - `permission:followups.manage` on `POST follow-ups` (dean `:340`, chairperson `:449`) and `follow-ups/(:num)/pass-down` (`:450`);
  - `permission:audit.view` on `admin/audit-log` and its export (`:877-878`), and `permission:audit.manage` on `admin/audit-log/settings` (`:879`, keeping `reauth`).
- Modify: the views that offer those actions. Hide the follow-up "New" and "Pass down" buttons without `followups.manage`; hide the Monitor Program/Department entries in `app/Helpers/navigation_helper.php` without their permission; hide the audit settings form without `audit.manage`.
- Test: `tests/feature/PermissionsTakeEffectTest.php`

**Interfaces:**
- Consumes: Task 3's `permission` filter.

- [ ] **Step 1: Write the failing tests**

One test per row. Clear the permission in the person's institution (or on the platform for `audit.*`), then assert the route refuses and the control or nav entry is gone. With the permission, assert both work:

| Who | Permission cleared | Route refused | Gone from the page |
|---|---|---|---|
| chairperson | `program.monitor` | `GET chairperson/monitor-program` | nav entry |
| dean | `department.monitor` | `GET dean/monitor-department` | nav entry |
| chairperson | `followups.manage` | `POST chairperson/follow-ups` | "New follow-up" button |

Then the cases that don't fit the table:
- **Follow-up replies:** with `followups.manage` cleared, a received follow-up can still be replied to and closed.
- **Instructor without `courses.view`:** `GET instructor/courses/{o}/{slug}` is refused.
- **Audit:** `audit.manage` can't be cleared for the locked `admin` role, so assert the filter is on the settings route: read `service('routes')` for `admin/audit-log/settings` and check its filters include `permission:audit.manage`.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/feature/PermissionsTakeEffectTest.php`
Expected: FAIL, because the routes don't check.

- [ ] **Step 3: Implement as listed under Files.**

- [ ] **Step 4: Run them, and the affected suites**

Run: `vendor/bin/phpunit --no-coverage tests/feature/PermissionsTakeEffectTest.php`, plus the follow-up, monitor, audit-log and navigation tests (`grep -rl "follow-ups\|monitor-program\|monitor-department\|audit-log\|navigation" tests --include=*.php`)
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: every permission on the Roles pages takes effect`

---

### Task 8: Full check and docs

- [ ] **Step 1: Run every suite, one at a time**

Run: `vendor/bin/phpunit --no-coverage`, then `npm test`, then `npm run build && npm run e2e`
Expected: PASS, apart from the six known `AiWorkerEndpointsTest` failures. A Playwright journey that clicks a control that is now hidden or refused for a role it shouldn't have is a finding. Default permissions keep every current journey working.

- [ ] **Step 2: Docs**

- Update the system admin Roles doc (`grep -ril "roles and permissions\|roles.manage" docs/*.md`):
  - applicable cells only;
  - the new and removed permissions;
  - the institution layer.
- Add a "Roles and permissions" section to `docs/institutional-admin-area.md`, covering who, what, the safeguards and the audit entry.
- Note in `docs/course-access.md` which course action needs which permission (the Global Constraints table).

Stage them, and hand over the `git add` list with the task messages.
