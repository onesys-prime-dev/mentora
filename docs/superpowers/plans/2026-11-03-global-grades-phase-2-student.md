# Global Grades, phase 2: the student page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A student opens Grades from the tile and sees every course of a term with its live grade, status and standing, a units-weighted GWA over finalized courses, and a small per-course breakdown, without opening each course.

**Architecture:** The phase 1 `GradesController` gains the `student` area and renders a student partial from a new batched `StudentGradesOverview` (stored `gradebook_records` only). A course row expands by fetching a small-table fragment built from the same `GradeService::buildStudentGrades` the course tab uses. A `grades_seen` table remembers what each viewer last saw so changed rows get a badge. A shared `GradebookRecordModel::effectiveBandId()` makes the global page and the course tab show the same band.

**Tech Stack:** CodeIgniter 4.7 + Shield, PHP 8.2, MySQL/MariaDB, vanilla JS (Vite), Tailwind v4, PHPUnit 10, Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-03-global-grades-design.md` (§2, §3, §4, §5.0–§5.4, §11 phase 2, §12). Phase 1 code is on this branch's parent (`1a0cb51`).

## Global Constraints

- Branch `feat/global-grades-student`, stacked on `feat/global-grades-shell` (already created and checked out). **No commits by the executor**; the owner commits by hand.
- Keep each file's line endings; `app/Config/Routes.php` is CRLF (Edit tool only). New JS files are LF.
- Every list number comes from stored rows. **Never call `GradingSchemeService::forOffering`** from the page or its lists; no scheme or an unpublished scheme reads as "Not published yet" and such a row does not expand.
- A grade shows only when the course's scheme is `Published` (as on the course tab).
- Viewing as someone writes nothing: the page's only write (`grades_seen`) is skipped while `Impersonation::active()`.
- Enrolments shown: `enrolled`, `completed`, `withdrawn`; never `dropped`. Offerings shown: status `active` or `completed` (archived left out, as the course routes do).
- Query count of the page must not grow with the number of courses (test 3 vs 15 courses).
- UI: the phase 1 shell (`app/Views/pages/grades/index.php`), `components/metric-card`, `components/empty-state`, `components/skeleton`; four states; 360–1440 px; colour never the only signal; keyboard and focus rings.
- Copy (exact): page description "Your grades in every course, as they stand now."; bar line "**{term name}** · {n} courses · {u} units · ends {M j}" ("ended {M j}" for a past term; date part omitted when `end_date` is null); picker label "Change term"; summary cards "GWA", "Units", "Passed", "Failed", "INC"; not-counted note "{n} course(s) not counted in the GWA: {CODE} ({reason}), …" with reasons "in progress", "not published", "Incomplete", "Withdrawn", "no grade scale", "a different grade scale"; chips "Not published yet", "In progress", "Finalized", "Signed off", "Withdrawn"; badge "Changed"; row meta "Updated {relative time}" (`AssessmentOverviewService::relativeTime`); expanded "Show categories" / "Hide categories", "not yet", "Passing is {p}%", "Adjusted by your instructor", "Open course grades →"; empty state "No courses this term".

## Review Focus

1. A course with a published scheme but no `gradebook_records` row for the student yet (newly enrolled): the row shows "—" with In progress, never an error, and opening the page creates nothing (Task 3 test `testACourseWithNoRecordYetShowsADash`).
2. A term whose courses are all unfinalized, or an institution with no default scale: the GWA card is hidden or reads "—" without dividing by zero (Task 3 tests).
3. A student enrolled in the same course code in two terms: each term lists only its own offering (Task 3 `testEachTermListsOnlyItsOwnCourses`).
4. Expanding a row for a course the student is not in (a guessed id): 404, never another student's or course's breakdown (Task 4 `testAnotherCoursesBreakdownIs404`).
5. Opening the page twice with no change, then after a grade changes: the badge appears only after the change and only once (Task 2 / Task 5 tests).

---

### Task 1: One effective band for the global page and the course tab

**Files:**
- Modify: `app/Models/Assessments/GradebookRecordModel.php` (beside `effectivePercentage`, line ~92)
- Modify: `app/Services/Courses/GradeService.php:189-196` (`buildStudentGrades` band lookup)
- Test: `tests/database/EffectiveBandTest.php`

**Interfaces:**
- Produces: `GradebookRecordModel::effectiveBandId(?array $record): ?int` (static) — `overridden_grade_scale_item_id` when `is_override`; else `final_grade_scale_item_id` when `finalized_at`; else `calculated_grade_scale_item_id`; null for a null record.

- [ ] **Step 1: Failing tests:** `testOverrideWins`, `testAFinalizedRecordUsesItsFinalBand`, `testOtherwiseTheCalculatedBand`, `testNoRecordHasNoBand`; and `testTheCourseTabShowsTheFinalBandOnceFinalized` (a finalized record whose calculated band differs from its final band → `buildStudentGrades(...)['summary']['grade_label']` is the final band's name).
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/database/EffectiveBandTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** the static method and switch `buildStudentGrades` to it.
- [ ] **Step 4: Run** it plus `tests/database/GradingEngineServiceTest.php tests/database/ParentCourseTabsServiceTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `fix(grades): the course tab shows the final band once grades are final`

