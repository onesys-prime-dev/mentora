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

---

## Wave 2 (added 2026-10-28)

P1 scenarios whose expected result is the code's behavior (**VCI**) or a decision (**IBR**), expected to pass; not yet run. A student's view of an unpublished scheme (PS-GBK-10) is TC-GRD-03.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-GBK-08 | The final grade is the weighted average of what has been graded, and the band follows the scale | Instructor | P1 | VCI |
| TC-GBK-09 | A scheme is published only when its weights total 100%, and nothing that moves a grade is accepted while finalized | Instructor | P1 | VCI |
| TC-GBK-10 | Manual scores, excused work and Incomplete, before and after finalizing | Instructor | P1 | VCI |
| TC-GBK-11 | The offline grade sheet comes back in only as it went out | Instructor | P1 | VCI |
| TC-GBK-12 | What stops finalizing, what finalizing freezes, and who may reopen | Instructor, chairperson, dean | P1 | VCI |

### TC-GBK-08 The final grade is the weighted average of what has been graded, and the band follows the scale

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`, IT101-B with three students enrolled for the test; scheme: Quizzes 50%, Assignments 50%, the default scale (1.00–5.00, pass 75); two quizzes and two assignments, each item worth the same points
- **Steps and expected (table-driven; enter the scores as manual scores or by grading, then read each student's final grade):**

  | Student | Quizzes | Assignments | Final grade |
  |---|---|---|---|
  | A | 80, 90 | 70, (not graded) | Quizzes 85, Assignments 70 → **77.50**, Passed |
  | B | 80, (marked missing, score 50 on it) | 60, 60 | the missing item is left out: 80 and 60 → **70.00**, Failed |
  | C | (nothing graded) | (nothing graded) | **Incomplete**, no band |

- **Then:** set the weights to 60 / 30 on a draft copy and preview (the engine normalises to 100); give a category 0% (it does not count); add an item with no category (it does not count); turn periods on with Prelim 40 / Finals 60 (each period is averaged first, then weighted; an empty period is left out); enter scores giving 96.99 and 97.00, 74.99 and 75.00.
- **Expected (also):** percentages round to two decimals; each boundary falls in the band whose range holds it (97.00 in the higher band, 96.99 below; 75.00 Passed, 74.99 Failed); students who dropped are not graded; an override replaces the calculated grade. Recalculating twice keeps one record per student.
- **Already automated:** `tests/database/GradingEngineServiceTest.php` (all)
- **Playwright:** `tests/e2e/gradebook.spec.js`
- **Basis:** `GradingEngineService`; decision AMB-ASG-11 (register Part I2: the shipped scale is the default under test)
- **QA status:** VCI (PS-GBK-01, PS-GBK-02). The default scale and pass mark are the code's, not a confirmed institutional policy.
- **Review:** Draft

### TC-GBK-09 A scheme is published only when its weights total 100%, and nothing that moves a grade is accepted while finalized

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`, IT101-B
- **Steps:**
  1. Open Grades for the first time: the scheme is a copy of the default. Edit it and check another section's scheme and the template.
  2. Remove every category and publish. Set 60 / 30 and publish. Turn periods on with no periods and publish. Set a passing mark of −1 and 101.
  3. Make it valid and publish.
  4. Finalize the grades. Try to change a weight, a category, the passing mark, the scale, a manual score, a curve, and to import a grade sheet.
- **Expected:**
  - Step 1: the first use copies the default once; editing the copy never touches the template.
  - Step 2: each is refused with its reason ("Category weights add up to 90% — they must total 100%.", "Period weights add up to …", "The passing mark must be between 0 and 100.").
  - Step 3: publishing calculates everyone's grade.
  - Step 4: each is refused: "The grades are finalized. Reopen them to make changes."
  - A weight change on a published scheme is TC-GBK-06 (expected to fail today).
- **Already automated:** `tests/database/GradingSchemeServiceTest.php`; `GradeFinalizationServiceTest::testEverythingThatCouldMoveAGradeIsRefusedWhileFinalized`
- **Playwright:** `tests/e2e/gradebook.spec.js`
- **Basis:** `GradingSchemeService::publishProblem`, `GradeFinalizationService::lockRefusal`
- **QA status:** VCI (PS-GBK-03)
- **Review:** Draft

