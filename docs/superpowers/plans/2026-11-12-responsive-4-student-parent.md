# Responsive Phase 4: Student and Parent Screens. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every screen a student or a parent uses work from 280px to 2560px: dashboards that become tabs on
phones, the course list and Course Workspace, module and reading pages with a "Contents" dropdown, quiz taking,
calendar and schedule, grades, messages with a list-to-thread push, notifications, announcements, and the parent
portal.

**Architecture:** Phase 3's components do most of the work; this phase applies them page by page and adds three
small things: a phone-tabs component (`components/phone-tabs.php` + `shared/phone-tabs.js`) for dashboards, a
"Contents" dropdown for the module outline, and a phone navigation module for Messages
(`pages/messages/phone-nav.js`). Pages keep their desktop layout from `lg` up; changes are `max-lg:` or
container-query rules, so instructor, chairperson and dean users of the shared views see no difference.

**Tech Stack:** CodeIgniter 4 views, Tailwind v4.3, vanilla ES modules, Vitest + jsdom, PHPUnit (CIUnit feature
tests), Playwright + axe.

**Spec:** `docs/superpowers/specs/2026-11-09-responsive-ui-design.md` (§6 dashboards, §7 split panes: Messages and
Module/reading, §8 forms, §9 motion, §10 type and spacing, §11 edge conditions, §12 phase 4)

## Global Constraints

- Prerequisite: Phase 3 is committed (`feat/responsive-3-components` at 927f8c88). Branch
  `feat/responsive-4-student-parent` off it. **Do not commit.** Each task ends by handing over a commit message.
- No new npm or composer dependencies.
- Brand colour only through tokens; dark mode uses the existing `--dk-*` mapping. Every new surface looks right
  under `html[data-theme="dark"]`. The dark mapping covers plain classes such as `.bg-white`, not
  responsive-prefixed variants: an opaque base class has to be there.
- Touch targets ≥44×44 under `pointer: coarse` (`pointer-coarse:min-h-11 pointer-coarse:min-w-11`); phone inputs
  ≥16px (`text-base`, may drop to `sm:text-sm`); correct `inputmode` / `autocomplete`.
- Motion uses the Phase 1 tokens (`--motion-fast/base/slow`, `--ease-spring`, `--ease-exit`); reduced motion
  means 0ms. Hover effects only under `(hover: hover)`. No scroll-reveal animations.
- Every `view()` of a component or partial passes `['saveData' => false]`.
- Shared views stay shared: a change that is only for phones or narrow containers uses `max-lg:`, `max-sm:` or
  container queries. Do not change what an instructor, chairperson or dean sees at `lg` and above. Their phone
  layouts belong to phases 5 and 6; where a shared view is changed here for everyone, say so in the task report.
- Keep every existing hook. Notably: `[data-module]`, `[data-module-content]`, `[data-learning-item]`,
  `[data-learning-unit]`, `[data-learning-item-footer]`, `[data-course-feature]`, `[data-submit-quiz]`,
  `[data-calendar-*]`, `#messages-app[data-view]`, `[data-pane]`, `[data-role=*]` in Messages,
  `[data-notifications-page]`, `[data-disclosure]`, `[data-select-link]`, `[data-auto-search]`. Existing Vitest,
  PHPUnit and e2e specs must not be edited except where a task says an assertion changes.
- Breakpoints: `sm` 40rem, `md` 48rem, `lg` 64rem, `xl` 80rem, `xs` 25rem, `3xl` 120rem; `lock:` = ≥80rem wide and
  ≥47.5rem tall. Fluid type: `text-fluid-h1`, `text-fluid-h2`, `text-fluid-metric`; fluid spacing: `px-gutter`,
  `gap-fluid`.
- Hand-written list tables move onto `components/data-table.php`; grid tables get `[data-scroll-x]`. A data-table
  column that holds a live control needs a `role` or an edit path (Phase 3 carry-over).
- MySQL on this machine is killed under memory pressure: run DB-backed batches one at a time, check
  `mysqladmin ping` first, never run `npm run build` during PHPUnit feature tests or Playwright, and ask the user
  before restarting MySQL.
