# Responsive Phase 6: Chairperson and Dean Screens. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every screen a chairperson or a dean uses work from 280px to 2560px: both dashboards as phone
tabs, global grades (the program table, its action bar and the nudge dialog), the course lists and the program and
college monitors, the dean's approvals, sign-off, announcements, teaching-load and chairperson-review pages, the
chairperson's override requests, follow-ups, and the modals they open.

**Architecture:** Phases 1 to 5 built the pieces: `components/phone-tabs.php`, the `[data-scroll-x]` grid
container, `components/data-table.php` with phone rows and the row-actions sheet, auto full-screen form dialogs,
the expandable action bar and the container-query cards. This phase applies them to the staff views; it adds no new
shared module. Two things are new in shape: the chairperson and dean dashboards are not the generic 12-column grid
(they have a rail plus a main column), so their phone tabs are wired in their own templates; and the dashboard
partials under `partials/chairperson/` are reused by the dean. Staff screens keep their desktop layout from `lg`
up; changes are `max-lg:`, `max-md:` or container-query rules.

**Tech Stack:** CodeIgniter 4 views, Tailwind v4.3, vanilla ES modules, Vitest + jsdom, PHPUnit (CIUnit feature
tests), Playwright + axe.

**Spec:** `docs/superpowers/specs/2026-11-09-responsive-ui-design.md` (§5 grid tables, §6 dashboards, §8 dialogs
and forms, §9 motion, §10 type and spacing, §11 edge conditions, §12 phase 6)

## Global Constraints

- Prerequisite: Phase 5 is committed (`feat/responsive-5-instructor` at 345a76d9). Branch
  `feat/responsive-6-chairperson-dean` off it (already created). **Do not commit.** Each task ends by handing over
  a commit message.
- No new npm or composer dependencies.
- Brand colour only through tokens; dark mode uses the existing `--dk-*` mapping. Every new surface looks right
  under `html[data-theme="dark"]`. The dark mapping covers plain classes such as `.bg-white`, not
  responsive-prefixed variants: an opaque base class has to be there.
- Touch targets ≥44×44 under `pointer: coarse` (`pointer-coarse:min-h-11 pointer-coarse:min-w-11`); phone inputs
  ≥16px (`text-base`, may drop to `sm:text-sm`); correct `inputmode` / `autocomplete`.
- Motion uses the Phase 1 tokens (`--motion-fast/base/slow`, `--ease-spring`, `--ease-exit`); reduced motion
  means 0ms. Hover effects only under `(hover: hover)`. No scroll-reveal animations.
- Every `view()` of a component or partial passes `['saveData' => false]`.
- Shared views stay shared: the dean reuses `partials/chairperson/profile-card` and `quick-access`; the chairperson
  and the instructor share the grades Gradebook; every role shares `components/*`. A change that is only for
  phones, tablets or narrow containers uses `max-lg:`, `max-md:` or container queries. Do not change what a
  student, instructor or admin sees at `lg` and above; where a shared view must change for everyone, say so in the
  task report. Contrast and 44px fixes found by the sweep are allowed everywhere (say so).
- Print views (`dean/report-print.php`, grade reports, student report) are not changed and are verified unbroken.
- Keep every existing hook (`data-program-*`, `data-nudge-*`, `data-roster-*`, `data-grades-grid`,
  `data-section="totals"`, `[data-dialog*]`, `[data-action-bar*]`, `[data-scroll-x]`, `[data-phone-*]`). Existing
  Vitest, PHPUnit and e2e specs must not be edited except where a task says an assertion changes. The dean's group
  is read-only (`readonly` filter): a change must never add a write to it.
- Breakpoints: `sm` 40rem, `md` 48rem, `lg` 64rem, `xl` 80rem, `xs` 25rem, `3xl` 120rem; `lock:` = ≥80rem wide and
  ≥47.5rem tall. Fluid type `text-fluid-h1/h2/metric`, spacing `px-gutter`, `gap-fluid`; fluid page padding
  `p-5 sm:p-8 lg:p-10`.
- Hand-written list tables move onto `components/data-table.php`; grid tables get `[data-scroll-x]` (sticky first
  column where the first column names the row). A data-table column that holds a live control needs a `role` or
  an edit path.
- CSS cascade rule (found in Phase 5): container-query (`@…:`) rules come after breakpoint (`lg:`) rules in the
  built CSS, so a pair on the same property with different values must be guarded (`max-lg:@xs:…`); and a
  container query never matches the element that carries `@container`, only a descendant: put `@container` on an
  ancestor.