---

### Task 2: Remember what each viewer last saw

**Files:**
- Create: `app/Database/Migrations/2026-11-21-000001_CreateGradesSeenTable.php`, `app/Models/Grades/GradesSeenModel.php`, `app/Services/Grades/GradesSeen.php`
- Test: `tests/database/GradesSeenTest.php`

**Interfaces:**
- Table `grades_seen`: `id`, `viewer_user_id`, `student_user_id`, `offering_id`, `signature` VARCHAR(64), `seen_at` DATETIME; unique (`viewer_user_id`, `student_user_id`, `offering_id`); FKs to `users` and `course_offerings` with CASCADE (follow `CreateGradeSignoffsTable`).
- Produces (`GradesSeen`):
  - `static signature(array $row): string` — sha256 of `percentage|band|tag|finalized|signed_off` from a Task 3 row.
  - `changed(int $viewerId, int $studentId, array $signatures): array<int, bool>` — `$signatures` is `[offeringId => signature]`; true only when a stored signature exists and differs; one query.
  - `remember(int $viewerId, int $studentId, array $signatures): void` — upserts all in one statement; does nothing while `Impersonation::active()`.

- [ ] **Step 1: Failing tests:** `testAFirstLookIsNotAChange`, `testTheSameGradeIsNotAChange`, `testAChangedGradeIsAChangeOnce` (changed → true; after `remember` → false), `testViewingAsSomeoneRemembersNothing` (start a real view-as as in `tests/feature/ViewAsWritesNothingTest.php`), `testOneQueryEachWhateverTheCourses` (2 vs 10 offerings).
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/database/GradesSeenTest.php` (the database test case runs migrations itself; do not migrate the owner's development database) — Expected: FAIL.
- [ ] **Step 3: Implement** the migration (with `down()`), model and service.
- [ ] **Step 4: Run** it — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): remember the grades each viewer last saw`

---

### Task 3: The student's terms, course rows and summary

**Files:**
- Create: `app/Services/Grades/StudentGradesOverview.php`
- Test: `tests/database/StudentGradesOverviewTest.php`

**Interfaces:**
- Consumes: `GradebookRecordModel::effectivePercentage`, `effectiveBandId` (Task 1); `AcademicDefaults::forInstitution(int)['scaleId']`.
- Produces (`StudentGradesOverview`):
  - `terms(User $student): list<array{id: int, name: string, academic_year: string, status: string, start_date: ?string, end_date: ?string}>` — terms of the student's shown enrolments (Global Constraints), newest `start_date` first.
  - `defaultTermId(array $terms, ?int $requested): ?int` — requested when listed; else the first `active`; else the first.
  - `courses(User $student, array $term): list<Row>` ordered by course code. `Row` = `{offering_id, course_code, course_title, section_code, slug, units: float, instructors: string, published: bool, percentage: ?float, band: ?string, band_value: ?float, tag: string, status: string, inc_until: ?string, inc_reason: ?string, finalized: bool, signed_off: bool, withdrawn: bool, updated_at: ?string, scale_id: ?int}`. `status` ∈ `not_published|withdrawn|signed_off|finalized|in_progress` decided in that order (withdrawn = enrolment `withdrawn` or remarks `Withdrawn`). `tag` = `GradingEngineService::displayTag(remarks, finalized)`; "Incomplete" with `inc_set_at` set reads "INC". `updated_at` = record `last_calculated_at`. `instructors` = names joined ", ". When not published: percentage, band null.
  - `summary(array $rows, ?int $defaultScaleId): array{units: float, gwa: ?float, passed: int, failed: int, inc: int, not_counted: list<array{code: string, reason: string}>}` — units exclude withdrawn; GWA = Σ(band_value × units)/Σ units over rows finalized, published, `scale_id === $defaultScaleId`, band_value not null, tag not INC/Withdrawn; rounded to 2 decimals; null when none count or no default scale. Passed/Failed count finalized rows with tag Passed/Failed; INC counts rows with tag INC. Every non-counted row gets one reason, first match of: withdrawn → "Withdrawn"; not published → "not published"; INC → "Incomplete"; not finalized → "in progress"; scale null → "no grade scale"; scale ≠ default → "a different grade scale".
  - `barLine(array $term, array $rows, string $today): array{name: string, courses: int, units: float, ends: ?string}` — `ends` = "ends Dec 20" / "ended Dec 20" against `$today` (Y-m-d), null without `end_date`.