- Each task that adds a screen adds its row to `SCREENS` in `tests/e2e/responsive.spec.js` (Task 2 teaches the
  harness a `path` that is a function). Failures in untouched code that also happen on `main` (TC-DEAN-03,
  TC-DEAN-04, TC-GGR-04, TC-PRT-02, TC-MSG-06, TC-MSG-08) are not this phase's.

## Review Focus

1. **A student with nothing to show (no tasks, no courses, no active term, no announcements):** the phone tabs
   still render, each panel shows its empty copy, no tab has a dot, nothing overflows. Task 2's PHPUnit and e2e.
2. **Long unbreakable text (a 40-character course title, a long item title, a long thread name) at 320px:** it
   wraps or truncates inside its card and never widens the page. Tasks 3, 5 and 10's sweep rows.
3. **Messages opened by deep link (`?open=`) on a phone, and the browser Back button:** Back from a thread
   returns to the list; Back from a thread opened by deep link also returns to the list, not out of the app.
   Task 10's Vitest and e2e.
4. **The soft keyboard open on a quiz question (or the Messages composer):** the focused field is not covered by
   the sticky submit bar or the composer. Tasks 7 and 10's e2e.
5. **A reading item with a wide table, a long code line or an oversized image:** only the content scrolls
   sideways; the page does not. Task 6's PHPUnit.

---

### Task 1: Phone tabs component

**Files:**
- Create: `app/Views/components/phone-tabs.php`, `resources/js/shared/phone-tabs.js`
- Create: `tests/feature/PhoneTabsComponentTest.php`, `tests/js/shared/phone-tabs.test.js`
- Modify: `resources/js/app.js` (import and call `bindPhoneTabs()`), `resources/css/app.css`

**Interfaces:**
- Produces:
  - Component `components/phone-tabs` params: `label` (string, the tablist's aria-label), `tabs` (list of
    `['key' => string, 'label' => string, 'dot' => bool]`). Renders
    `<div data-phone-tabs class="lg:hidden …"><div role="tablist" aria-label="…">` with one
    `<button role="tab" data-phone-tab="{key}" aria-selected tabindex>` per tab; the first is selected, the others
    have `tabindex="-1"`. A `dot` adds `<span data-phone-tab-dot aria-hidden="true">` (red, 8px) and an
    `sr-only` " (new)" in the label. Tabs share the width equally, `min-h-11`, active tab `bg-brand text-white`.
    The bar is `sticky top-[var(--app-header-h,0px)] z-20` with an opaque background.
  - Panel contract (the page writes it): any element with `data-phone-panel="{key}"`; the first tab's panels also
    carry `data-phone-active`. CSS in `app.css`: below `lg`, `[data-phone-panel]:not([data-phone-active])` is
    `display:none`; from `lg` up nothing is hidden. Without JavaScript the first tab shows. Panels have no
    `aria-controls` link (a tab can own several panels).
  - `bindPhoneTabs(root = document): void` in `shared/phone-tabs.js`. Click, ArrowLeft/ArrowRight (wrapping),
    Home and End select a tab: `aria-selected`, roving `tabindex`, and `data-phone-active` moves to that key's
    panels. Binds each `[data-phone-tabs]` once.

- [ ] **Step 1: Write the failing tests.**
  - Vitest `phone-tabs.test.js` (jsdom): `click selects a tab and moves data-phone-active`, `arrow keys wrap and
    move focus`, `Home and End jump to the first and last`, `a tab with no panel does nothing`, `binding twice
    does not double-handle`.
  - PHPUnit `PhoneTabsComponentTest`: three tabs render with the first `aria-selected="true"`; `dot` renders the
    dot and the sr-only text; the root has `lg:hidden`; the others have `tabindex="-1"`.
- [ ] **Step 2: Run them and see them fail.** `npx vitest run tests/js/shared/phone-tabs.test.js`;
  `vendor/bin/phpunit tests/feature/PhoneTabsComponentTest.php`.
- [ ] **Step 3: Implement** the component, the module (wired in `app.js` next to `bindScrollX`) and the CSS. The
  spring/indicator is optional; a `--motion-fast` colour transition is enough, with `active:scale-[0.98]`
  press feedback under `pointer-coarse`.
