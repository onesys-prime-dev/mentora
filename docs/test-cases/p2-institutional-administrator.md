# P2 test cases: the institutional administrator

43 cases in two files, 5 added in Wave 1 of the QA plan (TC-CRS-03 rewritten) and 21 in Wave 2 (2026-10-28, at the end). Read [README.md](README.md) first. **All are `Draft`**
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

---

## Wave 2 (added 2026-10-28)

From the QA plan's Wave 2: P1 scenarios of the critical and high-risk modules. **VCI** means the expected result is the code's behavior and **IBR** a recorded decision; both are expected to pass. **FIX?** means a committed fix with a test, expected to pass. None has been run. All use the demo institution 1 and `institution.admin@mentora.test` / `Institution123!` unless the case says otherwise.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-IAA-08 | An institution can make its security stricter than the platform's, never looser | Institutional administrator | P1 | VCI |
| TC-IAA-09 | An institution's permissions layer on the platform's, take effect at once, and keep someone able to approve | Institutional administrator | P1 | IBR |
| TC-IAA-10 | A person outside a course, or outside the institution, gets "not found" for everything in it | Every role | P1 | IBR |
| TC-IAA-11 | A read-only, past or suspended institution can look and export, change nothing, and still manage its own account | Every role | P1 | IBR |
| TC-IAA-12 | The administrator views as one of their own people only, and it is view only | Institutional administrator | P1 | VCI |
| TC-STR-07 | A program or sub-department in use cannot be switched off, and the page says why | Institutional administrator | P1 | VCI |
| TC-CRS-07 | Chairs and deans from the SIS, and the rules for deans | Institutional administrator | P1 | VCI |
| TC-TRM-04 | A term moves between upcoming and active only while nobody is enrolled, and a completed term is closed | Institutional administrator | P1 | VCI |
| TC-TRM-05 | Closing a term completes its sections and places, and reopening puts back exactly that | Institutional administrator | P1 | VCI |
| TC-TRM-06 | A closed section is read-only for everyone in it | Instructor, student, chairperson | P1 | VCI |
| TC-OFF-04 | Adding a section, and assigning its instructors | Institutional administrator | P1 | VCI |
| TC-OFF-05 | Copying sections into a new term is all or nothing | Institutional administrator | P1 | VCI |
| TC-ENR-04 | Enrolling a list of students, and changing a student's status | Institutional administrator | P1 | VCI |
| TC-ENR-05 | Nobody is both a student and staff of one course | Institutional administrator | P1 | FIX? |
| TC-PPL-05 | Inviting, deactivating and changing the roles of people | Institutional administrator | P1 | VCI |
| TC-PAR-02 | Linking parents to students, and the two switches of the parent portal | Institutional administrator | P1 | VCI |
| TC-RPT-02 | The data export: only when no longer running, prepared in the background, kept seven days | Institutional administrator | P1 | VCI, FIX? |
| TC-SIS-01 | An SIS run refuses until it may run, and either finishes whole or leaves nothing | Institutional administrator | P1 | VCI |
| TC-SIS-02 | What the SIS owns, the app does not change by hand, and nothing the SIS drops is deleted | Institutional administrator | P1 | VCI |
| TC-SIS-03 | Grades go to the SIS once, after sign-off where the institution has deans | Institutional administrator | P1 | IBR |
| TC-SIS-04 | A file in the SIS folder becomes data in the app | Institutional administrator | P1 | VCI |

### TC-IAA-08 An institution can make its security stricter than the platform's, never looser

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** the platform's values (password 8, idle 120 minutes, 5 attempts for 15 minutes, code for administrators)
- **Steps:**
  1. On Settings › Security set password 12, idle 30, lockout 3 attempts. Then try password 6, idle 200, lockout 0 and 10.
  2. Set a value equal to the platform's. Add "dean" to the roles that need a code.
  3. As a student of institution 1, sign in, wait out the idle limit, fail three times. As a student of another institution, do the same.
  4. Invite someone and set a 10-character password through the link.
  5. As the system administrator raise the platform's password to 14. Press Reset on the institution.
