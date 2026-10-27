# Audit Plan 4 — Who Approves and Signs Off; One Chairperson Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** These flows never get stuck: grade-override requests and grade sign-off always reach someone who may decide them (findings 1, 2, F3, 7.2, 7.3, U1e, U1f, D3, D4). Each sub-department has exactly one chairperson, and chair actions can't collide (finding 16, U5).

**Architecture:**
- **One resolver:** `GradeAuthority` answers "who decides here": the department's dean if they hold the permission, otherwise the institution's admins who hold it. The override chain, sign-off and SIS export all ask it.
- **Admin screens:** institution admins get Approvals and Sign-off pages, which reuse the dean's views with the area as a parameter.
- **Chairs:** chairperson assignments get an `active_marker`, with a unique key per sub-department. Endorse, send back and withdraw become conditional updates, as the dean's decision already is.

**Tech Stack:** CodeIgniter 4.7 + Shield, MySQL, PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`, `CourseFactory`, `PeopleFactory`, `DeanFactory`, `GradeFactory`), Playwright.

**Spec:** `docs/superpowers/specs/2026-10-23-audit-fixes-design.md` § F3, Findings 1, 2, 16. Decisions: `docs/audit-2026-10-23-decisions.md` 1.1–1.6, 2.1–2.5, 7.2, 7.3, 16.0–16.1, U1e, U1f, U5, D3, D4.

## Global Constraints

- **Branch and commits:** branch `fix/approvals`, created from `fix/permissions` at 27f2012. No commits by Claude: each commit step stages files and states the message. Messages end `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Line endings:** CRLF files (`app/Config/Routes.php`, several services) are edited with the Edit tool, or with a Python script written to a file (shell heredocs collapse backslashes). Never `sed -i` on them.
- **Permission checks:** `RolePermissions::allows($user, $permission, $asRole)` (plan 3), with the role being decided as: `dean` or `institutional_admin`.
- **"No dean" (7.2, U1e):** a department has "no dean" for a duty when it has no active dean, or its dean lacks that duty's permission (`grades.approve` for overrides, `grades.signoff` for sign-off).
- **Whether approval is used at all (2.2):** an institution uses approval and sign-off when it has any active dean assignment, whatever their permissions. With none, overrides stay direct and finalized grades go straight to the SIS, as today.
- **Nobody decides their own (1.5, D3, D4):**
  - No one decides their own override request.
  - A dean may sign off a section they teach (D3).
  - An institution admin may not sign off a section they teach (D4); another admin must.
- **Exact copy:**
  - **One admin, own request (1.6):** "This needs another administrator: you can't decide your own request."
  - **No one may decide (7.3):** "Nobody in your institution can decide this yet. An administrator needs the permission to approve overrides."
  - **One admin, own section (D4):** "You teach this section, so another administrator signs it off."
  - **Messages naming who acted:** "the dean …" or "the administrator …".
- **One chairperson (16.0):** exactly one active chairperson per sub-department.
  - **Replacing:** a new chair replaces the old one only after confirmation (16.0b). The old assignment ends (status `revoked`, history kept), and the old chair is notified.
  - **Waiting requests (16.0c, U5):** requests waiting for a chair pass to the new chair, who is notified.
  - **Existing data (16.0d):** where a sub-department has several chairs, the most recently assigned one is kept, by migration.
- **Known local failures:** the six `AiWorkerEndpointsTest` failures are known. Run the full suite as `vendor/bin/phpunit --no-coverage`, and never alongside Playwright.

## Review Focus

- **Dean loses `grades.approve`:** a request waiting for that dean moves to the admins straight away. When the permission is restored, it moves back to the dean.
- **Two admins at once:** two admins decide the same `awaiting_institution` request together. Exactly one wins, and the other is told it was just decided.
- **One-admin institution:** the admin also teaches, and their own section is ready for sign-off. It waits, the admin sees the D4 message, and no sign-off is possible.
- **Replacing a chair:** a chair is replaced while requests wait for them. The new chair sees them in their queue and is notified, and the old chair can no longer act on them.
- **Endorse and withdraw together:** the chair endorses at the same moment the instructor withdraws. One of them wins, the request ends in one state, and one notification goes out.

---

### Task 1: One answer to "who decides here" (F3), and the admin's defaults (7.3)