- [ ] **Step 4: Run the tests and `npm run lint`; they pass.**
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): phone tabs component for dashboards`.

---

### Task 2: Student dashboard

**Files:**
- Modify: `app/Views/pages/dashboard/index.php`, and under `app/Views/pages/dashboard/partials/`:
  `upcoming-tasks.php`, `my-courses.php`, `announcements.php`, `reminders.php`, `key-metrics.php`,
  `profile-card.php`, `quick-access.php`, `college-announcements.php`
- Modify: `tests/e2e/responsive.spec.js` (SCREENS `path` may be `string | (page) => Promise<string>`; the `open()`
  helper awaits it)
- Test: the existing dashboard feature test (find it with `grep -rl "DashboardController\|dashboard" tests/feature`),
  or create `tests/feature/StudentDashboardTabsTest.php`

**Interfaces:**
- Consumes: Task 1's component and panel contract.
- Produces:
  - Student phone tabs, in this order: **Today** (`key-metrics`, `upcoming-tasks`, `reminders`), **Courses**
    (`profile-card`, `my-courses`, `quick-access`), **Updates** (`college-announcements`, `announcements`). From
    `lg` up the 12-column grid is unchanged. Today's `dot` is true when a task is overdue or due today; Updates
    gets a dot only if the widget data already says something is unseen (otherwise none).
  - Only the `student` layout gets tabs; instructor and chairperson dashboards are untouched (phases 5, 6). The
    `$layouts` map gains a per-slug `'phoneTab'` key; the tabs render only when the role's layout has one.
  - Lists cap at 5 below `lg` (`max-lg:hidden` on the 6th row onward) with a "View all" link that is `lg:hidden`:
    tasks → the calendar, courses → the courses page, announcements → the announcements page.
  - The page header row wraps: `text-fluid-h1` for the A.Y line, the search full-width below `sm`. Widget card
    padding `px-4 sm:px-6`.

- [ ] **Step 1: Write the failing tests.**
  - PHPUnit: the student dashboard contains `data-phone-tabs` with the tabs Today, Courses, Updates; every widget
    sits in the panel above; the first tab's panels have `data-phone-active`. The instructor dashboard has no
    `data-phone-tabs`. With a student who has no tasks, courses or term (Review Focus 1): the tablist renders, no
    `data-phone-tab-dot`, and each panel holds its empty-state copy.
  - Playwright, in a new `describe("student")` block: at 375 as `student`, only the Today panels are visible;
    clicking Courses shows `my-courses` and hides the task list; with more than 5 tasks, only 5 rows show and
    "View all" is visible. At 1366 the tablist is hidden and all widgets show.
  - A `SCREENS` row `student-dashboard` (`path: "/student/dashboard"`, `as: "student"`, scope `main`).
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement** the layout map change, the panel wrappers, the caps and the header row. Each widget's
  empty state stays as it is. If the identity column wrapper leaves a visible gap when all its children are
  hidden, make the wrapper `max-lg:contents`.
- [ ] **Step 4: Run** the PHPUnit file, then `npx playwright test responsive.spec.js -g "student-dashboard|student"
  --project=chromium`. They pass, axe included, light and dark (`RESPONSIVE_FULL=1`).
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): student dashboard becomes Today/Courses/Updates tabs on phones`.

---

### Task 3: Courses list and course cards

**Files:**
- Modify: `app/Views/pages/courses/index.php`, `app/Views/pages/courses/partials/course-card.php`,
  `app/Views/pages/parent/partials/course-card.php`
- Test: the existing courses-page feature test (grep `courses/index` / `CourseController`) and
  `tests/e2e/responsive.spec.js`

**Interfaces:**
- Produces: the course grid and the past-courses grid sit in an `@container`; columns follow the container's
  width (`grid-cols-1 @md:grid-cols-2 @3xl:grid-cols-3 @5xl:grid-cols-4`), not the viewport, so the list is 4-up
  on a wide screen and 2-up when a menu or panel narrows it. Heading `text-fluid-h1`. The cover is `h-28 xs:h-32`.
  The parent card follows the same rules. Cards keep the title clamp to two lines and `break-words`.

