# P2 test cases: the chairperson

5 cases, added in Wave 3 of the QA plan (2026-10-28). Read [README.md](README.md)
first. **All are `Draft`** (see the README). This file was asked for by the QA
decision register (MISS-DOC-01).

`tests/e2e/chairperson.spec.js` holds five browser tests that name no case yet
(sign-in, role switch, an instructor refused, a signed-out visitor, the
instructor regression). When it is next changed, its tests should name these
cases.

**Data:** `chairperson1@mentora.test` chairs the IT sub-department of CBM, whose
program holds IT101-A, IT101-B, IT102-A and IT201-A, and teaches none of them.
`chairperson2@mentora.test` holds the chairperson and instructor roles and
chairs nothing. The override chain the chairperson takes part in is in
[p2-dean.md](p2-dean.md) (TC-DEAN-04, 15, 16, 18).

QA status as in the README; none has been run.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-CHA-01 | A chairperson reaches the courses of the sub-departments they chair, and nothing else | Chairperson | P1 | VCI |
| TC-CHA-02 | What a chairperson changes in a course is recorded | Chairperson | P1 | VCI |
| TC-CHA-03 | A chairperson can reopen a section's grades but not finalize them | Chairperson | P1 | VCI |
| TC-CHA-04 | Monitor Program follows its permission and counts only the program | Chairperson, institutional administrator | P1 | IBR |
| TC-CHA-05 | The chairperson's day: sign-in, role switch, follow-ups and override requests | Chairperson | P1 | IBR, VCI |

### TC-CHA-01 A chairperson reaches the courses of the sub-departments they chair, and nothing else

- **Role:** Chairperson. **Priority:** P1
- **Data:** `chairperson1`; a course of another sub-department; a program added to IT during the test
- **Steps:** open Courses; open IT101-A; open the other sub-department's course by address. Add a new program under IT with a section, and open it. Revoke the assignment (test database) and use the site. As `chairperson2` acting as instructor, open their own section.
- **Expected:** every course of the chaired sub-departments is listed and opens, including a program added later, without touching the assignment; another sub-department's course is not found; a revoked assignment loses access at once; a chair with no assignment reaches nothing; a chairperson who also teaches is judged by the role of the area they are in.
- **Already automated:** `tests/database/ChairpersonAccessServiceTest.php`, `tests/feature/ChairpersonCourseAccessTest.php`, `ChairpersonCoursesPageTest.php`, `CourseMembershipTest::testAChairpersonOnlyReachesTheirOwnSubDepartment`
- **Playwright:** `tests/e2e/chairperson.spec.js` (untagged)
- **Basis:** `ChairpersonAccessService`
- **QA status:** VCI (PS-CHA-01)
- **Review:** Draft

### TC-CHA-02 What a chairperson changes in a course is recorded

- **Role:** Chairperson. **Priority:** P1
- **Steps:** as `chairperson1`, in IT101-A: post an announcement, edit it, grade a submission; open pages without changing anything. Read the audit log (and the dean's Chairperson review, TC-DEAN-26).
- **Expected:** each successful change writes a `course.chairperson_action` entry; reading writes none; announcing outside the program is refused.
- **Already automated:** `ChairpersonCourseAccessTest` (`testAChairpersonsMutationIsAudited`, `testReadingDoesNotCreateAnAuditEntry`, `testAChairpersonCannotAnnounceOutsideTheirProgram`)
- **Playwright:** no
- **Basis:** the chairperson course actions
- **QA status:** VCI (PS-CHA-02)
- **Review:** Draft

### TC-CHA-03 A chairperson can reopen a section's grades but not finalize them

- **Role:** Chairperson. **Priority:** P1
- **Data:** IT101-B ready to finalize, then finalized by `instructor1`
- **Steps:** as `chairperson1`, try to finalize IT101-B; after the instructor finalizes, reopen it with a reason. Use the rest of the gradebook.
- **Expected:** finalizing is refused; reopening works with a reason and the instructor is told; the chairperson otherwise has the instructor's gradebook.
- **Already automated:** `GradeRoutesTest::testTheChairpersonHasTheInstructorsGradebookExceptFinalizing`
- **Playwright:** no
- **Basis:** the grade routes, `GradeFinalizationService`
- **QA status:** VCI (PS-CHA-03)
- **Review:** Draft

### TC-CHA-04 Monitor Program follows its permission and counts only the program

- **Role:** Chairperson, institutional administrator. **Priority:** P1
- **Data:** `chairperson1`; Monitor Program; a section outside IT with students at risk
- **Steps:** open Monitor Program: the snapshot, enrolment, course comparison, at-risk list; filter by term (and an unknown term), by a date range (and an invalid one). Clear `program.monitor` for chairpersons and look again. As an instructor and signed out, open its endpoints. As the institution's administrator, look for it in the menu.
- **Expected:** every figure counts only the chair's program (no outside section, course or student); filtering by term narrows and lists only terms the chair's courses run in; an unknown term or invalid range is ignored, not an error; without the permission the menu entry is gone and the page refused; an instructor is denied every endpoint and a visitor sent to log in; the administrator is not offered it by default.
- **Already automated:** `tests/feature/MonitorProgramRoutesTest.php`, `MonitorProgramPageTest.php`, `tests/database/MonitorProgramServiceTest.php`, `PermissionsTakeEffectTest::testNavigationLeavesOutAMonitorThePersonCannotOpen`
- **Playwright:** `tests/e2e/monitor-program.spec.js` (untagged)
- **Basis:** `MonitorProgramService`; decision 7.4
- **QA status:** IBR (PS-CHA-04)
- **Review:** Draft

### TC-CHA-05 The chairperson's day: sign-in, role switch, follow-ups and override requests

- **Role:** Chairperson. **Priority:** P1
- **Data:** `chairperson2` (chairperson and instructor), `chairperson1`, a follow-up from `dean2`, an override request from `instructor2` waiting for the chair
- **Steps:**
  1. Sign in as `chairperson2`: they land on the chairperson dashboard. Switch to Instructor and back. As an instructor, open a chairperson page; signed out, open one.
  2. As `chairperson1`, clear `followups.manage` for chairpersons. Try to send a follow-up and to pass the dean's down; reply to the dean's and close it.
  3. Open Override requests; endorse one and send one back (TC-DEAN-16).
- **Expected:**
  - Step 1: the dashboard is the chairperson's; the role switch follows (TC-AUTH-10); an instructor is refused and a visitor sent to log in; an ordinary instructor's own pages are unaffected.
  - Step 2: without `followups.manage` the chairperson cannot create or pass down, but can still reply to and close a follow-up they received.
  - Step 3: the request list holds only what waits for them.
- **Already automated:** `tests/feature/ChairpersonDashboardPageTest.php`, `PermissionsTakeEffectTest::testAReceivedFollowUpCanStillBeAnsweredWithoutFollowupsManage`, `GradeOverrideRequestServiceTest::testTheChairpersonsListHoldsWhatWaitsForThemAndNothingElse`
- **Playwright:** `tests/e2e/chairperson.spec.js` (untagged)
- **Basis:** the chairperson area; decisions 7.4, D6
- **QA status:** IBR (PS-CHA-05), VCI (PS-CHA-06)
- **Review:** Draft
