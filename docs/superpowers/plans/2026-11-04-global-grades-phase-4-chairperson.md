# Global Grades, phase 4: the chairperson page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A chairperson opens Grades and sees every section of their program — tracker, sortable table, read-only grids they can deliberately edit, nudges to instructors, a program download and a program at-risk list — plus the workbench for the sections they teach.

**Architecture:** The phase 1 section-state logic is extracted so one builder serves the instructor's cards and the program table. A new `ChairpersonProgramOverview` lists the program's sections with averages and counts in a fixed number of queries. `GradesController` gains the `chairperson` area with two tabs; the grid is the same embedded roster (phase 1), loaded read-only and unlocked by an audited confirm. Nudges post to the existing Follow-ups; the program download reuses `GradeReportService::classWorkbook`.

**Tech Stack:** CodeIgniter 4.7 + Shield, PHP 8.2, MySQL/MariaDB, vanilla JS (Vite), Tailwind v4, PHPUnit 10, Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-03-global-grades-design.md` §8 (incl. "Phase 4 layout"), §2–§4, §7, §12, §13. Phases 1–3 are this branch's parents (HEAD `c0efbec`).

## Global Constraints

- Branch `feat/global-grades-chairperson`, stacked on phase 3 (created, checked out). **No commits by the executor.** Never migrate, write, start, stop or kill the owner's database.
- Keep line endings; `app/Config/Routes.php` is CRLF (Edit tool only). New files LF.
- A chairperson reaches only sections of the sub-departments they chair (`ChairpersonAccessService`), offerings `active` or `completed`.
- **Finalizing is the instructor's:** the chairperson area has no finalize route and none is added; the chairperson page shows no Finalize or Finalize ready sections controls (reopen stays where it is today).
- Program tab needs `program.monitor`; Edit as chairperson needs `grading.manage` (as chairperson); Nudge needs `followups.manage`; Download program needs `program.monitor`. A missing permission hides its control; the tab without `program.monitor` is not shown.
- Every number from stored rows; never `GradingSchemeService::forOffering` in lists; viewing as someone writes nothing; query count does not grow with sections (test 3 vs 15).
- Instructor, student and parent pages behave exactly as before.
- Copy (exact): description "Your sections and your program's."; tabs "My sections", "Program"; sub-pills "Gradebook", "To grade", "At risk" (My sections) and "Sections", "At risk" (Program); tracker "Finalized {f} of {n} · {r} ready · {b} blocked"; filter "Not finalized only"; columns "Section", "Instructor", "Average", "At risk", "Waiting", "Status"; buttons "Edit as chairperson", "Done", "Open in course →", "Nudge", "Download program"; confirm "You're editing {section} as chairperson. Every change is recorded in the audit log and the instructor can see it." with "Continue" / "Cancel"; nudge titles "{section}: {n} submissions waiting to be graded, oldest {d} days" and "{section}: not finalized; the term ends {M j}"; empty "No sections in your program this term".

## Review Focus

1. A chairperson guessing a section id outside their sub-departments (`?section=`, a row click forged in the URL, the download): 404 / left out, never another program's grid (Tasks 2, 5).
2. Edit as chairperson, then switching to another row: the new grid opens read-only again, and a score typed just before switching is saved to the right section (Task 3).
3. A section with two instructors: Nudge lets the chairperson pick the recipient, never sends to someone not teaching it (Task 4).
4. A chairperson who teaches nothing in their sub-departments, or lacks `program.monitor`: the page shows only the tab they have, never an empty tab bar or an error (Task 2).
5. A program with 60 sections: pagination, search and sort keep the page fast and the query count flat (Task 1, Task 2).

---

### Task 1: Program sections, and one section-state builder

**Files:**
- Create: `app/Services/Grades/SectionStates.php`, `app/Services/Grades/ChairpersonProgramOverview.php`
- Modify: `app/Services/Grades/InstructorGradesOverview.php` (`sections()` delegates state, waiting and notes to `SectionStates`; output unchanged)
- Test: `tests/database/ChairpersonProgramOverviewTest.php`; keep `tests/database/InstructorGradesOverviewTest.php` green

**Interfaces:**
- Produces:
  - `SectionStates::for(list<array> $offerings): array<int, array{waiting: int, state: string, note: string, has_scheme: bool}>` keyed by offering id — the exact rules, notes and batched queries `InstructorGradesOverview::sections()` uses today (finalized, not_published, waiting, blocked incl. publish problems, ready).
  - `ChairpersonProgramOverview::terms(User $chair): list<array{id, name, academic_year, status}>` (terms of chaired sections, newest first) and `defaultTermId(array $terms, ?int $requested): ?int` (as the instructor's).
  - `ChairpersonProgramOverview::sections(User $chair, int $termId, array $opts = []): array{rows: list<Row>, total: int, tracker: array{finalized: int, ready: int, blocked: int, total: int}}` — `$opts` = `{q?: string, notFinalized?: bool, sort?: 'section'|'instructor'|'average'|'at_risk'|'waiting'|'status', dir?: 'asc'|'desc', page?: int}`, 25 per page; tracker counts all sections of the term regardless of filters. `Row` = `{offering_id, course_code, course_title, section_code, slug, instructors: list<array{id: int, name: string}>, average: ?float, at_risk: int, waiting: int, state, note, has_scheme, offering_status}`. `average` = mean effective percentage of enrolled/completed students with a record (null when none). `at_risk` = students with grade standing At risk/Failed/INC (published schemes only; grade reasons only — the activity rule stays on the At risk sub-pill).
  - `ChairpersonProgramOverview::mySections(User $chair, int $termId): list<array>` — `InstructorGradesOverview::sections()` rows for sections the chairperson teaches **and** may reach as chairperson.

- [ ] **Step 1: Failing tests:** `testOnlySectionsOfChairedSubDepartmentsAreListed` (another sub-department's section absent; archived absent); `testColumnsAverageAtRiskWaitingAndStatus`; `testTheTrackerCountsTheWholeTermWhateverTheFilter`; `testSearchMatchesCodeTitleOrInstructor`; `testNotFinalizedOnly`; `testSortByEachColumnBothWays`; `testPagesOf25`; `testMySectionsAreTaughtAndChaired` (a taught section outside the chaired sub-department is left out); `testNoSchemeIsNotCreated`; `testTheQueryCountDoesNotGrowWithSections` (3 vs 15, 50 students each). InstructorGradesOverviewTest stays green unchanged.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/database/ChairpersonProgramOverviewTest.php tests/database/InstructorGradesOverviewTest.php` — Expected: new FAIL, old PASS.
- [ ] **Step 3: Implement** (search/sort/paginate in PHP over the term's rows is fine — a program term is at most a few hundred sections — as long as the queries stay batched).
- [ ] **Step 4: Run** it plus `tests/database/GlobalGradesQueryBudgetTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): a chairperson's program sections, and one section-state builder`

---

### Task 2: The chairperson Grades page

**Files:**
- Modify: `app/Controllers/Grades/GradesController.php` (chairperson area), `app/Views/pages/grades/index.php` (two tabs + sub-pills), `app/Helpers/navigation_helper.php` (chairperson tile url), `app/Config/Routes.php` (chairperson group)
- Create: `app/Views/pages/grades/partials/chairperson/program.php`, `…/program-at-risk.php`
- Test: `tests/feature/ChairpersonGradesPageTest.php`; add to `tests/unit/NavigationHelperTest.php`, `tests/database/GlobalGradesQueryBudgetTest.php`

**Interfaces:**
- Consumes: Task 1; phase 1 instructor partials (`partials/instructor/*`) for My sections with `area = 'chairperson'`; `GradesAtRisk::forSections`; the embedded roster.
- Produces: `GET chairperson/grades` (filter `permission:courses.view`). Query: `tab` ∈ `mine` | `program` (default `program` when the chairperson teaches nothing chaired, else `mine`); `sub` (mine: `gradebook|to-grade|at-risk`; program: `sections|at-risk`); `term`; `section`; program `q`, `nf=1`, `sort`, `dir`, `page`. My sections renders the instructor workbench partials with Finalize, Finalize ready sections and Download all **hidden**, grid API base `chairperson/api/courses/{id}`. Program renders tracker, filter, table (each row a link `?tab=program&section={id}…` carrying `data-program-row data-section-id data-slug data-state data-instructors='[{"id":…,"name":…}]' data-waiting data-oldest-days data-term-end`), Nudge/Download program buttons, and below the table the grid panel `[data-grades-grid][data-api-base][data-section-id][data-readonly="1"]` with action bar (`[data-edit-as-chair]` shown with grading.manage, `[data-edit-done]` hidden, Open in course →) for the selected section (default: first row of the page). `section` not among the chairperson's reachable sections → 404. Tile: chairperson url `site_url('chairperson/grades')`.

- [ ] **Step 1: Failing tests:** `testTheTileOpensTheGradesPageForChairpersons`; `testMySectionsIsTheWorkbenchWithoutFinalizeControls`; `testNoTaughtSectionsOpensOnProgramWithoutAMySectionsTab`; `testWithoutProgramMonitorThereIsNoProgramTab`; `testTheProgramTabShowsTrackerTableAndTheSelectedGridReadOnly`; `testSearchSortFilterAndPageAreInTheAddress`; `testASectionOutsideTheProgramIs404`; `testProgramAtRiskListsTheProgramsStudents`; `testControlsHiddenWithoutTheirPermissions` (no grading.manage → no Edit as chairperson; no followups.manage → no Nudge); `testTheChairpersonAreaStillHasNoFinalizeRoute` (POST `chairperson/api/courses/{id}/grades/finalization` → 404); `testOpeningThePageWritesNothing`; budget `testTheChairpersonPageCostsTheSameForThreeOrFifteenSections`; instructor/student/parent page tests stay green.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/feature/ChairpersonGradesPageTest.php tests/unit/NavigationHelperTest.php tests/database/GlobalGradesQueryBudgetTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** per spec §8 "Phase 4 layout" and the agreed table-with-grid-below preview; table scrolls sideways on phones inside its card; status words beside their dots.
- [ ] **Step 4: Run** it plus `tests/feature/InstructorGradesPageTest.php tests/feature/StudentGradesPageTest.php tests/feature/ParentGradesPageTest.php tests/feature/ViewAsWritesNothingTest.php tests/feature/PermissionsTakeEffectTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): the Grades page for chairpersons`

---

### Task 3: Program rows open the grid; Edit as chairperson

**Files:**
- Create: `resources/js/pages/grades/program.js`
- Modify: `resources/js/pages/grades/grades.js` (bind when `[data-program-row]` exists), `resources/js/pages/grades/section-switch.js` only if `loadInto` needs a query option
- Test: `tests/js/pages/grades-program.test.js`

**Interfaces:**
- Consumes: `loadInto`, `setGradesApiBase`, `flushPendingScores` (phase 1); `components/confirm` / the app dialog (`resources/js/shared/dialog.js`).
- Produces: `bindProgram(root: HTMLElement): void`.

- [ ] **Step 1: Failing tests:** `clicking a row flushes pending scores, then loads that section read-only (query embedded=1&readonly=1)`; `the address and aria-current follow the selected row`; `a slower earlier load never overwrites a later one`; `Edit as chairperson asks with the exact sentence; Continue reloads the grid without readonly and shows Done; Cancel changes nothing`; `Done or opening another row returns to read-only`; `without data-edit-as-chair nothing unlocks`.
- [ ] **Step 2: Run** `npx vitest run tests/js/pages/grades-program.test.js` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** it, `npx vitest run tests/js/pages`, `npm run lint` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): open a program section's grid and edit it as chairperson`

