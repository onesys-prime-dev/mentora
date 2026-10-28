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

---

## Wave 1 (added 2026-10-28)

From the QA plan's Wave 1. The expected results come from `docs/qa/decision-register.md`, so these two are **expected to fail until the defect is fixed** (QA status **REG**: the defect was seen in the code, not yet run).

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-GBK-06 | A published scheme's weights cannot be edited away from 100% | Instructor | P1 | REG |
| TC-GBK-07 | A signed-off gradebook names who signed it off | Instructor | P2 | REG |

### TC-GBK-06 A published scheme's weights cannot be edited away from 100%

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`, IT101-A. Its grading scheme is published with Quizzes 50% and Assignments 50%.
- **Steps:**
  1. Open Grades, then the grading scheme. Change Quizzes from 50 to 60 and save (the total becomes 110%).
  2. Change Quizzes to 40 and save (90%).
  3. Change Quizzes to 50.01 and to 49.99 (100.01% and 99.99%).
  4. Change Quizzes to 60 and Assignments to 40 in one go, and save each order (Quizzes first, then Assignments).
  5. Open the gradebook and read student1's final grade before and after each step.
- **Expected:**
  - Steps 1–3: each save is refused with "Category weights add up to 110% — they must total 100%." (with the actual total). The weights stay as they were, and the scheme stays published.
  - Step 4: a change that ends at exactly 100% is accepted. The way the form saves two categories must not leave the scheme at an invalid total in between: if it saves one at a time, the instructor needs a way to change both together (record how).
  - Step 5: no grade changes after a refused edit. The engine never silently rescales weights that do not total 100%.
  - A scheme that uses periods follows the same rule for the period weights ("Period weights add up to …").
- **Already automated:** the publish check: `GradingSchemeService::publishProblem` tests. Editing a published scheme has none.
- **Playwright:** planned
- **Basis:** decision AMB-ASG-02. Today `GradingSchemeService::updateCategory` does not re-check the total on a published scheme [read in the code; not run].
- **QA status:** REG (PS-GBK-04)
- **Review:** Draft. Expected result set by AMB-ASG-02.

### TC-GBK-07 A signed-off gradebook names who signed it off

- **Role:** Instructor. **Priority:** P2
- **Data:** two sections whose grades are finalized and signed off. One was signed off by the dean (`dean2`, CBM). The other is in a department with no eligible dean, signed off by the institution's administrator (see TC-DEAN-13 for the setup).
- **Steps:**
  1. As each section's instructor, open Grades.
  2. Press Reopen on the finalization (or send the reopen request directly).
- **Expected:**
  - The tag above the gradebook reads "Signed off by Diana Mendoza" (`dean2`) in the first section and "Signed off by Ines Administrador" (the administrator) in the second (name format to confirm). Neither says "the dean" when the dean did not sign.
  - The detail line under it shows the same name.
  - Reopening the finalization is refused while the sign-off stands. The message names the person who signed off and says the sign-off must be reopened first (wording to confirm). It does not say "Only the dean can reopen them" when an administrator signed off.
- **Already automated:** `tests/database/GradeSignoffServiceTest.php`, `tests/database/AdministratorSignsOffTest.php` (the rule, not the wording)
- **Playwright:** planned
- **Basis:** decision AMB-ASG-04 (register Part I3: the wording is "Signed off by <signer's name>"). Today the toolbar tag always says "Signed off by the dean" (`gradebook-toolbar.php` line 58; the detail at line 164 names the signer), and the refusal is `GradeFinalizationService::SIGNED_OFF_MESSAGE`, "The dean has signed off these grades. Only the dean can reopen them." [read in the code; not run].
- **QA status:** REG (PS-FIN-04)
- **Review:** Draft. Expected result set by AMB-ASG-04.