- **Expected:**
  - Stricter values are saved and audited; looser ones are refused naming the field, and nothing is saved. Lockout 0 ("never") is refused while the platform has a lockout (decision AMB-AUTH-06).
  - A value equal to the platform's is not stored, so the institution follows later platform changes.
  - The institution's rules hold its own people only: 30-minute idle, 3 failures, 12-character passwords, a code for deans. Another institution's people are untouched.
  - An invited person is held to the inviting institution's rule.
  - When the platform tightens past the institution, the platform wins. Reset goes back to the platform's values.
- **Already automated:** `tests/database/InstitutionSecurityTest.php` (all)
- **Playwright:** no
- **Basis:** `InstitutionSecurity` settings; decision U1c
- **QA status:** VCI (PS-AUT-04)
- **Review:** Draft

### TC-IAA-09 An institution's permissions layer on the platform's, take effect at once, and keep someone able to approve

- **Role:** Institutional administrator, then the affected roles. **Priority:** P1
- **Data:** Roles and permissions; `instructor1`, `dean2`, `chairperson1`
- **Steps:**
  1. Open Roles and permissions. Note it lists the five academic roles only. Change a box without confirming the password, then with it.
  2. Clear `courses.manage` for instructors. As `instructor1`, try to edit IT101-A's content. Repeat with `grading.manage`, `announcements.manage` and `discussions.manage`.
  3. Clear a box, then as the system administrator change the same permission's platform default, and a box the institution never touched. Put the institution's box back to the default.
  4. Try to set a permission that does not apply to a role, to clear a role's last permission, and to change the institutional administrator role.
  5. Clear `grades.approve` and then `grades.signoff` for deans.
  6. Make the institution read-only and try a change.
- **Expected:**
  - Step 1: only student, instructor, chairperson, dean and parent are shown; a change needs the password; then it applies to this institution only and is audited with before and after.
  - Step 2: each cleared permission takes effect on the next request; that part of the course becomes read-only for the role ("Your institution doesn't let your role do this." where a change is refused).
  - Step 3: boxes the institution never touched follow the platform's change; setting a box back to the default removes the institution's row.
  - Step 4: refused; the role keeps at least one permission; the administrator role cannot be changed.
  - Step 5: both Roles pages warn that nobody can approve (or sign off) in CBM; the institution's administrators stand in for that duty, and waiting requests move to them (TC-DEAN-04).
  - Step 6: the page can be looked at but not changed.
- **Already automated:** `tests/feature/InstitutionRolesPageTest.php`, `tests/feature/PermissionsTakeEffectTest.php`, `tests/database/PermissionMatrixTest.php`, `PermissionMatrixChangeTest.php`, `GradeAuthorityTest.php`
- **Playwright:** no
- **Basis:** `PermissionMatrix`, `RolePermissions`, `GradeAuthority`; decisions 7.1–7.7, U1a, U1b, U1e, U1f, D2
- **QA status:** IBR (PS-RBA-02, -03, -04, -06, -07, -11, -14)
- **Review:** Draft

### TC-IAA-10 A person outside a course, or outside the institution, gets "not found" for everything in it

- **Role:** Every role. **Priority:** P1
- **Data:** IT201-A's course, assessment, announcement and discussion ids; a person, term, offering and department id of a second institution (onboard one as in TC-SA-04)
- **Steps:**
  1. As `student3` (not in IT201-A), open IT201-A's pages and call its APIs. Repeat as `instructor1`, `chairperson2`, `dean1`, `parent2` and a student who dropped IT201-A.
  2. As institution 1's administrator, open and change the second institution's people, terms, offerings and departments by id.
  3. As `student3`, try to post in a course they are in (student route group).
- **Expected:**
  - Steps 1–2: every page and API answers 404 "not found", never 403 "forbidden" and never the data; writes are refused the same way.
  - A chairperson reaches only their own sub-department; an instructor who is also a chairperson reaches the program as chairperson.
  - Step 3: a student has no route to post announcements (TC-ANN-02).
