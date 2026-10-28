# P2 test cases: the dean

11 cases in the browser, and the PHP tests that stand behind the rest. Wave 1 of the QA plan (2026-10-28) rewrote TC-DEAN-04 and TC-DEAN-05 and added TC-DEAN-12 to 14; Wave 2 added TC-DEAN-15 to 20, Wave 3 TC-DEAN-21 to 31. All are at the end. Read
[README.md](README.md) first. All are `Draft` (see the README).

`tests/e2e/dean.spec.js` holds the cases. The demo data has two deans
(`app/Database/Seeds/DemoUsersSeeder.php`, `DeanAssignmentsSeeder.php`):

- **`dean1@mentora.test`** is the dean of the General department, which has no
  courses. It is the dean who reaches nothing of College of Business and
  Management (CBM), and the dean whose lists are all empty.
- **`dean2@mentora.test`** is the dean of CBM and also holds the instructor role
  (a dean cannot also be a chairperson). CBM holds IT101-A and IT101-B
  (`instructor1`), IT102-A and IT201-A (`instructor2`), all in the IT
  sub-department that `chairperson1@mentora.test` chairs.

The cases use IT201-A for the override chain (its three students have grades),
IT101-B for sign-off (nobody is enrolled, so its grades can be finalized in two
clicks) and IT101-A as the section that is blocked. Everything a person can do is
done through the screens. SQL is used for only two states the app has no screen
for yet (a dean who has lost the department, an institution made read-only), and
both are put back.

What these prove, in the order of the agreed definition of done:

1. A dean reaches nothing outside the department, by menu, address or form, and
   can change nothing inside a course they do not teach (TC-DEAN-03, 08).
2. No cross-role leakage after a role switch (TC-DEAN-02).
3. An override never takes effect without an approval, and goes through the
   chairperson first (TC-DEAN-04).
4. A signed-off section can be reopened only by whoever decides for the department: the dean who holds the sign-off permission, otherwise the institution's administrators (TC-DEAN-05, TC-DEAN-13).
5. Every screen meets the interface rules: all states exist, no sideways scroll
   at 360, 768 and 1440 pixels, the keyboard is enough, and the automated
   accessibility check finds nothing serious (TC-DEAN-09, 10, 11).

The cases attach a screenshot of each state to the Playwright report
(`npx playwright show-report`): the dashboard, the view-only course, the
approvals list and its dialog, the sign-off board, the follow-up form, the
announcements, every screen at each of the three widths, every empty state, the
no-department page and the read-only institution.