- Lessons from Phases 4 and 5: the e2e server's links start with `/index.php`, so never anchor a regex on
  `/dean` or `/chairperson`; the sweep's `open()` waits only for `main`, so tests of loaded content must wait for
  the feature to finish loading; axe contrast and 44px failures on pre-existing greys and small links are real and
  are fixed in the owning file; check line endings with `git ls-files --eol <file>` and keep each file's own
  endings (new files LF; never `sed -i` on a CRLF file); `tests/e2e/responsive.spec.js` is LF; Messages is switched
  on once for the whole sweep file; DB-backed runners run one at a time, the full matrix runs in chunks by size,
  and MySQL is never restarted by the agent (report it).

## Review Focus

- A chairperson who is also an instructor (multi-role user): the dashboard follows the active role; the phone tabs
  must follow the active role too, not the first group.
- No active program or college assignment (`dean/no-assignment.php`, a chairperson with no program): the empty
  state fits at 280 and has no dead tabs.
- Dashboards with no data (no needs-attention items, no announcements, no audit entries): each tab shows its
  empty copy, and the Needs Attention tab shows no dot.
- A program table with a long section or course name, and 20+ sections: the first column stays pinned and the page
  never scrolls sideways.
- The nudge dialog with no recipients (`data-nudge-send` disabled): it stays readable and its disabled button is
  44px.
- The dean's read-only pages must not show any control that writes (nothing new in the `dean` group posts
  anything the existing pages did not).
- Sign-off and approvals dialogs (reason fields, reauth): the primary action stays reachable above the phone
  keyboard.
- 200% zoom and landscape 667×375 on every new screen row.

---

## File Structure

| Area | Files |
|---|---|
| Dashboards | `pages/dashboard/index.php` (chairperson branch), `pages/dean/dashboard.php`, `pages/dashboard/partials/chairperson/*`, `pages/dashboard/partials/dean/*`, `pages/dashboard/partials/{college-announcements,audit-highlights}.php` |
| Global grades | `pages/grades/index.php`, `pages/grades/partials/chairperson/{program,program-at-risk,nudge-dialog}.php`, `resources/js/pages/grades/{program,nudge,grades,section-switch}.js` |
| Course lists and monitors | `pages/courses/{chairperson-index,monitor-program}.php`, `pages/dean/{courses,monitor-college}.php`, `pages/courses/partials/course-card.php` |
| Dean pages | `pages/dean/{approvals,signoff,chairperson-review,teaching-load,teaching-load-instructor,announcements,no-assignment}.php` |
| Shared staff pages | `pages/chairperson/override-requests.php`, `pages/follow-ups/index.php`, `pages/announcements/index.php` |
| Tests | `tests/e2e/responsive.spec.js`, `tests/e2e/support/sign-in.js`, new `tests/feature/{ChairpersonDashboardTabsTest,DeanDashboardTabsTest,ProgramGradesResponsiveTest,StaffPagesResponsiveTest,StaffModalsResponsiveTest}.php` |

---

### Task 1: Harness, accounts and the baseline sweep