- [ ] **Step 1: Write the failing tests.** PHPUnit: the list has the `@container` and the container-query column
  classes, and no `sm:grid-cols-2` / `xl:grid-cols-3` left. Playwright: a `student-courses` row in `SCREENS`;
  and a test that at 1366 the grid has four columns when the shell is locked and three when it isn't (read the
  first row's card count), and at 375 one column. A seeded course with a very long title (Review Focus 2): create
  one with `runSql` in the test, then assert no overflow at 320.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the PHPUnit file and `npx playwright test responsive.spec.js -g "student-courses" --project=chromium`.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): courses grid follows its container, fluid course cards`.

---

### Task 4: Course Workspace frame and the student feature pages

**Files:**
- Modify: `app/Views/pages/courses/view.php`, `resources/js/shared/scroll-x.js`,
  `tests/js/shared/scroll-x.test.js`
- Modify (padding and layout sweep; the files are found with
  `grep -rln "p-10\|px-10\|py-10\|gap-12" app/Views/pages/courses/features/{overview,announcements,discussions,resources}`):
  the overview partials (`continue-learning`, `needs-attention`, `progress`, `upcoming-deadlines`,
  `recent-activity`, `course-info`, `metrics`), the announcements index and `announcement-card`, the discussions
  index, `thread` and `thread-body`, the resources index and partials, and the student side of
  `features/grades/index.php`
- Test: `tests/e2e/responsive.spec.js`

**Interfaces:**
- Produces:
  - The course feature tab strip (`[role=tablist]` in `view.php`) becomes `data-scroll-x data-scroll-current`
    with the edge fades; `bindScrollX`'s "current" lookup accepts `[aria-current="page"], [aria-selected="true"]`.
    Selecting a tab keeps it in view (the feature loader's `highlightFeatureTab` re-measures: call `bindScrollX`'s
    centring again or reuse its export; do not duplicate the maths).
  - Student feature pages use fluid gutters in place of fixed `p-10` / `px-10`: `p-5 sm:p-8 lg:p-10` and
    `px-gutter` where the card is full width. Two-column layouts (`lg:flex-row`) stay as they are; below `lg`
    they stack with the main content first.
  - This phase owns the shared frame (banner, tab strip, `#course-content` card) because students are its main
    users; Phase 5 checks the instructor-only parts.

- [ ] **Step 1: Write the failing tests.** Vitest: `scroll-x centres an aria-selected tab` in `scroll-x.test.js`.
  Playwright: `SCREENS` rows `student-course-overview` and `student-course-announcements` (a `path` function that
  reads the first course card's href from `/student/courses`, then appends `/overview` or `/announcements`), and a
  `describe("student")` test: at 375 the tab strip scrolls, the active tab is fully inside the viewport after
  switching to the last tab, and the sticky banner collapses on scroll without covering the tab strip.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement** the strip, the selector change and the padding sweep.
- [ ] **Step 4: Run** `npx vitest run tests/js/shared/scroll-x.test.js` and the Playwright rows; then
  `tests/e2e/modules.spec.js tests/e2e/announcements.spec.js` once, to check nothing regressed.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): course workspace tab strip scrolls, fluid gutters on the student feature pages`.

---

### Task 5: Module list, learning item view and the Contents dropdown

**Files:**
- Modify: `app/Views/pages/courses/features/modules/learning-item-view.php`,
  `partials/learning-item-panel.php`, `partials/navigations.php`, `partials/breadcrumb.php` (only if it needs a
  class), `index.php`, `module.php`, `learning-item-editor.php` (it includes the same panel)
- Test: `tests/feature/` (grep the existing modules view test), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Produces:
  - Below `lg` the outline `<aside>` is a **Contents** control at the top of the item's main column: a
    `<details data-menu>` (the existing `details-menu.js` closes it on outside click and Escape) whose
    `<summary>` reads "Contents" with the module title and a chevron, and whose panel is the existing outline,
    styled like the header panels (white, `rounded-2xl`, `shadow-xl`, `max-h-[min(70dvh,32rem)] overflow-y-auto`,
    enters with `--motion-base` and `--ease-spring`). From `lg` up the aside is the sidebar it is today
    (`lg:w-80`). The item layout is `flex-col lg:flex-row`; the content column is `min-w-0 flex-1`.
  - `navigations.php` (previous/next/Mark as completed): below `lg` the footer is `sticky bottom-0` with an opaque
    background, `pb-safe-2` and 44px buttons, and a full-width Continue. The Previous/Continue disabled and lock
    hint states are kept.
  - `p-10` / `px-10` / `gap-12` in `module.php`, `index.php` and the item view become `p-5 sm:p-8 lg:p-10`
    (`gap-5 xl:gap-12`). The module card's "Module details" label and 60px status icon shrink below `sm`; the
    top bar of `module.php` wraps its actions.
  - A long module or item title (Review Focus 2) truncates in the Contents summary and wraps in the heading.

- [ ] **Step 1: Write the failing tests.** PHPUnit: the item view renders the outline once inside
  `details[data-menu]` with `lg:` classes that make it a sidebar; the footer has the sticky classes. Playwright:
  `SCREENS` row `student-module-item` (path function: open the first course's Modules tab, open the first item;
  reuse `support/course.js`); a test that at 375 the content column is wider than 300px (the defect that hid the
  breadcrumb), Contents opens, lists the units, and choosing an item closes it and loads that item; at 1366 the
  sidebar shows with no Contents control.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.** Check `learning-item-editor.php` at 1366 still shows the sidebar.
- [ ] **Step 4: Run** the new tests, then `tests/e2e/modules.spec.js tests/e2e/module-dependencies.spec.js
  tests/e2e/module-publish.spec.js` one file at a time.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): module outline becomes a Contents dropdown on phones, sticky prev/next, fluid module pages`.

