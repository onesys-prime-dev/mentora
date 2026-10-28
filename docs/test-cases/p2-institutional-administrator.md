# P2 test cases: the institutional administrator

43 cases in two files, and 5 added in Wave 1 of the QA plan (2026-10-28, at the end; TC-CRS-03 rewritten). Read [README.md](README.md) first. **All are `Draft`**
(see the README).

- `tests/e2e/institution-structure.spec.js` holds the screen-by-screen cases
  (TC-STR to TC-HOM). It is one serial story on the demo institution (1): later
  cases use what earlier ones made, so filter with `-g` only for cases that stand
  alone.
- `tests/e2e/institutional-admin.spec.js` holds the cases that cut across the
  area (TC-IAA): who can open it, the Apps menu, accessibility, the keyboard, a
  read-only institution.

The invited administrator setting a password and signing in is TC-SA-05, and the
lapse to read-only and the export is TC-SA-06, in
[p2-system-administrator.md](p2-system-administrator.md).

**Data:** the demo institutional administrator of institution 1
(`institution.admin@mentora.test`), and the demo students and instructors of the
same institution (`DemoUsersSeeder`).

## The screens

| ID | Case | Screen |
|---|---|---|
| TC-STR-01 | Lands on home, reaches the structure from the Apps menu | Structure |
| TC-STR-02 | Adding a department in a dialog shows it with a message | Structure |
| TC-STR-03 | A refused form shows its reason beside the field and keeps what was typed | Structure |
| TC-STR-04 | Editing opens the same dialog with the department's values | Structure |
| TC-STR-05 | A department with active sub-departments says why it cannot be switched off | Structure |
| TC-STR-06 | An empty new department can be switched off and on again | Structure |
| TC-CRS-01 | Adding a course files it under its program and shows it in the catalog | Courses |
| TC-CRS-02 | A duplicate code shows its reason in the dialog; search finds the course | Courses |
| TC-CRS-03 | A sub-department has one chairperson: replacing needs confirmation and tells the old one (rewritten 2026-10-28, see Wave 1) | Structure |
| TC-TRM-01 | An overlapping term goes through with a warning | Terms |
| TC-TRM-02 | A term that ends before it starts is refused beside the date | Terms |
| TC-TRM-03 | Closing a term says what changes, asks for the password, and can be undone | Terms |
| TC-OFF-01 | An offering is added and listed with no instructor yet | Offerings |
| TC-OFF-02 | The same section twice is refused beside the field | Offerings |
| TC-OFF-03 | An offering with no students can be archived and brought back | Offerings |
| TC-ENR-01 | Students are enrolled by email from the roster | Roster |
| TC-ENR-02 | A student can be dropped and the row stays with its new status | Roster |
| TC-ENR-03 | Going over capacity is refused until the box is ticked | Roster |
| TC-PPL-01 | A person is invited from a dialog and waits on the Invitations tab | People |
| TC-PPL-02 | An address that already has an account is refused in the dialog | People |
| TC-PPL-03 | Deactivating asks for the password in a dialog, then switches the account off | People |
| TC-PPL-04 | The last administrator has no Deactivate action | People |
| TC-PAR-01 | A parent is linked to a student and unlinked again | Parents |
| TC-UPL-01 | Files are previewed first; applying them (after the password) creates the people | CSV upload |
| TC-UPL-02 | A file with the wrong columns is refused and nothing is kept | CSV upload |
| TC-CPY-01 | Offerings are copied into a new term from a preview | Copy offerings |
| TC-BRD-01 | Colours are checked as typed, refused when unreadable, applied everywhere | Settings |
| TC-BRD-02 | A logo is uploaded, shown in the header, removed; colours reset | Settings |
| TC-BRD-03 | The profile is saved and the code stays | Settings |
| TC-ACD-01 | Academic defaults are saved, weights that do not add up are refused, reset | Settings |
| TC-SEC-01 | An announcement reaches the institution; stricter security asks for the password | Settings |
| TC-RPT-01 | Reports narrow by department, open a course, export CSV | Reports |
| TC-VCV-01 | A course opens view only and offers nothing to change | Course view |
| TC-SUB-01 | Subscription is read only; Integrations shows no folders | Subscription, Integrations |
| TC-AUD-01 | The audit log narrows by what was done and exports without addresses | Audit log |
| TC-VAS-01 | Viewing as a student needs a reason and the password, is marked, and exits back | People |
| TC-HOM-01 | Home lists what needs attention, recent activity and quick access | Home |