**Files:**
- Modify: `tests/e2e/support/sign-in.js` (add `chairperson` and `dean` to `accounts`), `tests/e2e/responsive.spec.js`
- Test: `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: `accounts[screen.as]` (`responsive.spec.js:527`), the `SCREENS` row shape `{ name, path, as, scope, then?, dialogs? }`.
- Produces: `accounts.chairperson` = `chairperson1@mentora.test` / `Chairperson123!` and `accounts.dean` =
  `dean1@mentora.test` / `Dean123!` (same values as `support/messages.js`); one `SCREENS` row per screen listed in
  the table below, so every later task adds assertions to a row that already runs.

| Row name | Path | as |
|---|---|---|
| `chairperson-dashboard` | `/chairperson/dashboard` | chairperson |
| `chairperson-courses` | `/chairperson/courses` | chairperson |
| `chairperson-monitor-program` | `/chairperson/monitor-program` | chairperson |
| `chairperson-grades` | `/chairperson/grades` | chairperson |
| `chairperson-override-requests` | `/chairperson/override-requests` | chairperson |
| `chairperson-follow-ups` | `/chairperson/follow-ups` | chairperson |
| `chairperson-announcements` | `/chairperson/announcements` | chairperson |
| `dean-dashboard` | `/dean/dashboard` | dean |
| `dean-courses` | `/dean/courses` | dean |
| `dean-grades` | `/dean/grades` | dean |
| `dean-approvals` | `/dean/approvals` | dean |
| `dean-signoff` | `/dean/signoff` | dean |
| `dean-announcements` | `/dean/announcements` | dean |
| `dean-teaching-load` | `/dean/teaching-load` | dean |
| `dean-chairperson-review` | `/dean/chairperson-review` | dean |
| `dean-monitor-college` | `/dean/monitor-college` | dean |

- [ ] **Step 1: Add the two accounts and the sixteen rows**, each scoped to `main` (tighten a scope to
  `main:has(<loaded content>)` only where the page loads asynchronously, and say which in the report).
- [ ] **Step 2: Run** `npx playwright test responsive.spec.js --project=chromium -g "chairperson|dean"`. Expected:
  some rows fail (overflow, 44px, axe). **List every failing row and its failing check in the report**; that list
  is the work list for Tasks 2 to 6. Do not fix anything in this task.
- [ ] **Step 3: Dark mode and landscape.** Run the same rows with `RESPONSIVE_FULL=1 -g "@375x812"` and
  `-g "@667x375"` and add the extra failures to the list.
- [ ] **Step 4: Hand over the commit message:** `test(responsive): chairperson and dean rows in the responsive sweep`.

---

### Task 2: Chairperson dashboard as phone tabs

**Files:**
- Modify: `app/Views/pages/dashboard/index.php` (the chairperson branch and the `$phoneTabs` list),
  `app/Views/pages/dashboard/partials/chairperson/{needs-attention,announcements,audit-highlights,instructor-workload,profile-card,program-snapshot,quick-access}.php`,
  `app/Views/pages/dashboard/partials/college-announcements.php`
- Test: `tests/feature/ChairpersonDashboardTabsTest.php` (new, no DB), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: `components/phone-tabs` (`label`, `tabs` of `{key, label, dot}`), the `$panel(string $slug)` helper
  pattern of the instructor dashboard, `$renderChairWidget(string $slug)`.
- Produces: for the active role `chairperson`, three tabs: `attention` ("Attention", dot when
  `$widgets['needs-attention']['items']` is non-empty), `program` ("Program"), `updates` ("Updates"). Panel map:
  `needs-attention` → attention; `quick-access`, `profile-card`, `program-snapshot`, `instructor-workload` →
  program; `college-announcements`, `announcements`, `audit-highlights` → updates. Tabs exist below `lg` only; the
  `lg` layout (quick-access strip, 20rem rail, main column) is byte-for-byte what it is today.

- [ ] **Step 1: Write the failing test.** `ChairpersonDashboardTabsTest`: the rendered chairperson dashboard
  contains `data-phone-tabs` with the three labels, each widget wrapper carries `data-phone-panel="<key>"` per the
  map above, the first tab's wrapper has `data-phone-active`, and the Attention dot is absent when there are no
  items. Playwright: row `chairperson-dashboard` at 375 switches the three tabs (the tab's panel becomes visible,
  the others hidden) and `expectTouchTargets` passes; at 1366 no tab bar is visible and the page does not scroll.
- [ ] **Step 2: Run both and see them fail.**
- [ ] **Step 3: Implement.** The quick-access strip is rendered once, where it is today, and its wrapper gets
  `data-phone-panel="program"` (panels only hide below `lg`, so the `lg` strip is unchanged). Each widget wrapper
  in the rail and the main column gets its `$panel(...)` attribute; the rail and main-column wrappers use
  `max-lg:contents` as the instructor dashboard's identity column does, so a widget in a hidden tab leaves no gap.
  The `h-80` card heights stay below `lg`; cards that scroll inside use `max-lg:h-auto max-lg:max-h-96`. Fix the
  44px and contrast failures Task 1 listed for these partials in their own files.
- [ ] **Step 4: Run** the new tests, then `tests/e2e/dean.spec.js` and `tests/e2e/chairperson.spec.js` (one file
  at a time).
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): chairperson dashboard as phone tabs`.

---

### Task 3: Dean dashboard as phone tabs

**Files:**
- Modify: `app/Views/pages/dean/dashboard.php`, `app/Views/pages/dashboard/partials/dean/{needs-attention,chairperson-activity,college-snapshot,announcements}.php`
- Test: `tests/feature/DeanDashboardTabsTest.php` (new, no DB), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: `components/phone-tabs`, the `$widget(string $partial, array $data)` helper in the template.
- Produces: three tabs: `attention` ("Attention", dot when `$overview['attention']` is non-empty), `college`
  ("College"), `updates` ("Updates"). Panel map: `dean/needs-attention` → attention; `chairperson/quick-access`,
  `chairperson/profile-card`, `dean/college-snapshot` → college; `dean/chairperson-activity`,
  `dean/announcements` → updates. The load-error card (`data-load-error`) is shown in every tab's place: below
  `lg` it is a single block outside the panels, so it is never hidden by a tab.

