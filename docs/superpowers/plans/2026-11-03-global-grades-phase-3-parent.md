# Global Grades, phase 3: the parent page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A parent opens Grades and sees each linked child's courses, grades, GWA and small breakdowns exactly as the child does, never an item, with a child switcher and a report download for finalized courses.

**Architecture:** Reuse phase 2 whole: `StudentGradesOverview` runs for the child's `User`, the student partials render the page with their links made configurable, and `GradesSeen` stores the parent as viewer. Two new parent routes behind the existing `parentportal`/`parentchild` filters: the page and the breakdown fragment.

**Tech Stack:** CodeIgniter 4.7 + Shield, PHP 8.2, MySQL/MariaDB, vanilla JS (Vite), Tailwind v4, PHPUnit 10, Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-03-global-grades-design.md` §6 (with the phase 3 layout paragraph), §2–§5, §12. Phases 1–2 are this branch's parents (`1a0cb51`, `05c616b`).

## Global Constraints

- Branch `feat/global-grades-parent`, stacked on `feat/global-grades-student` (created, checked out). **No commits by the executor.** Never migrate or write the owner's development database.
- Keep line endings; `app/Config/Routes.php` is CRLF (Edit tool only). New files LF.
- Parent boundary: a parent sees only linked children (`parentchild` filter / `ParentAccessService::childUser`), only while the parent portal is on (`parentportal`), never an item, manual score or assessment; a child who is also staff (`ParentAccessService::readsAsStudent` false) shows no grades, as on the parent course tab.
- Every number from stored rows; never `GradingSchemeService::forOffering`; viewing as someone writes nothing (`GradesSeen::remember` already skips).
- The student page and the instructor page must behave exactly as before.
- Query count does not grow with the number of courses.
- Copy (exact): description "Your child's grades in every course."; switcher label "Showing" (existing partial); "Download report"; the rest as the student page.

## Review Focus

1. A parent changing the child id in the address to a child not linked to them: 404 for page and breakdown (Task 1, Task 2 tests).
2. The parent portal switched off for the institution: the page shows the existing portal-off page; the breakdown API answers 403 JSON (Task 1, Task 2 tests).
3. Two parents of one child: each has their own "Changed" memory (Task 2 test).
4. A linked child who is also staff of the institution: the page shows no grade rows, no error (Task 2 test).
5. The student's own page after this change: unchanged links (student course URLs, student breakdown base) (Task 2 regression test).

---

### Task 1: The breakdown for a parent

**Files:**
- Modify: `app/Controllers/Grades/StudentCourseGradesController.php` (add a parent action, or create `ParentCourseGradesController.php` beside it — reuse, don't copy, the scheme check and rendering), `app/Views/pages/grades/partials/student/breakdown.php` (take the "Open course grades →" href from the caller instead of building the student URL), `app/Config/Routes.php` (parent group)
- Test: `tests/feature/ParentGradesBreakdownTest.php`; keep `tests/feature/StudentGradesBreakdownTest.php` green

**Interfaces:**
- Produces: `GET parent/children/(:num)/api/grades/courses/(:num)` with filters `['parentportal', 'parentchild']` → `{success: true, html}` for the child; 404 when the offering is not one the parent may view for that child (`ParentAccessService::canViewOffering`), when the scheme is missing or unpublished (checked with `GradingSchemeModel::findForOffering` first), or when the child is also staff (`readsAsStudent` false). "Open course grades →" goes to `parent/children/{child}/courses/{offering}/{slug}/grades`.

- [ ] **Step 1: Failing tests:** `testAParentSeesTheirChildsBreakdown` (same periods as the child's own); `testAnotherChildIs404`; `testACourseTheChildIsNotInIs404`; `testPortalOffIs403Json`; `testAnUnpublishedSchemeIs404AndCreatesNothing`; `testAChildWhoIsAlsoStaffIs404`; `testTheCourseLinkIsTheParentsView`; in StudentGradesBreakdownTest the student link stays `student/courses/{id}/{slug}/grades`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/feature/ParentGradesBreakdownTest.php tests/feature/StudentGradesBreakdownTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** it plus `tests/feature/ViewAsWritesNothingTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): a child's course breakdown for parents`

---

### Task 2: The parent Grades page