---

### Task 6: Learning item content (reading, video, PDF, link, assignment)

**Files:**
- Modify: `app/Views/pages/courses/features/modules/learning-items/views/{reading,video,pdf,link,assignment,quiz,discussion,locked}.php`,
  `partials/learning-item-header.php`, `partials/detail-panel.php`, and `resources/css/app.css` for a
  `.reading-content` rule if the reading markup has no wrapper class
- Test: `tests/feature/` (grep the existing reading-view test), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Produces:
  - Reading content (instructor-authored HTML) is contained: images `max-w-full h-auto`, tables and `<pre>` scroll
    inside their own box (`overflow-x-auto`), embedded `<iframe>` and `<video>` `max-w-full`, long words
    `break-words`. Body text `text-base` with a readable measure from `md` up (`max-w-prose` or the existing
    width).
  - The PDF viewer area is `min-h-[60dvh]` below `lg` and keeps its toolbar reachable (check
    `resources/js/shared/pdf` for fixed widths; do not change its behaviour).
  - The side detail panel of assignment and quiz items (`detail-panel.php`) goes under the main column below `xl`.
  - Header (`learning-item-header.php`): title `text-fluid-h2`, meta wraps, status badge stays visible.

- [ ] **Step 1: Write the failing tests.** PHPUnit: a reading item whose HTML holds a `<table>`, a long `<pre>`
  line and an `<img>` renders inside the contained wrapper (Review Focus 5); the detail panel has the `xl:`
  side-by-side classes only. Playwright: `SCREENS` rows `student-item-reading` and `student-item-pdf` if the seed
  has them (otherwise only the reading one and say so); after the page loads, no overflow at 320, 375, 834.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests and `tests/e2e/modules.spec.js`.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): contained reading content, phone-sized PDF area, stacked detail panel`.

---

### Task 7: Assessments (student) and quiz taking

**Files:**
- Modify: `app/Views/pages/courses/features/assessments/{index,view,quiz-answer-form}.php`,
  `partials/overview/{assessment-card,sidebar-student,metrics}.php`, `partials/{view-header,attempt-history,breadcrumb,navigations}.php`,
  `assessment-types/views/{quiz,assignment}.php`, `partials/assignment-work.php`
- Test: `tests/feature/` (grep the existing quiz-form test), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Produces:
  - Assessment list: `lg:flex-row` stays; below `lg` the sidebar stacks under the list. Filters wrap; the
    assessment card's due/status line wraps.
  - `quiz-answer-form.php`: `p-10` → `p-5 sm:p-8 lg:p-10`; the sticky top bar truncates the quiz title and keeps
    the back button, timer and progress visible; every choice label is a `min-h-11` row with the full row
    clickable; text answers use `text-base`; the submit bar is `sticky bottom-0`, opaque, `pb-safe-2`, full-width
    button below `sm`; the form has `scroll-pb-24` so a focused field scrolls clear of the bar (Review Focus 4).
  - The read-only attempts `<table>` in the "view as student" landing becomes `[data-scroll-x]`.
  - The fullscreen lock, leave guard and password gate keep working unchanged.

- [ ] **Step 1: Write the failing tests.** PHPUnit: the choice labels carry `min-h-11`, the submit bar has the
  sticky classes, the attempts table sits in `[data-scroll-x]`. Playwright: `SCREENS` row
  `student-assessments`; a `describe("student")` test at 375 that starts a quiz (mirror the start-an-attempt steps
  and the database reset used in `tests/e2e/quizzes.spec.js`), focuses the last text answer and asserts its box
  does not intersect the submit bar's box; the Submit button is inside the viewport; no overflow.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests, then `tests/e2e/quizzes.spec.js tests/e2e/quiz-security.spec.js
  tests/e2e/assignments.spec.js` one file at a time.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): student assessments and quiz taking on phones, sticky submit bar clear of the keyboard`.