**Files:**
- Create: `app/Services/Courses/Grades/GradeAuthority.php`
- Modify: `app/Config/AuthGroups.php`: the `institutional_admin` defaults gain `grades.approve` and `grades.signoff` (they are already applicable).
- Modify: `app/Services/Courses/Grades/GradeOverrideRequestService.php`:
  - `appliesTo` (`:39-52`) uses `usesApproval`;
  - `deanId` (`:~780`) becomes `GradeAuthority::deanFor($dept, 'grades.approve')`.
- Modify: `app/Services/Courses/Grades/GradeSignoffService.php:38-41` (`appliesTo`).
- Modify: `app/Services/Sis/SisGradeExporter.php:240-250`, where `signedOffSections`' own dean query becomes `usesApproval`.
- Test: `tests/database/GradeAuthorityTest.php`

**Interfaces:**
- Produces:
  - `GradeAuthority::deanFor(int $departmentId, string $permission): ?int`: the active dean's user id, or null when there is no dean or they lack the permission (asked as `dean`).
  - `GradeAuthority::institutionAdmins(int $institutionId, string $permission, array $except = []): list<int>`: active, non-deactivated institutional admins of the institution who hold the permission (asked as `institutional_admin`), minus `$except`.
  - `GradeAuthority::usesApproval(int $institutionId): bool`: any active dean assignment in the institution.

- [ ] **Step 1: Write the failing tests**
  - `testADeanWithThePermissionDecides`: the department's dean gives `deanFor(..., 'grades.approve')` equal to the dean's id.
  - `testADeanWithoutThePermissionCountsAsNoDean`: institution rows clear `grades.approve` for deans, so `deanFor` returns null, while `deanFor(..., 'grades.signoff')` still returns the dean.
  - `testTheAdministratorsWhoHoldThePermission`: two admins, one deactivated (`AccountControl::deactivate`), gives `[the active one]`. `$except` removes one.
  - `testApprovalIsUsedWhenTheInstitutionHasAnyDean`: true with one active dean. False with none, or with only an ended deanship.
  - `testTheAdministratorHoldsBothByDefault`: `AuthGroups::DEFAULT_MATRIX['institutional_admin']` contains both permissions.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/GradeAuthorityTest.php`
Expected: FAIL, because the class is missing.

- [ ] **Step 3: Implement, and swap the three callers.** Use `DeanAssignmentModel::currentDeanOfDepartment` for the dean. Admins come from `institution_users` joined to `auth_groups_users` (`group = 'institutional_admin'`), active and not deactivated (as `PeopleService::activeAdministrators` reads them).

- [ ] **Step 4: Run them, and the grade suites**

Run: `vendor/bin/phpunit --no-coverage tests/database/GradeAuthorityTest.php`, plus `grep -rl "GradeOverrideRequest\|GradeSignoff\|SisGradeExporter" tests --include=*.php`
Expected: PASS. With default permissions nothing changes.

- [ ] **Step 5: Stage**

Message: `feat: one answer to who decides a grade override or a sign-off`

---

### Task 2: The administrator decides when there is no dean (1.1–1.6)

**Files:**
- Modify: `app/Services/Courses/Grades/GradeOverrideRequestService.php`:
  - `decidable` (`:657-671`);
  - `claim` (`:677`), which takes the status it expects;
  - `approve`/`reject` (`:396-480`), including the give-back on failure;
  - `tellDeanIfWaiting` (`:~695`), which becomes `tellDeciders`;
  - `request`/`resubmit` messages and `routeText` (`:335-357`).
- Modify: `app/Services/Institutions/InstitutionRoles.php` and `app/Services/Admin/PermissionMatrix.php`. After a change to `grades.approve` for `dean` or `institutional_admin`, call `retargetDepartment` for every department of that institution, or of every institution for a platform change.
- Test: `tests/database/AdministratorDecidesOverridesTest.php`

**Interfaces:**
- Consumes: Task 1's `GradeAuthority`.
- Produces: `approve(User $decider, int $requestId, string $comment = '')` and `reject(User $decider, int $requestId, string $reason)`. Same signatures as today, now accepting an institutional admin for `awaiting_institution`.

- [ ] **Step 1: Write the failing tests** (department without a dean, in an institution that uses approval)
  - `testARequestWithNoDeanWaitsForTheAdministratorsAndTellsThemAll`: an instructor's request ends in `awaiting_institution`. Both admins get a `grade_override` notification, and the requester doesn't.
  - `testAnAdministratorApprovesAndTheGradeChanges`: approve gives `approved`, the gradebook override is set, and the requester is told "the administrator approved your request".
  - `testAnAdministratorRejectsWithAReason`: an empty reason is refused, and with a reason the result is `rejected`.
  - `testTheFirstAdministratorsDecisionWins`: admin A approves, then admin B's reject is refused with "That request was just decided or withdrawn."
  - `testNobodyDecidesTheirOwnRequest`: an admin who is also the section's instructor asks, and can't approve it.
  - `testWithOneAdministratorTheirOwnRequestWaitsAndSaysWhy`: the only admin is the requester, so the request waits and `request()`'s message ends with the 1.6 copy.
  - `testWithNobodyHoldingThePermissionItWaitsAndSaysWhy`: the institution clears `grades.approve` for admins, so the message ends with the 7.3 copy.
  - `testADeanWhoLosesThePermissionHandsTheirQueueToTheAdministrators`: the request is `awaiting_dean`, `InstitutionRoles::set($inst, 'dean', 'grades.approve', false, $admin)` makes it `awaiting_institution`, and granting it again moves it back.
  - `testAnAssignedDeanTakesOverWhatWaitsForTheAdministrators` (1.2): a new dean is assigned, and the request becomes `awaiting_dean`.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/AdministratorDecidesOverridesTest.php`