**Files:**
- Modify: `app/Controllers/Grades/GradesController.php` (parent area: `parent/grades` and `parent/children/{id}/grades`), `app/Views/pages/grades/index.php` (parent description; switcher in the page head actions), `app/Views/pages/grades/partials/student/rows.php` (course link, breakdown base and an optional per-row Download report link supplied by the caller), `app/Helpers/navigation_helper.php` (parent apps: a Grades tile after Calendar), `app/Config/Routes.php` (parent group)
- Test: `tests/feature/ParentGradesPageTest.php`; add a parent case to `tests/unit/NavigationHelperTest.php` and `tests/database/GlobalGradesQueryBudgetTest.php`

**Interfaces:**
- Consumes: `StudentGradesOverview` (run with the child's `User` from `ParentAccessService::childUser`), `GradesSeen` (viewer = parent id, student = child id), Task 1's endpoint.
- Produces: `GET parent/grades` (filter `parentportal` behaviour as `ParentCalendarController::index`: portal off → `pages/parent/portal-off`; no children → `pages/parent/not-linked`; else redirect to the first child's grades). `GET parent/children/(:num)/grades` (filters `['parentportal', 'parentchild']`), query `term`. Rows container `data-breakdown-base` = `site_url("parent/children/{child}/api/grades/courses")`; course title link `parent/children/{child}/courses/{offering}/{slug}`; finalized rows add "Download report" → `parent/children/{child}/api/courses/{offering}/grades/report/mine`. The child switcher options point at `parent/children/{id}/grades` (pass the target to the partial or build the options here; the dashboard's switcher must keep pointing at the dashboard). Tile: `['key' => 'grades', 'label' => 'Grades', 'icon' => 'grades', 'url' => site_url('parent/grades')]`, active on this page.

- [ ] **Step 1: Failing tests:** `testParentGradesOpensTheFirstChild`; `testPortalOffAndNotLinkedUseTheExistingPages`; `testThePageShowsTheChildsTermCoursesAndSummary` (same rows as the child's own page); `testTheSwitcherListsChildrenAndOpensTheirGrades` (two children; one child → no switcher); `testAnotherChildIs404`; `testFinalizedRowsOfferTheReport`; `testLinksPointAtTheParentsViews`; `testEachParentHasTheirOwnChangedMemory` (two parents of one child); `testAChildWhoIsAlsoStaffShowsNoGrades`; `testViewingAsAParentWritesNothing`; `testTheStudentPageIsUnchanged` (links and breakdown base still the student's); NavigationHelperTest parent tile; budget `testTheParentPageCostsTheSameForThreeOrFifteenCourses`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --no-coverage tests/feature/ParentGradesPageTest.php tests/unit/NavigationHelperTest.php tests/database/GlobalGradesQueryBudgetTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** — the student rendering path is shared, not copied; the JS from phase 2 works unchanged because it reads `data-breakdown-base`.
- [ ] **Step 4: Run** it plus `tests/feature/StudentGradesPageTest.php tests/feature/InstructorGradesPageTest.php tests/feature/ViewAsWritesNothingTest.php tests/feature/PermissionsTakeEffectTest.php` and `npx vitest run tests/js/pages` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(grades): the Grades page for parents`

---

### Task 3: Finish: look, end-to-end, docs, checks

**Files:** Create `tests/e2e/global-grades-parent.spec.js`; Modify `docs/test-cases/p2-global-grades.md`

- [ ] **Step 1: Playwright** as the demo parent (see `tests/e2e/parent.spec.js` for the account): TC-GGR-07 open Grades from the Apps menu, see the child's term line and rows, expand a course (no items listed), open the parent course view; TC-GGR-08 with two children switch child; TC-GGR-09 portal off (set and restore as `parent.spec.js` does) shows the portal-off page. Leave data as found.
- [ ] **Step 2: Run** `npm run build` then `npx playwright test tests/e2e/global-grades-parent.spec.js` — Expected: PASS.
- [ ] **Step 3: Screenshots** (throwaway spec, deleted; saved in the plan's git-ignored workspace) at 360 and 1440 px: filled with the switcher, a row expanded. Fix clear breakage only.
- [ ] **Step 4: Full checks:** `vendor/bin/phpunit --no-coverage` (≈25 min), `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run lint`, `npm test`.
- [ ] **Step 5: Hand off** one commit message for the phase. Do not commit.