- **Already automated:** `tests/feature/CourseMembershipTest.php` (all); the per-page tests named "another institution… is a 404"
- **Playwright:** `tests/e2e/announcements.spec.js` (TC-ANN-02)
- **Basis:** the `coursemember` filter, `CourseMembership`, `InstitutionScope`; decision D1
- **QA status:** IBR (PS-RBA-10, PS-SEC-06)
- **Review:** Draft

### TC-IAA-11 A read-only, past or suspended institution can look and export, change nothing, and still manage its own account

- **Role:** Every role of institution 1. **Priority:** P1
- **Data:** institution 1 put into each state in turn: subscription dates in the past (read-only), suspended, past, deleted; and back to active. Do not run `subscriptions:sync`.
- **Steps:**
  1. Move the end date into the past. As each role, open pages, then try a change (post an announcement, edit a course, enrol a student).
  2. As a student, set and remove a reminder and mark a notification read. As `parent1`, change which child they are emailed about. As anyone, sign out.
  3. Suspend the institution; then set it past; then delete it. Repeat step 1.
  4. As the system administrator, record a renewal.
  5. Make a second institution read-only and check institution 1 is unaffected.
- **Expected:**
  - The state follows the dates the moment they pass, without the nightly job. A banner says so: "Your institution is no longer active. You can still look at and export your work, but nothing can be changed." (a past institution has its own wording).
  - Every change is refused (a page goes back with the message; a script gets 403 JSON); reading pages and APIs still works.
  - Own-account changes still work: reminders, notification read state, a parent's email choices, signing out. Course and institution records stay frozen.
  - Suspended, past and deleted behave like read-only.
  - A renewal lifts the restriction on the next request. Each institution's state applies only to its own people; the system administrator is never restricted.
- **Already automated:** `tests/feature/ReadOnlyModeTest.php` (all), `ReadOnlyOwnThingsTest.php` (all), `tests/database/EffectiveStatusTest.php`, `InstitutionAccessTest.php`
- **Playwright:** `tests/e2e/institutional-admin.spec.js` (TC-IAA-06), `system-admin.spec.js` (TC-SA-06, 07)
- **Basis:** `ReadOnlyFilter`, `EffectiveStatus`; decisions 6.1, 8.1–8.3, D1
- **QA status:** IBR (PS-ROM-01, -02, -04, -05, -06)
- **Review:** Draft

### TC-IAA-12 The administrator views as one of their own people only, and it is view only

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** `student5`, a second institutional administrator of institution 1, a person of another institution, a deactivated person
- **Steps:**
  1. On People, View as student5 without a reason, then with one; confirm the password.
  2. While viewing, open a quiz, a notification and a lesson, and try to change something.
  3. Leave the view. Try to view as the other administrator, the system administrator, yourself, the deactivated person, and the other institution's person (by id).
  4. Wait out the time limit during a view.
- **Expected:**
  - A reason and the password are required; the view is marked on every page and read-only ("You are viewing as another person, which is view-only. Nothing can be changed. Exit the view to make changes as yourself.").
  - Viewing saves nothing for the person: no quiz attempt (the quiz shows its landing, or a running attempt read-only), no notification read, no last-active time, no progress.
  - Leaving returns to the People list. The audit entries made during the view name the administrator.
  - Another administrator, the system administrator, yourself and a deactivated person cannot be viewed; a person of another institution answers as if they do not exist.
  - The view ends on the next request after the time limit.
- **Already automated:** `tests/feature/InstitutionViewAsTest.php` (all), `ViewAsWritesNothingTest.php`, `tests/database/ViewAsQuizTest.php`
- **Playwright:** no
- **Basis:** `Impersonation`; decisions 3.1, 3.2
- **QA status:** VCI (PS-IMP-04); FIX? for the writes-nothing part (PS-IMP-02, PS-IMP-03, commit `5140068`)
- **Review:** Draft

