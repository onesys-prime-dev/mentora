# Parent Portal, Branch 3: The Course Workspace for a Parent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From the dashboard a parent opens one of their child's courses and sees four read-only tabs (Overview, Announcements, Assessments, Grades) in the same workspace the student uses, with nothing the agreed privacy lines exclude.

**Architecture:** The course page shell (`pages/courses/view.php`) is reused with a parent mode: four tabs, parent addresses, a view-only note. The tab scripts are unchanged (they only treat the role `instructor` specially, so `parent` behaves as a student and binds to nothing the fragments leave out). Each tab's endpoint returns `{html}` from a new `ParentCourseTabs` service that reads the child through the student's own services and renders parent-only fragments. A `parentchild` filter (extended to check the offering) and a new `parentportal` filter guard every route.

**Tech Stack:** CodeIgniter 4 (PHP 8.2), PHPUnit 10 (`DatabaseTestCase`, `RouteTestCase`), existing Vitest/ESLint gates (no new JS expected).

**Spec:** Appendix A of `2026-10-21-parent-portal-branch-1-access.md`; branches 1 and 2 on `feat/parent-access` and `feat/parent-dashboard` (this branch is stacked on them).

## Global Constraints

- Branch `feat/parent-course-view`, stacked on `feat/parent-dashboard`. **No commits** (the user reviews and commits). Branch 4 is not started without approval.
- The parent group stays **GET only**: nothing a parent does changes data (a lazily created default grading scheme is never triggered; a course with no scheme shows no standing).
- **Four tabs only**: Overview, Announcements, Assessments, Grades. Modules, Resources, Discussions have **no route** (404), and so do assessment views, quizzes, submissions, feedback and the scheme/roster/report routes of staff.
- **Never shown to a parent**: per-item or per-assessment scores, submissions, instructor feedback, discussion content, announcement replies and reactions, attachments, other students, class averages and counts, the child's activity feed or learning progress.
- A child outside the parent's links, or an offering the child is not enrolled in (any enrolment status counts), answers **404** before any controller. A switched-off portal shows the portal-off page (pages) or **403 JSON** (API) and reads no child data.
- A student who also holds a staff role (child) has no grade or assessment data read, as on the dashboard (the shared services would treat them as staff).
- The grade report PDF is **final-only** and **audited** (`grades.report_exported`, the parent as actor).
- UI: shared components and tokens only (`docs/ui-guide.md`); loading (the existing tab skeletons), empty, error and filled states; works at 360 px. **Wireframes G to K are approved before any view is written** (Tasks 1 to 5).
- No new `phpstan-baseline.neon` entries; test class names unique across `tests/unit`, `tests/feature`, `tests/database`.

## Review Focus

- An offering of a different child or a different institution (guessed id) → 404 on page and API, no data.
- A dropped, withdrawn or finished course opens read-only (ended course).
- A published announcement with replies, reactions and attachments: only subject, text, author and date appear.
- A draft or unpublished assessment is absent; a quiz with a graded score shows "Graded" and never the number.
- A grading scheme still a draft → "Grades are not published yet", no percentage.
- The PDF for a non-final grade → refused with a plain message; for a final grade → a PDF, and an audit row naming the parent and the child.
- `?assessment=5&view`, `?take` or `?roster` typed into the address of a parent tab → the tab's error state, never a quiz or a roster (the endpoints do not exist).

---

## Decisions to confirm with the wireframes

