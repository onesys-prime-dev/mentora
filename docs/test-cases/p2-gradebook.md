# P2 test cases: the gradebook

5 cases in the browser, and the PHP tests that stand behind the rest. Read
[README.md](README.md) first. All are `Draft` (see the README).

`tests/e2e/gradebook.spec.js` holds the cases. They run on the demo course
**IT101-A** as the main instructor (`instructor1@mentora.test`), and two of them
look at the same course as `student1@mentora.test`. The grading scheme is
published, and Lab 1 is handed in and not graded yet, so the grades cannot be
finalized (TC-GBK-04 depends on that). The cases leave the data as they found
it: the Incomplete is taken off and the curve is removed again.

What these cover is the whole gradebook as the grade work plan set it out:
running and final grades, the Passed / Failed / Incomplete (INC) / Withdrawn
standings, finalizing and reopening, manual items and Excused, grading on a
curve, the reports and the hand-off to the student information system. The
rules themselves are tested without a browser (see "Already automated" below).

| ID | Case | Role |
|---|---|---|
| [TC-GBK-01](#tc-gbk-01-instructor-searches-filters-and-sorts-the-gradebook) | Instructor searches, filters and sorts the gradebook | Instructor |
| [TC-GBK-02](#tc-gbk-02-instructor-marks-a-grade-incomplete-and-takes-it-off) | Instructor marks a grade Incomplete and takes it off | Instructor, student |
| [TC-GBK-03](#tc-gbk-03-instructor-previews-applies-and-removes-a-curve) | Instructor previews, applies and removes a curve | Instructor, student |
| [TC-GBK-04](#tc-gbk-04-grades-cannot-be-finalized-while-work-waits-to-be-graded) | Grades cannot be finalized while work waits to be graded | Instructor |
| [TC-GBK-05](#tc-gbk-05-instructor-downloads-the-class-gradebook-and-the-class-summary) | Instructor downloads the class gradebook and the class summary | Instructor |

---

### TC-GBK-01 Instructor searches, filters and sorts the gradebook

- **Role:** Instructor. **Priority:** P2
- **Data:** IT101-A, Grades tab, the Gradebook
- **Steps:**
  1. Open the Gradebook from the Grades tab.
  2. Type a student's name in Search; then a name nobody has; then clear it.
  3. Set Show to "Needs a look".
  4. Set Sort by to "Grade, highest first".
- **Expected:**
  - A strip above the table gives the class average and median, the highest and
    lowest, who is at or above the passing mark, and how many are not graded yet.
  - The count under the controls reads "N students", then "Showing 1 of N".
  - A search with no match shows "No one matches" instead of an empty table.
  - "Needs a look" lists only students who are At risk or Failed.
  - Sorted by grade, the highest comes first and a student with no grade yet last.
- **Already automated:** `tests/js/pages/roster-view.test.js`;
  `tests/database/GradingSchemeServiceTest.php`: `testTheGradebookGridShowsTheClassFiguresControlsAndStandingBadges`
- **Playwright:** `tests/e2e/gradebook.spec.js`
- **Basis:** `app/Views/pages/courses/features/grades/roster.php`,
  `resources/js/pages/courses/features/grades/roster/roster-view.js`
- **Review:** Draft

### TC-GBK-02 Instructor marks a grade Incomplete and takes it off

- **Role:** Instructor, then Student. **Priority:** P2
- **Data:** IT101-A, Dela Cruz, Juan (`student1`), 90% so far
- **Steps:**
  1. In the Gradebook, click the standing chip of Dela Cruz, Juan.
  2. Press "Mark Incomplete" with no reason; then give a reason and a finish-by
     date, and save.
  3. As the student, open the Grades tab.
  4. As the instructor, open the chip again and press "Remove INC".
- **Expected:**
  - A reason is required: the dialog says so and keeps what was typed.
  - The row reads INC with "Finish by Dec 20, 2026"; the grade stays 90%.
  - The student's Standing card reads "Incomplete (INC)" with the reason.
  - After "Remove INC" the standing is On track again.
- **Already automated:** `tests/database/GradingEngineServiceTest.php`: the Incomplete and Withdrawn cases;
  `tests/database/SisGradeExporterTest.php`: `testAPreviewSaysWhoIsLeftOutAndWhy`
- **Playwright:** `tests/e2e/gradebook.spec.js`
- **Basis:** `app/Services/Courses/Grades/GradingEngineService.php` (`markIncomplete`, `clearIncomplete`)
- **Review:** Draft

### TC-GBK-03 Instructor previews, applies and removes a curve

- **Role:** Instructor, then Student. **Priority:** P2
- **Data:** IT101-A; the final grade of student1 is 90%
- **Steps:**
  1. Open Curve on the Grades tab. Choose the final grade, "Add points for
     everyone", 2 points, and press Preview.
  2. Change the points to 3: the Apply button goes away. Set it back to 2 and
     preview again; then press Apply.
  3. As student1, open the Grades tab.
  4. As the instructor, open Curve, and press Remove on the curve in force.
  5. As the student, reload the Grades tab.
- **Expected:**
  - The preview says how many go up and shows the class average before and
    after; nothing is saved yet.
  - A changed form cannot be applied as it was previewed.
  - The student sees 92% and "adjusted by your instructor".
  - After Remove the student sees 90% again and no note.
  - No score the instructor entered was ever changed.
- **Already automated:** `tests/unit/GradeAdjustmentMathTest.php`,
  `tests/database/GradeAdjustmentServiceTest.php`, `tests/js/pages/curve-dialog.test.js`,
  `tests/feature/GradeReportRoutesTest.php`, `tests/feature/GradeRoutesTest.php`: `testTheCurveRoutesPreviewApplyAndRemoveOverHttp`
- **Playwright:** `tests/e2e/gradebook.spec.js`
- **Basis:** `app/Services/Courses/Grades/GradeAdjustmentService.php`
- **Review:** Draft

### TC-GBK-04 Grades cannot be finalized while work waits to be graded

- **Role:** Instructor. **Priority:** P2
- **Data:** IT101-A; Lab 1 is handed in and not graded
- **Steps:** press "Finalize grades" on the Grades tab.
- **Expected:**
  - The checklist says "Before you can finalize" and names the work waiting to
    be graded.
  - There is no "Finalize grades" button in the dialog.
  - Closing it leaves the gradebook unlocked: no "Grades are final" banner.
  - With nothing in the way, finalizing freezes each final grade (work never
    done counts as 0), locks scores, the scheme and imports, and Reopen needs a
    reason (covered by the PHP tests).
- **Already automated:** `tests/database/GradeFinalizationServiceTest.php`;
  `tests/feature/GradeRoutesTest.php`: `testFinalizingOverHttpLocksTheGradebookUntilItIsReopened`
- **Playwright:** `tests/e2e/gradebook.spec.js`
- **Basis:** `app/Services/Courses/Grades/GradeFinalizationService.php`
- **Review:** Draft

### TC-GBK-05 Instructor downloads the class gradebook and the class summary

- **Role:** Instructor. **Priority:** P2
- **Data:** IT101-A, Grades tab, the Reports menu
- **Steps:**
  1. Open Reports and choose the class gradebook as Data (CSV).
  2. Open Reports and choose the class summary print view.
- **Expected:**
  - A file named `gradebook-data-IT101-A-<date>.csv` is downloaded: the data for
    other software, with a `student_number` column, plain numbers and the students
    in it. (The Excel choice, "Report", is the formatted one to read and print, with
    notes in words and a Summary sheet; the offline grade sheet is a third thing,
    for entering manual scores and importing them back.)
  - The class summary opens in its own tab with its figures, the students and a
    "Print or save as PDF" button.
  - Both exports are in the audit log (`grades.report_exported`).
- **Already automated:** `tests/database/GradeReportServiceTest.php`,
  `tests/feature/GradeReportRoutesTest.php`, `tests/js/pages/report-menu.test.js`
- **Playwright:** `tests/e2e/gradebook.spec.js`
- **Basis:** `app/Services/Courses/Grades/GradeReportService.php`
- **Review:** Draft

---

## What the PHP tests settle

These rules have no browser case of their own; each is pinned down by tests
that run on every change.

| Rule | Tests |
|---|---|
| A Draft or Archived assessment is not part of the grade; changing the scheme never freezes a student's grade | `GradeItemSyncServiceTest`, `GradebookSyncServiceTest`, `GradingSchemeServiceTest` |
| Editing a quiz's questions keeps scores, maximums and the gradebook in step | `QuestionServiceTest` |
| Every change that can move a grade is in the audit log | `GradingSchemeServiceTest` (audit cases) |
| Names, maximums, restore, reorder, Excused | `GradingSchemeServiceTest`, `GradeFinalizationServiceTest` |
| Only finalized grades go to the SIS; the preview says who is left out and why | `SisGradeExporterTest`, `SisGradePayloadTest`, `InstitutionIntegrationsTest` |
| Who can reach which grade route, other sections' ids, a closed section | `GradeRoutesTest`, `CourseMembershipTest`, `ClosedOfferingTest`, `ReadOnlyModeTest` |
| How grades are written and summarized | `GradeFormattingTest` |