---

### Task 4: Nudge an instructor

**Files:**
- Create: `resources/js/pages/grades/nudge.js`, `app/Views/pages/grades/partials/chairperson/nudge-dialog.php`
- Modify: `app/Views/pages/grades/partials/chairperson/program.php` (a Nudge button per row, or one for the selected row — follow the agreed preview: one Nudge button acting on the selected row), `resources/js/pages/grades/grades.js`
- Test: `tests/js/pages/grades-nudge.test.js`; `tests/feature/ChairpersonNudgeTest.php`

**Interfaces:**
- Consumes: `POST chairperson/follow-ups` (existing; fields `offering_id`, `recipient_id`, `title`, `message`, `reason`, `due_date`; answers JSON `{message, redirect}` or 422 `{errors, message}` when the request is AJAX); reasons from `FollowUpRules::REASONS`; the row's data attributes (Task 2).
- Produces: the dialog prefilled per spec copy (title by state: waiting → the submissions sentence; otherwise not finalized → the term-end sentence), recipient select of the section's instructors (preselected when one), reason select, optional due date, message editable; Send posts via fetch with CSRF and `X-Requested-With`, shows a toast from `message`, closes, stays on the page; a 422 shows the error inside the dialog and keeps the input.

- [ ] **Step 1: Failing tests:** JS — `the dialog is prefilled from the selected row`; `two instructors give a choice, one is preselected`; `Send posts the fields and shows the toast`; `a 422 keeps the dialog open with the error`. PHP — `testANudgeBecomesAFollowUpForTheInstructor` (POST as AJAX → row in follow-ups for the instructor); `testANudgeToSomeoneNotTeachingTheSectionIsRefused` (existing rule, pinned).
- [ ] **Step 2: Run** `npx vitest run tests/js/pages/grades-nudge.test.js` and `vendor/bin/phpunit --no-coverage tests/feature/ChairpersonNudgeTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** both plus `npm run lint` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): nudge an instructor from the program table`