1. **The grade report PDF (needs your decision).** The student's final report has a **"Scores" section listing every item with its score** and a **Points column per category**. Q4 excluded per-item scores but included the PDF. Recommended: the parent's PDF is the same report **without** the Scores section and the Points column (so it matches the Grades tab). The alternative is the student's report exactly as it is (it shows every item score).
2. **Announcements:** the latest 20, pinned first; **no replies, reactions or attachments** (a parent cannot open the student's files); no "load more" in this version.
3. **Assessments and cards have no deep links into a quiz** (there is nothing a parent can open). The dashboard course cards now link to the course; the calendar's announcement chips link to the Announcements tab.

## Wireframes (approval gate for Tasks 1 to 5)

**G. The course page for a parent**
```
  < Back to dashboard
  [banner: IT101 - A  Intro to Computing   First Term 2026-27]
  (lock) View only. You are looking at IT101 A as Ann Lee's parent.
         Nothing here can be changed.
  [Overview] [Announcements] [Assessments] [Grades]
  +--------------------------------------------------------------+
  |  (the tab's content, or its loading skeleton)                |
  +--------------------------------------------------------------+
```
**H. Overview** — course facts only
```
  About this course
  Intro to Computing (IT101 - A) · First Term 2026-27
  Taught by  Tina Tan   ·  tina@school.example
  Schedule   Mon, Wed  9:00 – 10:30 AM · Room 4 · On-site
  [course description]
```
**I. Announcements** — read only
```
  (pin) Pinned  Welcome to the course          Tina Tan · 3 Sep
        [message text]
  Room change                                  Tina Tan · 5 Nov
        [message text]
  [empty: "No announcements yet."]   Showing the 20 most recent.
```
**J. Assessments** — no scores
```
  Quizzes
  Quiz Three     Due Thu 24 Oct, 5:00 PM          [Not started]
  Quiz Two       Due Thu 10 Oct                   [Graded]
  Assignments
  Project        Due Mon 28 Oct                   [Handed in]
  [empty: "No assessments yet."]
```
**K. Grades**
```
  Overall            68%  B-        So far          (!) At risk
  [Download grade report]   (only when the grades are final)
  How the grade is made up
  Quizzes   weight 40%   72%         Exams   weight 60%   65%
  [not published: "Grades are not published yet."]
```
The tab bar, skeletons and error states are the student's own.

---

## File Structure

- Modify `app/Filters/ParentChildFilter.php` (also check the offering); create `app/Filters/ParentPortalFilter.php`; modify `app/Config/Filters.php`, `app/Config/Routes.php`.
- Create `app/Controllers/Parents/ParentCourseController.php`, `app/Services/Parents/ParentCourseTabs.php`.
- Modify `app/Views/pages/courses/view.php` (parent mode), `app/Views/pages/parent/partials/course-card.php` (link), `app/Views/pages/courses/features/grades/reports/student-report.php` (a flag to leave out item scores).
- Create fragments under `app/Views/pages/parent/course/`: `overview.php`, `announcements.php`, `assessments.php`, `grades.php`.
- Modify `app/Services/Parents/ParentCalendarEvents.php` (announcement links).
- Tests: `tests/feature/ParentCoursePageTest.php`, `tests/feature/ParentCourseTabsTest.php`, `tests/feature/ParentGradeReportTest.php`, `tests/database/ParentCourseTabsServiceTest.php`; extend `ParentDashboardPageTest`, `ParentCalendarEventsTest`, `RouteAccessTest`.
- Docs: `docs/course-access.md`.

---

### Task 1: Filters, routes and the course shell (UI: wireframe G)

**Gate:** wireframe G approved before the view change.

**Files:** Modify `ParentChildFilter.php`, `Filters.php`, `Routes.php`, `pages/courses/view.php`, `pages/parent/partials/course-card.php`, `ParentDashboardController.php` (pass the child id to cards); Create `ParentPortalFilter.php`, `ParentCourseController.php`; Test `tests/feature/ParentCoursePageTest.php`, extend `ParentDashboardPageTest.php`.

**Interfaces:**
- Produces: filter alias `parentportal` (off → portal-off page for pages, 403 JSON for any path containing `api`); `parentchild` now also requires, when the path has `courses/{offering}`, `ParentAccessService::canViewOffering(parent, child, offering)`, else 404.
- Produces routes in the `parent` group (all `['filter' => ['parentportal', 'parentchild']]`):
  `GET children/(:num)/courses/(:num)/(:segment)` → `ParentCourseController::redirect/$1/$2/$3` (to the Overview tab);
  `GET children/(:num)/courses/(:num)/(:segment)/(overview|announcements|assessments|grades)` → `ParentCourseController::page/$1/$2/$3/$4`.
- `ParentCourseController::page(int $childId, int $offeringId, string $slug, string $feature): string` renders `pages/courses/view` with `userRole => 'parent'`, `courseUrlPrefix => 'parent/children/{child}'`, `features` = the four, `previousPage` back to the child's dashboard, a view-only note naming the child's parent, and `offeringClosed` as the student's page computes it.

- [ ] **Step 1: Write failing tests:** a linked child's course opens (200) with exactly the four tabs and `parent/children/{id}/api/courses/{offering}` in the page config; the redirect route lands on `.../overview`; each of `modules`, `resources`, `discussions` answers 404; another child's offering, an unenrolled offering, a non-numeric id answer 404 with no course title; a dropped course and a completed-term course open; a switched-off portal shows the portal-off page and no course data; other roles are turned away; writes refused; the dashboard course cards link to the course (`parent/children/{id}/courses/{offering}/{slug}`).
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/feature/ParentCoursePageTest.php tests/feature/ParentDashboardPageTest.php` → FAIL.
- [ ] **Step 3: Implement** the filters, routes and controller; in `view.php` let `$userRole`, `$features` and the back link be overridden by the controller's variables (`courseRole`, `courseFeatures`, `backUrl`), unchanged for every other role.
- [ ] **Step 4: Run** the two files plus `CourseControllerTest*`, `CourseMembershipTest`, `RouteAccessTest` (the new routes join its area checks) → PASS.

### Task 2: Overview tab (UI: wireframe H)

**Files:** Create `ParentCourseTabs.php` (first method), `pages/parent/course/overview.php`; Modify `Routes.php`, `ParentCourseController.php`; Test `tests/database/ParentCourseTabsServiceTest.php`, `tests/feature/ParentCourseTabsTest.php`.

**Interfaces:**
- Produces: `ParentCourseTabs::overview(int $offeringId): array{html: string}` using `CourseOfferingModel::getCourseOverviewData` and `CourseScheduleModel` (blocks as `OverviewService::scheduleBlocks` builds them); the instructor name and email; the description; the term.
- Route: `GET children/(:num)/api/courses/(:num)/overview` → `ParentCourseController::overview/$1/$2` (filters as Task 1).

- [ ] **Step 1: Write failing tests:** the fragment has the course title and code, the term, the instructor's name, the schedule (days, time, room, mode) and the description; it has **no** "Continue", no link containing `/modules`, no activity feed, no class size; a course with no instructor and no schedule says "Not scheduled yet" instead of breaking; the JSON endpoint returns `{html}` for the child's course and 404 for a foreign offering.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** Read only: no call to `recalculate`, no scheme creation.
- [ ] **Step 4: Run** the two new files → PASS.

### Task 3: Announcements tab (UI: wireframe I)

**Files:** extend `ParentCourseTabs.php`; Create `pages/parent/course/announcements.php`; Modify `Routes.php`, `ParentCourseController.php`; extend the two test files.

**Interfaces:**
- Produces: `ParentCourseTabs::announcements(int $offeringId): array{html: string}`: `AnnouncementModel::retrieveForOffering($offeringId, ['limit' => 20])` (pinned first), `render_announcement_message()` for the text, author name and date, `data-announcement-id` on each card (so the calendar's deep link can scroll to it).
- Route: `GET children/(:num)/api/courses/(:num)/announcements` (the optional `focus` query is accepted and ignored).

- [ ] **Step 1: Write failing tests:** subject, text, author and date appear; pinned first; a post with a reply, a reaction and an attachment shows **none** of those (assert the reply text and the file name are absent, and no `data-react`, no composer, no reply form); more than 20 posts show 20 and the "Showing the 20 most recent." note; empty state; another course's posts never appear.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement. Step 4: Run** → PASS.

### Task 4: Assessments tab (UI: wireframe J)

**Files:** extend `ParentCourseTabs.php`; Create `pages/parent/course/assessments.php`; Modify `Routes.php`, `ParentCourseController.php`; extend tests.

**Interfaces:**
- Produces: `ParentCourseTabs::assessments(User $child, int $offeringId): array{html: string}` from `AssessmentOverviewService::build($child, $offeringId, $courseId)` rows grouped by `assessment_type`, each reduced to `title`, `due` label and the status label (`student.label`); never `score`, `result`, `attempt` or any link.
- Route: `GET children/(:num)/api/courses/(:num)/assessments` (and **no** `/view`, `/take`, submission or feedback routes). A child holding a staff role gets an empty list.

- [ ] **Step 1: Write failing tests:** published assessments are listed by type with due label and status; a draft one is absent; a graded quiz with score 7.37 shows "Graded" and the page does not contain `7.37`; no anchor or button leads to a quiz (`/take`, `/view`); a missing/overdue one reads as such; empty state; a staff-role child gets the empty state; `GET .../assessments/5/view` and `.../take` answer 404.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement. Step 4: Run** → PASS.

### Task 5: Grades tab and the grade report (UI: wireframe K)

**Gate:** decision 1 (the PDF contents) is settled and wireframe K approved before this task.

**Files:** extend `ParentCourseTabs.php`; Create `pages/parent/course/grades.php`; Modify `student-report.php`, `Routes.php`, `ParentCourseController.php`; Test `tests/feature/ParentGradeReportTest.php`, extend tabs tests.

**Interfaces:**
- Produces: `ParentCourseTabs::grades(User $child, array $offering): array{html: string}` from `GradeService::buildStudentGrades` (summary and `categoryBreakdown`/`periodBreakdown`): overall percentage, band, Final/So far, tag, the per-category weight and percentage (no points), a "Download grade report" button (same `data-report-path="mine"` hook the student's page uses) **only when `is_final`**. `manualItems` and the assessment list are never passed to the view.
- Route: `GET children/(:num)/api/courses/(:num)/grades` and `GET children/(:num)/api/courses/(:num)/grades/report/mine` → `ParentCourseController::report/$1/$2`: `GradeReportService::studentReport($offering, $child, true)`; the parent variant of `student-report` (a `showItems` flag, default true, false here) via the same PDF writer; audit `grades.report_exported` (`kind` student_report, `student_id` the child); not final → 404 JSON with a plain message.

- [ ] **Step 1: Write failing tests:** percentage, band, tag and the Final/So far chip appear; categories show name, weight and percentage and **no** "pts"; no manual-item names, no assessment titles, no `7.37`; a draft scheme → "Grades are not published yet" and no percentage; INC/Withdrawn replace At risk; the download button only when final; the report: not final → 404 with the message; final → `application/pdf`, an audit row for the parent naming the child, and the PDF text (extracted with `PdfTextExtractor`) contains no "Scores" heading and no item name; a non-linked child → 404; a staff-role child → no standing.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement. Step 4: Run** → PASS.

### Task 6: Calendar links, docs and verification

**Files:** Modify `ParentCalendarEvents.php`, `ParentCalendarEventsTest.php`, `docs/course-access.md`.

- [ ] **Step 1: Update the test** so an announcement event now carries `url` = `parent/children/{child}/courses/{offering}/{slug}/announcements?announcement={id}`; watch it fail.
- [ ] **Step 2: Implement** the link; update the docs (parent course tabs, what is never shown, the report rule).
- [ ] **Step 3: Verify:** the new and touched PHPUnit files; the whole suite (`vendor/bin/phpunit`), reading failures by name (the six `AiWorkerEndpointsTest` ones are the known worker-secret ones); `vendor/bin/phpstan analyse`; `npx eslint resources/js tests/js`; `npx vitest run`. Report real output.

---

## Self-review

- **Spec coverage:** Q12 (four tabs, others refused) → Task 1; Q13 (child in the route, parent access check, GET only) → Task 1; Q14 (assessment status only; grades overall, tag, band, category percentages, final-only PDF) → Tasks 4 and 5; Q4 items 4 and 6 → Tasks 2 and 3; Q10 (PDF audited) → Task 5; Q9/Q20 (ended courses stay visible) → Task 1 tests. Notifications, the profile and invitations are branches 4 and 5.
- **Placeholder scan:** none; each step names tests and signatures. The one open item is decision 1 and it gates Task 5.
- **Type consistency:** `ParentCourseTabs` methods take the child as `User` where the shared services need one; the controller loads it once with `ParentAccessService::childUser`.
- **Proportion:** six tasks, one service, four fragments, two small filters.