### TC-GBK-10 Manual scores, excused work and Incomplete, before and after finalizing

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`, IT101-A gradebook, a manual item of 20 points
- **Steps:** enter −1, 0, 20, 21 and an empty value; excuse a student; mark a student Incomplete without a reason, with a reason and no date, with a date `2026-13-40`, then validly; finalize; then set an override and an Incomplete after finalizing; take the Incomplete off.
- **Expected:** a score is accepted from 0 to the maximum and cleared by an empty value; outside that it is refused; excused work is left out of the grade; Incomplete needs a reason and a real `Y-m-d` date; after finalizing, an override and Incomplete are still allowed; an Incomplete stays INC when the grades are finalized.
- **Already automated:** `GradeFinalizationServiceTest::testAFinalizedGradeCanStillBeOverriddenAndIncompleteStillSet`, `testAnIncompleteStaysIncompleteWhenTheGradesAreFinalized`; `GradebookSyncServiceTest::testAManualScoreOverwritesAndNullClearsIt`
- **Playwright:** `tests/e2e/gradebook.spec.js` (TC-GBK-02)
- **Basis:** `GradingSchemeService::updateManualScore`, `GradeFinalizationService`
- **QA status:** VCI (PS-GBK-05)
- **Review:** Draft

### TC-GBK-11 The offline grade sheet comes back in only as it went out

- **Role:** Instructor. **Priority:** P1
- **Data:** `instructor1`, IT101-A; the CSV and the XLSX exports of its grade sheet
- **Steps:**
  1. Download both. Import the CSV untouched.
  2. Import: a file that is not a grade sheet, an empty file, a PNG renamed `.csv`, IT102-A's sheet, a sheet exported before a category was added, one with reordered columns, one with an extra column, one with trailing blank columns.
  3. In a good sheet type `EX`, `ex`, `excused` over scores, clear an excused cell, type `abc`, and change two scores. Preview and apply.
  4. Finalize, then apply a sheet.
- **Expected:**
  - Step 1: the CSV starts with a byte-order mark (accents survive in Excel), has one row per enrolled student by last name, and the XLSX has live formulas, locked cells and instructions; an untouched sheet has nothing to import.
  - Step 2: each is refused with its reason; trailing blanks are accepted.
  - Step 3: `EX` in any case excuses the student and previews as such; clearing it or typing a number is a change; `abc` is refused with a hint; only the changed rows apply, and a row with an error is skipped.
  - Step 4: refused while finalized.
- **Already automated:** `tests/database/GradeSheetTest.php` (all)
- **Playwright:** `tests/e2e/gradebook.spec.js` (TC-GBK-05)
- **Basis:** `GradeSheetImportService`, `GradeSheetExportService`
- **QA status:** VCI (PS-GBK-06)
- **Review:** Draft

### TC-GBK-12 What stops finalizing, what finalizing freezes, and who may reopen

- **Role:** Instructor, chairperson, dean, student. **Priority:** P1
- **Data:** IT101-A (work waiting to be graded) and IT101-B; `instructor1`, `chairperson1`, `dean2`
- **Steps:**
  1. Try to finalize IT101-A. Then IT101-B with its scheme as a draft, with wrong weights, and with an open override request.
  2. Finalize IT101-B with one student who has missing work and one with an override.
  3. Change a score through the engine (a later grade) and compare the frozen and live values.
  4. As `chairperson1`, try to finalize; reopen without a reason, then with one. As `dean2` and as a student, try both. As the instructor, finalize twice.
- **Expected:**
  - Step 1: each blocker is named (handed-in work waiting, draft scheme, weights, the open request).
  - Step 2: missing work counts as 0 and sets the pass or fail tag; an override decides the tag; the students' parents are told at once; it is audited.
  - Step 3: the final grade stays frozen while the live calculation moves.
  - Step 4: only the instructor finalizes; the instructor and the chairperson can reopen, with a reason ("Give a reason for reopening the grades."); the dean and the student cannot change anything; finalizing twice is refused ("The grades are already finalized.").
- **Already automated:** `tests/database/GradeFinalizationServiceTest.php`, `tests/feature/GradeRoutesTest.php`
- **Playwright:** `tests/e2e/gradebook.spec.js` (TC-GBK-04)
- **Basis:** `GradeFinalizationService`
- **QA status:** VCI (PS-FIN-01, PS-FIN-02, PS-FIN-03)
- **Review:** Draft

---

## Wave 3 (added 2026-10-28)

P2 scenarios. QA status as in the README; none has been run.

| ID | Case | Role | Priority | QA status |
|---|---|---|---|---|
| TC-GBK-13 | A curve changes grades, never the entered scores, and can be undone | Instructor | P2 | VCI |
| TC-GBK-14 | The gradebook's reports and downloads agree with the gradebook | Instructor, student | P2 | VCI |
| TC-GBK-15 | Dropped and withdrawn students are left out of the calculation and tagged | Instructor | P2 | VCI |
| TC-GBK-16 | Finalizing tells the signer and the parents, and reopening says what happens to grades already sent | Instructor | P2 | VCI |

### TC-GBK-13 A curve changes grades, never the entered scores, and can be undone

- **Role:** Instructor. **Priority:** P2
- **Data:** `instructor1`, IT101-A; Quiz 1 with scores spread from 40 to 95; one student with an override, one excused
- **Steps:** preview a curve on Quiz 1, then on the final grade (+5 points); apply each; enter a later score; apply a second curve to Quiz 1; curve another item; undo; finalize and try to curve; try to undo IT101-B's curve from IT101-A.
- **Expected:** the preview shows before and after, how many would pass and reach full marks, and changes nothing; it refuses what cannot be curved; applying changes the grades but never the entered scores; the final-grade curve adds points but never passes 100; an override and excused work are never curved; a curve is kept as worked out when later scores change; a new curve on the same item replaces the old one, two items can each have one; undo puts every grade back; finalized grades cannot be curved until reopened; another section's curve cannot be undone; applying and undoing are audited; on a draft scheme a recalculation is flagged instead.
- **Already automated:** `tests/database/GradeAdjustmentServiceTest.php` (all), `GradeRoutesTest::testTheCurveRoutesPreviewApplyAndRemoveOverHttp`
- **Playwright:** `tests/e2e/gradebook.spec.js` (TC-GBK-03)
- **Basis:** `GradeAdjustmentService`
- **QA status:** VCI (PS-GBK-07)
- **Review:** Draft

### TC-GBK-14 The gradebook's reports and downloads agree with the gradebook

- **Role:** Instructor, student. **Priority:** P2
- **Data:** IT101-A with finalized grades including an excused item, an override and a curve; IT101-B with no grading set up
- **Steps:** download the class report (PDF and Excel), the summary, the CSV and a student report; open IT101-B's report; as student1 download "my report"; as student5 try student1's.
- **Expected:** a section without grading has no report; the class report carries exactly the gradebook's grades; the summary has mean, median, range and who is at or above passing, with each grade in one bucket; standing reads Passed or Failed only once final; excused items and overrides are noted; the CSV is data (an excused item is a blank score named in its own column; final, overridden and curved are yes or no flags), safe to open in a spreadsheet, and differs from the Excel report, which colours standings and has a summary sheet; a student report is only for someone in the section and can be held back until final; file names are safe; downloads are audited.
- **Already automated:** `tests/database/GradeReportServiceTest.php` (all)
- **Playwright:** `tests/e2e/gradebook.spec.js` (TC-GBK-05)
- **Basis:** `GradeReportService`
- **QA status:** VCI (PS-GBK-08)
- **Review:** Draft

### TC-GBK-15 Dropped and withdrawn students are left out of the calculation and tagged

- **Role:** Instructor. **Priority:** P2
- **Data:** IT101-A; drop student3 and withdraw student4 on the roster
- **Steps:** recalculate; open the gradebook and the class summary; bring student3 back with a status change.
- **Expected:** the dropped and withdrawn students are not graded by the engine, are tagged (Dropped, Withdrawn) in the gradebook, and are not counted in the summary; a status change back refreshes the tag and grades them again. (Bringing them back through Add is TC-ENR-07, expected to fail today.)
- **Already automated:** `GradingEngineServiceTest::testStudentsWhoDroppedAreNotGraded`
- **Playwright:** no
- **Basis:** `GradingEngineService::syncEnrolment`
- **QA status:** VCI (PS-GBK-11)
- **Review:** Draft

### TC-GBK-16 Finalizing tells the signer and the parents, and reopening says what happens to grades already sent

- **Role:** Instructor. **Priority:** P2
- **Data:** IT101-B (CBM has a dean); a section in a department with no eligible dean; parents linked to its students; an SIS connection; the parent notifier made to fail once
- **Steps:** finalize each section; finalize with the parent notifier failing; send the grades to the SIS (`sis:grades`), then reopen; reopen a section never sent; read the chairperson's notifications.
- **Expected:** finalizing tells the dean once (or the administrators where there is no eligible dean) and the students' parents at once; a failure telling parents never blocks finalizing; reopening grades already sent tells the instructor what happens next with the SIS; reopening grades never sent says nothing about the SIS; the chairperson is told of a reopen.
- **Already automated:** `GradeFinalizationServiceTest` (`testFinalizingTellsTheStudentsParentsAtOnce`, `testAParentNotifierThatFailsNeverBlocksFinalizing`, `testReopeningGradesAlreadySentTellsTheInstructorWhatHappensNext`, `testReopeningGradesNeverSentSaysNothingAboutTheSis`), `GradeSignoffServiceTest::testTheDeanIsToldOnceTheGradesAreFinalized`
- **Playwright:** no
- **Basis:** `GradeFinalizationService`
- **QA status:** VCI (PS-FIN-05)
- **Review:** Draft