Each has its own steps and expected result in the spec file next to the case's
title; the rules underneath are proved by PHPUnit
(`tests/feature/Institution*Test.php`, `tests/database/Institution*Test.php`).

## The whole area

| ID | Case | Role |
|---|---|---|
| TC-IAA-01 | Only the institution's administrator can open the area; a signed-out visitor is sent to log in | Student, Instructor, Anonymous |
| TC-IAA-02 | A record that is not the institution's answers as if it did not exist | Institutional administrator |
| TC-IAA-03 | Every tile of the Apps menu opens its screen | Institutional administrator |
| TC-IAA-04 | The main screens pass an automated accessibility check | Institutional administrator |
| TC-IAA-05 | A dialog can be opened, filled and left with the keyboard alone, and focus comes back | Institutional administrator |
| TC-IAA-06 | A read-only institution can still be looked at and exported, but nothing offers to change it | Institutional administrator |

## TC-IAA-01 Only the institution's administrator

- **Steps:** as a student, then an instructor, open each of the area's main screens (home, structure, terms, offerings, people, reports, settings, subscription, integrations, audit log). Signed out, open the people list.
- **Expected:** signed in people are sent to their own area and no institution page is shown; the signed-out visitor is sent to the login page.
- **Already automated:** `tests/feature/RouteAccessTest.php` (every institution route, every role).

## TC-IAA-02 Records of other institutions

- **Steps:** as the administrator, open the roster, the read-only course view and the copy page of an id that belongs to no one here.
- **Expected:** each answers "not found", not a refusal that would confirm it exists elsewhere.
- **Already automated:** the cross-institution cases in every `tests/feature/Institution*Test.php`.

## TC-IAA-03 The Apps menu

- **Steps:** from home open the Apps menu and use each tile: Academics, Terms, Offerings, People, Reports, Settings, Subscription, Integrations, Audit log, Data export.
- **Expected:** each reaches its own screen.

## TC-IAA-04 Accessibility

- **Steps:** open each main screen and run the automated check (axe, WCAG 2 A and AA rules).
- **Expected:** no serious or critical problem on any of them. Findings fixed when the case was added: the header logo link had no name, and the green status badge was a hair under the contrast ratio.
- **Not covered by the tool:** reading order, the words, and a real screen reader. These stay with a person.

## TC-IAA-05 Keyboard only

- **Steps:** on People, move focus to *Invite a person* and press Enter. Press Tab repeatedly. Press Escape.
- **Expected:** the dialog opens with focus inside it, Tab never leaves it, Escape closes it and focus returns to the button.
- **Already automated:** `tests/js/shared/dialog.test.js`.

## TC-IAA-06 A read-only institution

- **Setup:** the institution's status is set to read-only (the case does it in the database and puts it back).
- **Steps:** sign in. Open People and Offerings. Open Reports, the audit log and the data export.
- **Expected:** the read-only banner is shown; no *Invite a person* or *Add an offering*; the three looking pages open.
- **Already automated:** `tests/feature/ReadOnlyModeTest.php`, `tests/feature/InstitutionAuditLogPageTest.php`, `tests/feature/InstitutionHomeAttentionTest.php`.

---

## Wave 1 (added 2026-10-28)

From the QA plan's Wave 1. The expected results come from `docs/qa/decision-register.md`; none has been run. QA status: **FIX?** a fix is committed and a test pins it, expected to pass; **NB** required but not built, expected to fail.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-CRS-03 | A sub-department has one chairperson: replacing needs confirmation and tells the old one (rewritten) | Institutional administrator | P1 | FIX? |
| TC-CRS-04 | The database refuses a second active chairperson, whatever path writes it | (database) | P2 | FIX? |
| TC-CRS-05 | Two administrators assigning a chairperson at once get one chair and a friendly message | Institutional administrator ×2 | P2 | FIX? |
| TC-CRS-06 | A dean is assigned to a department, and the assignment ended, from the institution area | Institutional administrator | P2 | NB |
| TC-IAA-07 | Taking away an instructor's discussion moderation stops it in the server, not only on the page | Institutional administrator, instructor | P2 | FIX? |