- [ ] **Step 1: Write the failing tests** as in Task 2, for the dean template: three labels, panel attributes, no
  dot when `attention` is empty, and the load-error state renders no tab bar.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement**; the `lg` layout does not change. The load-error "Try again" link is 44px on touch.
- [ ] **Step 4: Run** the new tests and `tests/e2e/dean.spec.js`.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): dean dashboard as phone tabs`.

---

### Task 4: Global grades: program table, action bar and the nudge dialog

**Files:**
- Modify: `app/Views/pages/grades/index.php`, `app/Views/pages/grades/partials/chairperson/{program,program-at-risk,nudge-dialog}.php`,
  `resources/js/pages/grades/{program,nudge,grades,section-switch}.js` (only where a hook moves)
- Test: `tests/feature/ProgramGradesResponsiveTest.php` (new, no DB), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: `[data-scroll-x]` with `data-sticky-first` (`shared/scroll-x.js`), `data-action-bar` (`shared/action-bar.js`),
  `components/dialog` (auto full-screen form dialogs), the existing `data-program-table`, `data-program-search`,
  `data-program-actions`, `data-nudge-*` hooks.
- Produces: `program.php`'s table sits in a `[data-scroll-x data-sticky-first]` region (its own
  `overflow-x-auto` wrapper and the per-cell `sticky left-0` classes are replaced, keeping the selected-row inset
  shadow); the program/college action bar (`data-program-actions`) is a `data-action-bar` below `lg` with the
  Nudge and Download actions in the open panel; the nudge dialog's fields are named, 16px on phones, with a pinned
  primary action; `max-w-[13rem]`/`max-w-[14rem]` cell caps use `min-w-0 truncate` inside the pinned scroller so a
  long name cannot widen the table past its container.

- [ ] **Step 1: Write the failing tests.** PHPUnit: `program.php` output contains `data-scroll-x` and
  `data-sticky-first`, no hand-written `overflow-x-auto` wrapper, the nudge dialog's recipient, title and message
  fields each have an accessible name. Playwright (`chairperson-grades` and `dean-grades` rows): no page overflow
  at 320/375/768, the first column stays on screen after scrolling the table sideways, the nudge dialog opened at
  375 passes `expectTouchTargets`, panel-overflow and axe, a nudge with no recipients leaves `data-nudge-send`
  disabled and 44px.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.** Keep the sort/filter hooks working with the scroller (verify `program.js` finds its
  table with the new wrapper). At `lg` and above the table, its minimum widths (`min-w-[52rem]` for the dean,
  `min-w-[46rem]` for the chairperson) and the action bar look exactly as before.
- [ ] **Step 4: Run** the new tests, then `tests/e2e/global-grades-chairperson.spec.js`,
  `global-grades-dean.spec.js`, `global-grades.spec.js` (one file at a time).
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): global grades program table scrolls with a pinned section column, phone action bar and nudge dialog`.

---

### Task 5: Course lists and the program and college monitors

**Files:**
- Modify: `app/Views/pages/courses/{chairperson-index,monitor-program}.php`, `app/Views/pages/dean/{courses,monitor-college}.php`,
  `app/Views/pages/courses/partials/course-card.php`
- Test: `tests/feature/StaffPagesResponsiveTest.php` (new, no DB; Task 6 adds to it), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: `components/data-table.php` (columns, rows, `role` per column), `[data-scroll-x]`, container queries
  on the course cards (Phase 4: `@container` on an ancestor of the card).
- Produces: the three `lg:overflow-y-auto` page cards (`chairperson-index.php:34`, `monitor-program.php:74`,
  `dean/courses.php:54`) have no sticky bar hidden under the header between 1024 and 1279 and at 1366×657 (state
  the result per page; fix where it reproduces by giving the sticky element the header offset below the lock, as
  Phase 5 did for the Announcements sidebar); the filter form of `chairperson-index.php` stacks below `sm`, its
  controls are 44px on touch and 16px on phones; `monitor-program.php:184` and `dean/monitor-college.php:184,260`
  tables move onto `components/data-table.php` (phone rows with the row-actions sheet where a row has actions;
  otherwise a `[data-scroll-x]` grid with the first column pinned); the metric tiles at `monitor-college.php:135`
  (`grid-cols-2 lg:grid-cols-4`) and `monitor-program.php:130` use the Phase 3 metric cards unchanged in value at
  `lg`.

