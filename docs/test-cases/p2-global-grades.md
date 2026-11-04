# P2 test cases: the Grades page (every section an instructor teaches, every course a student takes)

15 cases in the browser, and the PHP and JavaScript tests that stand behind the rest. Read
[README.md](README.md) first. All are `Draft` (see the README).

`tests/e2e/global-grades.spec.js` holds TC-GGR-01 to 03, and `tests/e2e/global-grades-student.spec.js` TC-GGR-04 to 06 (the
student's page, below) and `tests/e2e/global-grades-parent.spec.js` TC-GGR-07 to 09 (the parent's page, for a linked child) and `tests/e2e/global-grades-chairperson.spec.js` TC-GGR-10 to 12 (the chairperson's Program tab, as `chairperson1@mentora.test`) and `tests/e2e/global-grades-dean.spec.js` TC-GGR-13 to 15 (the dean's page as `dean2@mentora.test`, and the badge and entry link as `instructor1@mentora.test`). The instructor cases run as the main instructor
(`instructor1@mentora.test`) on the demo data: **IT101-A** (5 students, work waiting to be graded, and a
manual Class Participation item) and **IT101-B** (nobody enrolled, so its grades are ready to be
finalized). The cases leave the data as they found it: the score is put back, the students and the
category weight that TC-GGR-03 changes are restored, and the section it finalizes is reopened with a reason.