---

### Task 8: Calendar and schedule

**Files:**
- Modify: `app/Views/pages/calendar/index.php`, `resources/js/pages/calendar/calendar.js`,
  `app/Views/pages/schedule/index.php`
- Test: `tests/js/pages/calendar-phone.test.js` (new), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Produces:
  - Calendar toolbar: wraps in two rows below `md` (navigation and label; then the view switch), label
    `text-fluid-h2`, nav and view buttons `pointer-coarse:min-h-11 min-w-11`; the legend moves into a
    `<details data-menu>` "Key" below `md` (visible inline from `md`).
  - Month view below `md`: a cell shows its date and up to three colour dots (one per event kind, from
    `KIND_META`) instead of title chips; chips return from `md`. `min-h-14 md:min-h-[96px]`. Tapping a cell opens
    the existing date menu; its modal (`#calendar-modal`) becomes a bottom sheet below `sm` (rises from the
    bottom, `max-h-[85dvh]`, safe-area padding). Past days stay unclickable. The year view's mini months use
    `grid-cols-1 sm:grid-cols-2` as they do. Parent, instructor and chairperson calendars share this code and
    keep every `data-calendar-*` hook.
  - `calendar.js` exports `eventDots(events: CalendarEvent[]): string` (the dot markup, at most 3, no duplicates
    of a kind) for the test.
  - Schedule: the week grid sits in a `[data-scroll-x data-sticky-first]` container (hour labels pinned, day
    header row sticky), `min-w-180` stays, and on a phone the page opens scrolled to today's column. The title row
    wraps. The "now" line keeps working.