- [ ] **Step 1: Write the failing tests.** PHPUnit: the two monitor pages contain `components/data-table` output
  (`data-table`) and no bare `<table class="w-full text-left text-sm">`; the filter form's controls carry the
  touch and text-size classes. Playwright (`chairperson-courses`, `chairperson-monitor-program`, `dean-courses`,
  `dean-monitor-college`): the standard checks, plus a test at 1100×768 and 1366×657 that no sticky bar on these
  pages pins above the header's height (reuse the Phase 5 sticky-bar helper).
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement**, keeping the `data-section="totals"` hook and the monitor's JS data loading
  (`api/monitor-college/(:segment)` replaces fragments: after a fragment loads the table must still render in
  data-table form).
- [ ] **Step 4: Run** the new tests, then `tests/e2e/monitor-program.spec.js` and
  `tests/e2e/dean.spec.js`.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): course lists and program and college monitors on phones`.

---

### Task 6: Dean pages, override requests, follow-ups and announcements

**Files:**
- Modify: `app/Views/pages/dean/{approvals,signoff,chairperson-review,teaching-load,teaching-load-instructor,announcements,no-assignment}.php`,
  `app/Views/pages/chairperson/override-requests.php`, `app/Views/pages/follow-ups/index.php`,
  `app/Views/pages/announcements/index.php` (this file has no CR: keep it as is; `git ls-files --eol` says `i/-text`)
- Test: `tests/feature/StaffPagesResponsiveTest.php`, `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: `components/data-table.php` (already used by these pages), `components/dialog` (already used);
  Phase 3's `form-grid`.
- Produces: every page above passes the standard checks at all sizes; their data tables' action columns show
  44px controls (or sit in the row-actions sheet); the approve/reject and sign-off/reopen dialogs have a pinned
  primary action; reason fields are 16px on phones with an accessible name; `no-assignment.php` and the empty
  states fit at 280.

- [ ] **Step 1: Write the failing tests** from the Task 1 failure list for these rows: PHPUnit asserts per file
  the classes each fix adds (named controls, `pointer-coarse:min-h-11`, `text-base sm:text-sm` on reason fields);
  Playwright checks that the dialogs these pages open (`dialogs` entries: approve, reject, sign off, reopen,
  announce) fit at 375 and 834 with 44px controls and a visible primary action, opening them through the page's
  own controls.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement** in each page's own file; nothing here adds a write route.
