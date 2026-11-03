# Global Grades, phase 1: shell and instructor workbench Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An instructor grades waiting work and finalizes every section from one Grades page, opened from the Grades tile, without opening each course.

**Architecture:** A new area-aware `GradesController` (the Announcements pattern) renders `instructor/grades` from stored data only: section cards, a cross-section To grade queue and an At risk list, all built by batched services whose query count does not grow with the number of sections. The Gradebook tab embeds the existing course roster grid (made embeddable, not copied) and reuses the existing finalize dialog. Two bulk endpoints (Finalize ready sections, Download all) repeat the course checks per section. "Waiting to grade" gets one definition, `PendingGrading`, which Finalize also uses.

**Tech Stack:** CodeIgniter 4.7 + Shield, PHP 8.2, MySQL, PhpSpreadsheet, vanilla JS (Vite), Tailwind, PHPUnit 10, Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-03-global-grades-design.md` (§0, §2–§4, §7, §11 phase 1, §12, §13).

## Global Constraints

- Branch `feat/global-grades-shell` (exists; it already holds the spec, `.gitignore` and the 30-unit change). Check `git branch --show-current` before the first edit. **No commits by the executor**: each "Commit" step stages the files and states the message, ending `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Keep each file's line endings (`tr -cd '\r' < f | wc -c`). `app/Config/Routes.php` is CRLF: edit it with the Edit tool, never `sed -i`.
- Tests never set the database through command-line env vars (`.env` wins); use `Tests\Support\TestEnv` / the existing test cases.
- Every number comes from stored rows. **Never call `GradingSchemeService::forOffering`** (it creates and recalculates) from the Grades page's lists, cards or bulk endpoints; a missing scheme reads as "Not published yet".
- "Waiting to grade" = pending work that can still change the grade (`AssessmentResultService::resultFor(...)['pending']`: any ungraded quiz attempt, an assignment's latest submission), from students `enrolled` or `completed`, on assessments whose status is not `Archived`.
- Speed budget (spec §0): on 15 sections × 50 students, page load < 1 s and a section switch < 0.5 s locally; the page's query count must not grow with sections.
- UI: `layouts/app`, the Announcements shell (back link, `data-page-panel`, `components/page-head`, brand-tint bar with pill tabs and counts), `components/metric-card`, `components/empty-state`, `components/skeleton`; brand `#0040A6` / hover `#002068`; four states (loading, empty, error, filled); 360–1440 px; keyboard and focus rings on every control.
- Copy (exact): page description "Every section you teach."; tabs "Gradebook", "To grade", "At risk"; buttons "Finalize ready sections", "Download all", "Finalize", "Download", "Open in course →", "Grade →"; Grade work back link "← Back to To grade"; empty states "No sections this term" / "Nothing waiting to grade" / "No one is at risk".

## Review Focus

1. A score typed into the grid and a section card clicked before the debounce fires: the score is saved, not lost (Task 3, `flushPendingScores` test).
2. `?section=` naming a section the instructor does not teach, or not a number: 404, not someone else's grid and not a silent fallback (Task 5).
3. A `return` value pointing to another site, a `javascript:` URL or another page of the app: ignored, and the Grade work back link behaves as today (Task 7).
4. An instructor with no terms or no sections in the chosen term: the page renders its empty state, no warnings, Finalize ready sections and Download all hidden (Task 5).
5. A section that stops being ready between page load and "Finalize ready sections" (new work handed in): skipped and named with its reason; nothing else changes (Task 8).

---

### Task 1: One definition of "waiting to grade", and Finalize uses it

**Files:**
- Create: `app/Services/Courses/Grades/PendingGrading.php`
- Modify: `app/Services/Courses/Grades/GradeFinalizationService.php:367-381` (`ungradedWork`)
- Test: `tests/database/PendingGradingTest.php`; add one case to `tests/database/GradeFinalizationServiceTest.php`

**Interfaces:**
- Consumes: `AssessmentOverviewService::latestAttempts(array $assessmentIds, array $studentIds): array` (public), `AssessmentResultService::resultFor(bool $isQuiz, array $attempts): array`.
- Produces: `PendingGrading::forOfferings(list<int> $offeringIds): array{assessments: list<array{offering_id: int, assessment_id: int, title: string, type: string, waiting: int, late: int, oldest_at: ?int, first_student_id: ?int}>, byOffering: array<int, int>}`. `type` is the lower-cased assessment type name (`quiz`, `assignment`, …). `late` counts pending attempts submitted after `due_date`. Only assessments with `waiting > 0` are listed; `byOffering` has every requested id (0 when none).

- [ ] **Step 1: Write the failing tests** in `PendingGradingTest` (DatabaseTestCase, `CourseFactory`/`AssessmentFactory`):
  - `testAnAssignmentCountsOnlyItsLatestSubmission`: attempt 1 `Submitted` ungraded, attempt 2 `Graded` → `waiting` 0 and `byOffering[$o] === 0`; then a third attempt `Submitted` → 1.
  - `testEveryUngradedQuizAttemptCounts`: two `Submitted` quiz attempts by one student → 2.
  - `testArchivedAssessmentsAndDroppedStudentsAreLeftOut`: archived assessment with a `Submitted` attempt → not listed; a `dropped` student's attempt → not counted.
  - `testLateAndOldestAreReported`: due `2026-10-01 23:59:00`, submissions at `2026-10-02 08:00` and `2026-09-30 08:00` → `late` 1, `oldest_at === strtotime('2026-09-30 08:00:00')`.
  - `testItAgreesWithTheAssessmentsTab`: same fixture as above read through `AssessmentOverviewService::build` as the instructor → each assessment's `section.toGrade` equals `waiting` (0 when unlisted).
  - `testTheQueryCountDoesNotGrowWithSections`: queries for 2 offerings == queries for 8 offerings (count with `Events::on('DBQuery', …)` as in `DashboardQueryBudgetTest::queriesOf`).
  - In `GradeFinalizationServiceTest`: `testAReplacedAssignmentAttemptNoLongerBlocksFinalizing`: an older ungraded attempt with a later graded one → `readiness()` has no "waiting to be graded" blocker.

- [ ] **Step 2: Run them**
  Run: `vendor/bin/phpunit --no-coverage tests/database/PendingGradingTest.php --filter . ; vendor/bin/phpunit --no-coverage --filter testAReplacedAssignmentAttempt tests/database/GradeFinalizationServiceTest.php`
  Expected: FAIL (class not found; the finalization case is blocked by the old rule).

- [ ] **Step 3: Implement `PendingGrading::forOfferings`.** Three queries in total whatever the number of offerings: assessments of those offerings (join `course_modules` on `module_id`, `cm.offering_id IN (…)`, `a.status <> 'Archived'`, type name via `assessment_types`), enrolments (`enrolled`, `completed`) of those offerings, then `latestAttempts` over all of them; attempts of a student count only for an assessment of an offering they are enrolled in. Then make `ungradedWork($offeringId)` return `(new PendingGrading())->forOfferings([$offeringId])['byOffering'][$offeringId]`, and update its doc comment ("pending work that can still change the grade, as on the Assessments tab").

- [ ] **Step 4: Run** the two commands above, then `vendor/bin/phpunit --no-coverage tests/database/GradeFinalizationServiceTest.php tests/database/GradingLockedWhileFinalizedTest.php tests/feature/GradeRoutesTest.php`
  Expected: PASS. If an existing test asserted that an older replaced attempt blocks finalizing, change its expectation (agreed behaviour change, spec §7) and say so in the commit body.

- [ ] **Step 5: Commit**
  `git add app/Services/Courses/Grades/PendingGrading.php app/Services/Courses/Grades/GradeFinalizationService.php tests/database/PendingGradingTest.php tests/database/GradeFinalizationServiceTest.php`
  Message: `feat(grades): one rule for work waiting to be graded, used by Finalize`

---

### Task 2: The instructor's terms and section cards

**Files:**
- Create: `app/Services/Grades/InstructorGradesOverview.php`
- Test: `tests/database/InstructorGradesOverviewTest.php`

**Interfaces:**
- Consumes: `PendingGrading::forOfferings` (Task 1).
- Produces (all on `InstructorGradesOverview`):
  - `terms(User $user): list<array{id: int, name: string, academic_year: string, status: string}>` — terms of offerings the user is assigned to teach, newest `start_date` first.
  - `defaultTermId(list<array> $terms, ?int $requested): ?int` — `$requested` when it is in the list; else the first `active`; else the first.
  - `sections(User $user, int $termId): list<array{offering_id: int, course_code: string, course_title: string, section_code: string, slug: string, students: int, waiting: int, state: string, note: string, offering_status: string}>` ordered by course code then section. `state` ∈ `finalized` | `not_published` | `waiting` | `blocked` | `ready`, decided in that order: an open `grade_finalizations` row (`reopened_at IS NULL`); no scheme row for the offering or its status ≠ `Published`; `waiting > 0`; an open `grade_override_requests` row (`open_marker = 1`); else ready. `note` (exact): "Finalized", "Grades not published yet", "{n} waiting to be graded", "{n} override request(s) open", "Ready to finalize". `slug` = `url_title(course_title, '-', true)`.
  - `defaultSectionId(list<array> $sections, ?int $requested): ?int` — `$requested` when listed; `null` for an empty list; else the most `waiting`, ties by list order. (A requested id that is not listed is the controller's 404, Task 5.)
  - `summary(list<array> $sections): array{waiting: int, ready: int, finalized: int, total: int}`.

- [ ] **Step 1: Write the failing tests**
  - `testTermsAreTheOnesTheyTeachInNewestFirst` and `testTheDefaultTermIsTheActiveOne` (falls back to the first; a requested listed term wins; an unlisted one is ignored).
  - `testEachSectionSaysWhereItStands`: five sections set up as finalized / no scheme / 2 waiting / open override request / ready → states and notes exactly as above; `students` counts `enrolled` + `completed` only.
  - `testASectionWithoutASchemeIsNotGivenOne`: after `sections()`, `grading_schemes` has no row for that offering.
  - `testAnotherInstructorsSectionsAreNotListed`.
  - `testTheDefaultSectionIsTheBusiest` (and requested listed wins; empty list → null).
  - `testTheQueryCountDoesNotGrowWithSections`: `sections()` for 3 vs 15 sections (each with 50 enrolled students and 2 assessments) → equal counts.

- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/database/InstructorGradesOverviewTest.php` — Expected: FAIL (class not found).

- [ ] **Step 3: Implement** with one query for sections (join `instructor_assignments`, `course_offerings`, `courses`, correlated enrolment count as in `InstructorAssignmentModel::retrieveInstructorSections`), one each for schemes, open finalizations and open override requests over the offering ids, plus `PendingGrading`.

- [ ] **Step 4: Run** the same command — Expected: PASS.

- [ ] **Step 5: Commit** `git add app/Services/Grades/InstructorGradesOverview.php tests/database/InstructorGradesOverviewTest.php` — `feat(grades): the instructor's terms and section cards for the Grades page`

---

### Task 3: The course gradebook grid can be embedded

**Files:**
- Modify: `resources/js/pages/courses/features/grades/api.js:9-12` (`base`)
- Modify: `resources/js/pages/courses/features/grades/roster/roster.js` (`loadGradebookRoster`, the debounce timers)
- Modify: `app/Controllers/Courses/Grades/GradingSchemeController.php:166-182` (`roster`)
- Modify: `app/Views/pages/courses/features/grades/roster.php:20-31` (view-only flag, back button)
- Test: `tests/js/pages/grades-embed.test.js`; add to `tests/feature/GradeRoutesTest.php`

**Interfaces:**
- Produces (JS): `setGradesApiBase(url: string | null): void` in `api.js` — when set, `base()` returns `${url}/grades`; `null` restores `window.coursesConfig.apiBaseUrl`. `loadGradebookRoster(back, { container = "#course-content", query = "" } = {}): Promise<void>` — renders into `container` (selector or element) and passes `query` (e.g. `"embedded=1&readonly=1"`) to the roster request. `flushPendingScores(): Promise<void>` — fires every pending debounced score save now and resolves when they have all settled.
- Produces (PHP): `GET …/grades/roster?embedded=1` omits the back button (`data-gradebook-roster-back`); `?readonly=1` renders the grid view-only. `readonly` can only make it view-only, never editable.
- `fetchGradebookRoster(query = "")` in `api.js` appends `?${query}` when given.

- [ ] **Step 1: Write the failing tests**
  - Vitest `grades-embed.test.js` (jsdom, `fetch` mocked): `base follows setGradesApiBase and falls back to coursesConfig`; `the roster renders into the given container with the query`; `flushPendingScores saves a score typed just before switching` (type into a `[data-manual-score-input]`, call `flushPendingScores()` before the timer, expect one PATCH to `…/roster/items/{item}/students/{student}` with the typed score).
  - PHPUnit in `GradeRoutesTest`: `testTheEmbeddedRosterHasNoBackButton`, `testReadonlyMakesTheRosterViewOnlyButNeverEditable` (as the teacher, `?readonly=1` → no `data-manual-score-input`; as an instructor without `grading.manage`, no `readonly` → still view-only).

- [ ] **Step 2: Run** `npx vitest run tests/js/pages/grades-embed.test.js` and `vendor/bin/phpunit --no-coverage --filter "EmbeddedRoster|ReadonlyMakes" tests/feature/GradeRoutesTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement.** In `roster()` pass `embedded` and `readonly` (truthy when `'1'`) to the view; in `roster.php` `$viewOnly = CourseAbilities::viewOnly(...) || ! empty($readonly)` and wrap the back button in `if (empty($embedded))`. Keep each pending save in `timers` as its function so `flushPendingScores` can clear the timeout and call it. The course tab keeps calling `loadGradebookRoster(back)` unchanged.

- [ ] **Step 4: Run** both commands, plus `npx vitest run tests/js/pages/roster-view.test.js tests/js/pages/override-dialog.test.js` — Expected: PASS.

- [ ] **Step 5: Commit** the five files — `refactor(grades): the gradebook grid can be embedded outside the course page`

---

### Task 4: At risk across sections, for either reason

**Files:**
- Create: `app/Services/Grades/GradesAtRisk.php`
- Test: `tests/database/GradesAtRiskTest.php`

**Interfaces:**
- Consumes: `RosterRiskService::atRisk(list<int>): list<array{offering_id, student_id, name, progress, days_since, reason}>` (`reason` ∈ `inactive` | `behind`).
- Produces: `GradesAtRisk::forSections(list<array> $sections, ?string $filter, ?string $today = null): list<array{offering_id: int, student_id: int, name: string, section: string, slug: string, reasons: list<array{kind: string, text: string}>}>`. `$sections` are Task 2's rows. `kind` ∈ `grade` | `inc` | `activity`; `$filter` ∈ `grade` | `inc` | `activity` | null keeps students with at least one reason of that kind. One row per student and section, grade reasons first, then by name. Reason text (exact): grade, not finalized and `remarks = 'Failed'` → "Failing · {p}%"; finalized and `Failed` → "Failed · {p}%" (`p` = `effectivePercentage`, one decimal); INC (`inc_set_at` set) → "INC · finish by {M j, Y}", or "INC · overdue" when `inc_until` < `$today`; "INC" when no date; activity `inactive` → "Inactive {d} days" ("Never active" when `days_since` is null); `behind` → "Behind on published work".
- Only sections whose scheme is Published contribute grade reasons; activity reasons come from every section.

- [ ] **Step 1: Write the failing tests:** `testFailingSoFarAndFailedAreGradeReasons`, `testIncShowsItsDateAndWhenItIsOverdue` (`$today = '2026-12-21'`, `inc_until = '2026-12-20'` → "INC · overdue"), `testInactiveStudentsComeFromTheDashboardRule`, `testAStudentWithBothReasonsIsOneRow`, `testTheFilterKeepsOnlyThatKind`, `testAnUnpublishedSchemeGivesNoGradeReasons`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/database/GradesAtRiskTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** with one query over `gradebook_records` (+ schemes) for the offering ids and one `RosterRiskService::atRisk` call; names come from `user_profiles` in the same records query.
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): at-risk students across sections, by grade and by activity`

---

### Task 5: The Grades page for instructors (route, controller, views, tile)

**Files:**
- Create: `app/Controllers/Grades/GradesController.php`
- Create: `app/Views/pages/grades/index.php`, `app/Views/pages/grades/partials/instructor/cards.php`, `…/instructor/gradebook.php`, `…/instructor/to-grade.php`, `…/instructor/at-risk.php`
- Modify: `app/Config/Routes.php` (instructor group, near line 125; CRLF)
- Modify: `app/Helpers/navigation_helper.php:129` (Grades tile url)
- Test: `tests/feature/InstructorGradesPageTest.php`; add to `tests/unit/NavigationHelperTest.php`; `tests/database/GlobalGradesQueryBudgetTest.php`

**Interfaces:**
- Consumes: Tasks 1, 2, 4; `components/*`; the finalize modal partial `pages/courses/features/grades/partials/modals/grade-finalize`.
- Produces: route `GET instructor/grades` → `Grades\GradesController::index`, filter `permission:courses.view`. Query: `tab` ∈ `gradebook` (default) | `to-grade` | `at-risk`; `term`; `section`; To grade filters `qsection`, `type`, `late=1`; At risk `reason`. View data `page = 'grades'` (highlights the tile and loads `resources/js/pages/grades/grades.js`). The Gradebook panel carries `data-grades-grid data-api-base="{site_url('instructor/api/courses/'.$id)}" data-section-id="{id}"`; each card is a link `?term=&section={id}` with `data-section-card`. Tile: `'url' => $role === 'instructor' ? site_url('instructor/grades') : null` (student and chairperson stay null until their phases).
- To grade rows: grouped as Task 1's rows, oldest first; label "{code}-{section} · {title}", detail "{n} waiting · oldest {k} days · {Type}" plus " · {late} late" when > 0; **Grade →** href `site_url("instructor/courses/{id}/{slug}/assessments") . '?assessment={aid}&grade=1&return=' . rawurlencode(<this page's path + query with tab=to-grade>)`.

- [ ] **Step 1: Write the failing tests** (RouteTestCase):
  - `testTheTileOpensTheGradesPage` (NavigationHelperTest: instructor url ends `instructor/grades`; student and chairperson null).
  - `testTheGradebookTabShowsEveryCardAndOpensOnTheBusiestSection` (three sections; the selected card is the one with most waiting; summary cards "Waiting to grade", "Ready to finalize", "At risk", "Finalized" with the right numbers).
  - `testASectionTheyDoNotTeachIs404` and `testANonNumericSectionIs404`.
  - `testToGradeListsOldestFirstWithAReturnAddress` (the `return` param decodes to `/instructor/grades?…tab=to-grade…`).
  - `testToGradeFiltersBySectionTypeAndLate`.
  - `testAtRiskShowsReasonsAndFilters`.
  - `testNoSectionsShowsTheEmptyStateAndNoBulkButtons` and `testNoTermsAtAll`.
  - `testWithoutCoursesViewThereIsNoPage` (permission removed → refused, tile hidden).
  - `testFinalizeAndDownloadAllAreHiddenWithoutGradingManage`.
  - `testOpeningThePageWritesNothing` (row counts of every table before/after a GET, except `audit_logs`; no `grading_schemes` row created).
  - `GlobalGradesQueryBudgetTest::testThePageCostsTheSameForThreeOrFifteenSections` (GET `instructor/grades` in each tab; 15 × 50 students).
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/feature/InstructorGradesPageTest.php tests/database/GlobalGradesQueryBudgetTest.php tests/unit/NavigationHelperTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** the controller (`area()` as in `AnnouncementsController`; only `instructor` in this phase), the route, the tile, and the views per spec §2 and §7 and the approved mockup (`.superpowers/brainstorm/…/instructor-workbench.html`): page-head, brand-tint bar (tabs with counts on the left; term `select` with `data-select-link` on the right), four metric cards, "Your sections" row with the two bulk buttons (shown only with `grading.manage` and at least one ready section / one section), horizontally scrolling cards with a readiness dot and its word (colour is never the only signal), the action bar (the card's note; **Finalize** as a `data-open-finalize` button, shown with `grading.manage` and the section not finalized; **Download** menu linking the selected section's `…/grades/report/class?format=xlsx` and `…/grades/report/summary?format=pdf`; **Open in course →** to `instructor/courses/{id}/{slug}/grades`; each card carries `data-note`, `data-slug`, `data-state` so Task 6 can update the bar on a switch), an empty `data-grades-grid` container (filled by Task 6), the finalize modal partial. The page catches load failures into the Announcements-style error card.
- [ ] **Step 4: Run** the command above and `vendor/bin/phpunit --no-coverage tests/feature/ViewAsWritesNothingTest.php tests/feature/PermissionsTakeEffectTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): the Grades page for instructors: sections, To grade and At risk`

---

### Task 6: Switching sections in place, and finalizing from the page

**Files:**
- Create: `resources/js/pages/grades/grades.js`, `resources/js/pages/grades/section-switch.js`
- Test: `tests/js/pages/grades-section-switch.test.js`

**Interfaces:**
- Consumes: `setGradesApiBase`, `loadGradebookRoster`, `flushPendingScores` (Task 3); `bindFinalizeDialog(reload)` from `resources/js/pages/courses/features/grades/finalize/finalize-dialog.js`; Task 5's data attributes.
- Produces: `sectionHref(currentHref: string, sectionId: number): string` (sets `section`, keeps every other parameter) and `switchSection(card: HTMLElement): Promise<void>` in `section-switch.js`.

- [ ] **Step 1: Write the failing tests:** `sectionHref keeps term and tab`; `clicking a card flushes pending scores before loading the next grid` (order of calls: flush, then roster fetch with base `…/api/courses/{id}` and query `embedded=1`); `the selected card gets aria-current and the address changes with replaceState`; `a failed load shows the error state with Try again`.
- [ ] **Step 2: Run** `npx vitest run tests/js/pages/grades-section-switch.test.js` — Expected: FAIL.
- [ ] **Step 3: Implement.** On load: `setGradesApiBase(grid.dataset.apiBase)` and `loadGradebookRoster(null, { container: grid, query: "embedded=1" })`. Card click (with JS on; the link still works without it): prevent default, `await flushPendingScores()`, swap the base, load, move `aria-current`, update the action bar text from the card's `data-note`, `history.replaceState`. Finalize: call `bindFinalizeDialog(() => window.location.reload())` **once** after the first base is set; it reads `base()` when it opens, so it follows each `setGradesApiBase` without re-binding (binding again would stack click handlers). A switch also rewrites the action bar's links and note from the card's data attributes, and hides Finalize when `data-state="finalized"`.
- [ ] **Step 4: Run** — Expected: PASS; then `npm run lint`.
- [ ] **Step 5: Commit** — `feat(grades): switch sections in place and finalize from the Grades page`

---

### Task 7: Grade work leads back to the queue

**Files:**
- Create: `resources/js/shared/grades-return.js`
- Modify: `resources/js/pages/courses/features/assessments/index.js:1110-1160` (`loadGradeWork`)
- Test: `tests/js/pages/grades-return.test.js`

**Interfaces:**
- Produces: `safeReturn(value: string | null, origin: string): string | null` — returns the value only when it resolves to `origin` and its path ends with `/instructor/grades` or `/chairperson/grades` (any query allowed); otherwise null.

- [ ] **Step 1: Write the failing tests:** accepts `/instructor/grades?tab=to-grade&qsection=3`; accepts the same under a sub-folder base (`/mentora/public/instructor/grades`); rejects `https://evil.test/instructor/grades`, `//evil.test/instructor/grades`, `javascript:alert(1)`, `/instructor/courses/3/x/grades`, `null`. Plus a jsdom test: with `?assessment=4&grade=1&return=…` in the address, the pushed state for the next student still has `return`, and `[data-back-to-assessments]` reads "← Back to To grade" and navigates to it.
- [ ] **Step 2: Run** `npx vitest run tests/js/pages/grades-return.test.js` — Expected: FAIL.
- [ ] **Step 3: Implement:** read `return` from `window.location.search`, keep it in the query `loadGradeWork` pushes when `safeReturn` accepts it, and when present relabel the back button and send it there instead of the Assessments list.
- [ ] **Step 4: Run** it, plus `npx vitest run tests/js/pages` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): Grade work opened from the Grades page leads back to the queue`

---

### Task 8: Finalize ready sections, and Download all

**Files:**
- Create: `app/Controllers/Grades/GradesBulkController.php`
- Modify: `app/Services/Courses/Grades/GradeReportService.php` (extract the sheet filling from `writeXlsx`, add the workbook)
- Modify: `app/Config/Routes.php` (instructor group)
- Modify: `resources/js/pages/grades/grades.js` (confirm dialog and result)
- Test: `tests/feature/GradesBulkTest.php`; add to `tests/database/GradeReportServiceTest.php` (create it if absent)

**Interfaces:**
- Consumes: `InstructorGradesOverview::sections` (Task 2), `GradeFinalizationService::readiness/finalize`, `GradeReportService::classReport`, `RolePermissions::allows($user, 'grading.manage', 'instructor')`.
- Produces:
  - `POST instructor/grades/finalize-ready` (body `term`) → JSON `{success: true, done: list<{offering_id, label}>, skipped: list<{label, reason}>}`. For each section the user teaches in that term whose card state is `ready`: refuse with a reason when the offering status is not `active` ("This section is closed."), when `grading.manage` is missing (whole request 403, nothing done), or when `readiness()` now has blockers (its first blocker text); otherwise `finalize()`. `label` = "{code}-{section}".
  - `GET instructor/grades/download?term=` → one XLSX, one sheet per section with a report, titled "{code}-{section}" (unique, ≤ 31 chars), filename `gradebooks-{term name slug}-{Ymd}.xlsx`; one `grades.report_exported` audit entry per section (`kind` `class_gradebook`, `format` `xlsx`, `rows`), as `GradeReportController::audit` writes.
  - `GradeReportService::classWorkbook(list<array> $reports): array{file_path: string, filename: string}`.

- [ ] **Step 1: Write the failing tests:** `testOnlyReadySectionsTheyTeachAreFinalized` (ready, waiting, another instructor's ready section → only the first finalized); `testASectionThatStoppedBeingReadyIsSkippedWithItsReason` (hand in work after building the card list); `testAClosedSectionIsSkipped`; `testWithoutGradingManageNothingIsFinalized` (403); `testWhileViewingAsNothingIsFinalized`; `testDownloadAllHasASheetPerSectionAndAnAuditEach` (open the file with PhpSpreadsheet: sheet titles, header row of the first sheet equals the single-section class sheet's); `testDownloadAllWithNoSectionsRefusesPolitely`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/feature/GradesBulkTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement.** The routes sit in the `instructor` group (so `role:instructor` and `readonly` run); `finalize-ready` also needs `permission:courses.view`. In the page, "Finalize ready sections ({n})" opens `components/confirm` listing the sections; the result is shown in the dialog ("Finalized 2 sections. Skipped 1: IT101-B — 1 waiting to be graded."), then the page reloads.
- [ ] **Step 4: Run** it and `vendor/bin/phpunit --no-coverage tests/feature/GradeReportRoutesTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): finalize every ready section and download every gradebook at once`

---

### Task 9: Finish: look, speed, end-to-end, docs

**Files:**
- Create: `tests/e2e/global-grades.spec.js`, `docs/test-cases/p2-global-grades.md`
- Modify: `docs/test-cases/README.md` (list the new file)

- [ ] **Step 1: Playwright** on the demo data as `instructor1@mentora.test`: TC-GGR-01 open Grades from the Apps menu, the busiest section is selected, switch cards and type a manual score then switch at once, reload and see it kept; TC-GGR-02 To grade → Grade → → "← Back to To grade" lands on the queue with the same filter; TC-GGR-03 Finalize ready sections finalizes the ready one and names the skipped one (leave the data as found: reopen with a reason at the end).
- [ ] **Step 2: Run** `npx playwright test tests/e2e/global-grades.spec.js` — Expected: PASS.
- [ ] **Step 3: Look at it** with the `run` skill at 360, 768 and 1440 px in all four states (loading, empty, error, filled); fix what does not match the mockup and the Announcements shell; take screenshots for the user.
- [ ] **Step 4: Measure the budget**: seed 15 sections × 50 students for one instructor (a throwaway spark command or SQL script in the scratch folder, not committed), then time `instructor/grades` and a card switch in the browser's network panel. Expected: < 1 s and < 0.5 s. Record both numbers in the PR body.
- [ ] **Step 5: Full checks**
  Run: `composer test`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run lint`, `npm test`
  Expected: all green (or only failures that are also on `main`, named in the hand-off).
- [ ] **Step 6: Hand off** the list of commits (Tasks 1–9, plus the earlier ones on this branch: the spec and `.gitignore`, and `chore(defaults): a high teaching load starts at 30 units` for `AcademicDefaults.php` and its three tests) with their messages. Do not commit.
