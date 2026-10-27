# Parent Portal, Branch 2: Dashboard and Calendar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A linked parent opens `/parent/dashboard` and sees, for one child at a time, what needs attention, the child's courses with their standing, the next 14 days of deadlines and past terms, plus a read-only calendar page.

**Architecture:** A new `ParentDashboardService` assembles the page by calling the services the student's own pages already use, passing the child as the student (`GradeService::buildStudentGrades`, `AssessmentOverviewService::build`). The child id is part of the address (`parent/children/{child}`) and a `parentchild` route filter answers 404 for any child the parent may not reach. The calendar reuses the calendar page and script in a read-only mode, fed by a subclass of `CalendarEventService` that leaves out the child's private reminders.

**Tech Stack:** CodeIgniter 4 (PHP 8.2), PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`), Vitest (jsdom) for the calendar script.

**Spec:** Appendix A of `2026-10-21-parent-portal-branch-1-access.md` (confirmed 2026-10-21), plus the branch 1 code on `feat/parent-access` (commit `0193214`). This branch is stacked on it.

## Global Constraints

- Branch `feat/parent-dashboard`, stacked on `feat/parent-access`. **No commits** (the user reviews and commits). Branch 3 is not started without approval.
- The parent group stays **GET only**. Nothing a parent does changes data.
- A parent sees only published, student-visible data about a linked child. Never shown: per-item scores, per-assessment scores, submissions, feedback text, discussion content, the child's private calendar reminders. Standing = percentage, band, tag and a "Final"/"So far" chip, from `buildStudentGrades` (published schemes only).
- "At risk" is the gradebook's tag for a running grade below the scheme's passing mark (`GradeService` summary `tag === 'At risk'`). INC and Withdrawn replace it. Excused work is never "missing".
- A foreign, unlinked or unknown child id answers **404**. A switched-off portal shows no child data (branch 1 behaviour, unchanged).
- UI: shared components and tokens only (`docs/ui-guide.md`); loading, empty, error and filled states designed; works at 360 px. **Wireframes E and F are approved before any view is written** (Tasks 3 and 4).
- No new `phpstan-baseline.neon` entries; test class names unique across `tests/unit`, `tests/feature`, `tests/database`.

## Review Focus

- A child enrolled in nothing this term (dashboard must say so, not break).
- A child whose grading scheme is still a draft: no percentage or tag leaks (`is_published` false).
- A dropped/withdrawn course, and a course in a completed term, appear only under "Past terms".
- An assessment the child handed in late is not "missing"; a past-due, never-handed-in one is, once.
- A switched-off portal or foreign child id on the new routes shows no data (404 / portal-off page).
- The child's personal reminders never reach the parent's calendar JSON.
- Two children with the same name; a parent with one child (no switcher shown).

---

## Decisions to confirm with the wireframes

1. **At-risk meaning (corrects Q16's wording).** Q16 said "use `behind_below_percent`". That setting is learning-item progress and belongs to the instructor's roster risk; the grade-based "At risk" a student sees on their own Grades tab comes from the passing mark. The plan uses the **grade-based tag**, so the parent and the student see the same word for the same state. `behind_below_percent` is not used.
2. **Course cards do not link yet** (the course workspace is branch 3). Branch 3 adds the link.
3. **Calendar** keeps the agreed "reuse the existing calendar page" but in a read-only mode (no Add buttons, no reminders, chips not clickable until branch 3).

## Wireframes (approval gate for Tasks 3 and 4)

**E. Dashboard, linked parent, two children**
```
  (icon) Parent                  Showing: [ Ann Lee v ]      [ View calendar ]
  Your children's progress at a glance.
  +--------------------------------------------------------------+
  | Needs attention                                              |
  | (!) At risk in IT101-A: the grade so far is below the        |
  |     passing mark.                                            |
  | (!) Not handed in: Quiz 2, IT102-B, due 3 days ago.          |
  | (ok) Final grade available: IT103-A.                         |
  |   [empty: "Nothing needs attention right now."]              |
  +--------------------------------------------------------------+
  Courses this term
  +---------------------------+ +---------------------------+
  | IT101-A                   | | IT102-B                   |
  | Intro to Computing        | | Web Basics                |
  | T. Tan                    | | R. Cruz                   |
  | 68%   B-   So far         | | 91%   A    Final          |
  | (!) At risk               | | (ok) Passed               |
  +---------------------------+ +---------------------------+
  [empty: "Ann is not enrolled in any course this term."]
  +--------------------------------------------------------------+
  | Coming up in the next 14 days                                |
  | Thu 24 Oct, 5:00 PM  Quiz 3 · IT101-A            Not started |
  | Mon 28 Oct, 9:00 AM  Project · IT102-B           Handed in   |
  |   [empty: "No deadlines in the next 14 days."]               |
  +--------------------------------------------------------------+
  > Past terms (3)   (collapsed; each row: course, term, Dropped /
                      Withdrawn / Completed, final grade if any)