---

### Task 5: Download program

**Files:**
- Modify: `app/Controllers/Grades/GradesBulkController.php` (a `downloadProgram()` action), `app/Config/Routes.php` (chairperson group: `GET grades/download-program` with `permission:program.monitor`), `app/Views/pages/grades/partials/chairperson/program.php` (button href)
- Test: `tests/feature/ChairpersonDownloadProgramTest.php`

**Interfaces:**
- Consumes: `ChairpersonProgramOverview::sections()` (all rows of the term, unpaginated — add an `all` option or a dedicated method), `GradeReportService::classReport` + `classWorkbook`, the audit entry `GradesBulkController::download` writes.
- Produces: one XLSX of every program section with grading in the term (filename `program-gradebooks-{term slug}-{Ymd}.xlsx`), one `grades.report_exported` audit per section; refusal with a message when none has grading.

- [ ] **Step 1: Failing tests:** `testASheetPerProgramSectionAndAnAuditEach`; `testSectionsOutsideTheProgramAreLeftOut`; `testWithoutProgramMonitorIsRefused`; `testNoGradingYetRefusesPolitely`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/feature/ChairpersonDownloadProgramTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** (reuse `classWorkbook`; no copied sheet code).
- [ ] **Step 4: Run** it plus `tests/feature/GradesBulkTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): download every gradebook of the program`

