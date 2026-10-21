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