### TC-STR-07 A program or sub-department in use cannot be switched off, and the page says why

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** Structure: CBM › IT › its program, with active courses and sections in the active term
- **Steps:** switch off the IT sub-department; the program; a program whose courses are archived but which still has sections in the active term. Then build a new branch (department, sub-department, program), switch it off from the bottom up and back on from the top down. Try to add a program under an inactive sub-department.
- **Expected:** each node in use is refused with the reason (active children, active courses, sections in an active term); an empty branch switches off bottom-up and back on top-down; nothing is created under an inactive parent; switching twice is harmless.
- **Already automated:** `tests/database/StructureServiceTest.php` (all)
- **Playwright:** `tests/e2e/institution-structure.spec.js` (TC-STR-05, TC-STR-06)
- **Basis:** `StructureService`
- **QA status:** VCI (PS-STR-02)
- **Review:** Draft

### TC-CRS-07 Chairs and deans from the SIS, and the rules for deans

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** an SIS connection on the test server (file connector, see `docs/sis-integration.md`); a staff file naming a dean for CBM and a chairperson for IT
- **Steps:**
  1. Run the staff file. Look at Structure and the deans.
  2. Try to remove or replace the SIS chairperson by hand.
  3. Run a file with two chairs for IT, and one with two deans for one department.
  4. Assign a chairperson as dean (service or SIS). Assign `instructor2` as dean.
  5. Assign the first dean in an institution that had none, where finalized grades were not yet sent to the SIS.
  6. Turn the connection off and look again.
- **Expected:**
  - The SIS chairperson replaces one made by hand, and the history stays; the page marks it as SIS and refuses changing it by hand ("This assignment is managed by your student information system. Change it there; the next sync brings it here.").
  - A second chair or dean for one place in one file is refused for that row; the rest of the run goes on.
  - One dean per department and one department per dean, enforced by the database; a chairperson cannot be dean; an instructor can and keeps teaching.
  - Step 5: from then on unsent finalized grades need sign-off, and the administrators are told which sections wait.
  - With the connection off, nothing is marked and everything can be changed by hand.
- **Already automated:** `tests/database/SisStaffImportTest.php`, `DeanAssignmentServiceTest.php`, `tests/feature/SisStaffLockPagesTest.php`, `AdministratorSignsOffTest::testTheFirstDeanTellsEachSignerWhatNowNeedsSignOff`
- **Playwright:** no
- **Basis:** `SisImporter`, `DeanAssignmentService`, `ChairAssignmentService`; decisions 2.3, D8, D9
- **QA status:** VCI (PS-CHR-09, -10), IBR (PS-CHR-11)
- **Review:** Draft

### TC-TRM-04 A term moves between upcoming and active only while nobody is enrolled, and a completed term is closed

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** Terms; a new term "Test term" with no sections; the active 1st Semester 2026-2027
- **Steps:** create "Test term" (also with the same name in the same year, and in another year); start it; move it back to upcoming; start it again while another term is active; enrol a student in one of its sections and try to move it back; edit and move a completed term; send a status of completed directly.
- **Expected:** created as upcoming and audited; the same name in the same year is refused, another year is fine; upcoming ⇄ active works while nobody is enrolled; starting a second active term warns that another is active; with students enrolled it cannot go back to upcoming; a completed term cannot be edited or moved; "completed" is set only by closing (TC-TRM-05).
- **Already automated:** `tests/database/TermServiceTest.php` (all)
- **Playwright:** `tests/e2e/institution-structure.spec.js` (TC-TRM-01, 02)
- **Basis:** `TermService`
- **QA status:** VCI (PS-TRM-02)
- **Review:** Draft

### TC-TRM-05 Closing a term completes its sections and places, and reopening puts back exactly that

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** "Test term" with two sections: one with two enrolled students and one dropped student, one archived
- **Steps:**
  1. Press Close: read the preview, confirm the password.
  2. After closing, change one completed place by hand back to enrolled (test database). Then reopen the term.
  3. Copy a section from the closed term into another term (TC-OFF-05) and try to reopen it.
  4. Make the audit write fail (test database trigger) and close.
- **Expected:**
  - The preview counts what will change. Closing asks for the password, sets the sections and the enrolled places to completed, leaves dropped places and archived sections alone, and stores what it changed.
  - Reopening restores only the rows still completed; the one changed afterwards is left as it is.
  - A term something was copied from cannot be reopened.
  - A failed audit undoes the whole close.
  - A closed term cannot be closed again, an open one cannot be reopened. Closing with unsigned grades is covered in Wave 3 (PS-TRM-05, not built).