---

### Task 6: Finish: look, end-to-end, docs, checks

**Files:** Create `tests/e2e/global-grades-chairperson.spec.js`; Modify `docs/test-cases/p2-global-grades.md`

- [ ] **Step 1: Playwright** as the demo chairperson (see `tests/e2e/chairperson.spec.js`): TC-GGR-10 open Grades → Program, see the tracker and table, open a row's grid read-only; TC-GGR-11 Edit as chairperson → confirm → change a manual score → Done → reload shows it (restore the score after); TC-GGR-12 Nudge an instructor → toast → the follow-up exists (remove it after). Leave data as found.
- [ ] **Step 2: Run** `npm run build` then `npx playwright test tests/e2e/global-grades-chairperson.spec.js` — Expected: PASS.
- [ ] **Step 3: Screenshots** (throwaway spec, deleted; saved in the plan's git-ignored workspace) at 360 and 1440 px: Program table with grid below, the edit confirm, the nudge dialog, My sections. Fix clear breakage only.
- [ ] **Step 4: Full checks:** `vendor/bin/phpunit --no-coverage` (≈25 min), `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run lint`, `npm test`. If MySQL is down or hangs, report BLOCKED — do not start, stop or kill it.
- [ ] **Step 5: Hand off** one commit message for the phase. Do not commit.