- [ ] **Step 1: Write the failing tests.** Vitest: `eventDots gives one dot per kind, at most three`, and
  `month cells show no chips below md` (render with a stubbed `matchMedia`/class check). Playwright: `SCREENS`
  rows `student-calendar`, `student-schedule` and `parent-calendar` (path `/parent/calendar`, `as: "parent"`);
  at 375 a cell shows dots and tapping today's cell opens the sheet inside the viewport; a past day does nothing;
  the schedule's grid scrolls inside its container with the first column pinned (`data-fade-end` set) and the
  page itself does not scroll sideways.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests, `npx vitest run tests/js/pages/calendar-*.test.js` (the stale-date
  `calendar-abilities.test.js` failure is not this phase's), and Playwright.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): calendar dots and day sheet on phones, schedule grid with pinned hours`.

---

### Task 9: Grades (student and parent)

**Files:**
- Modify: `app/Views/pages/grades/index.php` (the student-shaped branch only),
  `app/Views/pages/grades/partials/student/{summary,rows,breakdown}.php`,
  `app/Views/pages/parent/course/grades.php`, `app/Views/pages/parent/course/partials/grade-category.php`,
  `app/Views/pages/courses/features/grades/reports/student-report.php`
- Test: `tests/feature/` (grep the existing student grades view test), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Produces:
  - Summary tiles are `@container` cards (`grid-cols-2 @xl:grid-cols-4`), with `text-fluid-metric` for the
    number. Course rows (the `[data-disclosure]` list) keep their markup; the toggle is a 44px target, the course
    title wraps, and the grade chip stays on the first line.
  - The breakdown `<table>` (loaded when a row opens) is a `[data-scroll-x]` grid with a pinned first column; the
    student grade report's table likewise. Parent grade categories use `grid-cols-2 @lg:grid-cols-4`.
  - The term picker is full width below `sm`. The chairperson/dean/instructor branches of `grades/index.php` are
    not touched.

- [ ] **Step 1: Write the failing tests.** PHPUnit: breakdown and report tables are inside `[data-scroll-x]`;
  summary uses the container classes. Playwright: `SCREENS` rows `student-grades` (`/student/grades`) and
  `parent-grades` (`/parent/grades`); a test that expands the first course row at 375 and the breakdown scrolls
  inside its box without page overflow.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests and `tests/e2e/global-grades-student.spec.js tests/e2e/global-grades-parent.spec.js`
  (TC-GGR-04 may fail on `main` too).
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): student and parent grades with container tiles and scrolling breakdown grids`.

---

### Task 10: Messages, notifications and announcements

**Files:**
- Create: `resources/js/pages/messages/phone-nav.js`, `tests/js/pages/messages-phone-nav.test.js`
- Modify: `resources/js/pages/messages/messages.js` (call the module from `openThread` / `closeThread` and the
  back button), `app/Views/pages/messages/index.php`, `app/Views/pages/notifications/index.php`,
  `app/Views/pages/announcements/index.php`, `resources/css/app.css`
- Test: `tests/js/pages/messages-phone-nav.test.js`, `tests/e2e/responsive.spec.js`,
  `tests/e2e/messages.spec.js` (must keep passing)

**Interfaces:**
- Produces:
  - `createPhoneNav({ root, isPhone, onBack }): { opened(id: number): void, closed(): void, destroy(): void }`
    in `phone-nav.js`. On a phone, `opened` pushes one history entry (`history.pushState({ messagesThread: id })`)
    and remembers it; `popstate` calls `onBack`; `closed()` after an in-app back calls `history.back()` only when
    this module pushed the entry (a thread opened by deep link has no entry, so Back from it calls `onBack`
    directly, Review Focus 3). A rightward swipe (≥80px, mostly horizontal, within 600ms, starting outside a text
    field or horizontally scrollable element) on the thread pane calls `onBack`. `isPhone` is `() =>
    matchMedia("(max-width: 47.999rem)").matches`.
  - Messages layout below `md`: the list and thread panes are stacked in one `relative overflow-hidden` frame;
    `#messages-app[data-view]` drives a push: the thread slides in from the right over `--motion-slow` with
    `--ease-exit`, the list shifts left and dims behind it. The hidden pane is `inert` and `aria-hidden` (this
    replaces `group-data-[view=*]:max-md:hidden`); the Phase 1 reduced-motion tokens make it instant. From `md`
    the two-pane grid is unchanged.
  - The frame height is `calc(100dvh - var(--app-header-h, 4.5rem) - 1.25rem)` instead of `100dvh - 8.3rem`
    (same on notifications); the composer textarea is `text-base sm:text-[13px]`, its Send button and the list
    chips are `pointer-coarse:min-h-11`, and the composer has `pb-safe`. The soft keyboard keeps the composer
    visible (`interactive-widget=resizes-content` is already in the viewport meta).
  - Notifications: filter chips `pointer-coarse:min-h-11`, rows full-width targets. Announcements: the level
    filter becomes a `[data-scroll-x]` pill strip; the search field is full width below `sm`.

- [ ] **Step 1: Write the failing tests.**
  - Vitest `messages-phone-nav.test.js`: `opening a thread on a phone pushes one history entry`, `popstate calls
    onBack`, `a thread opened without a push calls onBack directly`, `a rightward swipe calls onBack`,
    `a swipe starting in the composer does not`, `nothing is pushed above md`, `destroy removes the listeners`.
  - Playwright (`describe("student")`, 375, the messages feature enabled via `support/messages.js`): opening a
    conversation shows the thread and `inert` on the list; browser Back returns to the list and keeps the page;
    `?open=` deep link, then Back, also lands on the list; focusing the composer leaves it inside the viewport
    (Review Focus 4); `SCREENS` rows `student-messages` and `student-notifications`, `student-announcements`.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement** the module, wire it, and rewrite the pane classes. All existing `messages-*.test.js`
  files and `tests/e2e/messages.spec.js` keep passing unchanged (TC-MSG-06 and TC-MSG-08 fail on `main` too).
