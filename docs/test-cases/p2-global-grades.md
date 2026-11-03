# P2 test cases: the Grades page (every section an instructor teaches, every course a student takes)

6 cases in the browser, and the PHP and JavaScript tests that stand behind the rest. Read
[README.md](README.md) first. All are `Draft` (see the README).

`tests/e2e/global-grades.spec.js` holds TC-GGR-01 to 03, and `tests/e2e/global-grades-student.spec.js` TC-GGR-04 to 06 (the
student's page, below). The instructor cases run as the main instructor
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