The page is `instructor/grades`, opened from the Grades tile in the Apps menu: a Gradebook tab (a card per
section and the chosen section's grid), To grade (work waiting, oldest first) and At risk. The rules behind
it are tested without a browser (see "Already automated").

| ID | Case | Role |
|---|---|---|
| [TC-GGR-01](#tc-ggr-01-instructor-opens-grades-from-the-apps-menu-switches-sections-and-keeps-a-typed-score) | Instructor opens Grades from the Apps menu, switches sections and keeps a typed score | Instructor |
| [TC-GGR-02](#tc-ggr-02-grade-work-opened-from-to-grade-leads-back-to-the-same-queue) | Grade work opened from To grade leads back to the same queue | Instructor |
| [TC-GGR-03](#tc-ggr-03-finalize-ready-sections-finalizes-the-ready-one-and-names-the-skipped-one) | Finalize ready sections finalizes the ready one and names the skipped one | Instructor |
| [TC-GGR-04](#tc-ggr-04-student-opens-grades-from-the-apps-menu-sees-the-term-and-expands-a-course) | Student opens Grades from the Apps menu, sees the term and expands a course | Student |
| [TC-GGR-05](#tc-ggr-05-student-changes-term-with-the-picker) | Student changes term with the picker | Student |
| [TC-GGR-06](#tc-ggr-06-a-grade-changed-by-the-instructor-shows-changed-once) | A grade changed by the instructor shows Changed once | Student, Instructor |
| [TC-GGR-07](#tc-ggr-07-parent-opens-grades-from-the-apps-menu-sees-the-childs-courses-and-expands-one) | Parent opens Grades from the Apps menu, sees the child's courses and expands one | Parent |
| [TC-GGR-08](#tc-ggr-08-parent-with-two-children-switches-child) | Parent with two children switches child | Parent |
| [TC-GGR-09](#tc-ggr-09-with-the-parent-portal-off-the-parent-sees-the-portal-off-page) | With the parent portal off the parent sees the portal-off page | Parent, Institution administrator |
| [TC-GGR-10](#tc-ggr-10-chairperson-opens-program-sees-the-tracker-and-table-and-opens-a-section-read-only) | Chairperson opens Program, sees the tracker and table, and opens a section read-only | Chairperson |
| [TC-GGR-11](#tc-ggr-11-chairperson-edits-a-manual-score-as-chairperson-and-the-change-is-kept) | Chairperson edits a manual score as chairperson and the change is kept | Chairperson |
| [TC-GGR-12](#tc-ggr-12-chairperson-nudges-an-instructor-and-the-follow-up-exists) | Chairperson nudges an instructor and the follow-up exists | Chairperson |
| [TC-GGR-13](#tc-ggr-13-dean-opens-grades-from-the-apps-menu-picks-a-program-and-opens-a-section-read-only) | Dean opens Grades from the Apps menu, picks a program, and opens a section read-only | Dean |
| [TC-GGR-14](#tc-ggr-14-the-strips-sign-off-link-opens-sign-off) | The strip's Sign-off link opens Sign-off | Dean |
| [TC-GGR-15](#tc-ggr-15-instructor-sees-the-waiting-badge-on-grades-and-all-my-sections-lands-on-that-section) | Instructor sees the waiting badge on Grades and "All my sections" lands on that section | Instructor |

---

### TC-GGR-01 Instructor opens Grades from the Apps menu, switches sections and keeps a typed score

- **Role:** Instructor. **Priority:** P2
- **Data:** instructor1; IT101-A (most work waiting) and IT101-B
- **Steps:**
  1. Sign in and open the Apps menu, then Grades.
  2. Look at which section card is selected.
  3. Type a different score into a Class Participation cell, and at once click the IT101-B card.
  4. Reload the page.
- **Expected:**
  - The page opens at `instructor/grades`; IT101-A, the section with the most work waiting, is selected and
    its gradebook grid is shown.
  - Clicking IT101-B shows that section at once without reloading the page: its card is marked as chosen and
    the action bar names IT101-B.
  - After the reload the typed score is there: a score typed just before switching is saved, not lost.
- **Already automated:** `tests/js/pages/grades-section-switch.test.js`, `tests/js/pages/grades-embed.test.js`
  (the pending score is saved before the switch); `tests/feature/InstructorGradesPageTest.php`;
  `tests/database/InstructorGradesOverviewTest.php`; `tests/database/GlobalGradesQueryBudgetTest.php`
- **Playwright:** `tests/e2e/global-grades.spec.js`
- **Basis:** `app/Controllers/Grades/GradesController.php`, `app/Views/pages/grades/`,
  `resources/js/pages/grades/`
- **Review:** Draft

### TC-GGR-02 Grade work opened from To grade leads back to the same queue

- **Role:** Instructor. **Priority:** P2
- **Data:** instructor1; IT101-A has a quiz, two assignments waiting
- **Steps:**
  1. On the Grades page, open the To grade tab and set Type to Quiz.
  2. Press Grade → on the quiz.
  3. Pick another student in the list.
  4. Press "← Back to To grade".
- **Expected:**
  - The queue narrows to the quiz.
  - Grade work opens for that quiz, and the address keeps the way back while you move between students.
  - The back link reads "← Back to To grade" and lands on the To grade tab with Type still on Quiz.
  - A `return` address that is not a Grades page of this site is ignored (the back link behaves as before).
- **Already automated:** `tests/js/pages/grades-return.test.js`; `tests/feature/InstructorGradesPageTest.php`
- **Playwright:** `tests/e2e/global-grades.spec.js`
- **Basis:** `app/Views/pages/grades/partials/instructor/to-grade.php`, `resources/js/shared/grades-return.js`
- **Review:** Draft

### TC-GGR-03 Finalize ready sections finalizes the ready one and names the skipped one

- **Role:** Instructor. **Priority:** P2
- **Data:** instructor1; IT101-B is ready. To have a second ready card that is then refused, set up IT101-A:
  its five students are dropped (so no work waits). After the page is open (step 1), IT101-A is set to completed (closed): it is still offered, and is refused when the instructor confirms. Restore
  both afterwards. (The server reads the cards again when Finalize is confirmed, so a section whose weights
  break after loading is left out rather than named; a closed section is named.)
- **Steps:**
  1. Open the Grades page: the summary reads "Ready to finalize 2 / 2" and the button "Finalize ready
     sections" shows 2. Now set IT101-A to completed.
  2. Press it: the dialog names IT101-A and IT101-B. Press Finalize.
  3. Close the dialog with Done.
  4. Reopen IT101-B from its course Grades tab with a reason.
- **Expected:**
  - The dialog then says "Finalized 1 section." and "Skipped 1: IT101-A", with the reason "This section is
    closed".
  - After Done the IT101-B card reads "Finalized" and the button is gone.
  - Reopening with a reason brings IT101-B back to ready.
- **Already automated:** `tests/feature/GradesBulkTest.php` (including a card that stopped being ready);
  `tests/js/pages/grades-finalize-ready.test.js`; `tests/database/GradeFinalizationServiceTest.php`
- **Playwright:** `tests/e2e/global-grades.spec.js`
- **Basis:** `app/Services/Grades/ReadySectionFinalizer.php`, `app/Controllers/Grades/GradesBulkController.php`,
  `resources/js/pages/grades/finalize-ready.js`
- **Review:** Draft

### TC-GGR-04 Student opens Grades from the Apps menu, sees the term and expands a course

- **Role:** Student. **Priority:** P2
- **Data:** student1; IT101-A (published scheme, 90.00%, In progress) and IT102-A (nothing graded yet). The demo
  scheme has no grading periods, so the case gives IT101-A's scheme two (Midterm, Final) for its length and removes
  them afterwards.
- **Steps:**
  1. Sign in, open the Apps menu, then Grades.
  2. Read the term line, the summary cards and the two rows.
  3. Press the arrow on IT101-A: its breakdown opens under it.
  4. Press "Show categories", then "Hide categories".
  5. Close the row and open it again.
  6. Press "Open course grades →".
- **Expected:**
  - The line reads "1st Semester · 2 courses · 6 units · ends Dec 12"; the cards are GWA (a dash: nothing is
    finalized), Units, Passed, Failed and INC.
  - IT101-A reads 90.00% with "In progress"; IT102-A reads a dash with "In progress".
  - The breakdown shows a column per period with its percentage, "Passing is {p}%" and the link; the categories
    show and hide behind their toggle, and the row opens again without losing them.
  - The link opens the course's page.
- **Already automated:** `tests/feature/StudentGradesPageTest.php`; `tests/feature/StudentGradesBreakdownTest.php`
  (including a course the student is not in: 404); `tests/database/StudentGradesOverviewTest.php`;
  `tests/database/GlobalGradesQueryBudgetTest.php`; `tests/js/pages/grades-student-rows.test.js`
- **Playwright:** `tests/e2e/global-grades-student.spec.js`
- **Basis:** `app/Controllers/Grades/GradesController.php`, `app/Controllers/Grades/StudentCourseGradesController.php`,
  `app/Services/Grades/StudentGradesOverview.php`, `app/Views/pages/grades/partials/student/`,
  `resources/js/pages/grades/student-rows.js`
- **Review:** Draft

### TC-GGR-05 Student changes term with the picker

- **Role:** Student. **Priority:** P2
- **Data:** student1. The demo gives the student one term, so the case adds a finished one ("Summer", 2025-2026, ended
  Jun 1) with CS101-A (no published scheme), and removes it afterwards.
- **Steps:**
  1. Open the Grades page.
  2. Choose "Summer (2025-2026)" in "Change term".
- **Expected:**
  - The address gains `?term=` and the line reads "Summer · 1 course · ... · ended Jun 1".
  - Only CS101-A is listed, with "Not published yet" and no arrow to open it; IT101-A is not listed.
- **Already automated:** `tests/database/StudentGradesOverviewTest.php` (each term lists only its own courses, the
  default term); `tests/feature/StudentGradesPageTest.php`
- **Playwright:** `tests/e2e/global-grades-student.spec.js`
- **Basis:** `app/Services/Grades/StudentGradesOverview.php`, `app/Views/pages/grades/index.php`
- **Review:** Draft

### TC-GGR-06 A grade changed by the instructor shows Changed once

- **Role:** Student, Instructor. **Priority:** P2
- **Data:** student1 and instructor1; student1 has a graded Class Participation score in IT101-A. The score is put
  back at the end.
- **Steps:**
  1. As the student, open the Grades page (no badge).
  2. As the instructor, change student1's Participation score in IT101-A's gradebook.
  3. As the student, reload the Grades page, then reload it again.
- **Expected:**
  - After the change, IT101-A carries a "Changed" badge once.
  - On the next reload the badge is gone.
- **Already automated:** `tests/database/GradesSeenTest.php`; `tests/feature/StudentGradesPageTest.php` (also: viewing
  as someone does not clear it)
- **Playwright:** `tests/e2e/global-grades-student.spec.js`
- **Basis:** `app/Services/Grades/GradesSeen.php`, `app/Models/Grades/GradesSeenModel.php`
- **Review:** Draft

### TC-GGR-07 Parent opens Grades from the Apps menu, sees the child's courses and expands one

- **Role:** Parent. **Priority:** P2
- **Data:** parent1 (follows student1 and student5); both children are in IT101-A, which has a published scheme.
- **Steps:**
  1. Sign in, open the Apps menu, then Grades.
  2. Read the description, the term line, the summary and the IT101-A row.
  3. Press the arrow on IT101-A: its breakdown opens under it.
  4. Press "Open course grades →".
- **Expected:**
  - The page opens at `parent/children/{child}/grades` for the first child; it reads "Your child's grades in
    every course.", with the term line and the summary cards.
  - The breakdown shows the course's periods or categories and "Passing is {p}%", and lists no assessment, item or
    manual score.
  - The link opens the parent's view of the course (read-only, "View only").
- **Already automated:** `tests/feature/ParentGradesPageTest.php`; `tests/feature/ParentGradesBreakdownTest.php`;
  `tests/feature/ParentAuthorizationMatrixTest.php` (another child: 404); `tests/database/GlobalGradesQueryBudgetTest.php`
- **Playwright:** `tests/e2e/global-grades-parent.spec.js`
- **Basis:** `app/Controllers/Grades/GradesController.php`, `app/Controllers/Grades/ParentCourseGradesController.php`,
  `app/Services/Grades/CourseBreakdownFragment.php`, `app/Views/pages/grades/`
- **Review:** Draft

### TC-GGR-08 Parent with two children switches child

- **Role:** Parent. **Priority:** P2
- **Data:** parent1 follows two children.
- **Steps:**
  1. Open `parent/grades`.
  2. In "Showing", choose the other child.
- **Expected:**
  - "Showing" lists both children, with the one shown selected.
  - Choosing the other opens the same page for that child (the address changes to the other child's id), with its
    own term line and rows.
- **Already automated:** `tests/feature/ParentGradesPageTest.php` (the switcher; one child shows none)
- **Playwright:** `tests/e2e/global-grades-parent.spec.js`
- **Basis:** `app/Views/pages/parent/partials/child-switcher.php`, `app/Views/pages/grades/index.php`
- **Review:** Draft

### TC-GGR-09 With the parent portal off the parent sees the portal-off page

- **Role:** Parent, Institution administrator. **Priority:** P2
- **Data:** parent1. The institution administrator switches the parent portal off in Institution settings, Parent
  portal, and the case turns it back on at the end.
- **Steps:**
  1. As the administrator, set the parent portal to Off and save.
  2. As the parent, open `parent/grades`.
  3. As the administrator, set it back to On; the parent opens `parent/grades` again.
- **Expected:**
  - The parent sees "The parent portal is switched off" and no grade rows.
  - After it is on again, the page opens the child's grades.
- **Already automated:** `tests/feature/ParentGradesPageTest.php` (portal off and not linked use the existing
  pages); `tests/feature/ParentGradesBreakdownTest.php` (the breakdown answers 403 JSON)
- **Playwright:** `tests/e2e/global-grades-parent.spec.js`
- **Basis:** `app/Controllers/Grades/GradesController.php`, `app/Controllers/Parents/ParentCalendarController.php`
- **Review:** Draft

### TC-GGR-10 Chairperson opens Program, sees the tracker and table, and opens a section read-only

- **Role:** Chairperson. **Priority:** P2
- **Data:** chairperson1, whose sub-department offers IT101-A (graded by hand, work waiting), IT101-B, IT102-A and IT201-A.
- **Steps:**
  1. Open `chairperson/grades`, tab Program.
  2. Read the tracker and the table; click the IT101-A row.
- **Expected:**
  - The page says "Your sections and your program's."; the tracker reads "Finalized {f} of {n} · {r} ready · {b} blocked".
  - The table has the columns Section, Instructor, Average, At risk, Waiting, Status.
  - The row opens IT101-A's grid below, marked Read-only with no score fields, with "Edit as chairperson" and
    "Open in course →"; there is no Finalize control anywhere.
- **Already automated:** `tests/feature/ChairpersonGradesPageTest.php`; `tests/database/ChairpersonProgramOverviewTest.php`;
  `tests/js/pages/grades-program.test.js`; `tests/database/GlobalGradesQueryBudgetTest.php`
- **Playwright:** `tests/e2e/global-grades-chairperson.spec.js`
- **Basis:** `app/Controllers/Grades/GradesController.php`, `app/Services/Grades/ChairpersonProgramOverview.php`,
  `app/Views/pages/grades/partials/chairperson/program.php`
- **Review:** Draft

### TC-GGR-11 Chairperson edits a manual score as chairperson and the change is kept

- **Role:** Chairperson. **Priority:** P2
- **Data:** chairperson1, IT101-A. The case puts the score back at the end.
- **Steps:**
  1. On Program, open IT101-A and choose "Edit as chairperson".
  2. Read the confirmation and choose Continue.
  3. Change a Class Participation score and choose Done.
  4. Reload, open IT101-A and edit again; read the score. Put the original score back and choose Done.
- **Expected:**
  - The confirmation says "You're editing IT101-A as chairperson. Every change is recorded in the audit log and the
    instructor can see it." with Continue and Cancel.
  - After Continue the grid is editable and Done shows; Done returns it to read-only.
  - After the reload the changed score is shown; at the end the original is shown again.
- **Already automated:** `tests/feature/ChairpersonGradesPageTest.php`; `tests/js/pages/grades-program.test.js`
  (confirm, Done, switching rows returns to read-only and saves a pending score to its own section)
- **Playwright:** `tests/e2e/global-grades-chairperson.spec.js`
- **Basis:** `resources/js/pages/grades/program.js`, `app/Controllers/Grades/GradesBulkController.php`
- **Review:** Draft

### TC-GGR-12 Chairperson nudges an instructor and the follow-up exists

- **Role:** Chairperson. **Priority:** P2
- **Data:** chairperson1, IT101-A (work waiting to be graded). The case deletes the follow-up it creates.
- **Steps:**
  1. On Program, open IT101-A and choose Nudge.
  2. Read the subject and choose Send.
  3. Open `chairperson/follow-ups`, "Sent by you".
- **Expected:**
  - The dialog's subject reads "IT101-A: {n} submissions waiting to be graded, oldest {d} days" and the recipient is
    the section's instructor.
  - A toast confirms; the chairperson stays on the page.
  - The follow-up is listed under "Sent by you".
- **Already automated:** `tests/feature/ChairpersonNudgeTest.php` (a recipient who does not teach the section is refused);
  `tests/js/pages/grades-nudge.test.js`
- **Playwright:** `tests/e2e/global-grades-chairperson.spec.js`
- **Basis:** `resources/js/pages/grades/nudge.js`, `app/Services/Courses/FollowUpService.php`
- **Review:** Draft

### TC-GGR-13 Dean opens Grades from the Apps menu, picks a program, and opens a section read-only

- **Role:** Dean. **Priority:** P2
- **Data:** dean2 (College of Business and Management; IT101-A is in the BSIT program). Nothing is changed.
- **Steps:**
  1. Open the Apps menu and choose Grades.
  2. Read the strip and the table; open the program picker.
  3. Choose "Bachelor of Science in Information Technology", then "All programs" again.
  4. Choose the IT101-A row.
- **Expected:**
  - The page says "Every section in your department."; the strip reads "Finalized {f} of {n} · Signed off {s} ·
    Override requests waiting: {o}"; the table has a Signed off column; "Download department" is offered.
  - The picker's first option is "All programs".
  - Choosing a program keeps the strip; choosing the IT101-A row shows its grid read-only, with no score fields, no
    "Edit as chairperson" and no Finalize.
- **Already automated:** `tests/feature/DeanGradesPageTest.php`, `tests/feature/DeanDownloadDepartmentTest.php`,
  `tests/database/DeanDepartmentOverviewTest.php`
- **Playwright:** `tests/e2e/global-grades-dean.spec.js`
- **Basis:** `app/Services/Grades/DeanDepartmentOverview.php`, `app/Views/pages/grades/index.php`
- **Review:** Draft

### TC-GGR-14 The strip's Sign-off link opens Sign-off

- **Role:** Dean. **Priority:** P2
- **Data:** dean2. Nothing is changed.
- **Steps:**
  1. On the dean's Grades page, choose "Signed off {s}" in the strip.
- **Expected:**
  - The Sign-off page opens (`dean/signoff`). Nothing is signed off from the Grades page itself.
- **Already automated:** `tests/feature/DeanGradesPageTest.php` (the links appear only with the permission)
- **Playwright:** `tests/e2e/global-grades-dean.spec.js`
- **Basis:** `app/Views/pages/grades/partials/chairperson/program.php`
- **Review:** Draft

### TC-GGR-15 Instructor sees the waiting badge on Grades and "All my sections" lands on that section

- **Role:** Instructor. **Priority:** P2
- **Data:** instructor1, IT101-A (work waiting to be graded). The badge is cached for 60 seconds per user, so the case
  checks that it is drawn after a fresh sign-in and never a changed count. Nothing is changed.
- **Steps:**
  1. Sign in and open the Apps menu.
  2. Open IT101-A and its Grades tab; choose "All my sections →".
- **Expected:**
  - The Grades tile carries a pill with the count, spoken as "{n} waiting to grade".
  - The link opens `instructor/grades` with IT101-A selected and its grid shown.
- **Already automated:** `tests/database/GradesBadgeTest.php`, `tests/feature/GradesBadgeTileTest.php` (no pill at 0,
  "99+" from 100, no second query within 60 seconds), `tests/feature/GradesEntryLinksTest.php`
- **Playwright:** `tests/e2e/global-grades-dean.spec.js`
- **Basis:** `app/Services/Grades/GradesBadge.php`, `app/Views/components/app-tile.php`,
  `app/Services/Courses/GradeService.php`
- **Review:** Draft