- [ ] **Step 4: Run** the Vitest files (`npx vitest run tests/js/pages/messages-*.test.js
  tests/js/pages/notifications-page.test.js`), then the Playwright rows, then `tests/e2e/messages.spec.js`.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): messages list-to-thread push with Back and swipe, phone-sized composer, notifications and announcements on phones`.

---

### Task 11: Parent screens

**Files:**
- Modify: `app/Views/pages/parent/dashboard.php`, `parent/partials/{attention,child-switcher,course-card,deadlines,past-terms}.php`,
  `parent/course/{overview,announcements,assessments,grades,instructor}.php`, `parent/profile.php`,
  `student/profile.php`, `parent/{not-linked,portal-off}.php`
- Test: `tests/feature/` (grep the existing parent dashboard test), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: Task 1's component.
- Produces:
  - Parent dashboard phone tabs: **Attention** (`attention`, dot when any item), **Courses** (course cards and
    past terms), **Deadlines** (`deadlines`). From `lg` up the page is unchanged. The child switcher is a full-width
    select below `sm`; the page-head actions wrap.
  - Parent course pages: their tab strip (`parent-course-tabs.js`) uses `data-scroll-x`/`data-scroll-current` via
    `components/tabs` or the same attributes; grade categories use container grids (as Task 9); the instructor
    tab's contact card stacks.
  - Profile pages: the `<dl>` uses `sm:grid-cols-[8rem_1fr]` already; check the notification form's controls are
    44px and the save button is full width below `sm`.

- [ ] **Step 1: Write the failing tests.** PHPUnit: the parent dashboard has `data-phone-tabs` with Attention,
  Courses and Deadlines; with no attention items there is no dot; a parent with no children linked still shows
  `not-linked`. Playwright: `SCREENS` rows `parent-dashboard` (`as: "parent"`, `/parent/dashboard`),
  `parent-course` (path function from the first course card), `parent-profile`, `student-profile`; at 375 the
  Courses tab shows the cards and the page has no overflow.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests and `tests/e2e/parent.spec.js` (TC-PRT-02 fails on `main` too).
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): parent portal dashboard tabs and phone layouts`.

---

### Task 12: Phase wrap-up

- [ ] **Step 1: Automated checks.** `npm test` (the calendar stale-date test fails on `main` too), `npm run lint`,
  `npm run build`, `php -l` on changed PHP, and `vendor/bin/phpunit tests/feature` once (one run, MySQL up).
- [ ] **Step 2: Sweeps.** `npx playwright test responsive.spec.js --project=chromium`, then with
  `RESPONSIVE_FULL=1` (all sizes, landscape, 200% zoom, light and dark), then `--project=webkit`. Fix real
  failures in the owning task's files and say so; do not loosen a check.
- [ ] **Step 3: Regression batches**, one file at a time: `parent.spec.js`, `global-grades-student.spec.js`,
  `global-grades-parent.spec.js`, `messages.spec.js`, `modules.spec.js`, `module-dependencies.spec.js`,
  `quizzes.spec.js`, `quiz-security.spec.js`, `assignments.spec.js`, `announcements.spec.js`,
  `auth-login.spec.js`. Rerun a failure once; compare with `main` before blaming this phase.
- [ ] **Step 4: Review the instructor, chairperson and dean views** of every shared file this phase changed at
  `lg` and above (dashboard, calendar, course frame, module pages, assessments, grades, messages): they look the
  same as on `main`. A short note per file.
- [ ] **Step 5: Hand over** the summary, the commit messages in order, the baseline step (push; Actions →
  Responsive → Run workflow with `update_snapshots`), and a real-device checklist (iPhone Safari, Android Chrome):
  - Dashboard tabs switch, the dot shows, "View all" works, the bar stays under the header while scrolling.
  - A module item: Contents opens and closes, prev/next sits above the home bar.
  - A quiz: the keyboard never hides the answer you are typing or the Submit button.
  - Calendar: a day opens a sheet; the schedule scrolls sideways with the hours pinned.
  - Messages: opening a thread pushes in, Back and a right swipe return; the composer stays above the keyboard.
  - Dark mode on the dashboard, the Contents panel and the Messages list.
- [ ] **Step 6: Carry into Phase 5:** the instructor side of every shared view touched here, the Course
  Workspace editors, Grade Work, Quiz Builder and gradebook; the seven `lg:overflow-y-auto` page cards still
  unchecked; Linux baselines for the new screens.