### TC-CRS-03 A sub-department has one chairperson: replacing needs confirmation and tells the old one

*Rewritten 2026-10-28 (register PLN-03). The earlier case assigned a chair and removed it, from before decision 16.0 (one chairperson per sub-department). The Playwright test keeps its ID and must be updated to these steps.*

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** `institution.admin@mentora.test`; the IT sub-department of CBM, chaired by `chairperson1@mentora.test`; `chairperson2@mentora.test` (Isabel Mendoza, holds the chairperson role and chairs nothing); `instructor2`, with a grade-override request for IT201-A waiting for the chairperson (make one as in TC-DEAN-04, step 1)
- **Steps:**
  1. Open Structure. On the IT sub-department, choose Isabel Mendoza and press Assign, without confirming.
  2. Press Replace chairperson and confirm in the dialog.
  3. As `chairperson1`, open the notifications. As the new chairperson, open Override requests.
  4. As the administrator, press Remove on the new chairperson and confirm.
  5. As `dean2`, open Approvals.
  6. Try to assign `dean2` as chairperson of IT.
- **Expected:**
  - Step 1: refused beside the field: "Antonio Fernandez chairs IT. Assign Isabel Mendoza instead? Confirm to replace them." (name format to confirm). Nothing changes.
  - Step 2: the dialog reads "Replace Antonio Fernandez as chairperson of IT? Their assignment ends and they are told." After it, IT has exactly one chairperson, the new one. The old assignment is kept in the history, ended.
  - Step 3: `chairperson1` has "Chairperson assignment ended": "Isabel Mendoza now chairs IT, so your assignment there has ended." The waiting request is now the new chairperson's to endorse, and they are told "Grade overrides to endorse".
  - Steps 4–5: with no chairperson left, the waiting request moves on to the dean (who now sees it in Approvals) instead of waiting for nobody. The page confirms "The chairperson was removed from the sub-department."
  - Step 6: refused: "That person is a dean. A dean cannot also be a chairperson."
  - An assignment the SIS supplies is refused before any confirmation, with "This assignment is managed by your student information system. Change it there; the next sync brings it here."
- **Already automated:** `tests/database/OneChairpersonTest.php` (all), `tests/feature/StructureChairReplaceTest.php`, `tests/database/ChairAssignmentServiceTest.php`
- **Playwright:** `tests/e2e/institution-structure.spec.js` (to update to these steps)
- **Basis:** `ChairAssignmentService::assign`, `revoke`; `app/Views/pages/institution/structure.php`; decisions 16.0, 1.1; commit `48543c7`. The request moving on in step 4 is the code's behavior, which supersedes the sign-off entry AMB-INST-03 (register Part H).
- **QA status:** FIX? (PS-CHR-01, PS-CHR-08)
- **Review:** Draft. Expected result set by decision 16.0 and the code at `ae86042`.

### TC-CRS-04 The database refuses a second active chairperson, whatever path writes it

- **Role:** none (database level). **Priority:** P2
- **Data:** the test database after migration `2026-11-15-000001_OneChairpersonPerSubDepartment`; the IT sub-department with its active chair
- **Steps:**
  1. Insert a second `chairperson_assignments` row for IT with status `active` directly with SQL.
  2. Insert one with status `revoked`.
  3. Run the migration on a copy that holds two active chairs for one sub-department (made before the migration).
- **Expected:**
  - Step 1 is refused by the unique key. IT keeps one active chair.
  - Step 2 is accepted (ended assignments are history and do not count).
  - Step 3: the migration keeps the most recently assigned chair active and ends the other.
