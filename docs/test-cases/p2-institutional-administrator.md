# P2 test cases: the institutional administrator

43 cases in two files. Read [README.md](README.md) first. **All are `Draft`**
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
| TC-CRS-03 | A chairperson is assigned to a sub-department and removed again | Structure |
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