- **Already automated:** `tests/database/TermCloseServiceTest.php` (all)
- **Playwright:** `tests/e2e/institution-structure.spec.js` (TC-TRM-03)
- **Basis:** `TermCloseService`
- **QA status:** VCI (PS-TRM-03, PS-TRM-04)
- **Review:** Draft

### TC-TRM-06 A closed section is read-only for everyone in it

- **Role:** Instructor, student, chairperson. **Priority:** P1
- **Data:** a completed section (from TC-TRM-05) and an archived one
- **Steps:** as its instructor, student and chairperson, open the closed section and try to change content, grade, submit work, post an announcement from the calendar. Open the archived section.
- **Expected:** the course page says it is closed; every change is refused; reading works; the calendar cannot post into it; another active section stays writable; an archived section is not opened at all.
- **Already automated:** `tests/feature/ClosedOfferingTest.php` (all)
- **Playwright:** no
- **Basis:** the `closedoffering` filter
- **QA status:** VCI (PS-TRM-07)
- **Review:** Draft

### TC-OFF-04 Adding a section, and assigning its instructors

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** Offerings; IT101 in the active term (sections A and B exist, one archived section C)
- **Steps:**
  1. Add section `d` (lowercase), `A` again, `C` (archived), with capacity 0, 1 and above the maximum; offer an archived course and a course in a completed term.
  2. Lower a section's capacity below its enrolled count. Rename B onto A.
  3. Assign `instructor2` to a section, assign them twice, assign a student, then unassign them. Do the same on an archived section and on a section whose instructor the SIS supplies.
- **Expected:**
  - The section is saved in capitals and audited; course, term and section together are unique, archived sections included; capacity stays within its bounds; only an active course in an upcoming or active term can be offered.
  - Capacity cannot go below the students enrolled; a section cannot be renamed onto another.
  - Only the institution's instructors can be assigned, once; unassigning keeps their work; an archived section's instructors cannot be changed; an SIS instructor is refused by hand while the connection is on.
- **Already automated:** `tests/database/OfferingServiceTest.php` (all), `tests/feature/SisStaffLockPagesTest.php`
- **Playwright:** `tests/e2e/institution-structure.spec.js` (TC-OFF-01 to 03)
- **Basis:** `OfferingService`
- **QA status:** VCI (PS-OFF-01, PS-OFF-03)
- **Review:** Draft

### TC-OFF-05 Copying sections into a new term is all or nothing

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** the 1st Semester's sections; an upcoming "2nd Semester" term 5 months later, which already has an IT201-A
- **Steps:**
  1. Open Copy, choose the source and target terms, read the plan, choose all sections including IT201-A, with and without instructors.
  2. Remove IT201-A from the choice and copy.
  3. Open a copied section, and the original.
  4. Try the source as the target, a completed term as the target, and reopening the source term afterwards.
- **Expected:**
  - Step 1: a section that already exists in the target refuses the whole run; nothing is copied.
  - Step 2: the content comes across with its links remapped; dates move by the difference between the terms; files are shared, not duplicated; instructors only when asked; students, submissions and grades never. The copy is audited, and a completed section's copy is active.
  - Step 3: the original is untouched.
  - Step 4: the target must be an open term other than the source; archived sections are not offered; the source term cannot be reopened once something was copied from it.
- **Already automated:** `tests/database/OfferingCopyServiceTest.php` (all)
- **Playwright:** no
- **Basis:** `OfferingCopyService`
- **QA status:** VCI (PS-OFF-04)
- **Review:** Draft

### TC-ENR-04 Enrolling a list of students, and changing a student's status

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** the IT101-B roster (empty, capacity 40); a list mixing student numbers and emails, commas and new lines, a teacher's email, an SIS-managed student, an already enrolled student, a dropped student, a completed student, an unknown email; a list of 501 lines
- **Steps:**
  1. Paste the mixed list and enrol. Paste an empty box, and the 501 lines.
  2. Enrol more than the capacity, without and with the administrator's override.
  3. Change a student's status to dropped, then the same status again, then an unknown status, then back to enrolled in a full section.
  4. Try a change on an archived section.