| ID | Case | Role |
|---|---|---|
| [TC-DEAN-01](#tc-dean-01-a-dean-signs-in-and-lands-on-the-department-dashboard) | A dean signs in and lands on the department dashboard | Dean |
| [TC-DEAN-02](#tc-dean-02-a-dean-who-also-teaches-switches-roles-and-the-app-follows) | A dean who also teaches switches roles and the app follows | Dean, instructor |
| [TC-DEAN-03](#tc-dean-03-a-course-opened-by-its-dean-is-view-only) | A course opened by its dean is view only | Dean |
| [TC-DEAN-04](#tc-dean-04-an-override-is-a-request-the-chairperson-endorses-it-the-dean-decides-it-and-the-grade-changes-only-then) | An override is a request: the chairperson endorses it, the dean decides it, and the grade changes only then | Instructor, chairperson, dean, student |
| [TC-DEAN-05](#tc-dean-05-the-dean-signs-off-a-sections-final-grades-and-only-the-departments-decider-can-reopen-the-sign-off) | The dean signs off a section's final grades, and only the department's decider can reopen the sign-off | Instructor, dean |
| [TC-DEAN-06](#tc-dean-06-a-dean-sends-a-follow-up-and-the-instructor-answers-it-and-closes-it) | A dean sends a follow-up and the instructor answers it and closes it | Dean, instructor |
| [TC-DEAN-07](#tc-dean-07-a-dean-posts-a-department-announcement-that-its-people-read-and-ends-it) | A dean posts a department announcement that its people read, and ends it | Dean, student |
| [TC-DEAN-08](#tc-dean-08-nothing-outside-the-deans-department-can-be-reached) | Nothing outside the dean's department can be reached | Dean |
| [TC-DEAN-09](#tc-dean-09-the-deans-screens-work-with-the-keyboard-alone) | The dean's screens work with the keyboard alone | Dean |
| [TC-DEAN-10](#tc-dean-10-every-screen-of-the-dean-fits-360-768-and-1440-pixels-and-passes-the-accessibility-check) | Every screen of the dean fits 360, 768 and 1440 pixels and passes the accessibility check | Dean |
| [TC-DEAN-11](#tc-dean-11-the-states-of-a-dean-with-nothing-to-show-no-department-or-a-read-only-institution) | The states of a dean with nothing to show, no department, or a read-only institution | Dean |
| [TC-DEAN-12](#tc-dean-12-an-override-cannot-be-requested-while-the-grades-are-finalized) | An override cannot be requested while the grades are finalized (Wave 1) | Instructor |
| [TC-DEAN-13](#tc-dean-13-whoever-decides-for-the-department-may-reopen-a-sign-off-an-administrator-gave) | Whoever decides for the department may reopen a sign-off an administrator gave (Wave 1) | Administrator, dean, instructor |
| [TC-DEAN-14](#tc-dean-14-a-menu-tile-disappears-when-the-dean-loses-the-permission-behind-it) | A menu tile disappears when the dean loses the permission behind it (Wave 1) | Administrator, dean |
| [TC-DEAN-15](#tc-dean-15-where-an-override-request-goes-first) | Where an override request goes first (Wave 2) | Instructor, chairperson, dean |
| [TC-DEAN-16](#tc-dean-16-endorse-send-back-resubmit-withdraw-approve-and-reject) | Endorse, send back, resubmit, withdraw, approve and reject (Wave 2) | Instructor, chairperson, dean |
| [TC-DEAN-17](#tc-dean-17-with-no-eligible-dean-the-institutions-administrators-decide-and-nobody-decides-their-own-request) | With no eligible dean the institution's administrators decide, and nobody decides their own request (Wave 2) | Administrator |
| [TC-DEAN-18](#tc-dean-18-two-people-acting-on-one-request-at-once-the-first-counts) | Two people acting on one request at once: the first counts (Wave 2) | Chairperson, instructor |
| [TC-DEAN-19](#tc-dean-19-who-signs-off-and-what-blocks-it) | Who signs off, and what blocks it (Wave 2) | Dean, administrator |
| [TC-DEAN-20](#tc-dean-20-each-step-of-the-chain-tells-the-right-people) | Each step of the chain tells the right people (Wave 2) | All of the chain |

The cases run in this order and share one database: TC-DEAN-04 to 07 leave their
results behind (a decided request, a finalized section, a closed follow-up, an
ended announcement), and TC-DEAN-09 to 11 read screens that those results fill.
Filter with `-g` only for a case that stands alone (01, 02, 03, 08).

---

### TC-DEAN-01 A dean signs in and lands on the department dashboard

- **Role:** Dean. **Priority:** P2
- **Data:** `dean2@mentora.test`
- **Steps:**
  1. Sign in.
  2. Look at the page, then open the account menu.
- **Expected:**
  - The dean lands on `/dean/dashboard`.
  - The page has the department's numbers ("Your department") and "Needs
    Attention".
  - The tiles Courses, Approvals, Sign-off, Follow-ups, Announcements, Teaching
    load, Chairperson review and Monitor Department are all there.
  - The account menu names the role: Dean.
- **Already automated:** `tests/feature/DeanDashboardPageTest.php`,
  `tests/database/DeanDashboardServiceTest.php`, `tests/unit/DeanAttentionRulesTest.php`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Views/pages/dean/dashboard.php`,
  `app/Services/Courses/DeanDashboardService.php`
- **Review:** Draft

### TC-DEAN-02 A dean who also teaches switches roles and the app follows

- **Role:** Dean, then instructor. **Priority:** P2
- **Data:** `dean1@mentora.test` (only a dean), `dean2@mentora.test` (dean and instructor)
- **Steps:**
  1. As `dean1`, open the account menu.
  2. As `dean2`, sign in for the first time, open the account menu and switch to Instructor.
  3. Open `/dean/approvals`.
  4. Switch back to Dean.
- **Expected:**
  - A dean with no other role has nothing to switch to.
  - The first sign-in of a dean who also teaches opens as the dean, the highest role held.
  - After the switch the role reads Instructor on the instructor's dashboard, and the
    dean's Approvals tile is gone.
  - `/dean/approvals` leads back to the instructor's dashboard: the dean's pages are not
    reachable as an instructor.
  - After switching back, Approvals opens.
- **Already automated:** `tests/feature/DeanRoleRoutesTest.php`,
  `tests/database/ActiveRoleServiceTest.php`, `tests/js/shared/role-switch.test.js`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Services/Auth/ActiveRoleService.php`, `app/Controllers/Auth/RedirectController.php`
- **Review:** Draft

### TC-DEAN-03 A course opened by its dean is view only

- **Role:** Dean. **Priority:** P2
- **Data:** `dean2@mentora.test`, IT201-A
- **Steps:**
  1. Open Courses and open IT201-A.
  2. Open each tab in turn: Modules, Discussions, Assessments, Announcements, Grades.
  3. On Grades, open the Gradebook.
  4. Open `/instructor/courses`.
- **Expected:**
  - The page says first: "View only. You are looking at IT201 A ...".
  - Every tab can be read, and none offers what the dean cannot do: no Add Module, no
    New discussion, no Add Assessment, no announcement composer.
  - On Grades there is no Finalize, Reopen, Curve, Grading Scheme or Offline grade
    sheet. Reports stay.
  - The Gradebook lists each student's grade and standing as plain text, with no button
    to override a grade or mark it Incomplete.
  - `/instructor/courses` leads back to the dean's dashboard.
- **Already automated:** `tests/feature/DeanCourseViewTest.php`: `testTheDeanIsOfferedNoControlThatWouldBeRefused`,
  `testTheInstructorOfTheSameCourseStillHasEveryControl`,
  `testNoRouteExistsToChangeAnythingInACourse`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Services/Courses/CourseViewer.php`, `app/Views/pages/courses/view.php`
- **Review:** Draft

### TC-DEAN-04 An override is a request: the chairperson endorses it, the dean decides it, and the grade changes only then

*Updated 2026-10-28 (register PLN-03): the dean decides only because CBM has a dean who holds `grades.approve`. Where a department has no such dean, the institution's administrators decide instead (decisions 1.1, 1.3; `GradeAuthority`). The steps below are unchanged.*

- **Role:** Instructor, chairperson, dean, then student. **Priority:** P2
- **Data:** IT201-A: Reyes, Pedro (`student2`, 50%) and Villanueva, Michael (`student5`, 94%);
  `instructor2`, `chairperson1`, `dean2`
- **Steps:**
  1. As the instructor, open the Gradebook and ask for 75% for Pedro Reyes and 99% for
     Michael Villanueva, each with a reason.
  2. As the dean, open Approvals.
  3. As the chairperson, open Override requests and endorse both.
  4. As the dean, open Approvals, review Pedro's request and approve it.
  5. Review Michael's request, press Reject with no reason, then give a reason and reject it.
  6. Open the Decided list.
  7. As each student, open IT201-A's Grades.
- **Expected:**
  - The instructor's grid still shows 50% and marks the request as waiting for the chairperson.
  - Before the endorsement the dean has nothing to decide ("Nothing waiting for you").
  - After it, Approvals shows 2 waiting. The dialog shows the reason and who endorsed it,
    and says what approving does: "Approve 75% for Pedro Reyes in IT201 A? This replaces the
    calculated 50%."
  - Rejecting needs a reason and says who will read it.
  - Decided shows Approved for Pedro and Rejected for Michael.
  - Pedro sees 75%; Michael still sees 94% and neither sees any request.
  - Nobody decides their own request. Two people acting on one request at once: the second is told "That request was just acted on."
  - If the dean role loses `grades.approve`, the waiting requests move at once to the institution's administrators, who decide them on their own Approvals page.
- **Already automated:** `tests/feature/OverrideRequestFlowTest.php`,
  `tests/feature/DeanApprovalsPageTest.php`, `tests/database/GradeOverrideRequestServiceTest.php`,
  `tests/unit/OverrideRequestChainTest.php`; the administrators' path: `tests/database/AdministratorDecidesOverridesTest.php`,
  `tests/feature/InstitutionApprovalsPageTest.php`; races: `tests/database/OverrideRequestRaceTest.php`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Services/Courses/Grades/GradeOverrideRequestService.php`,
  `app/Services/Courses/Grades/GradeAuthority.php`, `app/Views/pages/dean/approvals.php`; commit `48543c7`
- **QA status:** FIX? (PS-OVR-04)
- **Review:** Draft

### TC-DEAN-05 The dean signs off a section's final grades, and only the department's decider can reopen the sign-off

*Updated 2026-10-28 (register PLN-03 and AMB-ASG-04): "only the dean" now means the department's decider, the dean who holds `grades.signoff`, otherwise the institution's administrators (TC-DEAN-13). Reopening needs a reason, not the password.*

- **Role:** Instructor, then dean. **Priority:** P2
- **Data:** IT101-B (no students), `instructor1`, `dean2`; IT101-A is blocked
- **Steps:**
  1. As the instructor, open IT101-B's Grades and finalize the grades.
  2. As the dean, open Sign-off.
  3. Press Sign off for IT101-B, confirm, and give the password again.
  4. As the instructor, reload Grades.
  5. As the dean, press Reopen with no reason; then give one.
  6. As the instructor, reload Grades.
- **Expected:**
  - The board shows IT101-B as Ready, and IT101-A as Blocked with "has not finalized the
    grades yet".
  - Signing off says "only you can" reopen it, then asks for the password.
  - IT101-B then reads Signed off.
  - The instructor sees "Signed off by Diana Mendoza" (the dean who signed; name format to confirm) and has no Reopen button. **Expected to fail today:** the toolbar says "Signed off by the dean" (TC-GBK-07).
  - Reopening needs a reason ("Give a reason for reopening, so the instructor knows why.") and does not ask for the password; afterwards IT101-B is Ready again.
  - The instructor can reopen their own finalization again.
- **Already automated:** `tests/database/GradeSignoffServiceTest.php`,
  `tests/feature/DeanSignoffPageTest.php`, `tests/database/GradeFinalizationServiceTest.php`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Services/Courses/Grades/GradeSignoffService.php` (`mayDecide`, `reopen`),
  `app/Views/pages/dean/signoff.php`; decision AMB-ASG-04; commit `48543c7`
- **QA status:** FIX? for the rule (PS-SGN-03, PS-SGN-04); REG for the instructor's wording (PS-FIN-04)
- **Review:** Draft

### TC-DEAN-06 A dean sends a follow-up and the instructor answers it and closes it

- **Role:** Dean, then instructor. **Priority:** P2
- **Data:** IT201-A, `dean2`, `instructor2` (Joseph Garcia)
- **Steps:**
  1. As the dean, open Follow-ups. Press New follow-up, choose IT201 A, and Continue.
  2. Choose Joseph Garcia, a kind, a subject and a message. Send.
  3. As the instructor, open Requests, open the request, reply, then Mark as done.
  4. As the dean, open Follow-ups, Done.
- **Expected:**
  - Before anything is sent the list says "No open follow-ups".
  - The form offers the section's chairperson and instructors, and nobody else.
  - The request appears in the dean's list.
  - The instructor finds it under Requests, and the reply shows in its thread.
  - After "Mark as done" the instructor's list says "Nothing is waiting for you".
  - The dean sees it under Done, with the reply.
- **Already automated:** `tests/feature/FollowUpsPageTest.php`,
  `tests/database/FollowUpServiceTest.php`, `tests/unit/FollowUpRulesTest.php`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Services/Courses/FollowUpService.php`, `app/Views/pages/follow-ups/index.php`
- **Review:** Draft

### TC-DEAN-07 A dean posts a department announcement that its people read, and ends it

- **Role:** Dean, then student. **Priority:** P2
- **Data:** `dean2`, `student1` (in CBM's IT101-A), `dean1` (another department)
- **Steps:**
  1. As the dean, open Announcements and post one to the whole department, for everyone.
  2. As the student, open Announcements.
  3. As the other department's dean, open Announcements.
  4. As the dean, End it.
  5. As the student, reload.
  6. As the dean, open Ended.
- **Expected:**
  - Before posting the list says "No announcements showing".
  - The post shows in the dean's list with who it is for.
  - The student reads it under "Your department", in the Announcements app.
  - The other department's dean has no sign of it.
  - Ending it takes it away from the student at once ("Nothing from your department right
    now") and keeps it in the dean's Ended list.
- **Already automated:** `tests/feature/DepartmentAnnouncementsPageTest.php`,
  `tests/database/DepartmentAnnouncementServiceTest.php`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Services/Courses/DepartmentAnnouncementService.php`,
  `app/Views/pages/announcements/index.php`
- **Review:** Draft

### TC-DEAN-08 Nothing outside the dean's department can be reached

- **Role:** Dean. **Priority:** P2
- **Data:** `dean2` finds the addresses of a CBM course, instructor and program; `dean1` asks for them
- **Steps:**
  1. As the dean of CBM, note the address of IT201-A, of an instructor's teaching-load page and of
     a program's Monitor Department page.
  2. As the dean of the General department, open each address, then a made-up course address.
  3. Open `/instructor/dashboard`.
- **Expected:**
  - Each answers 404 with the page "We couldn't find that page", the same page as for an address that
    never existed: nothing says that the thing exists elsewhere.
  - `/instructor/dashboard` leads back to the dean's dashboard.
- **Already automated:** `tests/feature/DeanCourseViewTest.php`,
  `tests/feature/TeachingLoadPageTest.php`, `tests/feature/MonitorDepartmentPageTest.php`,
  `tests/feature/FriendlyNotFoundPageTest.php`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Services/Courses/DeanAccessService.php`, `app/Views/errors/html/error_404.php`
- **Review:** Draft

### TC-DEAN-09 The dean's screens work with the keyboard alone

- **Role:** Dean. **Priority:** P2
- **Data:** `dean2`
- **Steps:**
  1. On the dashboard press Tab until the Approvals tile has the focus, then Enter.
  2. On Announcements, focus "New announcement" and press Enter.
  3. Press Tab twelve times, then Escape.
- **Expected:**
  - Tab reaches the Approvals tile within 40 presses, the focus is visibly marked, and Enter opens
    Approvals.
  - The dialog opens and takes the focus inside it.
  - Tab never leaves the dialog.
  - Escape closes it and gives the focus back to the button that opened it.
- **Already automated:** `tests/js/shared/dialog.test.js`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `resources/js/shared/dialog.js`, `app/Views/components/dialog.php`
- **Review:** Draft

### TC-DEAN-10 Every screen of the dean fits 360, 768 and 1440 pixels and passes the accessibility check

- **Role:** Dean. **Priority:** P2
- **Data:** `dean2`; the nine screens: Dashboard, Courses, Approvals, Sign-off, Follow-ups,
  Announcements, Chairperson review, Teaching load, Monitor Department
- **Steps:**
  1. For each width, set the window to that width and open each screen.
  2. At 360 and at 1440, run the automated accessibility check (the WCAG A and AA rules).
- **Expected:**
  - Every screen opens, with its heading.
  - No screen scrolls sideways as a whole at any of the three widths (a table may scroll inside
    its own frame).
  - The check finds no serious or critical problem on any screen at 360 or 1440.
  - A screenshot of every screen at every width is in the report.
- **Already automated:** none: this is what only a browser can show
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Views/pages/dean/`, `app/Views/pages/dashboard/partials/dean/`
- **Review:** Draft

### TC-DEAN-11 The states of a dean with nothing to show, no department, or a read-only institution

- **Role:** Dean. **Priority:** P2
- **Data:** `dean1` (a department with no courses), then `dean1` after losing the department, then
  `dean2` while institution 1 is read-only. The case puts both changes back.
- **Steps:**
  1. As `dean1`, open Approvals, Sign-off, Follow-ups, Announcements, Chairperson review, Courses,
     Teaching load, Monitor Department and the dashboard.
  2. End `dean1`'s deanship and open each of the nine screens.
  3. Make the institution read-only. As `dean2`, open each screen, then try to post an announcement.
- **Expected:**
  - Every list says what it is for and what will appear in it ("Nothing waiting for you", "No sections
    to sign off", "No open follow-ups", "No announcements showing", "Nothing to review", "No courses",
    "Nothing to monitor yet"), and the dashboard says "You're all caught up".
  - A dean with no department sees "You are not assigned to a department yet" on every screen: an
    explanation, not an error and not any data.
  - In a read-only institution the page says "Read-only." everywhere, every screen can still be
    opened, and posting an announcement is refused with the reason, saving nothing.
  - None of these pages has a serious accessibility problem.
- **Already automated:** `tests/feature/DeanRoleRoutesTest.php`, `tests/feature/DeanApprovalsPageTest.php`,
  `tests/feature/DeanSignoffPageTest.php`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Views/pages/dean/no-assignment.php`, `app/Views/layouts/partials/read-only-banner.php`
- **Review:** Draft

---

## Wave 1 (added 2026-10-28)

From the QA plan's Wave 1. The expected results come from `docs/qa/decision-register.md`. QA status **REG**: the defect was seen in the code and the case is expected to fail until it is fixed; not yet run.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-DEAN-12 | An override cannot be requested while the grades are finalized | Instructor | P2 | REG |
| TC-DEAN-13 | Whoever decides for the department may reopen a sign-off an administrator gave | Administrator, dean, instructor | P2 | REG |
| TC-DEAN-14 | A menu tile disappears when the dean loses the permission behind it | Institutional administrator, dean | P3 | REG |

### TC-DEAN-12 An override cannot be requested while the grades are finalized

- **Role:** Instructor. **Priority:** P2
- **Data:** `instructor2`, IT201-A, with its grades finalized (finalize them in Grades first; IT201-A has graded students)
- **Steps:**
  1. Open the Gradebook and ask for an override of Pedro Reyes's grade to 75%, with a reason.
  2. Send the same request directly (the override request route), so the page's own check is not what is tested.
  3. Reopen the finalization with a reason, then ask for the override again.
- **Expected:**
  - Steps 1–2: refused with "The grades are finalized. Reopen them to make changes." No request is recorded, and nobody is told of one.
  - Step 3: the request is accepted and goes to the chairperson, as in TC-DEAN-04.
  - In an institution with no deans, the instructor's direct override after finalization keeps working as before. It is a separate, deliberate path.
- **Already automated:** `tests/database/GradeOverrideRequestServiceTest.php` (the request flow, not this refusal)
- **Playwright:** planned
- **Basis:** decision AMB-ASG-05. Today `GradeOverrideRequestService::request` does not check finalization [read in the code; not run].
- **QA status:** REG (PS-OVR-11)
- **Review:** Draft. Expected result set by AMB-ASG-05.

### TC-DEAN-13 Whoever decides for the department may reopen a sign-off an administrator gave

- **Role:** Institutional administrator, then dean, then instructor. **Priority:** P2
- **Data:** `institution.admin@mentora.test`, `dean2`, `instructor1`, IT101-B. **Setup:** take the `grades.signoff` permission away from the dean role on the Roles page, so CBM has no dean who may sign off.
- **Steps:**
  1. As `instructor1`, finalize IT101-B's grades.
  2. As the administrator, open Sign-off. Sign off IT101-B, confirming the password.
  3. Give `grades.signoff` back to the dean role.
  4. As `dean2`, open Sign-off. Reopen IT101-B with no reason, then with a reason.
  5. As `instructor1`, open IT101-B's Grades.
- **Expected:**
  - Step 2: the administrator's board lists only the departments with no eligible dean. The sign-off works and asks for the password.
  - Step 4: the dean sees IT101-B as signed off by the administrator and may reopen it. A reason is required ("Give a reason for reopening, so the instructor knows why."); **no password** is asked. Afterwards IT101-B is Ready again.
  - Step 5: before step 4 the instructor reads "Signed off by <the administrator's name>" (see TC-GBK-07); after it the sign-off tag is gone and the reason reaches the instructor.
  - While the department has an eligible dean, the administrator cannot sign off or reopen there ("That section is not one you sign off.").
- **Already automated:** `tests/database/AdministratorSignsOffTest.php` (`testAnAdministratorSignsOffWhereThereIsNoDean`, `testWhereThereIsADeanTheAdministratorCannot`, `testTheAdministratorReopensWithAReason`, `testExistingSignOffsStayWhenTheSignerChanges`); `tests/feature/InstitutionSignoffPageTest.php`
- **Playwright:** planned
- **Basis:** `GradeSignoffService::mayDecide` and `reopen`; `GradeAuthority`; decision AMB-ASG-04. Expected to fail only on the wording in step 5 (the toolbar's "Signed off by the dean"); the rest is the committed behavior of `48543c7`.
- **QA status:** REG (PS-SGN-08)
- **Review:** Draft. Expected result set by AMB-ASG-04.

### TC-DEAN-14 A menu tile disappears when the dean loses the permission behind it

- **Role:** Institutional administrator, then dean. **Priority:** P3
- **Data:** `institution.admin@mentora.test`, `dean2`
- **Steps:**
  1. As the administrator, open Roles and permissions and clear, for the dean role, the permission behind Approvals (`grades.approve`). Then the ones behind Follow-ups and Announcements.
  2. As `dean2`, sign in again and open the Apps menu and the sidebar after each change.
  3. Open each page by its address.
  4. Give the permissions back.
- **Expected:**
  - Each tile disappears from the menu as soon as its permission is cleared, and comes back when it is given back.
  - The address is refused either way (the route checks the permission).
  - Courses and Monitor department already behave this way.
- **Already automated:** `tests/feature/PermissionsTakeEffectTest.php` (the routes)
- **Playwright:** no
- **Basis:** decision AMB-AUTH-08. Today `navigation_helper.php` hides only Courses and Monitor department for a dean; Approvals, Follow-ups and Announcements stay visible [read in the code; not run].
- **QA status:** REG (PS-RBA-09)
- **Review:** Draft. Expected result set by AMB-AUTH-08.

---

## Wave 2 (added 2026-10-28)

P1 scenarios of the override chain and sign-off whose expected result is a decision (**IBR**) or the code's behavior (**VCI**), expected to pass; not yet run. TC-DEAN-04 and TC-DEAN-05 (rewritten in Wave 1) and TC-DEAN-13 already cover parts of these; the cases below cover the rest. Several need an institution, department or person set up differently from the demo data: each says how.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-DEAN-15 | Where an override request goes first | Instructor, chairperson, dean | P1 | IBR |
| TC-DEAN-16 | Endorse, send back, resubmit, withdraw, approve and reject | Instructor, chairperson, dean | P1 | VCI |
| TC-DEAN-17 | With no eligible dean the institution's administrators decide, and nobody decides their own request | Institutional administrator | P1 | IBR |
| TC-DEAN-18 | Two people acting on one request at once: the first counts | Chairperson, instructor | P1 | IBR |
| TC-DEAN-19 | Who signs off, and what blocks it | Dean, institutional administrator | P1 | IBR |
| TC-DEAN-20 | Each step of the chain tells the right people | All of the chain | P1 | IBR |

### TC-DEAN-15 Where an override request goes first

- **Role:** Instructor, chairperson, dean. **Priority:** P1
- **Data:** CBM (dean `dean2`, IT chaired by `chairperson1`); a second institution with no deans at all; a department in institution 1 with no dean (General has `dean1`: end it on the test database, then put it back)
- **Steps and expected (for each, open the request dialog first: it says who the request would go to):**

  | Requester | Situation | First step |
  |---|---|---|
  | Instructor (`instructor2`, IT201-A) | chair and dean present | waits for the chairperson |
  | Instructor | IT has no chairperson | skips to the dean, and the requester is told |
  | Chairperson (`chairperson1`, teaching a section for the test) | | goes straight to the dean |
  | The only chairperson, requesting for their own section | | cannot endorse their own: goes to the dean |
  | Dean (`dean2` teaching IT101-A for the test) | | goes to the institution's administrators |
  | Instructor | department with no dean, institution has deans | after the chairperson, to the administrators |
  | Instructor | institution with no deans at all | no request: the override applies at once (0–100, a reason, kept in history) |

- **Expected (also):** in every request case the grade stays as calculated until approved; a second open request for the same student is refused, a closed one does not block; the database allows one open request per student and section; requests already past the chairperson do not move back when a chairperson is assigned later.
- **Already automated:** `tests/unit/OverrideRequestChainTest.php` (all), `tests/database/GradeOverrideRequestServiceTest.php`
- **Playwright:** `tests/e2e/dean.spec.js` (TC-DEAN-04), `chairperson.spec.js` (untagged; name this case in it)
- **Basis:** `OverrideRequestChain`, `GradeOverrideRequestService::request`; decisions 1.1, 1.5, 2.2, U5
- **QA status:** IBR (PS-OVR-01, PS-OVR-02, PS-CHR-03, PS-CHR-05)
- **Review:** Draft

### TC-DEAN-16 Endorse, send back, resubmit, withdraw, approve and reject

- **Role:** Instructor, chairperson, dean. **Priority:** P1
- **Data:** IT201-A, `instructor2`, `chairperson1`, `dean2`; three requests (Pedro Reyes, Michael Villanueva, Angela Cruz)
- **Steps:**
  1. As the chairperson, send Pedro's back with no comment, then with one. As the instructor, edit and resubmit it.
  2. Endorse all three. As another chairperson, try to act on them.
  3. As the instructor, withdraw Angela's. As someone else, try to withdraw one.
  4. As the dean, approve Pedro's; reject Michael's with no reason, then with one.
  5. Try to act on a decided request.
- **Expected:**
  - Sending back needs a comment and returns the request to the requester, who can edit it and start the chain again.
  - Only the requester can withdraw; only the department's chairperson can endorse or send back; a chairperson cannot act on their own request or one no longer waiting.
  - Approving changes the grade through the engine; rejecting needs a reason; the decided list shows both.
  - A decided request takes no further action.
- **Already automated:** `GradeOverrideRequestServiceTest` (the endorse, send-back, withdraw and decision tests), `tests/feature/OverrideRequestFlowTest.php`, `DeanApprovalsPageTest.php`
- **Playwright:** `tests/e2e/dean.spec.js` (TC-DEAN-04)
- **Basis:** `GradeOverrideRequestService`
- **QA status:** VCI (PS-OVR-03)
- **Review:** Draft

### TC-DEAN-17 With no eligible dean the institution's administrators decide, and nobody decides their own request

- **Role:** Institutional administrator (two of them), dean. **Priority:** P1
- **Data:** institution 1 with a second administrator; CBM with `grades.approve` cleared for deans (Roles page); `dean2`
- **Steps:**
  1. With the permission cleared, make a request from IT201-A and have it endorsed. Read both administrators' notifications and Approvals pages.
  2. As one administrator approve it; as the other try to decide it.
  3. Make a request and reject it with no reason, then with one. Look for a send-back button.
  4. As an administrator who teaches a section, request an override there.
  5. Remove the second administrator and repeat step 4.
  6. Remove the permission from everyone and make a request.
  7. Give the permission back to deans (and assign a dean where there was none).
  8. As an instructor, open the institution's Approvals and Sign-off pages.
- **Expected:**
  - Step 1: every administrator is told; the request waits on their Approvals page.
  - Step 2: the first decision wins; the second is told "That request was just acted on."
  - Step 3: rejecting needs a reason; administrators have no send-back.
  - Step 4: the administrator cannot decide their own request; another administrator does.
  - Step 5: with one administrator their own request waits and says it needs a second administrator.
  - Step 6: it waits and says why.
  - Step 7: waiting requests move to the dean (`grade.override_rerouted` is recorded when the permission changes).
  - Step 8: refused. Another institution's administrator sees nothing of it.
- **Already automated:** `tests/database/AdministratorDecidesOverridesTest.php` (all), `tests/feature/InstitutionApprovalsPageTest.php` (all)
- **Playwright:** no
- **Basis:** `GradeAuthority`, `GradeOverrideRequestService`; decisions 1.1–1.6, 7.2, U1e; commit `48543c7`
- **QA status:** IBR (PS-OVR-04 to PS-OVR-07, PS-OVR-12), VCI (PS-OVR-14)
- **Review:** Draft

### TC-DEAN-18 Two people acting on one request at once: the first counts

- **Role:** Chairperson and instructor, in two browsers. **Priority:** P1
- **Data:** an open request waiting for `chairperson1`, made by `instructor2`
- **Steps:** at the same moment, the chairperson presses Endorse and the instructor presses Withdraw. Repeat with Send back against Withdraw, and with a dean's decision against a re-route triggered by a permission change.
- **Expected:** only the first action counts; the second person is told "That request was just acted on."; the request ends in one state; one notification is sent. Without a race each action still works.
- **Already automated:** `tests/database/OverrideRequestRaceTest.php` (all); the database's unique keys for an open request, a sign-off, a finalization, a curve, a follow-up, an attempt and an enrolment are each tested in their service's test
- **Playwright:** no (two simultaneous sessions)
- **Basis:** `GradeOverrideRequestService` (status-guarded updates); decision 16.1
- **QA status:** IBR (PS-OVR-08, PS-DAT-02)
- **Review:** Draft

### TC-DEAN-19 Who signs off, and what blocks it

- **Role:** Dean, institutional administrator. **Priority:** P1
- **Data:** CBM sections: IT101-A (not finalized), IT201-A (finalized, with an open override request), IT101-B (finalized); `dean2` teaching IT101-B for the test; a second administrator; a second institution with no deans
- **Steps:**
  1. As `dean2`, open Sign-off. Read the states. Try to sign off IT101-A and IT201-A.
  2. Sign off IT101-B (which they teach); confirm the password. Sign off the whole program.
  3. Switch to Instructor and try to sign off.
  4. Clear `grades.signoff` for deans. As the administrator who teaches a section, sign off the program; as the other administrator, sign off the section the first one teaches.
  5. Give the permission back. Change the dean of CBM. Look at the earlier sign-offs. As the new dean, reopen one with a reason.
  6. In the institution with no deans, finalize a section and look for a Sign-off page.
- **Expected:**
  - Step 1: IT101-A is Blocked "has not finalized the grades yet"; IT201-A is blocked by the open request; IT101-B is Ready. Not-ready sections cannot be signed off.
  - Step 2: a dean may sign off a section they teach; it asks for the password; signing off the program takes the ready sections and leaves the rest. The gradebook stays locked and gains no way to edit.
  - Step 3: a dean acting as an instructor cannot sign off.
  - Step 4: the administrators' board lists only the departments with no eligible dean; an administrator does not sign off a section they teach ("another signs it off"); the other administrator does. It asks for the password and works like the dean's.
  - Step 5: sign-offs stay valid when the signer changes; the new dean can reopen one with a reason.
  - Step 6: an institution with no deans needs no sign-off.
  - An approved override after sign-off still works and is recorded as a later change.
- **Already automated:** `tests/database/GradeSignoffServiceTest.php`, `AdministratorSignsOffTest.php`, `tests/feature/DeanSignoffPageTest.php`, `InstitutionSignoffPageTest.php`
- **Playwright:** `tests/e2e/dean.spec.js` (TC-DEAN-05)
- **Basis:** `GradeSignoffService::mayDecide`; decisions 2.1–2.5, D3, D4; commit `48543c7`
- **QA status:** VCI (PS-SGN-01), IBR (PS-SGN-02 to PS-SGN-06, PS-REA-05 for the password)
- **Review:** Draft

### TC-DEAN-20 Each step of the chain tells the right people

- **Role:** Everyone in the chain. **Priority:** P1
- **Data:** the requests and sign-offs of TC-DEAN-15 to 19; each person's notification bell and the mail sink
- **Steps:** after each step, read the notifications of the requester, the chairperson, the dean, every administrator, and the old and new chairperson.
- **Expected:**

  | Event | Told |
  |---|---|
  | Request waiting for the chairperson | the chairperson (not the dean yet) |
  | Endorsed, or the chairperson skipped | the dean, at that moment |
  | Waiting for the administrators | every administrator |
  | Sent back, approved, rejected | the requester |
  | First decision among administrators | nobody is told twice |
  | Grades finalized (institution with deans) | the dean, once |
  | Signed off, reopened | the people concerned (instructor; on reopen with the reason) |
  | Chairperson replaced | the old chairperson; the new one about waiting requests |
  | First dean assigned | each signer about what now needs sign-off |

- **Already automated:** the notification tests in `GradeOverrideRequestServiceTest`, `GradeSignoffServiceTest`, `AdministratorDecidesOverridesTest`, `AdministratorSignsOffTest`, `OneChairpersonTest`
- **Playwright:** no
- **Basis:** `NotificationService` calls in the services named; decisions 1.4, 2.3, 16.0b, 16.0c
- **QA status:** IBR (PS-NOT-06)
- **Review:** Draft

---

## Wave 3 (added 2026-10-28)

P2 scenarios, and the P1 scenarios of the dean area that no earlier wave took. QA status as in the README; none has been run.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-DEAN-21 | The Approvals and Decided pages show each request's breakdown and reasons | Dean, institutional administrator | P2 | VCI |
| TC-DEAN-22 | The requester sees why a request skipped a step or waits | Instructor | P2 | IBR |
| TC-DEAN-23 | Sign-off holds later grade changes back from the SIS until it is signed again | Dean | P2 | VCI |
| TC-DEAN-24 | The dashboard's attention items come in a fixed order from fixed rules | Dean | P2 | VCI |
| TC-DEAN-25 | Teaching load, Monitor Department and the department report give the right figures | Dean | P2 | VCI |
| TC-DEAN-26 | Chairperson review lists what chairs changed in courses they do not teach | Dean | P2 | VCI |
| TC-DEAN-27 | A course the dean opens offers no way to change it | Dean | P2 | VCI |
| TC-DEAN-28 | A dean confirming the password lands back in the dean's area | Dean | P2 | VCI |
| TC-DEAN-29 | The dean reaches only the department they are dean of today | Dean | P1 | VCI |
| TC-DEAN-30 | Follow-ups: who may ask whom, one open per subject, passing down, and closing | Dean, chairperson, instructor | P1 | VCI |
| TC-DEAN-31 | Department and institution announcements reach the right people, and end | Dean, each audience | P1 | VCI |

### TC-DEAN-21 The Approvals and Decided pages show each request's breakdown and reasons

- **Role:** Dean, institutional administrator. **Priority:** P2
- **Data:** waiting and decided requests from TC-DEAN-16 and 17
- **Steps:** open Approvals and Decided as `dean2` and as the administrator; filter by program, section and status; open a request.
- **Expected:** each list holds what it should; filters narrow it; a request shows the student, current and requested grade with the grade's breakdown, the reason, who endorsed it and their comment, and the decision reason; the approve dialog says what approving does ("This replaces the calculated …").
- **Already automated:** `tests/feature/DeanApprovalsPageTest.php`, `InstitutionApprovalsPageTest.php`
- **Playwright:** `tests/e2e/dean.spec.js` (TC-DEAN-04)
- **Basis:** `app/Views/pages/dean/approvals.php`, `OverrideApprovalFilters`
- **QA status:** VCI (PS-OVR-13)
- **Review:** Draft

### TC-DEAN-22 The requester sees why a request skipped a step or waits

- **Role:** Instructor. **Priority:** P2
- **Data:** the situations of TC-DEAN-15 and 17: no chairperson; the only administrator's own request; nobody holding `grades.approve`
- **Steps:** make a request in each situation and open it in the gradebook.
- **Expected:** the requester reads that the chairperson step was skipped and why; that the request needs a second administrator; that nobody can approve it yet (wording for each to confirm, from `OverrideRequestChain`'s words for each status).
- **Already automated:** `OverrideRequestChainTest::testEveryOpenStatusHasWordsForWhoIsNext`, `AdministratorDecidesOverridesTest` (the "says why" tests)
- **Playwright:** no
- **Basis:** `OverrideRequestChain`; decisions U5, 7.3
- **QA status:** IBR (PS-OVR-15)
- **Review:** Draft

### TC-DEAN-23 Sign-off holds later grade changes back from the SIS until it is signed again

- **Role:** Dean. **Priority:** P2
- **Data:** a signed-off section already sent to the SIS
- **Steps:** look for anything sign-off locks that finalizing did not; approve an override and run `sis:grades`; reopen the sign-off, approve another, run it; sign off again and run it.
- **Expected:** sign-off adds no lock of its own (finalizing already locks); an override approved after sign-off is sent once as an update; while the sign-off is reopened changes wait, and go when it is signed again.
- **Already automated:** `SisGradeExporterTest` (`testAChangeApprovedAfterSignOffIsSentOnceAsAnUpdate`, `testChangesWaitWhileTheSignOffIsReopenedAndGoWhenItIsSignedAgain`), `GradeSignoffServiceTest::testSigningOffAddsNoWayToEditAndTheGradebookStaysLocked`
- **Playwright:** no
- **Basis:** `SisGradeExporter`, `GradeSignoffService`
- **QA status:** VCI (PS-SGN-10)
- **Review:** Draft

### TC-DEAN-24 The dashboard's attention items come in a fixed order from fixed rules

- **Role:** Dean. **Priority:** P2
- **Data:** CBM with: overrides waiting, sections ready and sections holding up sign-off, overdue follow-ups, overdue grading in two sub-departments, quiet courses, students at risk; then with none of these
- **Steps:** open the dashboard in each state.
- **Expected:** nothing to report is an empty list; overrides waiting are one item linking to Approvals; ready and holding-up sections are two items linking to Sign-off; overdue follow-ups link to the overdue tab; overdue grading and quiet courses are one item per sub-department, most first, never for zero; students at risk link to Monitor Department; items come in that priority order; counts and words agree in number.
- **Already automated:** `tests/unit/DeanAttentionRulesTest.php` (all)
- **Playwright:** `tests/e2e/dean.spec.js` (TC-DEAN-01)
- **Basis:** `DeanAttentionRules`
- **QA status:** VCI (PS-DEA-01)
- **Review:** Draft

### TC-DEAN-25 Teaching load, Monitor Department and the department report give the right figures

- **Role:** Dean. **Priority:** P2
- **Data:** CBM: a section with two instructors, an instructor with no students, the institution's high-load units set to 18
- **Steps:** open Teaching load; pick another term and a term outside the department; narrow to IT; sort by each column; open one instructor. Open Monitor Department and the department report, and export each. As `dean1`, open `instructor2`'s load.
- **Expected:** each instructor's numbers are their own and a shared section counts for both; zeros and no turnaround for an instructor with nothing; a high load is marked at the institution's unit count; it opens on the newest term and falls back to it for a term not in the department; sorting puts missing values last and ties by name; another department's dean sees only their own sections of the same instructor; a dean with no department sees nothing. Exports hold the same figures. (The calculations were not read in detail: check the figures by hand once.)
- **Already automated:** `tests/database/TeachingLoadServiceTest.php`, the Monitor Department and report tests
- **Playwright:** no
- **Basis:** `TeachingLoadService`, `DepartmentReportService`
- **QA status:** VCI (PS-DEA-05)
- **Review:** Draft

### TC-DEAN-26 Chairperson review lists what chairs changed in courses they do not teach

- **Role:** Dean. **Priority:** P2
- **Data:** `chairperson1` changes something in IT101-A (does not teach it) and in a section they teach (set up for the test)
- **Steps:** as `dean2`, open Chairperson review; filter by chairperson, sub-department and dates; mark entries reviewed; as `dean1` and as the chairperson, try to mark one.
- **Expected:** it lists the chairpersons' changes in the department's courses, newest first, described in words, and leaves out what a chair did in a course they teach and anything of another department; filters narrow it (an outside filter finds nothing, not everything); marking moves an entry between tabs, changes nothing in the entry and is audited; only the dean of the department marks, and a new dean starts with none.
- **Already automated:** `tests/database/ChairpersonReviewServiceTest.php` (all)
- **Playwright:** no
- **Basis:** `ChairpersonReviewService`
- **QA status:** VCI (PS-DEA-06)
- **Review:** Draft

### TC-DEAN-27 A course the dean opens offers no way to change it

- **Role:** Dean (also as the institutional administrator's course view). **Priority:** P2
- **Data:** `dean2`; IT101-A (not theirs to teach); a section they teach
- **Steps:** open IT101-A from the dean's Courses; look for any control that changes something; send writes to the course routes directly; open a course file and a student's submission file; open the section they teach as dean and as instructor.
- **Expected:** the course opens view only and shows what the instructor sees, drafts included; no route exists to change anything and an attempt leaves the course untouched; no control is offered that would be refused; a course file opens, a student's submission does not; their own section is view only as dean and normal as instructor; a closed course says so too.
- **Already automated:** `tests/feature/DeanCourseViewTest.php` (all)
- **Playwright:** `tests/e2e/dean.spec.js` (TC-DEAN-03)
- **Basis:** the dean course routes
- **QA status:** VCI (PS-MOD-12)
- **Review:** Draft

### TC-DEAN-28 A dean confirming the password lands back in the dean's area

- **Role:** Dean (not an administrator). **Priority:** P2
- **Data:** `dean2`
- **Steps:** sign off a section (password asked), confirm on the shared confirm page; repeat after the window with nothing pending (open the confirm page directly).
- **Expected:** after confirming, the dean returns to the Sign-off page, or with nothing pending to the dean dashboard; never to an administrator area.
- **Already automated:** none for a dean (GAP-AUTH-04)
- **Playwright:** no
- **Basis:** `ReauthService` (return page and fallback)
- **QA status:** VCI (PS-REA-04)
- **Review:** Draft

### TC-DEAN-29 The dean reaches only the department they are dean of today

- **Role:** Dean. **Priority:** P1
- **Data:** `dean2` (CBM); `dean1` (General)
- **Steps:** as `dean2`, open CBM's courses, people and programs, then General's by address. End `dean2`'s assignment (test database) and use the site. Send writes to the dean's course API.
- **Expected:** reach is the assigned department only (its programs, offerings, the institution's members and their instructors); another department answers not found; an ended assignment reaches nothing at once and shows the explanation page; the dean's read-only course API has no write route.
- **Already automated:** `tests/database/DeanAccessServiceTest.php`, `tests/feature/DeanCourseViewTest.php`
- **Playwright:** `tests/e2e/dean.spec.js` (TC-DEAN-08, 11)
- **Basis:** `DeanAccessService`
- **QA status:** VCI (PS-DEA-02, PS-DEA-07)
- **Review:** Draft

### TC-DEAN-30 Follow-ups: who may ask whom, one open per subject, passing down, and closing

- **Role:** Dean, chairperson, instructor. **Priority:** P1
- **Data:** `dean2`, `chairperson1`, `instructor1`, `instructor2`
- **Steps:** as the dean, ask the chairperson and the instructor of a section; ask yourself, a student, an instructor outside CBM. Ask about a student not in the course. Ask the same subject twice. As the chairperson, ask an instructor of IT; pass the dean's request down to an instructor of the course and to one outside it. Reply from both sides and from a third person. Close it twice. Leave one past its due date, and one a week without an answer.
- **Expected:** a dean may ask the chairperson and instructors of their sections, never themselves; a chairperson only the instructors of their sub-departments; nobody outside the department; a student-related request must be about someone in the course; the same subject cannot be asked twice while open (the database refuses it too); bad input stores nothing; only the two sides reply and each is told; either side closes, once, audited and told; a chairperson passes only a dean's request, only to an instructor of the course, and the dean still sees it; a request is overdue after its due date or a week unanswered. Without `followups.manage`, a received follow-up can still be answered (TC-CHA-05).
- **Already automated:** `tests/database/FollowUpServiceTest.php`, `tests/unit/FollowUpRulesTest.php`, `tests/feature/FollowUpsPageTest.php`
- **Playwright:** `tests/e2e/dean.spec.js` (TC-DEAN-06)
- **Basis:** `FollowUpService`
- **QA status:** VCI (PS-DEA-03)
- **Review:** Draft

### TC-DEAN-31 Department and institution announcements reach the right people, and end

- **Role:** Dean, then each audience. **Priority:** P1
- **Data:** `dean2`; CBM with IT and another sub-department; an instructor teaching in two departments; a person who is both instructor and chairperson; a platform announcement (system administrator) and an institution announcement (institution's administrator)
- **Steps:** post to the whole department, to IT only, to one program, and to staff only and students only; post to a group nobody is in; edit an active one and an ended one; end one; set one with a last day of today and look tomorrow.
- **Expected:** the whole department reaches its students, instructors and chairpersons as they are right now; a sub-department reaches its programs and its chairperson; a program those in it and its sub-department's chairperson; the role filter narrows; an instructor in two departments sees each one's for their own sections; a person reached twice is told once; posting is audited and in-app only, and tells everyone except the dean; a post nobody is in says so but is kept; only an active one can be edited; ending keeps the history; the last day still counts. Platform and institution announcements show to their audience until their end date.
- **Already automated:** `tests/database/DepartmentAnnouncementServiceTest.php`, `tests/feature/DepartmentAnnouncementsPageTest.php`, `InstitutionSecurityTest` (the institution announcement), `GeneralSettingsTest::testTheAnnouncementShowsForEveryoneAndNothingWhenEmpty`
- **Playwright:** `tests/e2e/dean.spec.js` (TC-DEAN-07)
- **Basis:** `DepartmentAnnouncementService`
- **QA status:** VCI (PS-DEA-04, PS-ANN-08)
- **Review:** Draft