- **Already automated:** `OneChairpersonTest::testTheDatabaseKeepsOneActiveChair`, `testTheMigrationKeepsTheMostRecentlyAssigned`
- **Playwright:** no
- **Basis:** the migration's unique key (`active_marker`); decision 16.0; commit `48543c7`
- **QA status:** FIX? (PS-CHR-06)
- **Review:** Draft. Expected result set by decision 16.0.

### TC-CRS-05 Two administrators assigning a chairperson at once get one chair and a friendly message

- **Role:** Institutional administrator, in two browsers. **Priority:** P2
- **Data:** a second institutional administrator for institution 1 (invite one from People), and two people who hold the chairperson role
- **Steps:** both administrators open Structure on the same sub-department with no chairperson. Each picks a different person and presses Assign at the same moment.
- **Expected:**
  - Exactly one assignment is made.
  - The other administrator sees, beside the field, "Someone else just changed the chairperson of <sub-department>. Reload the page to see who chairs it now." No database error, stack trace or blank page is shown.
  - The unique key in the database stays the final safeguard (TC-CRS-04).
- **Already automated:** `OneChairpersonTest::testTwoAdministratorsAssigningAtOnceGetTheRaceMessage`
- **Playwright:** no (two simultaneous sessions)
- **Basis:** `ChairAssignmentService::assign` (the `$raced` message); decision AMB-INST-07; commits `48543c7`, `67cc8bf`
- **QA status:** FIX? (PS-CHR-07)
- **Review:** Draft. Expected result set by AMB-INST-07.

### TC-CRS-06 A dean is assigned to a department, and the assignment ended, from the institution area

- **Role:** Institutional administrator. **Priority:** P2
- **Data:** `institution.admin@mentora.test`; the General department (dean `dean1`); a person who holds the dean role and no department
- **Steps:**
  1. Find the screen to assign a dean to a department. Assign the person to a department with no dean.
  2. Try to assign a second dean to the same department, and to assign a chairperson as dean.
  3. End `dean1`'s assignment.
  4. Try to end an assignment the SIS supplies.
- **Expected:**
  - Assigning and ending are possible from the institution area. Each is audited, and an ended assignment stays in the history.
  - A conflicting or invalid assignment is refused with a reason beside the field.
  - The person who lost the department has no dean powers there from then on.
  - An SIS-managed assignment is refused with "This assignment is managed by your student information system. Change it there; the next sync brings it here."
- **Already automated:** `tests/database/DeanAssignmentServiceTest.php` (the service, which exists)
- **Playwright:** no
- **Basis:** decision MISS-INST-01 (register Part I3). Today `DeanAssignmentService::assign` and `end` exist but no controller or screen calls them: deans come only from the seed and the SIS [read in the code; not run].
- **QA status:** NB (PS-CHR-12)
- **Review:** Draft. Expected result set by MISS-INST-01.

### TC-IAA-07 Taking away an instructor's discussion moderation stops it in the server, not only on the page

- **Role:** Institutional administrator, then instructor. **Priority:** P2
- **Data:** `institution.admin@mentora.test`, `instructor1`, IT101-A; a discussion thread started by `student1` with a reply by `student2`
- **Steps:**
  1. As the administrator, open Roles and permissions and clear `discussions.manage` for the instructor role.
  2. As `instructor1`, open the thread. Then send directly the requests to delete the thread, delete student2's reply, mark the reply as the answer, pin and lock.
  3. Give the permission back and repeat step 2.
- **Expected:**
  - Without the permission, every one of these requests is refused by the server ("You can only delete your own discussion." and "Only the discussion's author or the instructor can mark an answer." where these apply), and the page offers none of the buttons.
  - With it, the instructor can do each one.
  - The student who started the thread can still delete it and mark an answer either way.
- **Already automated:** `tests/database/CourseRoleChecksTest.php`, `tests/feature/PermissionsTakeEffectTest.php`
- **Playwright:** no
- **Basis:** `DiscussionService::canModerate` (reads the permission through `CourseAbilities::viewOnly`); decision D6; commit `5140068`
- **QA status:** FIX? (PS-RBA-12)
- **Review:** Draft. Expected result set by decision D6.