- **Expected:**
  - Step 1: students are found by number or email; those who cannot be enrolled are skipped with the reason (not a student, SIS-managed, already enrolled, completed) and the rest go in; a dropped or withdrawn student is reinstated; it is audited. An empty box and more than 500 lines are refused.
  - Step 2: going over capacity is refused unless the override is ticked.
  - Step 3: status changes keep the enrolment and are audited; the same or an unknown status is refused; bringing someone back into a full section needs the override.
  - Step 4: an archived section takes no changes. What the SIS owns is not changed by hand.
  - Reinstating through Add must refresh the gradebook tag like a status change (Wave 3, PS-ENR-03; expected to fail today).
- **Already automated:** `tests/database/EnrolmentServiceTest.php` (all)
- **Playwright:** `tests/e2e/institution-structure.spec.js` (TC-ENR-01 to 03)
- **Basis:** `EnrolmentService`
- **QA status:** VCI (PS-ENR-01, PS-ENR-02)
- **Review:** Draft

### TC-ENR-05 Nobody is both a student and staff of one course

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** `instructor2` (teaches IT201-A), `chairperson1` (chairs IT, whose program holds IT101 to IT201), `dean2` (CBM), the institution's administrator
- **Steps:**
  1. Enrol `instructor2` in IT201-A, and in IT201's other section in the same term. Enrol them in IT101-A.
  2. Enrol a student of IT102-A, then try to assign them as IT102-A's instructor.
  3. Enrol `chairperson1` in IT101-A. Make a student who is enrolled in an IT course the chairperson of IT.
  4. Enrol `dean2` in a CBM course; enrol the administrator in a course of the institution.
  5. Do the same through an SIS file and a CSV upload.
  6. On a copy with an existing conflict (made before the migration), run the migration.
- **Expected:**
  - Steps 1–4: refused with the reason, whichever role came first; enrolling `instructor2` in IT101-A, which they do not teach, works (TC-QUIZ-06).
  - Step 5: the conflicting row fails alone with the same reason; the rest of the run goes on.
  - Step 6: the migration removes the enrolment and keeps the staff role; a former student who teaches later keeps their record.
- **Already automated:** `tests/database/CourseRoleConflictsTest.php`, `EndCourseRoleConflictsTest.php`, `CourseRoleWritersTest.php`, `SisStaffImportTest` (the two "fails alone" tests)
- **Playwright:** no
- **Basis:** `CourseRoleConflicts`; migration `2026-11-16-000001_EndCourseRoleConflicts`; decisions 9.2–9.7, D7; commit `5140068`
- **QA status:** FIX? (PS-ENR-04, PS-ENR-05, PS-RBA-13); the migration part is PS-ENR-07
- **Review:** Draft

### TC-PPL-05 Inviting, deactivating and changing the roles of people

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** People; the plan's student seats nearly full; a second administrator; `chairperson1`; an address already used by another institution's account
- **Steps:**
  1. Invite a person of each role; invite with a bad email and with an address in use here; invite a student when all seats are used; invite a parent with their students, with a typo in one.
  2. Resend and cancel an invitation.
  3. Deactivate a student (password asked), reactivate them with no seat left, deactivate yourself, the last administrator, and the second administrator.
  4. Remove the chairperson role from `chairperson1`.
  5. Invite the address that belongs to another institution.
- **Expected:**
  - Each invitation is sent and listed under Invitations; bad ones are refused beside the field; a student needs a free seat; a parent invitation with a typo stops on the students field.
  - Deactivating needs the password and is audited; nobody deactivates themselves or the last administrator; a second administrator can be, while another remains; reactivating a student needs a seat.
  - Removing the chairperson role ends their chair assignment.
  - Step 5: refused; one account belongs to one institution.
  - A person the SIS manages keeps their name and email.