Expected: FAIL, because an admin isn't allowed to decide and no admins are notified.

- [ ] **Step 3: Implement**
  - `decidable($user, $id)` returns the row when either:
    - status `awaiting_dean`, the user is acting as `dean` with access to the offering, and `allows(dean, 'grades.approve', 'dean')`; or
    - status `awaiting_institution`, the user is acting as `institutional_admin`, in the offering's institution, with `allows(…, 'grades.approve', 'institutional_admin')`.

    In both cases the user must not be the requester.
  - `claim` updates only `WHERE status = <the row's status>`. On a failed grade change, the status goes back to that same value.
  - Messages name the role that acted.

- [ ] **Step 4: Run them, and the existing override tests**

Run: `vendor/bin/phpunit --no-coverage tests/database/AdministratorDecidesOverridesTest.php`, plus `grep -rl "GradeOverrideRequest\|override-requests\|approvals" tests --include=*.php`
Expected: PASS. An existing test asserting the fixed "the dean" wording is updated; ledger it.

- [ ] **Step 5: Stage**

Message: `fix: with no dean, the institution's administrators decide grade-override requests`

---

### Task 3: The administrator's Approvals page

**Files:**
- Modify: `app/Views/pages/dean/approvals.php`. It takes `$area` (`'dean'` or `'institution'`) for every `site_url('dean/…')`. The back link goes to `{$area}/dashboard`.
- Modify: `app/Services/Courses/Grades/GradeOverrideRequestService.php`. Extract `listForDean`'s builder (`:545-610`) into a private `listIn(list<int> $departmentIds, string $waitingStatus, User $viewer, OverrideApprovalFilters $f)`. `listForDean` uses it. Add `listForInstitution(User $admin, OverrideApprovalFilters $f): list<array>`, which covers the institution's departments with `awaiting_institution`, never the admin's own requests. Add `requestersForInstitution(User $admin)` too.
- Create: `app/Controllers/Institution/ApprovalsController.php` (index, approve, reject), modelled on `Dean\ApprovalsController`.
- Modify: `app/Config/Routes.php`, in the institution writing group: `get('approvals')`, `post('approvals/(:num)/approve')`, `post('approvals/(:num)/reject')`, filter `permission:grades.approve`.
- Modify: `app/Helpers/navigation_helper.php`, adding an "Approvals" entry for the institutional admin.
- Test: `tests/feature/InstitutionApprovalsPageTest.php`

**Interfaces:**
- Consumes: Task 2's `approve`/`reject`.

- [ ] **Step 1: Write the failing tests**
  - The admin sees an `awaiting_institution` request of their institution, with the grade breakdown, but not another institution's request or their own.
  - Approve and reject through the page work. The reject form needs a reason.
  - The dean's page is unchanged (`tests/feature/*Approval*` still pass).
  - An instructor can't open it.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/feature/InstitutionApprovalsPageTest.php`
