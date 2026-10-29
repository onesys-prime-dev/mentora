# Parent Portal, Branch 5: Link at Invite Time, and the Student's "Linked Parents" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The administrator can name a parent's students in the same step as inviting the parent, so the parent's first sign-in already shows their children; and a student can see, read-only, which parents can see their progress.

**Architecture:** A parent has no account until they accept the invitation, and a link needs an account. So the invitation carries the named students (validated at invite time, stored as ids on the invitation) and the links are created in the same transaction that creates the account. `ParentLinkService` is split into "find the students" and "create the links" so the invite flow and the existing link screen share one set of rules and one audit. The student's list is a line on their dashboard's identity card, built from the same "parents with access" rule the notifications use.

**Tech Stack:** CodeIgniter 4 (PHP 8.2), PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`).

**Spec:** Appendix A of `2026-10-21-parent-portal-branch-1-access.md` (Q3, Q10, Q19, Q22); branches 1 to 4 on `feat/parent-access` … `feat/parent-notifications` (this branch is stacked on them).

## Global Constraints

- Branch `feat/parent-invite-and-student-list`, stacked on `feat/parent-notifications`. **No commits** (the user reviews and commits). Branch 6 is not started without approval.
- Naming students is **optional** and only for the **Parent** role (any other role with students named is refused with a plain message). Students are named by student number or email, one per line, exactly as on the existing Parents screen; the same limits (`EnrolmentList::MAX`) and the same reasons for skipping apply.
- **At invite time every named student must be found** (a student of this institution holding the student role); if any is not, the invitation is **not sent** and each unmatched name is listed with its reason. At acceptance a student who is no longer valid is skipped (and the skip is audited); the account is made regardless.
- Links created through an invitation are **audited** like any other (`parent.linked`, target `parent_link`), with the inviting administrator as the actor and `via: invitation` with the invitation id; `invitation.accepted` also records how many were linked.
- A **resend** keeps the named students. A new invitation to the same email replaces the open one and carries the students typed for it.
- Parents use no seats; no seat check changes.
- The student sees **names only** (no email, no number, no detail), only the parents who actually have access now (linked, active, in the institution, the portal on), and cannot change anything.
- UI: shared components and tokens only; works at 360 px; **wireframes N, O and P are approved before any view changes** (Tasks 4 and 5).
- No new `phpstan-baseline.neon` entries; test class names unique; every service keeps a test class named after it.

## Review Focus

- A name that matches two people, a non-student, a student of another institution, a duplicate line, more than the maximum → refused at invite with the reason, nothing sent, no invitation row.
- An invitation accepted after a student left or was deleted: the parent's account is made, the valid students are linked, the rest skipped and audited.
- The same invitation accepted twice (a race) links once; a link that already exists is not duplicated.
- A resent invitation links the same students; a replaced invitation does not link the old list.
- The student's line shows nothing when no parent has access (none linked, parent deactivated, portal off) and never another student's parents.
- The existing Parents screen (link/unlink by text) behaves exactly as before.

---

## Decisions to confirm with the wireframes

1. **Where the student sees their parents (DECIDED by the user: a new page).** Students had no profile page (the "My Profile" menu item did nothing), so this branch builds a **new read-only `student/profile` page**: name, email, student number and "Parents who can see your progress" (names only). The menu's "My Profile" becomes a real link for students (and for parents, whose page exists since branch 4). The dashboard identity card is not changed.
2. **Strict at invite.** One unmatched name stops the invitation and nothing is sent, so a typo is fixed at once. The alternative (invite anyway and skip the unmatched) means a parent could sign in to an empty page without anyone noticing.
3. **The field is always shown.** The dialog cannot hide a field by role without new script, so "Students to link" sits under Role with the note "Only for a parent". Choosing another role with students typed is refused.
4. **Replacing and resending.** A resend reuses the saved students; sending a fresh invitation to the same address replaces the open one, with the students typed this time.

## Wireframes

**N. People: Invite a person** (new field)
```
  Invite a person                                       (x)
  First name [........]   Last name [........]
  Email      [..........................]
  Role       [ Parent v ]
  Students to link (optional)
  [ 2026-001                          ]
  [ ben@school.example                ]
  Only for a parent: student numbers or emails, one per line. They
  are linked when the parent accepts the invitation.
                                   (Cancel) [Send invitation]
  [error: "2026-009 is not a student number or email in your
   institution. Nothing was sent."]
```
**O. People: pending invitations** — a parent invitation with students shows them
```
  Person              Role     Status     Link works until
  Pam Parent          Parent   Invited    8 Nov 2026
  pam@school.example           2 students to link
```
**P. The student's profile (`student/profile`, read only)** — replaces the identity-card line
```
  < Back to dashboard
  (icon) Profile              Who you are in Mentora, and who can see your progress.
  +-----------------------------------------------------------+
  | Your account                                              |
  |  Name            Ann Lee                                  |
  |  Email           ann@school.example                       |
  |  Student number  2026-001                                 |
  |  To change any of these, contact your school.             |
  +-----------------------------------------------------------+
  +-----------------------------------------------------------+
  | Parents who can see your progress                         |
  |  (icon) Olga Another                                      |
  |  (icon) Pam Parent                                        |
  |  They can see your courses, deadlines and grades. They    |
  |  cannot change anything. Your school decides who is linked.|
  |  [none: "No parent can see your progress."]               |
  +-----------------------------------------------------------+
```

---

## File Structure

- Create `app/Database/Migrations/2026-11-09-000001_AddStudentsToInstitutionInvitations.php`.
- Modify `app/Models/Academic/InstitutionInvitationModel.php`, `app/Services/Institutions/{ParentLinkService,InvitationService,PeopleService}.php`, `app/Services/Parents/{ParentAccessService,ParentNotifier}.php`, `app/Services/Dashboard/DashboardService.php`, `app/Controllers/Institution/PeopleController.php`.
- Modify views `app/Views/pages/institution/people.php`, `app/Views/layouts/partials/header.php`; create `app/Views/pages/student/profile.php`, `app/Controllers/Students/StudentProfileController.php`.
- Tests: `tests/database/{ParentLinkServiceTest,InvitationServiceTest,ParentAccessServiceTest,PeopleServiceTest}.php` (extend), `tests/database/AddStudentsToInvitationsMigrationTest.php`, `tests/feature/{InstitutionPeopleInviteParentTest,StudentProfilePageTest}.php`.
- Docs: `docs/institutional-admin-area.md`, `docs/course-access.md`.

---

### Task 1: The invitation carries the students

**Files:** Create the migration; Modify `InstitutionInvitationModel.php`; Test `tests/database/AddStudentsToInvitationsMigrationTest.php`.

**Interfaces:**
- Produces: column `institution_invitations.student_ids` TEXT NULL (a JSON list of student user ids), reversible; `InstitutionInvitationModel` allows it.

- [ ] **Step 1: Write failing tests:** the column exists after migrating; a row can hold a JSON list and reads it back; old rows read `null`; rolling the migration back removes only that column.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement. Step 4: Run** the new test plus `tests/database/InvitationServiceTest.php` → PASS.

### Task 2: `ParentLinkService`: find the students, create the links

**Files:** Modify `ParentLinkService.php`; extend `ParentLinkServiceTest.php`.

**Interfaces:**
- Produces: `ParentLinkService::resolve(int $institutionId, string $text): array{students: list<int>, skipped: list<array{identifier: string, reason: string}>}`: the ids of the named students (a duplicate line counts once) and each name that cannot be used with its reason (the existing reasons: not found, ambiguous, not a student), failing with `StructureException` on an empty text or more than `EnrolmentList::MAX` lines.
- Produces: `ParentLinkService::createLinks(int $institutionId, int $parentId, array $studentIds, ?User $actor = null, array $audit = []): array{linked: int, skipped: list<int>}`: re-checks each id is still a student of the institution, skips one already linked, inserts the rest in one transaction, and writes one `parent.linked` audit entry per link (extra keys in `$audit` merged into `new`).
- `link()` is rebuilt on these two and behaves exactly as before.

- [ ] **Step 1: Write failing tests:** `resolve` returns ids and reasons (number, email, duplicate line, two matches, a non-student, another institution, empty, over the limit); `createLinks` links valid ids, skips a deleted or non-student id and an existing link, audits each with the actor and the extra keys, and is all-or-nothing; every existing `ParentLinkServiceTest` case still passes untouched.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement. Step 4: Run** `ParentLinkServiceTest.php` and `tests/feature/InstitutionParentLinksTest.php` → PASS.

### Task 3: Invite with students, accept links them

**Files:** Modify `InvitationService.php`, `PeopleService.php`; extend `InvitationServiceTest.php`, `PeopleServiceTest.php`.

**Interfaces:**
- Consumes: Task 1 column; Task 2 `resolve` and `createLinks`.
- Produces: `InvitationService::invite(..., array $input, ...)` accepts `$input['students']` (text) for role `parent`: resolves with `ParentLinkService::resolve`, refuses (an `InvalidArgumentException` listing every unmatched name and "Nothing was sent.") when any is unmatched or when the role is not `parent`, and stores the ids in `student_ids`. `resend` copies `student_ids`. `accept` calls `createLinks` in its transaction (actor = the inviting administrator, `['via' => 'invitation', 'invitation_id' => id]`) and adds `linked_students` to the `invitation.accepted` audit entry.
- Produces: `PeopleService::invite` turns that refusal into a `StructureException::field('students', ...)`.

- [ ] **Step 1: Write failing tests:** a parent invitation with two valid students stores both ids and sends the email; an unmatched name refuses everything (no row, no email, the reason named); students on a non-parent role are refused; accepting links both students (two `parent_student_links` rows, `created_by_user_id` the administrator, two `parent.linked` audit rows with `via: invitation`), the account exists with the parent role, and `invitation.accepted` shows 2; a student deleted before acceptance is skipped, the others linked, the account made; accepting twice (the second is refused) links once; resend keeps the ids; a fresh invitation to the same address replaces the open one and links only the new list; a parent invitation without students behaves as today.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement. Step 4: Run** the three test files → PASS.

### Task 4: The invite dialog and the pending list (UI: wireframes N and O)

**Gate:** wireframes N and O approved before the view is changed.

**Files:** Modify `people.php`, `PeopleController.php` if it filters the posted fields; Test `tests/feature/InstitutionPeopleInviteParentTest.php`.

**Interfaces:**
- The invite dialog gains `students` (textarea, optional, note under it); the pending invitations table shows "n students to link" under a parent invitation that has any. The posted `students` reaches `PeopleService::invite` through the existing `input()`.

- [ ] **Step 1: Write failing tests:** the administrator posts an invite for a parent with two students → 200, the invitation row holds both ids, the pending list shows "2 students to link"; an unmatched student answers 422 with the field `students` and the name and reason, no invitation row; students on a student invitation → 422; a parent invitation with no students still works; the page shows the field and its note; a non-administrator cannot invite; a read-only institution refuses; another institution's student is not accepted.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement. Step 4: Run** → PASS.

### Task 5: The student's profile page with their parents (UI: wireframe P)

**Files:** Modify `ParentAccessService.php`, `ParentNotifier.php`, `app/Config/Routes.php`, `app/Views/layouts/partials/header.php`; Create `app/Controllers/Students/StudentProfileController.php`, `app/Views/pages/student/profile.php`; extend `ParentAccessServiceTest.php`; Test `tests/feature/StudentProfilePageTest.php`.

**Interfaces:**
- Produces: `ParentAccessService::parentsOf(int $studentId): list<array{parent_id: int, institution_id: int}>`: the SQL `ParentNotifier::parentsOf` had (linked, active, holds the parent role, member of the link's institution, the student a member and not deleted), without the portal check. `ParentNotifier::parentsOf` delegates to it and keeps the portal filter and its per-institution memo.
- Produces: `ParentAccessService::parentNamesWithAccess(int $studentId): list<string>`: the full names of those parents whose institution has the portal on, in name order (names only).
- Produces: `GET student/profile` in the `student` group (read only; no write route) → `StudentProfileController::index`. The header's "My Profile" becomes a link to `{student|parent}/profile` for those two roles and stays inert for the others.

- [ ] **Step 1: Write failing tests:** `parentsOf` lists only parents who can reach the student now (the notifier tests pass untouched); the page shows the student's name, email, student number ("Not set" when empty) and their parents by name in order, and no parent's email; no parent, a deactivated parent, a portal switched off, another student's parents; other roles turned away; no write route exists under `student/profile`; the header links students and parents to their own profile and instructors keep the inert item.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement. Step 4: Run** the files above plus `ParentNotifierTest.php`, `RouteAccessTest.php` → PASS.

### Task 6: Docs and verification

**Files:** Modify `docs/institutional-admin-area.md`, `docs/course-access.md`.

- [ ] **Step 1:** document naming students at invite time (strict at invite, linked on acceptance, audited, resend/replace rules) in `docs/institutional-admin-area.md` (the Parents section) and the student's line in `docs/course-access.md`.
- [ ] **Step 2: Verify:** the new and touched PHPUnit files; the whole suite (`vendor/bin/phpunit`), reading failures by name (the six `AiWorkerEndpointsTest` ones are the known worker-secret failures); `vendor/bin/phpstan analyse`; `npx eslint resources/js tests/js`; `npx vitest run`. Report real output.

---

## Self-review

- **Spec coverage:** Q19 "link students at invite time (optional)" → Tasks 1 to 4; Q10 "the student sees a read-only Linked parents list" → Task 5 (placement is decision 1) and "link changes are audited" → Task 2/3 audit; Q3 (administrator-only, no self-registration) unchanged. The tests, e2e and the remaining acceptance criteria are branch 6.
- **Placeholder scan:** none; each step names tests and signatures. Decision 1 gates Task 5, wireframes N/O gate Task 4.
- **Type consistency:** `resolve` returns ids (ints) and `createLinks` takes ids; the invitation stores the same ids; `parentsOf` returns the shape the notifier already used.
- **Proportion:** six tasks, one column, one split service method pair, one dialog field, one card line.