- **Already automated:** `tests/feature/InstitutionPeopleTest.php`, `tests/database/PeopleServiceTest.php`
- **Playwright:** `tests/e2e/institution-structure.spec.js` (TC-PPL-01 to 04)
- **Basis:** `PeopleService`; decision D1
- **QA status:** VCI (PS-PPL-01, PS-PPL-02), IBR (PS-PPL-03)
- **Review:** Draft

### TC-PAR-02 Linking parents to students, and the two switches of the parent portal

- **Role:** Institutional administrator, then parent. **Priority:** P1
- **Data:** `parent2` (linked to nobody); student3 and student4; a teacher's email; a student of another institution
- **Steps:**
  1. Link parent2 to student3 by number and student4 by email, plus the teacher and the other institution's student. Link student3 again. Link student3 to `parent1` too.
  2. Unlink student4.
  3. Switch the parent portal off for the institution. As parent2, open the dashboard, a child API and the profile.
  4. Switch it on again. As the system administrator, switch the Mentora flag `parent.portal` off; try to switch it on at the institution.
- **Expected:**
  - Students are linked by number or email and audited; the teacher and the other institution's student are skipped with the reason; an existing link is skipped; one student can have two parents. Unlinking changes nothing else.
  - With either switch off the parent sees no child data (an API answers 403 JSON "The parent portal is switched off."), and the profile still works.
  - The institution cannot turn the portal on past Mentora's flag. Each switch change is audited, and one institution's switch does not affect another.
- **Already automated:** `tests/database/ParentLinkServiceTest.php`, `ParentPortalTest.php`
- **Playwright:** `tests/e2e/parent.spec.js`
- **Basis:** `ParentLinkService`, `ParentPortal`; decision AMB-OPS-04
- **QA status:** VCI (PS-PAR-01, PS-PAR-02)
- **Review:** Draft

### TC-RPT-02 The data export: only when no longer running, prepared in the background, kept seven days

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** institution 1 active, then read-only; a second administrator; on a test server, the export scale data (`php spark db:seed ExportScaleSeeder`: 20,000 students, 5 years, about 50 graded items a course)
- **Steps:**
  1. While active, open Data export. Then make the institution read-only and press Create an export. Press it again.
  2. Run `php spark exports:run`. Download the file as the requester. As the second administrator, look at the page.
  3. Move the export's date past 7 days and try to download. Make the build fail and ask again.
  4. As another institution's administrator, download institution 1's export by its address.
  5. Run the export on the scale data and time it.
- **Expected:**
  - Step 1: while running, the export is not offered ("available once the subscription has ended"); when read-only or past, it is queued; a second request while one is open is refused.
  - Step 2: the job prepares it one at a time per institution; only the requester is told it is ready; every administrator sees that one is being prepared.
  - Step 3: after 7 days it cannot be downloaded; a failed build tells the requester and frees the slot for another request.
  - Step 4: refused.
  - Step 5: it completes in the background without timing out; a run that stalls is failed after 30 minutes.
- **Already automated:** `tests/feature/InstitutionExportTest.php`, `tests/database/InstitutionExportQueueTest.php`, `InstitutionExportScaleTest.php`
- **Playwright:** `tests/e2e/system-admin.spec.js` (TC-SA-06)
- **Basis:** `InstitutionExportService`, `ExportsRun`; decisions 12.1–12.4; commit `5069228`
- **QA status:** VCI (PS-RPT-02); FIX? (PS-RPT-03)
- **Review:** Draft

### TC-SIS-01 An SIS run refuses until it may run, and either finishes whole or leaves nothing

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** Integrations; the file connector with a sample set of files (people, courses, enrolments, staff, class times)
- **Steps:**
  1. Run a sync with: the SIS flag off; settings not saved; the connection disabled; the institution read-only; no earlier preview. Read each refusal.
  2. Run a preview, then the real run.
  3. Put a file with bad rows in, and run. Make the run fail partway (an unreadable file, or a test that throws), and run again after fixing it.