Expected: FAIL, because there's no route.

- [ ] **Step 3: Implement as listed under Files.** Programs for the filter come from the institution's programs. Requesters come from `requestersForInstitution`.

- [ ] **Step 4: Run them, and the dean approval tests**

Run: `vendor/bin/phpunit --no-coverage tests/feature/InstitutionApprovalsPageTest.php`, plus `grep -rl "dean/approvals" tests --include=*.php`
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: the institution's administrator has an Approvals page for requests with no dean`

---

### Task 4: The administrator signs off where there is no dean (2.1–2.5, D3, D4)

**Files:**
- Modify: `app/Services/Courses/Grades/GradeSignoffService.php`:
  - `mayDecide` (`:341-352`);
  - `signOff`, `signOffProgram` and `reopen` (`:102-208`): wording and scope;
  - `announceReady` (`:211-227`): to admins when there is no dean;
  - `board` and `counts` (`:248-300`): extract a scope parameter.
- Modify: `app/Services/Institutions/DeanAssignmentService.php:30-102`. When the first active dean of an institution is assigned, tell the admins (holding `grades.signoff`) which sections now need sign-off.
- Test: `tests/database/AdministratorSignsOffTest.php`

**Interfaces:**
- Consumes: Task 1's `GradeAuthority`.
- Produces:
  - `GradeSignoffService::boardFor(list<int> $programIds, ?int $termId, string $area): array`, the shape `board()` returns. `board($dean, $term)` calls it with the dean's programs and `'dean'`.
  - `GradeSignoffService::adminProgramIds(User $admin): list<int>`: the programs of the admin's institution whose department has no dean holding `grades.signoff`.

- [ ] **Step 1: Write the failing tests**
  - `testAnAdministratorSignsOffWhereThereIsNoDean`: a ready section in a dean-less department is signed off by the admin, and it's then included by `SisGradeExporter` (dry run).
  - `testWhereThereIsADeanTheAdministratorCannot`: refused with "That section is not one you sign off."
  - `testADeanMaySignOffASectionTheyTeach` (D3).
  - `testAnAdministratorMayNotSignOffTheirOwnSection` (D4): refused with the D4 copy. A second admin can sign it off.
  - `testTheAdministratorReopensWithAReason`: needs a reason, and the instructor is told "the administrator reopened …".
  - `testTheAdministratorsAreToldASectionIsReady`: `announceReady` with no dean notifies the admins, but not an admin who teaches the section.
  - `testTheFirstDeanTellsTheAdministratorsWhatNowNeedsSignOff` (2.3): finalized, not-signed-off sections exist, and assigning the institution's first dean notifies the admins with the count and the first section labels.
  - `testExistingSignOffsStayWhenTheSignerChanges` (2.5).

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/AdministratorSignsOffTest.php`
Expected: FAIL, because `mayDecide` refuses the admin.

- [ ] **Step 3: Implement.** `mayDecide($user, $offeringId)` allows two cases:
  - the dean of the offering's department, acting as dean, with `grades.signoff` (teaching it is fine);
  - an institutional admin, acting as one, of that institution, where `deanFor(dept, 'grades.signoff')` is null, holding `grades.signoff`, and not an instructor of the offering (`instructor_assignments`).

  Otherwise it gives the matching refusal.

- [ ] **Step 4: Run them, and the existing sign-off tests**

Run: `vendor/bin/phpunit --no-coverage tests/database/AdministratorSignsOffTest.php`, plus `grep -rl "GradeSignoff\|signoff" tests --include=*.php`
Expected: PASS. Fixed-wording asserts are updated and ledgered.

- [ ] **Step 5: Stage**

Message: `fix: with no dean, the institution's administrator signs off finalized grades`

---

### Task 5: The administrator's Sign-off page

**Files:**
- Modify: `app/Views/pages/dean/signoff.php`. It takes `$area` for its links. The CSV export, "Decide the open requests" and "Send a follow-up" links show only for `dean`; for `institution`, the open-requests link goes to `institution/approvals`.
- Create: `app/Controllers/Institution/SignoffController.php` (index, signOff, signOffProgram, reopen), modelled on `Dean\SignoffController`.
- Modify: `app/Config/Routes.php`, in the institution writing group:
  - `get('signoff')`;
  - `post('signoff/program/(:num)/(:num)')` with `reauth`;
  - `post('signoff/(:num)')` with `reauth`;
  - `post('signoff/(:num)/reopen')`.

  All carry `permission:grades.signoff`.