```
- Switcher: a select that navigates to `parent/children/{id}`; hidden with one child. Each tone has an icon and words (never colour alone). Grade with no published scheme shows "Not graded yet" instead of a percentage.

**F. Calendar (read-only)**
```
  < Back to dashboard
  (icon) Ann Lee's calendar                      Month | Week | Day
  [ month grid: deadlines (warning) and announcements (blue) only ]
  Clicking a day lists its items; no "Add" buttons, no reminders,
  chips are not links.
```

---

## File Structure

- Modify `app/Models/Courses/EnrollmentModel.php`: `retrieveStudentCourseOfferings(int $userId, bool $past = false, bool $history = false)`.
- Modify `app/Services/Parents/ParentAccessService.php`: `childUser()`.
- Create `app/Services/Parents/ParentDashboardService.php`, `app/Services/Parents/ParentCalendarEvents.php`.
- Create `app/Filters/ParentChildFilter.php`; modify `app/Config/Filters.php` (alias `parentchild`).
- Modify `app/Controllers/Parents/ParentDashboardController.php`; create `ParentCalendarController.php`.
- Views: rewrite `app/Views/pages/parent/dashboard.php`; partials in `app/Views/pages/parent/partials/` (`attention`, `course-card`, `deadlines`, `past-terms`, `child-switcher`); reuse `pages/calendar/index.php` with a read-only flag.
- Modify `resources/js/pages/calendar/calendar.js` (read-only mode for role `parent`).
- Modify `app/Config/Routes.php`, `app/Helpers/navigation_helper.php` (Calendar tile).
- Tests: `tests/database/{EnrollmentHistoryTest,ParentDashboardServiceTest,ParentCalendarEventsTest}.php`, `tests/feature/{ParentDashboardPageTest,ParentCalendarPageTest}.php`, `tests/js/pages/calendar-read-only.test.js`; extend `ParentAccessServiceTest`, `RouteAccessTest`, `NavigationHelperTest`.
- Docs: `docs/course-access.md` (dashboard and calendar lines).

---

### Task 1: Data access: every enrolment of a child, and the child as a user

**Files:** Modify `EnrollmentModel.php`, `ParentAccessService.php`; Test `tests/database/EnrollmentHistoryTest.php`, extend `ParentAccessServiceTest.php`.

**Interfaces:**
- Produces: `EnrollmentModel::retrieveStudentCourseOfferings(int $userId, bool $past = false, bool $history = false): array` where `$history = true` returns every enrolment of the student (any enrolment status, any offering status) and each row also carries `enrollment_status` and `offering_status`; the default calls are unchanged.
- Produces: `ParentAccessService::childUser(User $parent, int $studentId): ?User` (the child as a Shield `User`, or null when `canViewChild` is false).

- [ ] **Step 1: Write failing tests.** `EnrollmentHistoryTest`: a student enrolled (active offering), dropped (active offering), withdrawn and completed (completed offering) → `history: true` returns all four with the right `enrollment_status`; the default and `past: true` calls return exactly what they did before (assert counts); a student's enrolments never include another student's. `ParentAccessServiceTest`: `childUser` returns the child (id equal) for a linked child and `null` for an unlinked one, another parent's child, `0`, and a deleted student.
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/database/EnrollmentHistoryTest.php tests/database/ParentAccessServiceTest.php` → FAIL (unknown parameter / method).
- [ ] **Step 3: Implement** both. For `$history`, skip the two status filters and select the two status columns (additional column only); `childUser` loads through Shield's `UserModel::findById` after `canViewChild`.
- [ ] **Step 4: Run** the same files plus `tests/database/EnrollmentModelTest*.php` and the student dashboard tests (`DashboardServiceTest`) → PASS.

