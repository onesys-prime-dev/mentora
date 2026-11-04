# Global Grades, phase 5: the dean page and the entry points Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The dean gets a read-only Department view of every section with sign-off status and links to the decisions; every role's Grades tile shows what needs them; and the course tabs, dashboards and Monitor pages link to the Grades page.

**Architecture:** The chairperson's program query is generalised to a scope of sub-departments so the dean's department reuses it; the dean page reuses the Program partials and JS read-only. A `GradesBadge` service counts per role and caches 60 s per user; `app-tile` draws the count. Links are small additions to existing views.

**Tech Stack:** CodeIgniter 4.7 + Shield, PHP 8.2, MySQL/MariaDB, vanilla JS (Vite), Tailwind v4, PHPUnit 10, Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-03-global-grades-design.md` §9 (incl. "Phase 5 layout"), §10 (incl. "Phase 5 decisions"), §2–§4, §12. Phases 1–4 are this branch's parents (HEAD `dec65b2`).

## Global Constraints

- Branch `feat/global-grades-dean`, stacked on phase 4 (created, checked out). **No commits by the executor.** Never migrate, write, start, stop or kill the owner's database.
- Keep line endings; `app/Config/Routes.php` is CRLF (Edit tool only). New files LF.
- The dean reaches only sections of their department(s) (`DeanAssignmentModel::departmentIdsOfDean`, active assignments), offerings `active` or `completed`. The dean never edits grades on this page and never signs off or approves here (the strip links to the existing pages).
- Dean page needs `department.monitor` (as dean); strip links need `grades.signoff` / `grades.approve` (as dean); Nudge needs `followups.manage`; Download department needs `department.monitor`.
- Every number from stored rows; never `GradingSchemeService::forOffering`; viewing as someone writes nothing; page query count flat in sections; badges cached 60 s per user and their cost never runs more than once per request.
- Instructor, student, parent and chairperson pages behave exactly as before.
- Copy (exact): dean description "Every section in your department."; picker first option "All programs"; strip "Finalized {f} of {n} · Signed off {s} · Override requests waiting: {o}" with the counts as links ("Signed off" → Sign-off, "Override requests waiting" → Approvals) only when permitted; column "Signed off" values "Yes" / "—"; button "Download department"; course-tab links "All my grades →" / "All grades →" / "All my sections →"; dashboard links "See all"; Monitor links "View grade records →" (to Grades) and "View analytics →" (back); badge "99+" for ≥ 100.

## Review Focus

1. A dean whose department has sections in several programs, choosing "All programs" vs one program: counts in the strip and the table agree (Task 2 test).
2. A badge for a user with nothing to do: no badge drawn, and no badge query on the second page view within 60 s (Task 3 test).
3. A dean of a department with an ended assignment: no sections, an empty state, no error (Task 1 test).
4. The course Grades tab for a chairperson acting in a course, or a dean: no "All my sections →" link that leads to a page they can't open (Task 4 test).
5. Monitor Department → Grades and back keeps the term (Task 4 test).

---

### Task 1: Department sections through a shared program query

**Files:**
- Create: `app/Services/Grades/DeanDepartmentOverview.php`
- Modify: `app/Services/Grades/ChairpersonProgramOverview.php` (extract the term-rows / page / all logic so it takes a list of sub-department ids; the chairperson passes theirs — behaviour unchanged)
- Test: `tests/database/DeanDepartmentOverviewTest.php`; keep `tests/database/ChairpersonProgramOverviewTest.php` green unchanged

**Interfaces:**
- Produces (`DeanDepartmentOverview`): `programs(User $dean): list<array{id: int, name: string}>`; `terms(User $dean)`; `defaultTermId(array $terms, ?int $requested)`; `sections(User $dean, int $termId, ?int $programId, array $opts): array{rows, total, strip: array{finalized: int, signed_off: int, total: int, requests_waiting: int}}` with the chairperson's row shape plus `signed_off: bool` and `program_id: int`; opts as the chairperson's (q, notFinalized, sort incl. `signed_off`, dir, page); `all(User $dean, int $termId, ?int $programId): list<Row>`; `termEnd(int $termId): ?string`. `requests_waiting` = open grade override requests (open_marker = 1) on those sections.

- [ ] **Step 1: Failing tests:** `testOnlyTheDepartmentsSectionsAreListed` (another department's absent; archived absent; ended dean assignment → nothing); `testProgramFilterAndAllPrograms`; `testSignedOffColumnAndStrip`; `testRequestsWaitingCountsOpenOverrideRequests`; `testSortBySignedOff`; `testTheQueryCountDoesNotGrowWithSections` (3 vs 15).
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/database/DeanDepartmentOverviewTest.php tests/database/ChairpersonProgramOverviewTest.php` — Expected: new FAIL, old PASS.
- [ ] **Step 3: Implement** (sub-departments of the dean's departments → the shared query; one batched query each for sign-offs and override requests).
- [ ] **Step 4: Run** it plus `tests/database/GlobalGradesQueryBudgetTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): a dean's department sections through the shared program query`

---

### Task 2: The dean Grades page

**Files:**
- Modify: `app/Controllers/Grades/GradesController.php` (dean area), `app/Views/pages/grades/index.php`, `app/Views/pages/grades/partials/chairperson/program.php` (make the dean variant: Signed off column, no Edit, Download department, strip with links — parametrise, don't copy), `app/Controllers/Grades/GradesBulkController.php` (`downloadDepartment()` reusing the shared workbook helper), `app/Helpers/navigation_helper.php` (dean apps: Grades tile, gated by `department.monitor` like the dean's other tiles), `app/Config/Routes.php` (dean group: `GET grades` with `permission:department.monitor@dean`, `GET grades/download-department` same)
- Modify if needed: `resources/js/pages/grades/grades.js` (bind program.js/nudge.js for the dean page; program.js must never offer an unlock there)
- Test: `tests/feature/DeanGradesPageTest.php`; `tests/feature/DeanDownloadDepartmentTest.php`; add to `tests/unit/NavigationHelperTest.php`, `tests/database/GlobalGradesQueryBudgetTest.php`

**Interfaces:**
- Produces: `GET dean/grades` — query `program`, `sub` (`sections|at-risk`), `term`, `section`, `q`, `nf`, `sort`, `dir`, `page`. Grid base `dean/api/courses/{id}` (the dean's existing read-only course API; check the roster GET route exists there), always `data-readonly="1"` and no `[data-edit-as-chair]`. Nudge posts to `dean/follow-ups` (check the dean's follow-up route and recipients rule; recipients = the section's instructors the dean may message, minus the dean). `section` outside the department → 404.

- [ ] **Step 1: Failing tests:** `testTheTileOpensTheGradesPageForDeans` (hidden without department.monitor); `testTheDepartmentViewShowsStripTableAndReadOnlyGrid`; `testStripLinksOnlyWithTheirPermissions`; `testProgramPickerAndAllPrograms`; `testASectionOutsideTheDepartmentIs404`; `testNoEditControlEver`; `testDepartmentAtRisk`; `testOpeningThePageWritesNothing`; `testViewingAsADeanWritesNothing`; download: `testASheetPerDepartmentSectionAndAnAuditEach`, `testOtherDepartmentsLeftOut`, `testWithoutDepartmentMonitorRefused`; budget `testTheDeanPageCostsTheSameForThreeOrFifteenSections`; chairperson page tests stay green.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/feature/DeanGradesPageTest.php tests/feature/DeanDownloadDepartmentTest.php tests/unit/NavigationHelperTest.php tests/database/GlobalGradesQueryBudgetTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** it plus `tests/feature/ChairpersonGradesPageTest.php tests/feature/ChairpersonDownloadProgramTest.php tests/feature/ViewAsWritesNothingTest.php tests/feature/PermissionsTakeEffectTest.php` and `npx vitest run tests/js/pages` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): the Grades page for deans`

---

### Task 3: Tile badges

**Files:**
- Create: `app/Services/Grades/GradesBadge.php`
- Modify: `app/Views/components/app-tile.php` (optional `badge` key), `app/Helpers/navigation_helper.php` (Grades tile gets `badge` from `GradesBadge::for($user, $role)`)
- Test: `tests/database/GradesBadgeTest.php`; add to `tests/unit/NavigationHelperTest.php` or a feature test rendering the header

**Interfaces:**
- Produces: `GradesBadge::for(User $user, string $role): int` — instructor: waiting to grade over their current-term sections (`PendingGrading`); chairperson: waiting over the sections they teach in their sub-departments (My sections); student: courses of the current term whose "Changed" check is true (`StudentGradesOverview` + `GradesSeen::changed`, viewer = student) — read-only, never `remember`; parent: the same summed over linked children (viewer = parent) while the portal is on; dean: sections ready to sign off (`GradeSignoffService::counts($dean)` — read its shape). Cached 60 s per user and role (`cache()` with a key like `grades-badge-{role}-{userId}`), and memoised for the request. 0 → no badge; ≥ 100 → "99+". The badge is drawn as a small pill on the tile corner with an `aria-label` like "3 waiting" / "2 changed" / "4 ready to sign off" per role.

- [ ] **Step 1: Failing tests:** one per role with known data; `testZeroDrawsNoBadge`; `test99Plus`; `testASecondViewWithinAMinuteRunsNoBadgeQuery` (count `DBQuery` events); `testTheStudentBadgeNeverWritesSeen`; `testViewingAsSomeoneWritesNothing`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/database/GradesBadgeTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** (tests flush the cache between cases).
- [ ] **Step 4: Run** it plus `tests/unit/NavigationHelperTest.php tests/database/DashboardQueryBudgetTest.php tests/database/GlobalGradesQueryBudgetTest.php` and a header/dashboard feature test — Expected: PASS; the dashboard budget may need its expected counts raised by the badge's first-view cost only (say so if so).
- [ ] **Step 5: Commit** — `feat(grades): the Grades tile shows what needs each person`

---

### Task 4: Links into the Grades page

**Files:**
- Modify: the course Grades tab views (`app/Views/pages/courses/features/grades/index.php` and/or its partials — student and instructor variants), `app/Views/pages/parent/course/grades.php`, `app/Views/pages/dashboard/partials/grading-queue.php`, `app/Views/pages/dashboard/partials/at-risk-students.php`, `app/Views/pages/courses/monitor-program.php`, `app/Views/pages/dean/monitor-department.php`, `app/Views/pages/grades/partials/chairperson/program.php` (+ the dean variant) for the "View analytics →" back links
- Test: `tests/feature/GradesEntryLinksTest.php`

**Interfaces:**
- Produces: on the course Grades tab, "All my grades →" for a student (→ `student/grades?term={offering's term}`), "All my sections →" for an instructor acting as instructor in that course (→ `instructor/grades?term=…&section={offering}`), nothing for a chairperson acting in a course they don't teach or for a dean; on the parent course grades tab "All grades →" (→ `parent/children/{id}/grades?term=…`). Dashboard grading-queue "See all" → the role's To grade view (instructor `instructor/grades?tab=to-grade`; chairperson `chairperson/grades?tab=mine&sub=to-grade` when they have My sections, else no link); at-risk card "See all" → instructor `?tab=at-risk`, chairperson Program at risk (`?tab=program&sub=at-risk`). Monitor Program "View grade records →" → `chairperson/grades?tab=program&term={same}`; Monitor Department → `dean/grades?term={same}`; back links "View analytics →" from the Program tab / dean page to the Monitor pages with the term. Links only appear when the target page and tab are permitted.

- [ ] **Step 1: Failing tests:** one per link (presence, exact href with the term, absence when not permitted), incl. Review Focus items 4 and 5.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/feature/GradesEntryLinksTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** it plus the course-tab, dashboard and Monitor feature tests that already exist (find them with `grep -l` on the view names) — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): link the course tabs, dashboards and Monitor pages to the Grades page`

---

### Task 5: Finish: look, end-to-end, docs, checks

**Files:** Create `tests/e2e/global-grades-dean.spec.js`; Modify `docs/test-cases/p2-global-grades.md`

- [ ] **Step 1: Playwright** as the demo dean (see `tests/e2e/dean.spec.js`): TC-GGR-13 open Grades from the Apps menu, pick a program, see the strip and table, open a grid read-only; TC-GGR-14 the strip's Sign-off link opens Sign-off; TC-GGR-15 as instructor1, the Grades tile shows the waiting badge and the course Grades tab's "All my sections →" lands on that section. Leave data as found.
- [ ] **Step 2: Run** `npm run build` then `npx playwright test tests/e2e/global-grades-dean.spec.js` plus the four earlier global-grades specs — Expected: PASS.
- [ ] **Step 3: Screenshots** (throwaway spec, deleted; saved in the plan's git-ignored workspace) at 360 and 1440 px: dean page, a badge on the Apps menu, a course tab link. Fix clear breakage only.
- [ ] **Step 4: Full checks:** `vendor/bin/phpunit --no-coverage` (≈25 min), `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run lint`, `npm test`. Watch inventory/matrix tests that must list new services and routes. If MySQL is down or hangs, report BLOCKED — never start, stop or kill it.
- [ ] **Step 5: Hand off** one commit message for the phase. Do not commit.