- Modify: `app/Helpers/navigation_helper.php`, adding a "Sign-off" entry for the institutional admin.
- Test: `tests/feature/InstitutionSignoffPageTest.php`

**Interfaces:**
- Consumes: Task 4's `boardFor` and `adminProgramIds`.

- [ ] **Step 1: Write the failing tests**
  - The admin sees the dean-less department's programs and sections, and not a department with a dean.
  - Sign-off needs the password.
  - The own-section card shows the D4 copy instead of the button.
  - The dean's page is unchanged.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/feature/InstitutionSignoffPageTest.php`
Expected: FAIL, because there's no route.

- [ ] **Step 3: Implement as listed under Files.**

- [ ] **Step 4: Run them, and the dean sign-off tests**

Run: `vendor/bin/phpunit --no-coverage tests/feature/InstitutionSignoffPageTest.php`, plus `grep -rl "dean/signoff" tests --include=*.php`
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: the institution's administrator has a Sign-off page for departments with no dean`

---

### Task 6: The warning that no one can approve or sign off (U1f, 7.3)

**Files:**
- Modify: `app/Services/Courses/Grades/GradeAuthority.php`: add `gaps`.
- Modify: `app/Controllers/Institution/RolesController.php` + `app/Views/pages/institution/roles.php`; `app/Controllers/Admin/RolesController.php` + `app/Views/pages/admin/roles.php`.
- Test: `tests/database/GradeAuthorityGapsTest.php`, plus a test on each of the two pages.

**Interfaces:**
- Produces: `GradeAuthority::gaps(int $institutionId): list<array{department: string, permission: 'grades.approve'|'grades.signoff'}>`. For an institution that uses approval, it lists each department with no dean holding the permission when no admin holds it either.

- [ ] **Step 1: Write the failing tests**
  - No gaps by default.
  - Clearing admins' `grades.approve` in an institution with a dean-less department gives one gap.
  - Clearing deans' `grades.signoff` as well adds the departments with deans.
  - **Institution page:** shows the warning alert "Nobody can approve grade overrides in <department>" (or "sign off grades"), one line per gap.
  - **Admin Roles page:** shows "<institution>: nobody can approve grade overrides in <department>" for each institution with gaps.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/GradeAuthorityGapsTest.php`, plus the two page tests
Expected: FAIL, because `gaps` is undefined.

- [ ] **Step 3: Implement.** Both pages use the existing `components/alert` with tone `warning`, above the grid.

- [ ] **Step 4: Run them**

Run: the commands above
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `feat: both Roles pages warn when nobody can approve or sign off`

---

### Task 7: One chairperson per sub-department (16.0, 16.0b–d, U5)

**Files:**
- Create: `app/Database/Migrations/2026-11-15-000001_OneChairpersonPerSubDepartment.php`:
  - add `active_marker TINYINT NULL`;
  - set it to 1 for each sub-department's most recently assigned active chair, and revoke the other active ones (16.0d);
  - add unique `(sub_department_id, active_marker)`.

  The existing unique `(sub_department_id, chairperson_user_id)` stays, because assigning again reuses the row (`ChairAssignmentService.php:129-136`).
- Modify: `app/Services/Institutions/ChairAssignmentService.php`:
  - the docblock rule (`:11-17`);
  - `assign` (`:97-145`), which gets a new `bool $replace = false` parameter: with an active chair and `! $replace` it throws `StructureException::field('user_id', "<old> chairs <sub>. Assign <new> instead?")` and the error carries `replace_needed`;
  - with `$replace`, it revokes the old one in the same transaction and notifies the old chair, then the new chair if requests wait for them;
  - `revoke` sets `active_marker = null`.
- Modify: `app/Controllers/Institution/StructureController.php:162`, passing `replace` from the form, and the Structure page's chair dialog (`app/Views/pages/institution/structure.php`). When the server says `replace_needed`, the dialog shows "<old> chairs <sub>. Replace them with <new>?" and a confirm button that resubmits with `replace=1`.
- Modify: `app/Services/Sis/SisImporter.php:707`, where the SIS sync passes `replace: true` (the SIS is the owner).
- Modify: `app/Services/Courses/Grades/GradeOverrideRequestService.php:111, 171`. The loops over `chairIds` stay correct with one chair. The pending-requests notice to a new chair is sent from `ChairAssignmentService` (count of `awaiting_chair` in the sub-department).
- Test: `tests/database/OneChairpersonTest.php`, `tests/feature/StructureChairReplaceTest.php`

**Interfaces:**
- Produces: `ChairAssignmentService::assign(int $institutionId, int $subDepartmentId, int $userId, ?User $actor = null, bool $replace = false): int`.

- [ ] **Step 1: Write the failing tests**
  - `testASecondChairIsRefusedWithoutConfirmation`: the exception message names both, and the old chair stays.
  - `testConfirmingReplacesTheChairAndKeepsTheHistory`: the old row is `revoked`, the new one active, the old chair has a notification, and the audit has `structure.chairperson_revoked` plus `structure.chairperson_assigned`.
  - `testWaitingRequestsPassToTheNewChair`: an `awaiting_chair` request shows in the new chair's `listForChair` and not the old one's, the new chair is notified, and the old chair's `endorse` is refused.
  - `testTheDatabaseKeepsOneActiveChair`: inserting a second row with `active_marker = 1` for the same sub-department fails.
  - `testTheMigrationKeepsTheMostRecentlyAssigned`: a pure helper, `OneChairpersonPerSubDepartment::assignmentsToRevoke(rows)`, as plan 2 did for memberships.
  - **Structure page:** the first submit answers with the confirmation, and the second with `replace=1` replaces.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/OneChairpersonTest.php tests/feature/StructureChairReplaceTest.php`