### Task 2: `ParentDashboardService`

**Files:** Create `app/Services/Parents/ParentDashboardService.php`; Test `tests/database/ParentDashboardServiceTest.php`.

**Interfaces:**
- Consumes: Task 1 methods; `GradeService::buildStudentGrades(User $child, array $offering, ?array $overview)` (offering array needs `id` and `course_id`); `AssessmentOverviewService::build(User, offeringId, courseId)` rows with `student.key`, `student.label`, `due_ts`, `title`.
- Produces: `ParentDashboardService::forChild(User $parent, int $studentId, ?int $now = null): ?array` returning null when the parent may not reach the child, else
  `['child' => ['id', 'name'], 'courses' => list<CourseCard>, 'past' => list<CourseCard>, 'attention' => list<array{kind: 'at_risk'|'missing'|'final', tone: 'warning'|'danger'|'success', text: string, offering_id: int}>, 'deadlines' => list<array{title, course_code, due_ts, status}>]`.
  `CourseCard = ['offering_id', 'course_code', 'section_code', 'course_title', 'instructor_name', 'term_name', 'enrollment_status', 'offering_status', 'percentage' (?float), 'band' (?string), 'tag' (string), 'is_final' (bool), 'graded' (bool)]`.

Rules (each is a test):
- `courses` = enrolment `enrolled` in an `active` offering; everything else (`dropped`, `withdrawn`, `completed`, or offering `completed`) is `past`. The standing of a past card uses the same call.
- Percentage, band and tag only when the summary `is_published`; otherwise `percentage` and `band` are null, `tag` is "Incomplete", `graded` false.
- `attention` `at_risk`: current course with tag `At risk`. `missing`: a Published assessment of a current course whose student key is `overdue` or `missed` (one entry per assessment; excused → none). `final`: summary `is_final` and `finalized_at` within 14 days (course shown once). INC and Withdrawn never produce `at_risk`.
- `deadlines`: assessments with `due_ts` between now and now + 14 days from current courses, soonest first, `status` = the overview label (no score); an assessment already past due is not a deadline.

- [ ] **Step 1: Write failing tests** with `CourseFactory` (offerings, enrolments, assessments) and `GradeFactory` (published scheme, records): `testACurrentCourseCarriesTheStandingTheStudentSees`; `testAnUnpublishedSchemeShowsNoPercentageOrTag`; `testDroppedWithdrawnAndCompletedCoursesArePast`; `testAnAtRiskCourseIsFlaggedAndAPassingOneIsNot`; `testIncAndWithdrawnAreNeverAtRisk`; `testAPastDueAssessmentIsMissingOnceAndAHandedInOneIsNot`; `testAFreshFinalGradeIsAnAttentionItemForFourteenDays`; `testDeadlinesAreTheNextFourteenDaysSoonestFirstWithoutScores`; `testAChildWithNoCoursesGivesEmptyLists`; `testAnUnreachableChildGivesNull`. Pass `$now` in so time is an input.
- [ ] **Step 2: Run** → FAIL (class missing).
- [ ] **Step 3: Implement** with the rules above; no query the student pages do not already make except the Task 1 enrolment list and a `finalized_at` read from `gradebook_records`.
- [ ] **Step 4: Run** the new test file plus `GradeServiceTest*`, `AssessmentOverviewServiceTest` → PASS.

### Task 3: Routes, filter and dashboard page (UI: wireframe E)

**Gate:** wireframe E approved before the views are written.

**Files:** Create `app/Filters/ParentChildFilter.php`; Modify `app/Config/Filters.php`, `app/Config/Routes.php`, `ParentDashboardController.php`, `dashboard.php`; Create partials; Test `tests/feature/ParentDashboardPageTest.php`, extend `RouteAccessTest.php`.

**Interfaces:**
- Produces: filter alias `parentchild` (reads URI segment 3 of `parent/children/{id}/...`; unknown, unlinked, non-numeric or ≤ 0 → `PageNotFoundException`). Routes: `GET parent/children/(:num)` → `ParentDashboardController::child/$1`. `GET parent/dashboard` and `GET parent` show the first child (alphabetical) via the same data.
- Consumes: `ParentDashboardService::forChild`.

