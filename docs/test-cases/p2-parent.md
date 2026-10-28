# P2 test cases: the parent portal

10 cases in the browser, and the PHP tests that stand behind the rest. Read
[README.md](README.md) first. All are `Draft` (see the README).

`tests/e2e/parent.spec.js` holds the cases. The demo data links
**`parent1@mentora.test`** to Juan Dela Cruz (student1, in IT101-A and IT102-A)
and Michael Villanueva (student5, in IT101-A and IT201-A), and
**`parent2@mentora.test`** to nobody (`app/Database/Seeds/DemoParentLinksSeeder.php`).
A parent is read-only: the only things they can save are their own email
switches and a password-reset link.

What these prove, in the order of the agreed definition of done:

1. A parent reaches nothing for a student who is not theirs, by menu, address or
   form, and every write is refused (TC-PRT-03, 04, 08).
2. No removed tab, per-item score, submission, feedback or discussion text
   appears in anything a parent receives (TC-PRT-02; the exhaustive sweep is
   `ParentPrivacyCanaryTest`).
3. The demo data holds every state: two children, a child in two courses, and a
   parent with none (TC-PRT-01, 07).
4. Existing student, instructor and administrator flows are unchanged (TC-PRT-09
   and 10 use them; the whole suite stays green).

| ID | Case | Role |
|---|---|---|
| [TC-PRT-01](#tc-prt-01-a-parent-lands-on-the-dashboard-and-switches-children) | A parent lands on the dashboard and switches children | Parent |
| [TC-PRT-02](#tc-prt-02-a-childs-course-opens-read-only-with-four-tabs) | A child's course opens read-only with four tabs | Parent |
| [TC-PRT-03](#tc-prt-03-a-course-the-child-is-not-in-answers-404) | A course the child is not in answers 404 | Parent |
| [TC-PRT-04](#tc-prt-04-a-student-who-is-not-their-child-answers-404) | A student who is not their child answers 404 | Parent |
| [TC-PRT-05](#tc-prt-05-the-calendar-is-read-only) | The calendar is read-only | Parent |
| [TC-PRT-06](#tc-prt-06-a-parent-chooses-which-child-they-are-emailed-about) | A parent chooses which child they are emailed about | Parent |
| [TC-PRT-07](#tc-prt-07-a-parent-with-no-linked-student-gets-an-explanation) | A parent with no linked student gets an explanation | Parent |
| [TC-PRT-08](#tc-prt-08-other-roles-cannot-open-the-parent-area) | Other roles cannot open the parent area | Student |
| [TC-PRT-09](#tc-prt-09-a-student-sees-which-parents-can-see-their-progress) | A student sees which parents can see their progress | Student |
| [TC-PRT-10](#tc-prt-10-the-institutions-administrator-sees-who-follows-which-students) | The administrator sees who follows which students | Institutional administrator |

Not in the browser (manual, on staging): the email a parent receives, and the
`parents:notify` command running on its schedule (see `docs/notifications.md`
and `docs/server-setup.md`). The notification wording and the rule that no grade
travels in it are tested in `tests/database/ParentNotifierTest.php`.

---

### TC-PRT-01 A parent lands on the dashboard and switches children

- **Role:** Parent. **Priority:** P2
- **Data:** `parent1@mentora.test`
- **Steps:**
  1. Sign in.
  2. Open the "Showing" list and pick the other child.
- **Expected:**
  - The parent lands on `/parent/dashboard` with "Courses this term" and the
    first child's courses (for example Introduction to Computing).
  - The list holds exactly the two linked children.
  - Picking the other child opens that child's dashboard.
- **Already automated:** `tests/feature/ParentRoleRoutesTest.php`,
  `tests/feature/ParentDashboardPageTest.php`
- **Playwright:** `tests/e2e/parent.spec.js`
- **Basis:** `app/Views/pages/parent/dashboard.php`, `partials/child-switcher.php`
- **Review:** Draft

### TC-PRT-02 A child's course opens read-only with four tabs

- **Role:** Parent. **Priority:** P2
- **Data:** `parent1@mentora.test`, the first course card
- **Steps:**
  1. Open a course from the dashboard.
  2. Open the Grades tab.
- **Expected:**
  - The tabs are exactly Overview, Announcements, Assessments and Grades.
  - A "View only" marker is shown; there is no Modules or Discussions tab.
- **Already automated:** `tests/feature/ParentCoursePageTest.php`,
  `tests/feature/ParentCourseTabsTest.php`, `tests/feature/ParentPrivacyCanaryTest.php`
- **Playwright:** `tests/e2e/parent.spec.js`
- **Basis:** `app/Services/Parents/ParentCourseTabs.php`
- **Review:** Draft

### TC-PRT-03 A course the child is not in answers 404

- **Role:** Parent. **Priority:** P2
- **Data:** `parent1@mentora.test`; Computer Programming 1 (IT102-A) is Juan's,
  not Michael's
- **Steps:** Open Michael's address for Juan's IT102-A course directly.
- **Expected:** "Not found" (404), whatever the course tab.
- **Already automated:** `tests/feature/ParentAuthorizationMatrixTest.php`:
  `testACourseTheChildIsNotInIs404OnEveryCourseRoute`
- **Playwright:** `tests/e2e/parent.spec.js`
- **Basis:** `app/Services/Parents/ParentAccessService.php`
- **Review:** Draft

### TC-PRT-04 A student who is not their child answers 404

- **Role:** Parent. **Priority:** P2
- **Data:** `parent1@mentora.test`
- **Steps:** Type `/parent/children/<id>` for ids 1 to 40 except the two children.
- **Expected:** Every one answers 404 (other students, staff, other parents).
- **Already automated:** `tests/feature/ParentAuthorizationMatrixTest.php`:
  `testAChildWhoIsNotTheParentsIs404OnEveryChildRoute`
- **Playwright:** `tests/e2e/parent.spec.js`
- **Basis:** `app/Filters/ParentChildFilter.php`
- **Review:** Draft

### TC-PRT-05 The calendar is read-only

- **Role:** Parent. **Priority:** P2
- **Data:** `parent1@mentora.test`
- **Steps:** Open Calendar from the menu.
- **Expected:** The month shows the child's deadlines and announcements; there
  are no Add buttons and no Reminder legend (a parent cannot add or edit).
- **Already automated:** `tests/feature/ParentCalendarPageTest.php`,
  `tests/database/ParentCalendarEventsTest.php`
- **Playwright:** `tests/e2e/parent.spec.js`
- **Basis:** `resources/js/pages/calendar/calendar.js` (`isReadOnly`)
- **Review:** Draft

### TC-PRT-06 A parent chooses which child they are emailed about

- **Role:** Parent. **Priority:** P2
- **Data:** `parent1@mentora.test`
- **Steps:**
  1. Open Profile, untick "Email me about Michael", press "Save choices".
  2. Reload Profile.
- **Expected:** The box stays unticked. Notifications in the app stay on.
- **Already automated:** `tests/feature/ParentProfilePageTest.php`,
  `tests/database/ParentPreferencesTest.php`
- **Playwright:** `tests/e2e/parent.spec.js`
- **Basis:** `app/Controllers/Parents/ParentProfileController.php`
- **Review:** Draft

### TC-PRT-07 A parent with no linked student gets an explanation

- **Role:** Parent. **Priority:** P2
- **Data:** `parent2@mentora.test`
- **Steps:** Sign in.
- **Expected:** "You are not linked to a student yet", with no error and no data.
- **Already automated:** `tests/feature/ParentRoleRoutesTest.php`:
  `testAParentWithNoChildrenSeesAnExplanation`
- **Playwright:** `tests/e2e/parent.spec.js`
- **Basis:** `app/Views/pages/parent/not-linked.php`
- **Review:** Draft

### TC-PRT-08 Other roles cannot open the parent area

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test`
- **Steps:** Sign in, then type `/parent/dashboard`.
- **Expected:** The student is sent away from the parent area.
- **Already automated:** `tests/feature/ParentAuthorizationMatrixTest.php`:
  `testEveryOtherRoleIsTurnedAwayFromEveryParentRoute`
- **Playwright:** `tests/e2e/parent.spec.js`
- **Basis:** `app/Config/Routes.php` (the `parent` group)
- **Review:** Draft

### TC-PRT-09 A student sees which parents can see their progress

- **Role:** Student. **Priority:** P2
- **Data:** `student5@mentora.test` (linked to Roberto Diaz Cruz)
- **Steps:** Open "My Profile".
- **Expected:** Roberto is listed with the sentence that parents cannot change
  anything.
- **Already automated:** `tests/feature/StudentProfilePageTest.php`
- **Playwright:** `tests/e2e/parent.spec.js`
- **Basis:** `app/Views/pages/student/profile.php`
- **Review:** Draft

### TC-PRT-10 The institution's administrator sees who follows which students

- **Role:** Institutional administrator. **Priority:** P2
- **Data:** `institution.admin@mentora.test`
- **Steps:** Open People, then Parents.
- **Expected:** Roberto has Juan and Michael as linked students; the other parent
  shows "No student linked".
- **Already automated:** `tests/feature/InstitutionParentLinksTest.php`,
  `tests/feature/InstitutionPeopleInviteParentTest.php` (inviting a parent with
  their students)
- **Playwright:** `tests/e2e/parent.spec.js`
- **Basis:** `app/Views/pages/institution/parents.php`
- **Review:** Draft

---

## Wave 2 (added 2026-10-28)

P1 scenarios of the parent portal whose expected result is the code's behavior (**VCI**), expected to pass; not yet run.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-PRT-11 | Every parent page refuses everyone but the parent, and refuses another child or course | Every role | P1 | VCI |
| TC-PRT-12 | The dashboard shows each child's current and past courses, what needs attention, and the next two weeks | Parent | P1 | VCI |
| TC-PRT-13 | Parents are told each event once, emailed only when they chose to be, and never told a score | Parent | P1 | VCI |
| TC-PRT-14 | No private work of the student reaches anything a parent can open | Parent | P1 | VCI |

### TC-PRT-11 Every parent page refuses everyone but the parent, and refuses another child or course

- **Role:** Every role. **Priority:** P1
- **Data:** `parent1` (student1, student5), `parent2`, one account of each other role; the list of parent routes in `ParentAuthorizationMatrixTest`
- **Steps:** as each other role and signed out, open every parent route. As `parent1`, open the routes with student2's id and with a course their child is not in. Send a change to every route. Switch the portal off and open the child routes.
- **Expected:** other roles are turned away; a signed-out visitor goes to the login page; another child or course answers 404; their own child and course are served; only the two account forms (profile and email choices) accept a write; with the portal off no child data is shown on any child route.
- **Already automated:** `tests/feature/ParentAuthorizationMatrixTest.php` (all). Its first test fails whenever a parent route is added without being listed, so keep it current.
- **Playwright:** `tests/e2e/parent.spec.js` (TC-PRT-03, 04, 08)
- **Basis:** the `parentchild` filter
- **QA status:** VCI (PS-PRT-01)
- **Review:** Draft

### TC-PRT-12 The dashboard shows each child's current and past courses, what needs attention, and the next two weeks

- **Role:** Parent. **Priority:** P1
- **Data:** `parent1`; student1 and student5 with: a current course with a published scheme, one with a draft scheme, a dropped and a completed course, an at-risk course, an INC, a past-due assessment not handed in, one handed in, one excused, a final grade from 3 days ago and one from 20 days ago, deadlines in 5 and 20 days
- **Steps:** open the dashboard for each child and switch between them.
- **Expected:** current courses carry the standing the student sees, and no percentage or tag where the scheme is unpublished; dropped, withdrawn and completed courses are past; at-risk is flagged (never for INC or withdrawn); a past-due unsubmitted assessment is missing once, a handed-in or excused one is not, and a past course's misses do not count; a final grade is an attention item for 14 days; deadlines are the next 14 days, soonest first, without scores; a child with no courses gives empty lists; a child who is also staff shows courses but no grade or assessment data; looking never changes the parent's active role.
- **Already automated:** `tests/database/ParentDashboardServiceTest.php` (all)
- **Playwright:** `tests/e2e/parent.spec.js` (TC-PRT-01)
- **Basis:** `ParentDashboardService`
- **QA status:** VCI (PS-PRT-02)
- **Review:** Draft

### TC-PRT-13 Parents are told each event once, emailed only when they chose to be, and never told a score

- **Role:** Parent. **Priority:** P1
- **Data:** student1 linked to `parent1` and a second parent; `parent1` emailed about student1 only; the mail sink
- **Steps:** finalize a grade; mark INC; withdraw the student; make the student at risk; post an announcement; let work go overdue in two courses; run `php spark parents:notify` twice on the same day; reopen and finalize again; switch the portal off and repeat one event.
- **Expected:** both parents are told each event once, in the app; email only where the parent's switch for that child is on; announcements are never emailed; overdue work is one summary per parent per day; running again tells nobody twice; finalizing again after a reopen tells again; no message carries a grade or score; with the portal off nobody is told; a student who left the institution has nobody to tell; a parent of another child hears nothing.
- **Already automated:** `tests/database/ParentNotifierTest.php` (all)
- **Playwright:** no
- **Basis:** `ParentNotifier`, `ParentsNotify` command
- **QA status:** VCI (PS-PRT-03). The at-risk message is sent once per episode and re-armed on recovery (decision AMB-COM-04): that part is PS-PRT-06, Wave 4.
- **Review:** Draft

### TC-PRT-14 No private work of the student reaches anything a parent can open

- **Role:** Parent. **Priority:** P1
- **Data:** student1 with planted private items: a quiz answer, an essay, an assignment file and text, a discussion reply, an announcement reaction, a private feedback comment, each containing a unique word (for example `CANARY-7Q2`)
- **Steps:** as `parent1`, open every page and API of the portal for student1 and download the grade report PDF. Search the responses for the planted words.
- **Expected:** none of the planted words or scores appears on any page, API or PDF; the course offers only its four tabs; the grade report carries no item names or scores; a child who is also staff has no grades shown; a deactivated child stays reachable, a deleted one does not.
- **Already automated:** `tests/feature/ParentPrivacyCanaryTest.php` (all)
- **Playwright:** no
- **Basis:** the parent controllers; decision PLN-09 (privacy tests are P1)
- **QA status:** VCI (PS-PRT-04)
- **Review:** Draft

---

## Wave 3 (added 2026-10-28)

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-PRT-15 | The grade report PDF: only once final, without item scores, and recorded | Parent | P2 | VCI |
| TC-PRT-16 | A future-dated announcement reaches parents on its date, not before | Parent | P2 | REG? |

### TC-PRT-15 The grade report PDF: only once final, without item scores, and recorded

- **Role:** Parent. **Priority:** P2
- **Data:** `parent1`; student1 in IT101-A (finalized) and IT102-A (not)
- **Steps:** download IT102-A's report, then IT101-A's; as student1 download their own; open the audit log; try another child's and another course; switch the portal off.
- **Expected:** a report that is not final is refused with a plain message; the final one is a PDF without item scores or the class mean; the student's own report keeps the item scores; each parent download is audited with the parent as actor; another child or course is 404; with the portal off there is no report; other roles are turned away.
- **Already automated:** `tests/feature/ParentGradeReportTest.php` (all)
- **Playwright:** no
- **Basis:** the parent grade report controller
- **QA status:** VCI (PS-PRT-05)
- **Review:** Draft

### TC-PRT-16 A future-dated announcement reaches parents on its date, not before

- **Role:** Parent. **Priority:** P2
- **Data:** an announcement posted from the calendar in IT101-A dated 3 days ahead; `parent1`
- **Steps:** run `parents:notify`; look at the parent's tab and notifications; move the clock to the date and run it again.
- **Expected:** before the date the parent sees nothing and is told nothing; on the date it appears and they are told once.
- **Already automated:** none
- **Playwright:** no
- **Basis:** decision AMB-COM-01. Suspected: the notifier tells parents at once [audit agent; not run].
- **QA status:** REG? (PS-PRT-07); the other screens are PS-ANN-05 (Wave 4) **Defect confirmed 2026-10-28** by reading the code (`docs/qa/defects-2026-10-28.md`).
- **Review:** Draft

---

## Wave 4 (added 2026-10-28)

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-PRT-17 | A parent cannot be linked to a student of another institution | Institutional administrator | P3 | IBR |

### TC-PRT-17 A parent cannot be linked to a student of another institution

- **Role:** Institutional administrator. **Priority:** P3
- **Steps:** link `parent2` to a student of another institution by email and by student number; invite as a parent an address that belongs to another institution.
- **Expected:** both refused; one account belongs to one institution, so a parent never follows children in two institutions and the notifier needs no case for it.
- **Already automated:** `ParentLinkServiceTest::testAnotherInstitutionsParentsLinksAndNonParentsAreNotFound`
- **Playwright:** no
- **Basis:** decisions D1, AMB-COM-05
- **QA status:** IBR (PS-PRT-08)
- **Review:** Draft
