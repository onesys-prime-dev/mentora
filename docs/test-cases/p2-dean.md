# P2 test cases: the dean

11 cases in the browser, and the PHP tests that stand behind the rest. Read
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
4. A signed-off section can be reopened only by the dean (TC-DEAN-05).
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
| [TC-DEAN-05](#tc-dean-05-the-dean-signs-off-a-sections-final-grades-and-only-the-dean-can-reopen-the-sign-off) | The dean signs off a section's final grades, and only the dean can reopen the sign-off | Instructor, dean |
| [TC-DEAN-06](#tc-dean-06-a-dean-sends-a-follow-up-and-the-instructor-answers-it-and-closes-it) | A dean sends a follow-up and the instructor answers it and closes it | Dean, instructor |
| [TC-DEAN-07](#tc-dean-07-a-dean-posts-a-department-announcement-that-its-people-read-and-ends-it) | A dean posts a department announcement that its people read, and ends it | Dean, student |
| [TC-DEAN-08](#tc-dean-08-nothing-outside-the-deans-department-can-be-reached) | Nothing outside the dean's department can be reached | Dean |
| [TC-DEAN-09](#tc-dean-09-the-deans-screens-work-with-the-keyboard-alone) | The dean's screens work with the keyboard alone | Dean |
| [TC-DEAN-10](#tc-dean-10-every-screen-of-the-dean-fits-360-768-and-1440-pixels-and-passes-the-accessibility-check) | Every screen of the dean fits 360, 768 and 1440 pixels and passes the accessibility check | Dean |
| [TC-DEAN-11](#tc-dean-11-the-states-of-a-dean-with-nothing-to-show-no-department-or-a-read-only-institution) | The states of a dean with nothing to show, no department, or a read-only institution | Dean |

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
- **Already automated:** `tests/feature/OverrideRequestFlowTest.php`,
  `tests/feature/DeanApprovalsPageTest.php`, `tests/database/GradeOverrideRequestServiceTest.php`,
  `tests/unit/OverrideRequestChainTest.php`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Services/Courses/Grades/GradeOverrideRequestService.php`,
  `app/Views/pages/dean/approvals.php`
- **Review:** Draft

### TC-DEAN-05 The dean signs off a section's final grades, and only the dean can reopen the sign-off

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
  - The instructor sees "Signed off by the dean" and has no Reopen button.
  - Reopening needs a reason; afterwards IT101-B is Ready again.
  - The instructor can reopen their own finalization again.
- **Already automated:** `tests/database/GradeSignoffServiceTest.php`,
  `tests/feature/DeanSignoffPageTest.php`, `tests/database/GradeFinalizationServiceTest.php`
- **Playwright:** `tests/e2e/dean.spec.js`
- **Basis:** `app/Services/Courses/Grades/GradeSignoffService.php`,
  `app/Views/pages/dean/signoff.php`
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