- [ ] **Step 1: Failing tests:** `testTermsAndTheDefaultTerm`; `testEachTermListsOnlyItsOwnCourses`; `testDroppedAndArchivedAreLeftOut`; `testStatusesInOrder` (one row per status); `testAnUnpublishedSchemeShowsNoGradeAndCreatesNoScheme` (and no-scheme course: `grading_schemes` unchanged after `courses()`); `testACourseWithNoRecordYetShowsADash`; `testTheBandFollowsTheEffectiveBand`; `testGwaIsUnitsWeightedOverFinalizedCoursesOnTheDefaultScale` (3 units 1.75 + 2 units 2.50 → 2.05; an in-progress and a different-scale course left out with their reasons); `testNoCountedCourseOrNoDefaultScaleGivesNoGwa`; `testIncAndWithdrawn`; `testBarLine` (past and current term, no end date); `testTheQueryCountDoesNotGrowWithCourses` (3 vs 15).
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/database/StudentGradesOverviewTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement**: one query for rows (enrollments → offerings → courses → terms, LEFT JOIN `grading_schemes`, `gradebook_records`, the band `grade_scale_items` via the effective band id computed in PHP from the three band ids → one follow-up query for the needed band rows, open `grade_signoffs`), one for instructor names over the offering ids.
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): a student's courses, GWA and term summary for the Grades page`

---

### Task 4: A course's small breakdown, on request

**Files:**
- Create: `app/Controllers/Grades/StudentCourseGradesController.php`, `app/Views/pages/grades/partials/student/breakdown.php`
- Modify: `app/Config/Routes.php` (student group)
- Test: `tests/feature/StudentGradesBreakdownTest.php`

**Interfaces:**
- Consumes: `GradeService::buildStudentGrades(User, array $offering)` (`summary`, `periodBreakdown`, `categoryBreakdown`).
- Produces: `GET student/api/grades/courses/(:num)` (filters `permission:courses.view`, `coursemember:student`) → JSON `{success: true, html}`. 404 when the course has no scheme or it is not Published (checked with `GradingSchemeModel::findForOffering` before anything else, so nothing is created). Fragment markup: `<table data-grades-breakdown>` with one `<th>` per period ("Prelim 30%") and one row of percentages ("not yet" when null); a `<button type="button" data-categories-toggle aria-expanded="false">Show categories</button>` and a hidden `[data-categories]` block listing, per period, each category "Name (40%) — 93.0%"; without periods the categories are the table's columns and there is no toggle; then the standing line (chip with the tag, "INC · finish by {M j, Y}" and the reason when INC), "Passing is {p}%", "Adjusted by your instructor" when `is_adjusted`, and "Open course grades →" to `student/courses/{id}/{slug}/grades`.