- [ ] **Step 4: Run** the new tests, then `tests/e2e/dean.spec.js`, `tests/e2e/chairperson.spec.js`,
  `tests/e2e/announcements.spec.js`, `tests/e2e/global-grades-dean.spec.js` (one file at a time).
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): dean approvals, sign-off, teaching load and review, override requests and follow-ups on phones`.

---

### Task 7: The staff views of shared Course Workspace screens

**Files:**
- Modify: only the shared files that fail the sweep below (list them in the report), under
  `app/Views/pages/courses/features/**` and `app/Views/pages/grades/**`
- Test: `tests/e2e/responsive.spec.js` (new rows), `tests/feature/StaffPagesResponsiveTest.php` for any markup fix

**Interfaces:**
- Consumes: Phase 5's responsive instructor screens, which the chairperson and the dean reach read-only through
  `chairperson/courses/…` and `dean/courses/…`.
- Produces: rows `chairperson-course-overview`, `chairperson-course-modules`, `chairperson-course-grades`,
  `chairperson-grade-work`, `dean-course-overview`, `dean-course-modules`, `dean-course-grades`, `dean-grade-work`
  (course = IT101-A for the chairperson; the dean's course is whichever of their college's courses the page lists
  first), all passing at the standard checks. Controls the dean's read-only group hides stay hidden; nothing
  Phase 5 added becomes visible to a role that must not see it (e.g. move buttons, content ⋯ sheet, score bar's
  Save).

- [ ] **Step 1: Add the eight rows and run them.** Expected: some fail. **List the failures.**
- [ ] **Step 2: For each failure, fix** the shared file minimally (a view that renders for several roles gets a
  change that is correct for all of them; say so). If a fix would change what an instructor sees at `lg`, stop and
  report instead.
- [ ] **Step 3: A test per hidden control:** at 375 the dean's course modules page has no `[data-question-move]`,
  `[data-module-move]`, `[data-learning-item-dependencies]`, or Save control (assert absence, not hidden state).
- [ ] **Step 4: Run** the new rows, then `tests/e2e/modules.spec.js` and `tests/e2e/gradebook.spec.js` (one at a
  time).
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): chairperson and dean views of the course workspace fit phones`.

---

### Task 8: Staff modals

**Files:**
- Modify: every dialog file the chairperson or dean opens that fails the sweep (the nudge dialog is Task 4's; the
  approvals, sign-off, announcement composer, follow-up and reauth dialogs are the others; list the files in the
  report)
- Test: `tests/feature/StaffModalsResponsiveTest.php` (new, no DB), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: Phase 3's dialog contract (`variant` auto: a form with a visible field is full-screen below `sm`;
  `variant => 'default'` opts a near-single-field dialog out; confirms stay small alerts), `shared/dialog.js`
  (`data-dialog-pin-actions`), Phase 5's `dialogs: [{ id, open }]` sweep entry and `via()` opener helper.
- Produces: every staff dialog opened at 375 and 834 has no horizontal overflow, ≥44px controls on touch,
  readable contrast in light and dark, a pinned primary action, and every field named. The two near-single-field
  dialogs on the admin side ("Assign a dean/chairperson", "Change roles") are Phase 7's: leave them.

- [ ] **Step 1: Write the failing tests.** Playwright: each staff `SCREENS` row from Tasks 4 to 6 lists its
  dialogs; PHPUnit: every dialog under the staff pages has a named field per input and `'variant' => 'default'`
  on dialogs with one short field (list them in the report).
- [ ] **Step 2: Run the sweep and list which dialogs fail.**
- [ ] **Step 3: Fix each failing dialog's file** minimally.
- [ ] **Step 4: Run** the sweep (smoke, then full in chunks) and `tests/e2e/dean.spec.js`,
  `tests/e2e/chairperson.spec.js`, one at a time.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): chairperson and dean dialogs fit phones`.

---

### Task 9: Phase wrap-up

- [ ] **Step 1: Automated checks.** `npm test` (the calendar stale-date test fails on `main` too), `npm run lint`,
  `npm run build`, `php -l` on changed PHP, then `vendor/bin/phpunit tests/feature` once (MySQL up).
- [ ] **Step 2: Sweeps.** `npx playwright test responsive.spec.js --project=chromium`, then `RESPONSIVE_FULL=1`
  in chunks by size (`@320x640`, `@375x812`, `@430x932`, `@768x1024`, `@834x1112`, `@1024x768`, `@1366x768`,
  `@1440x900`, `@1920x1080`, landscape, zoom200; light and dark), then `--project=webkit`. Fix real failures in
  the owning task's files and say so; do not loosen a check.
- [ ] **Step 3: Regression batches**, one file at a time: `dean.spec.js`, `chairperson.spec.js`,
  `global-grades.spec.js`, `global-grades-chairperson.spec.js`, `global-grades-dean.spec.js`,
  `monitor-program.spec.js`, `announcements.spec.js`, `modules.spec.js`, `gradebook.spec.js`, `grading.spec.js`.
  Rerun a failure once; known failures on `main`: TC-DEAN-03, TC-DEAN-04, TC-GGR-04 (compare before blaming this
  phase; flaky: TC-GGR-13).
- [ ] **Step 4: Review the student, instructor and admin views** of every shared file this phase changed at `lg`
  and above: they look the same as on `main`. A short note per file.
- [ ] **Step 5: Hand over** the summary, the commit messages in order, the baseline step (push; Actions →
  Responsive → Run workflow with `update_snapshots`), and a real-device checklist (iPhone Safari, Android Chrome):
  - Chairperson dashboard: the three tabs switch and the Attention dot shows when something needs attention.
  - Dean dashboard: the same, plus the load-error state.
  - Global grades: the program table scrolls sideways with the Section column pinned; the nudge dialog's keyboard
    never hides the message field; Download works.
  - Dean approvals and sign-off: the reason field and the primary action stay above the keyboard; sign-off asks
    for the password.
  - Monitor pages: tables scroll or become rows, no page scrolls sideways.
  - Dark mode on both dashboards and the program table.
- [ ] **Step 6: Carry into Phase 7:** `admin/home`, `institution/structure` page cards, the institution and
  platform-admin pages, the two near-single-field dialogs, monitoring, offering schedule and copy; Linux baselines
  for the new screens.