Expected: FAIL, because a second chair is accepted.

- [ ] **Step 3: Implement as listed under Files, then run `php spark migrate`.**

- [ ] **Step 4: Run them, and the chair and SIS tests**

Run: the command above, plus `grep -rl "ChairAssignment\|chairperson_assignments\|chairpersons" tests --include=*.php`
Expected: PASS. A test that relied on several chairs per sub-department is rewritten or deleted; ledger it.

- [ ] **Step 5: Stage**

Message: `fix: one chairperson per sub-department, replaced only on confirmation`

---

### Task 8: Chair actions can't collide (16.1)

**Files:**
- Modify: `app/Services/Courses/Grades/GradeOverrideRequestService.php`:
  - `endorse`, `sendBack` (`:210-282`) and `withdraw` (`:186-203`) each update `WHERE id = ? AND status = <expected>`, and continue only when one row changed;
  - the loser gets "That request was just acted on." and no notification is sent for it.
- Test: `tests/database/OverrideRequestRaceTest.php`

- [ ] **Step 1: Write the failing tests.** Simulate the race by changing the row's status between the read and the write: inject it through a test subclass overriding a protected `requestRow(int $id)` hook, or by updating the row in the `actionable`/`ownRequest` result before the call.
  - `testAWithdrawalAfterTheChairsReadMakesTheEndorsementLose`: `endorse` returns the "just acted on" message, the status stays `withdrawn`, and the requester got no "endorsed" notification.
  - `testAnEndorsementAfterTheRequestersReadMakesTheWithdrawalLose`.
  - `testSendBackLosesTheSameWay`.

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/database/OverrideRequestRaceTest.php`
Expected: FAIL, because the second action overwrites the first.

- [ ] **Step 3: Implement as listed under Files.**

- [ ] **Step 4: Run them, and the override tests**

Run: the command above, plus the Task 2 command
Expected: PASS.

- [ ] **Step 5: Stage**

Message: `fix: a chairperson's action and the requester's can't both win`

---

### Task 9: Full check and docs

- [ ] **Step 1: Run every suite, one at a time**

Run: `vendor/bin/phpunit --no-coverage`, then `npm test`, then `npm run build && npm run e2e`
Expected: PASS, apart from the six known `AiWorkerEndpointsTest` failures and the known calendar flake.

- [ ] **Step 2: Docs.**
  - `docs/institutional-admin-area.md`: add Approvals and Sign-off sections, and the one-chairperson rule under Structure.
  - The dean docs (`grep -ril "approvals\|sign-off" docs/*.md`): who decides when there is no dean.
  - `docs/roles-and-permissions.md`: `grades.approve` and `grades.signoff` now enforced, and the warning.

  Stage them, and hand over the `git add` list with the task messages.