- [ ] **Step 1: Failing tests:** `testTheBreakdownShowsPeriodsAndHidesCategoriesBehindTheToggle`; `testASchemeWithoutPeriodsShowsCategoriesAsColumns`; `testAnotherCoursesBreakdownIs404`; `testAnUnpublishedOrMissingSchemeIs404AndCreatesNothing`; `testTheNumbersMatchTheCourseTab` (the fragment's overall standing and period percentages equal `buildStudentGrades` for the same student).
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/feature/StudentGradesBreakdownTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** (percentages one decimal, `esc()` on every value).
- [ ] **Step 4: Run** it plus `tests/feature/ViewAsWritesNothingTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): a course's small grade breakdown for the student's Grades page`

---

### Task 5: The student Grades page

**Files:**
- Modify: `app/Controllers/Grades/GradesController.php` (`area()` admits `student`; a student branch), `app/Views/pages/grades/index.php` (role description; bar left line for the student; no tabs), `app/Helpers/navigation_helper.php:130` (student url), `app/Config/Routes.php` (student group)
- Create: `app/Views/pages/grades/partials/student/summary.php`, `app/Views/pages/grades/partials/student/rows.php`
- Test: `tests/feature/StudentGradesPageTest.php`; add to `tests/unit/NavigationHelperTest.php`; add a student case to `tests/database/GlobalGradesQueryBudgetTest.php`

**Interfaces:**
- Consumes: Tasks 2, 3; phase 1 shell.
- Produces: `GET student/grades` (filter `permission:courses.view`), query `term`. Each row: `data-grades-row data-offering-id data-expandable="1|0"`, a disclosure `<button aria-expanded="false" aria-controls="…">` only when published, an empty `[data-breakdown-slot]`. Each row shows code-section · title, units, instructors, "Updated {relative}", the band (large) with the percentage ("—" when null), the status chip, and the tag beneath it — for INC "INC · finish by {M j, Y}" (just "INC" without a date). Rows carry the "Changed" badge from `GradesSeen::changed`; after rendering the controller calls `GradesSeen::remember` with the shown signatures. Summary: five metric cards (GWA prominent, "—" when null and the card hidden when there is no default scale) and the not-counted note. Tile: `'url' => match ($role) { 'instructor' => site_url('instructor/grades'), 'student' => site_url('student/grades'), default => null }`.

- [ ] **Step 1: Failing tests:** `testTheTileOpensTheGradesPageForStudents` (chairperson still null); `testThePageListsTheTermsCoursesWithGradeChipAndStanding`; `testTheBarSaysTheTermCoursesUnitsAndEnd`; `testTheGwaCardAndNotCountedNote`; `testAnotherTermByQuery` and `testAnUnknownTermFallsBackToTheDefault`; `testNoCoursesShowsTheEmptyState`; `testChangedShowsOnlyAfterAGradeChanges` (GET, change a record's percentage, GET → "Changed", GET → none); `testViewingAsAStudentWritesNothing`; `testWithoutCoursesViewThereIsNoPage`; budget `testTheStudentPageCostsTheSameForThreeOrFifteenCourses`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/feature/StudentGradesPageTest.php tests/unit/NavigationHelperTest.php tests/database/GlobalGradesQueryBudgetTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** per spec §5.0–§5.4 and the approved mockup choices (bar B, expanded B: `.superpowers/brainstorm/912-1793686559/content/student-grades-v2.html`, options "bar-b" and "exp-b"; the first screen `student-grades.html` for rows and summary). The instructor branch is unchanged.
- [ ] **Step 4: Run** it plus `tests/feature/InstructorGradesPageTest.php tests/feature/ViewAsWritesNothingTest.php tests/feature/PermissionsTakeEffectTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): the Grades page for students`

---

### Task 6: Expanding a course in place

**Files:**
- Create: `resources/js/pages/grades/student-rows.js`
- Modify: `resources/js/pages/grades/grades.js` (bind it when `[data-grades-row]` exists)
- Test: `tests/js/pages/grades-student-rows.test.js`

**Interfaces:**
- Consumes: Task 4's endpoint and markup; Task 5's row attributes; the API base `data-breakdown-base` on the rows container (set by Task 5's view to `site_url('student/api/grades/courses')`).
- Produces: `bindStudentRows(root: HTMLElement): void`.

- [ ] **Step 1: Failing tests:** `the first expand fetches once and shows a skeleton meanwhile`; `collapsing and expanding again does not fetch again`; `a failed fetch shows the error with Try again, which retries`; `the categories toggle flips aria-expanded and the hidden block and its label`; `a row with data-expandable="0" has nothing to click`; `Enter and Space on the disclosure button work` (native button).
- [ ] **Step 2: Run** `npx vitest run tests/js/pages/grades-student-rows.test.js` — Expected: FAIL.
- [ ] **Step 3: Implement** (delegated click on the root; `components/skeleton`-style markup; `showLoadError` from `resources/js/shared/spa/error-state.js`).
- [ ] **Step 4: Run** it, `npx vitest run tests/js/pages`, `npm run lint` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): expand a course's grades in place on the student page`

---

### Task 7: Finish: look, end-to-end, docs, checks

**Files:**
- Create: `tests/e2e/global-grades-student.spec.js`; Modify: `docs/test-cases/p2-global-grades.md`

- [ ] **Step 1: Playwright** as `student1@mentora.test`: TC-GGR-04 open Grades from the Apps menu, see the term line, the summary cards and IT101-A's row; expand it, toggle categories, open course grades; TC-GGR-05 pick a past term with the picker (or the empty state if the demo has none); TC-GGR-06 as the instructor change a manual score in IT101-A, then as the student reload → "Changed" on that row, reload → gone (leave data as found).
- [ ] **Step 2: Run** `npx playwright test tests/e2e/global-grades-student.spec.js` — Expected: PASS.
- [ ] **Step 3: Screenshots** (throwaway spec, deleted after; saved in the plan's git-ignored workspace) at 360, 768, 1440 px: filled, a row expanded with categories shown, empty term, load error; fix clear breakage only.
- [ ] **Step 4: Full checks:** `vendor/bin/phpunit --no-coverage` (≈21 min; `composer test` times out), `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run lint`, `npm test`. Expected: all green, or pre-existing failures shown not to involve this branch's files.
- [ ] **Step 5: Hand off** one commit message for the whole phase (the owner commits each phase as one commit). Do not commit.