- **Expected:**
  - Step 1: each gate refuses with its own message, and nothing is written; a preview works with the connection disabled; the first real run needs a preview first.
  - Step 2: the preview writes nothing and its counts are what the real run then does.
  - Step 3: bad rows are counted and listed with their reason, and skipped; a failure partway leaves nothing behind and says so; an unreadable file fails the run before anything is written and tells the administrators; the next run starts clean. The audit row is part of the run.
- **Already automated:** `tests/database/SisImporterTest.php`, `SisImporterRollbackTest.php`
- **Playwright:** no
- **Basis:** `SisImporter`
- **QA status:** VCI (PS-SIS-01, PS-SIS-02)
- **Review:** Draft

### TC-SIS-02 What the SIS owns, the app does not change by hand, and nothing the SIS drops is deleted

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** a successful run from TC-SIS-01
- **Steps:**
  1. Run the same files again. Change a name, a course title and an email in the files and run.
  2. In the app, try to edit an SIS person's name, an SIS course and SIS enrolments; to unassign an SIS instructor; to replace an SIS chair or dean.
  3. Remove a person, an enrolment and a staff row from the files and run.
  4. Try to make the SIS take over an account of another institution, an administrator, an account with the wrong role, and two SIS records for one account.
  5. Disable the connection and repeat step 2.
- **Expected:**
  - Running again changes nothing; what the SIS changes is updated and the SIS wins.
  - Step 2: refused while the connection is enabled, with the reason.
  - Step 3: people are not removed; staff assignments are ended (never deleted) and only those the SIS supplied; if rows were unreadable nothing is ended; instructors are ended only in the active term.
  - Step 4: refused.
  - Step 5: everything can be changed by hand.
- **Already automated:** `SisImporterTest`, `tests/database/SisStaffAssignmentsTest.php`, `SisStaffImportTest.php`, `tests/feature/SisStaffLockPagesTest.php`
- **Playwright:** no
- **Basis:** `SisImporter`, `SisOwnership`; `docs/sis-integration.md`
- **QA status:** VCI (PS-SIS-03, PS-SIS-04, PS-SIS-08)
- **Review:** Draft

### TC-SIS-03 Grades go to the SIS once, after sign-off where the institution has deans

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** institution 1 (it has deans); IT101-B finalized and not signed off; IT201-A finalized and signed off; a second institution with no deans
- **Steps:**
  1. Run a grade-export preview, then `php spark sis:grades`.
  2. Run it again. Approve an override on a signed-off section and run. Reopen a sign-off, change a grade, sign off again, and run.
  3. Make the delivery fail three times, then succeed.
  4. In the institution with no deans, finalize a section and run.
- **Expected:**
  - Only signed-off sections go; the preview says which sections were left out and why ("the dean has not signed off", not finalized, withdrawn, INC). The frozen final grade is sent, with an override winning.
  - A second run sends nothing new; a changed grade is sent once as an update; changes wait while a sign-off is reopened.
  - A failing delivery is retried three times with the same delivery key; if it keeps failing nothing is marked sent and the administrators are told.
  - With no deans, a finalized grade goes without sign-off.
- **Already automated:** `tests/database/SisGradeExporterTest.php` (all)
- **Playwright:** no
- **Basis:** `SisGradeExporter`, `GradeAuthority`; decisions 2.1–2.3
- **QA status:** VCI (PS-SIS-05), IBR (PS-SIS-06)
- **Review:** Draft

### TC-SIS-04 A file in the SIS folder becomes data in the app

- **Role:** Institutional administrator. **Priority:** P1
- **Data:** the test server; the file connector's inbound folder for institution 1; one new student, one new course, one enrolment
- **Steps:** place the files in the inbound folder; run `php spark sis:sync`; sign in and look for the student, the course and the enrolment.
- **Expected:** the run is recorded with its counts on Integrations; the student can be found on People, the course in Courses, and the enrolment on the roster. No browser or command-line end-to-end test covers this today.
- **Already automated:** `SisImporterTest` (service level only)
- **Playwright:** no
- **Basis:** `SisSync` command, `SisImporter`, the file connector
- **QA status:** VCI (PS-SIS-09)
- **Review:** Draft