- [ ] **Step 1: Write failing tests:** the first child is shown on `/parent/dashboard`; `/parent/children/{id}` shows the named child and the switcher lists both children (absent with one child); another parent's child id, `0`, a student not linked, and a non-numeric id all answer 404 with no child name in the body; a switched-off portal still shows the portal-off page on `/parent/children/{id}`; the page shows each part of the service result (attention text, course card with percentage and tag, deadline, past terms count) and **never** a per-assessment score; empty states for no courses, no attention, no deadlines; other roles redirected; writes refused (404/403/405); `RouteAccessTest` discovers the new routes (its area checks cover logged-out and wrong-role).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** Views follow wireframe E with `components/page-head`, `metric-card`/`status-badge`, `empty-state`; every value escaped; tones carry an icon and words; the switcher is a plain select that navigates (no inline script).
- [ ] **Step 4: Run** `tests/feature/ParentDashboardPageTest.php tests/feature/ParentRoleRoutesTest.php tests/feature/RouteAccessTest.php` → PASS.

### Task 4: Read-only calendar (UI: wireframe F)

**Gate:** wireframe F approved before the view and script change.

**Files:** Create `ParentCalendarEvents.php`, `ParentCalendarController.php`; Modify `Routes.php`, `pages/calendar/index.php`, `resources/js/pages/calendar/calendar.js`; Test `tests/database/ParentCalendarEventsTest.php`, `tests/feature/ParentCalendarPageTest.php`, `tests/js/pages/calendar-read-only.test.js`.

**Interfaces:**
- Produces: `ParentCalendarEvents::forRange(User $child, string $from, string $to): array` (same shape as `CalendarEventService::forRange`, assessments and announcements only, **no reminders**). Routes `GET parent/children/(:num)/calendar` (page) and `GET parent/children/(:num)/api/calendar/events` (JSON), both with `parentchild`. `GET parent/calendar` redirects to the first child's calendar (portal-off and not-linked pages as in branch 1).

- [ ] **Step 1: Write failing tests:** a child's reminder never appears in the events (database and JSON); published assessments and announcements of the child's courses do; a foreign child → 404; the page has `data-role="parent"`, the child's name, `data-api-base` pointing at the parent route; JS (jsdom fixture with `data-role="parent"`): rendering the month and opening a day offers no `Add` action, no `data-calendar-add`, and no reminder form, while role `student` still does.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** `ParentCalendarEvents extends CalendarEventService` overriding `forRange` to omit reminders; in `calendar.js`, role `parent` hides every add/edit/delete action and renders chips without a link; the view passes the parent's API base and omits the reminder legend entry.
- [ ] **Step 4: Run** the three test files plus `npx vitest run tests/js/pages/calendar-read-only.test.js` and the existing calendar tests → PASS.

### Task 5: Menu, docs and verification

**Files:** Modify `navigation_helper.php`, `NavigationHelperTest.php`, `docs/course-access.md`.

- [ ] **Step 1: Update the menu test** so a parent's tiles are Home and Calendar and every tile URL is a registered GET route; watch it fail.
- [ ] **Step 2: Add the Calendar tile** (`parent/calendar`) and update the docs (the dashboard rules, the 14-day window, the calendar's read-only mode).
- [ ] **Step 3: Verify:** the new and touched PHPUnit files; the whole suite (`vendor/bin/phpunit`) reading the failures by name (the six `AiWorkerEndpointsTest` failures are the known local worker-secret ones); `vendor/bin/phpstan analyse`; `npx eslint resources/js/pages/calendar tests/js/pages`; `npx vitest run`. Report real output.

---

## Self-review

- **Spec coverage:** Q4 items 1, 3, 4(announcements via calendar), 5, 6 → Tasks 2 to 4 (announcement posts are shown on the calendar; the Announcements tab itself is branch 3); Q15 dashboard parts and calendar page → Tasks 3 and 4; Q16 rules → Task 2 (with the stated correction); Q20 dropped/past courses → Tasks 1 and 2; Q21 menu → Task 5. Notifications, profile and invitations are branches 4 and 5.
- **Placeholder scan:** none; each step names the tests and signatures.
- **Type consistency:** `forChild`'s `CourseCard` fields are the ones the views and the Task 3 tests read; `childUser` is used only by Tasks 2 to 4.
- **Proportion:** five tasks for one service, one filter, one page and one calendar mode.
