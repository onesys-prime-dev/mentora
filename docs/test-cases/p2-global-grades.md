# P2 test cases: the Grades page (every section an instructor teaches)

3 cases in the browser, and the PHP and JavaScript tests that stand behind the rest. Read
[README.md](README.md) first. All are `Draft` (see the README).

`tests/e2e/global-grades.spec.js` holds the cases. They run as the main instructor
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
