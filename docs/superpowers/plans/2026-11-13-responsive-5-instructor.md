# Responsive Phase 5: Instructor Screens. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every screen an instructor uses work from 280px to 2560px: the instructor dashboard as phone tabs,
Grade Work with a sticky score bar and a draggable divider, Quiz Builder with a sticky "Add question" bar, the
assessment editor, the gradebook and its grid tables, module and learning-item management (including reordering
by touch), the instructor parts of the Course Workspace, and the modals the instructor opens from them.

**Architecture:** Phases 1 to 4 built the pieces: the expandable action bar (`shared/action-bar.js`), the
`[data-scroll-x]` grid container, `components/data-table.php` with phone rows, auto full-screen form dialogs,
`components/phone-tabs.php` and the container-query cards. This phase applies them, and adds two small modules:
`shared/side-panel.js` (a secondary pane that slides over the main pane between `md` and `lg`) and
`shared/split-divider.js` (a draggable, remembered divider from `lg`). Pages keep their desktop layout from `xl`
up; changes are `max-lg:`, `max-md:` or container-query rules so chairperson, dean and student views of shared
files do not change.

**Tech Stack:** CodeIgniter 4 views, Tailwind v4.3, vanilla ES modules, Vitest + jsdom, PHPUnit (CIUnit feature
tests), Playwright + axe.

**Spec:** `docs/superpowers/specs/2026-11-09-responsive-ui-design.md` (§5 grid tables, §6 dashboards, §7 split
panes: Grade Work and Quiz Builder, §8 dialogs and forms, §9 motion, §10 type and spacing, §11 edge conditions,
§12 phase 5)

## Global Constraints

- Prerequisite: Phase 4 is committed (`feat/responsive-4-student-parent` at 08677b82). Branch
  `feat/responsive-5-instructor` off it. **Do not commit.** Each task ends by handing over a commit message.
- No new npm or composer dependencies.
- Brand colour only through tokens; dark mode uses the existing `--dk-*` mapping. Every new surface looks right
  under `html[data-theme="dark"]`. The dark mapping covers plain classes such as `.bg-white`, not
  responsive-prefixed variants: an opaque base class has to be there.
- Touch targets ≥44×44 under `pointer: coarse` (`pointer-coarse:min-h-11 pointer-coarse:min-w-11`); phone inputs
  ≥16px (`text-base`, may drop to `sm:text-sm`); correct `inputmode` / `autocomplete`. Rubric level buttons ≥40px.
- Motion uses the Phase 1 tokens (`--motion-fast/base/slow`, `--ease-spring`, `--ease-exit`); reduced motion
  means 0ms. Hover effects only under `(hover: hover)`. No scroll-reveal animations.
- Every `view()` of a component or partial passes `['saveData' => false]`.
- Shared views stay shared: a change that is only for phones, tablets or narrow containers uses `max-lg:`,
  `max-md:` or container queries. Do not change what a student, chairperson or dean sees at `lg` and above; where
  a shared view must change for everyone, say so in the task report. Contrast and 44px fixes found by the sweep
  are allowed everywhere (say so).
- Keep every existing hook (`data-grade-work-*`, `data-expand-grade-work-actions`, `data-question-list`,
  `data-save-draft`, `data-publish`, `data-delete-assessment`, `#editor-inspector-panel`, `data-roster-*`,
  `data-grades-grid`, `[data-module*]`, `[data-learning-*]`, `[data-dialog*]`, `[data-action-bar*]`,
  `[data-scroll-x]`, `[data-phone-*]`). Existing Vitest, PHPUnit and e2e specs must not be edited except where a
  task says an assertion changes. Unsaved-changes leave guards (quiz builder, Grade Work, editors) must keep
  working with the new bars.
- Breakpoints: `sm` 40rem, `md` 48rem, `lg` 64rem, `xl` 80rem, `xs` 25rem, `3xl` 120rem; `lock:` = ≥80rem wide and
  ≥47.5rem tall. Fluid type `text-fluid-h1/h2/metric`, spacing `px-gutter`, `gap-fluid`; fluid page padding
  `p-5 sm:p-8 lg:p-10`.
- Hand-written list tables move onto `components/data-table.php`; grid tables get `[data-scroll-x]` (sticky first
  column where the first column names the row). A data-table column that holds a live control needs a `role` or
  an edit path.
- Lessons from Phase 4 (apply to every task): the e2e server's links start with `/index.php`, so never anchor a
  regex on `/instructor` or `/student`; the sweep's `open()` waits only for `main`, so tests of loaded content
  must wait for the feature to finish loading (a skeleton can pass checks it should fail); axe contrast and 44px
  failures on pre-existing greys and small links are real and are fixed in the owning file; check line endings
  with `git ls-files --eol <file>` (`grep -c $'\r'` is unreliable in this shell) and keep each file's own endings
  (new files LF; never `sed -i` on a CRLF file); `tests/e2e/responsive.spec.js` is LF; Messages is switched on
  once for the whole sweep file (top-level `beforeAll`).
- MySQL on this machine is killed under memory pressure and was corrupted once: run DB-backed batches one at a
  time, check `mysqladmin -uroot ping` first, never run `npm run build` during PHPUnit feature tests or
  Playwright, and ask the user before restarting MySQL. Run the full matrix (`RESPONSIVE_FULL=1`) in chunks by
  size (`-g "@1366x768"` and so on) so one run does not exhaust memory.
- Each task that adds a screen adds its row to `SCREENS` in `tests/e2e/responsive.spec.js` (`path` may be a
  function; `as: "instructor"` is instructor1, who teaches IT101-A: "Assignment 1: Grade Calculator" with a
  rubric 12+10+8 and a late submission by student3, "Quiz 1: Variables and Data Types" with a partially graded
  attempt by student2). Failures in untouched code that also happen on `main` (TC-DEAN-03, TC-DEAN-04, TC-GGR-04,
  TC-PRT-02, TC-MSG-06, TC-MSG-08) are not this phase's.

## Review Focus

1. **Grade Work with nothing to grade (no submissions, or the selected student has not submitted):** the score
   bar shows the empty state and a disabled Save; nothing overflows at 320. Task 3.
2. **The soft keyboard open on a rubric score, the feedback box or a question's points field at 375:** the focused
   field is not covered by the sticky score bar or the Quiz Builder bar. Tasks 3, 4.
3. **Reordering without drag (questions in a quiz, modules, units, items on a touch screen):** every list that is
   reordered by dragging has a working, 44px move up / move down path under `pointer: coarse`. Tasks 4, 7.
4. **Long unbroken text (a 40-character assessment title, a student name, a question stem, a module title) at
   320px:** it wraps or truncates inside its card or bar and never widens the page. Tasks 3 to 9.
5. **A gradebook with many columns and few rows (30 items, 1 student), and with no students:** the grid scrolls
   inside its container with the first column pinned; the empty state shows without a grid. Task 6.

---

### Task 1: Instructor dashboard phone tabs

**Files:**
- Modify: `app/Views/pages/dashboard/index.php`, and under `app/Views/pages/dashboard/partials/`: `grading-queue.php`,
  `at-risk-students.php`, `teaching-load.php`, `class-progress.php`, `content-status.php`, `profile-card.php`,
  `quick-access.php`, `college-announcements.php`
- Test: `tests/feature/InstructorDashboardTabsTest.php` (new, no-DB render style of
  `StudentDashboardTabsTest.php`/`DashboardListCapsTest.php`), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: Phase 4's `components/phone-tabs` (`label`, `tabs` of `['key','label','dot']`) and the panel contract
  `data-phone-panel="{key}"` / `data-phone-active`; the `$layouts[$role]['phoneTab']` per-slug map and the
  `isset($layouts[$userRole])` gate in `dashboard/index.php`.
- Produces: instructor phone tabs, in this order: **To grade** (`grading-queue`, `at-risk-students`), **Sections**
  (`profile-card`, `teaching-load`, `class-progress`, `content-status`, `quick-access`), **Updates**
  (`college-announcements`). To grade carries the `dot` when the grading queue has items. The instructor `$layouts`
  entry gains `'phoneTab'` keys; the chairperson dashboard still gets no tabs. From `lg` up the 12-column grid is
  unchanged. Lists cap at 5 below `lg` with a `lg:hidden` "View all" (grading queue → the grades page, at-risk →
  the grades page's at-risk list).

- [ ] **Step 1: Write the failing tests.** PHPUnit: the instructor dashboard has `data-phone-tabs` with To grade,
  Sections, Updates and every widget in its panel; with an empty queue there is no dot and each panel holds its
  empty copy; the chairperson dashboard has no `data-phone-tabs`. Playwright (`describe("instructor")`, 375):
  only To grade shows, Sections switches, at 1366 the tablist is hidden; a `SCREENS` row `instructor-dashboard`.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement** the layout map keys, the panel wrappers and the caps.
- [ ] **Step 4: Run** the PHPUnit file, then `npx playwright test responsive.spec.js -g "instructor-dashboard|instructor" --project=chromium`
  (smoke, then `RESPONSIVE_FULL=1` light and dark). They pass.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): instructor dashboard becomes To grade/Sections/Updates tabs on phones`.

---

### Task 2: Side panel and split divider modules

**Files:**
- Create: `resources/js/shared/side-panel.js`, `resources/js/shared/split-divider.js`
- Create: `tests/js/shared/side-panel.test.js`, `tests/js/shared/split-divider.test.js`
- Modify: `resources/js/app.js` (import both), `resources/css/app.css`

**Interfaces:**
- Produces:
  - Markup contract for a side panel (the secondary pane: students list, palette, inspector):
    `<aside data-side-panel id="…" class="…">` plus a toggle `<button data-side-panel-toggle aria-controls="…"
    aria-expanded="false">` and an optional `[data-side-panel-close]` inside. From `lg` up the aside is a normal
    pane and the toggle is `lg:hidden`. Below `lg` the aside is a panel that slides over the main pane from the
    side (`--motion-base`, `--ease-spring`; `--motion-fast` out), opaque, `max-w-[min(22rem,90vw)]`, with a backdrop;
    it is `inert` and `aria-hidden` while closed. Opening traps nothing (it is not modal) but Escape and a backdrop
    tap close it and focus returns to the toggle. `bindSidePanels(root = document): void` (binds once, rebinds
    after `content:swapped`).
  - `bindSplitDivider(root = document): void` for `<div data-split data-split-key="grade-work-students"
    data-split-min="16rem" data-split-max="28rem"><div data-split-start>…</div><div data-split-handle role="separator"
    aria-orientation="vertical" tabindex="0" aria-valuenow aria-valuemin aria-valuemax>…</div><div data-split-end>…</div></div>`.
    From `lg` the handle drags the start pane's width (pointer events; Arrow keys move 16px, Home/End jump to min/max),
    clamped to min/max, stored as `localStorage["split:" + key]` (wrapped in try/catch) and restored on bind; double
    click resets. Below `lg` the handle is hidden and the panes take their own widths. The start width is set as the
    CSS variable `--split-start`.

- [ ] **Step 1: Write the failing tests** (jsdom): side-panel `toggle opens, sets aria-expanded and removes inert`,
  `Escape closes and returns focus to the toggle`, `backdrop click closes`, `binding twice does not double-handle`;
  split-divider `restores the saved width`, `pointer drag clamps to min and max and saves`, `arrow keys move 16px`,
  `a broken localStorage does not throw`, `double click resets`.
- [ ] **Step 2: Run and see them fail.** `npx vitest run tests/js/shared/side-panel.test.js tests/js/shared/split-divider.test.js`.
- [ ] **Step 3: Implement** both modules and the CSS (panel transition, backdrop, dark mode, reduced motion).
- [ ] **Step 4: Run** the tests and `npm run lint`; they pass.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): side panel and split divider modules for split-pane screens`.

---

### Task 3: Grade Work

**Files:**
- Modify: `app/Views/pages/courses/features/assessments/grade-work.php`, `partials/score-panel.php`,
  `partials/assignment-work.php`, `partials/submitted-work.php`, `partials/attempt-history.php`,
  `partials/security-report.php`, `resources/js/pages/courses/features/assessments/grading/grading.js`,
  `resources/css/app.css`
- Test: `tests/feature/GradeWorkResponsiveTest.php` (new, no-DB render style), `tests/js/pages/` (a grading test
  if one covers the touched JS), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: Task 2 (`data-side-panel`, `data-split`), Phase 1's `data-action-bar` contract (panel expands upward,
  `data-action-bar-toggle`, one child inside `data-action-bar-panel`).
- Produces:
  - **≥ lg:** the students list (the 290px column) is the split start pane, `data-split-key="grade-work-students"`,
    min `16rem`, max `28rem`, draggable and remembered; `xl:` and up as today.
  - **md–lg:** the students list is a `data-side-panel` opened by a "Students" toggle in the top bar.
  - **< md:** the submission fills the screen (the students list is the same side panel, toggle in the top bar);
    the score/grade controls live in a `sticky bottom-0` `data-action-bar` with the running total, a primary
    "Save, next" (the existing submit, with the hook `data-grade-work-submit-grade`), and a "Rubric" toggle that
    expands the criteria upward inside the bar: level buttons ≥40px, the feedback box (`text-base`), the score
    inputs `inputmode="decimal"`. The bar is opaque, `pb-safe-2`; the page has `scroll-mb-*` on score and feedback
    fields so a focused field clears the bar (the window scrolls, not the form). The top bar truncates the title
    and keeps back + save indicator. The "Edit" / re-grade (`data-regrade-open`) path and the view-only state keep
    working. No new behaviour: the same save, next and leave-guard code.

- [ ] **Step 1: Write the failing tests.** PHPUnit: the Grade Work page has the split markup with the key, the
  side-panel toggle, the action bar with `data-grade-work-submit-grade` inside it, and `inputmode` on score
  inputs. Playwright (`describe("instructor")`, one student selected, wait for the work to load): at 375 the
  score bar is in the viewport bottom, Rubric expands upward, a rubric level button is ≥40px, a focused feedback
  box does not intersect the bar (Review Focus 2), saving works and the student row shows Graded; at 1366 dragging
  the handle 80px changes the students column width and a reload keeps it; with a student who has no submission
  the bar shows the empty state and Save is disabled (Review Focus 1); a `SCREENS` row `instructor-grade-work`
  (path function: open IT101-A, "Assignment 1: Grade Calculator", View Submissions, select Maria Santos).
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests, then `tests/e2e/grading.spec.js` and `tests/e2e/assignments.spec.js` one at a time.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): Grade Work sticky score bar on phones, students side panel, draggable divider`.

---

### Task 4: Quiz Builder

**Files:**
- Modify: `app/Views/pages/courses/features/assessments/quiz-builder.php`, `partials/quiz-builder-palette.php`,
  `partials/quiz-builder-card.php`, `partials/question-card.php`, `partials/ai-review-tray.php`,
  `resources/js/pages/courses/features/assessments/questions/questions.js`
- Test: `tests/feature/QuizBuilderResponsiveTest.php` (new, no-DB), a Vitest for any new JS,
  `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: Task 2 (`data-side-panel`), the Phase 1 action bar.
- Produces:
  - **≥ lg:** unchanged (the 290px sticky palette beside the questions).
  - **md–lg:** the palette is a `data-side-panel` opened by an "Add question" toggle in the top bar.
  - **< md:** a `sticky bottom-0` `data-action-bar` with Save and Publish (existing hooks) and a "＋ Add question"
    toggle that expands the question-type palette upward inside the bar (each type is a ≥44px button that adds the
    question exactly as the palette's click does today). The question list takes the full width; question cards
    wrap their header controls and the points field is `inputmode="decimal"`.
  - **Touch reordering:** if `questions.js` reorders by drag only, each `quiz-builder-card` gets ▲ ▼ buttons
    (`data-question-move="up|down"`, `pointer-coarse:` 44px, `lg:` hidden on mouse devices) that move the card
    and persist through the same path the drag uses; the first/last buttons are disabled; focus stays on the
    button after the move and the move is announced in an `aria-live` region. Drag from the palette still works
    on mouse.

- [ ] **Step 1: Write the failing tests.** Vitest: `a move button reorders the list and calls the reorder path`,
  `the first card's up button is disabled`. PHPUnit: the page has the action bar with Save, Publish and the
  palette inside, and each card has the move buttons. Playwright (375): the bar is pinned, "Add question" expands
  the palette upward and choosing a type adds a card, moving the second card up swaps it and survives a reload
  (Review Focus 3), a focused points field clears the bar (Review Focus 2); `SCREENS` row
  `instructor-quiz-builder` (path function: open IT101-A, "Quiz 1: Variables and Data Types", edit).
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests, then `tests/e2e/assessment-editor.spec.js` and `tests/e2e/export-modal.spec.js`
  one at a time, and `npx vitest run tests/js/pages/quiz-*.test.js`.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): Quiz Builder sticky Add question bar on phones, palette side panel, touch reordering`.

---

### Task 5: Assessment editor and the instructor assessment pages

**Files:**
- Modify: `app/Views/pages/courses/features/assessments/editor.php`, `partials/editor-inspector-panel.php`,
  `partials/editor-actions.php`, `partials/view-header.php`, `partials/instructions-editor.php`,
  `partials/instructions-paragraph.php`, `partials/accepted-answers-editor.php`,
  `assessment-types/editor/{quiz,assignment}.php`, `index.php` (instructor parts only),
  `partials/overview/{sidebar-instructor,assessment-card,metrics}.php`, `view.php`
- Test: `tests/feature/AssessmentEditorResponsiveTest.php` (new, no-DB), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: Task 2 side panel; `.sticky-bar-top` / `.sticky-bar-bottom` from `app.css`.
- Produces:
  - `#editor-inspector-panel` (`w-1/5` today) is `lg:w-[clamp(16rem,20%,22rem)]` beside the content from `lg`; between
    `md` and `lg` it is a `data-side-panel` ("Settings" toggle in the top bar); below `md` it stacks under the
    content inside a `<details open>`-style "Settings" section with a ≥44px summary. Its fields use the form-field
    sizes (`text-base sm:text-sm`, 44px on touch) and date-time inputs stack.
  - `editor-actions` (delete, Save as Draft, Publish): `sticky bottom-0` below `lg` with an opaque background,
    `pb-safe-2`, buttons 44px, Publish and Save as Draft sharing the row, delete as an icon button with an
    accessible name; the page has `scroll-mb-*` so a focused field clears it. From `lg` unchanged.
  - Editor page padding `p-5 sm:p-8 lg:p-10`; the sticky top bar truncates the title; the instruction editors and
    accepted-answers editor wrap their toolbars. The instructor assessments overview (list, cards, metrics,
    sidebar) stacks below `lg` and its chips/filters wrap.

- [ ] **Step 1: Write the failing tests.** PHPUnit: the editor has the Settings side-panel/stack markup, the action
  footer carries the sticky classes, `view.php` and the overview use the new padding. Playwright: `SCREENS` rows
  `instructor-assessments` (the IT101-A Assessments tab) and `instructor-assessment-editor` (edit "Assignment 1");
  at 375 Settings opens/stacks, the footer is pinned and Publish is inside the viewport; a 40-character title
  (set through `runSql` and restored in `finally`, deleting by id, never leaving a backup table) does not overflow
  at 320 (Review Focus 4).
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests, then `tests/e2e/assessment-editor.spec.js` and `tests/e2e/assignments.spec.js`.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): assessment editor settings panel and pinned actions, instructor assessment pages on phones`.

---

### Task 6: Gradebook, roster, grading scheme and reports

**Files:**
- Modify: `app/Views/pages/courses/features/grades/roster.php`, `scheme-editor.php`,
  `partials/{gradebook-toolbar,assessment-list,assessment-breakdown,grade-categories,instructor-summary,student-summary,manual-items}.php`,
  `reports/{class-summary,student-report}.php` (screen styles only; print styles untouched),
  `app/Views/pages/grades/partials/instructor/{gradebook,at-risk,to-grade,filters,cards}.php`,
  `app/Views/pages/grades/index.php` (instructor branch only), `resources/js/pages/courses/features/grades/roster/roster-view.js`
- Test: `tests/feature/GradebookResponsiveTest.php` (new, no-DB render), `tests/js/pages/roster-view.test.js`
  (extend only if the render markup changes), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: Phase 3's `[data-scroll-x]` with `data-sticky-first` (first column cells carry `sticky left-0` and an
  opaque background), `bindScrollX`.
- Produces:
  - The roster table sits in a `[data-scroll-x data-sticky-first]` container (tabindex 0, role region, aria-label
    "Class roster"): sticky first column and sticky header row, edge fades, score inputs 44px on touch
    (`inputmode="decimal"`), the column header text does not wrap awkwardly. `roster-view.js`'s generated markup
    carries the same classes. `at-risk.php`'s table likewise (already `overflow-x-auto`).
  - `gradebook-toolbar` wraps on two rows below `md`; its menus (report, grade sheet) open as sheets below `sm`
    (reuse the Phase 3 row-actions sheet or the `menu` dialog variant) and stay absolute dropdowns from `md`.
  - The scheme editor's rows (name, weight, remove) wrap below `sm`; weights are `inputmode="decimal"` 44px; its
    sticky top bar wraps. The instructor grades page: section cards stay a snap-scrolling strip; filters stack.
  - Reports on screen: tables scroll inside `[data-scroll-x]`; the print views are verified unbroken (spec §11).

- [ ] **Step 1: Write the failing tests.** PHPUnit: the roster, at-risk and report tables are inside `[data-scroll-x]`
  with the sticky-first attribute where specified; score inputs carry `inputmode`. Playwright: `SCREENS` rows
  `instructor-gradebook` (IT101-A Grades tab, wait for the roster to load), `instructor-grades-page`
  (`/instructor/grades`), `instructor-scheme-editor`; at 375 the roster scrolls inside its container with the
  first column pinned (scrollLeft 200 keeps the first column at the container's left edge, `data-fade-start`
  set); a course with 30 manual items and one student (Review Focus 5, set up through `runSql` and restored in
  `finally`) has no page overflow; a course with no students shows the empty state and no grid.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests, then `tests/e2e/gradebook.spec.js` one file, and `npx vitest run tests/js/pages/roster-view.test.js tests/js/pages/scheme-editor.test.js`.
  Open the print views of a class summary and a student report once and confirm they are unchanged.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): gradebook and roster grids with pinned columns, grading scheme and reports on phones`.

---

### Task 7: Module management (instructor)

**Files:**
- Modify: `app/Views/pages/courses/features/modules/{index,module}.php`, `partials/{module-actions,module-actions-inline,learning-unit-actions,learning-item-actions,publish-chip,dependency-chip}.php`,
  `resources/js/pages/courses/features/modules/{module-sort,learning-unit-sort,learning-item-sort}.js`,
  `app/Views/pages/courses/features/modules/partials/learning-item-panel.php`
- Test: `tests/feature/ModuleManagementResponsiveTest.php` (new, no-DB), Vitest for any changed sort JS,
  `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: Phase 3's row-actions sheet contract (`components/data-table.php` moves action nodes into a sheet:
  reuse the same module, do not fork it) for the ⋯ action menus.
- Produces:
  - The module, unit and item action menus (`module-actions`, `learning-unit-actions`, `learning-item-actions`)
    open as bottom sheets below `md` with 44px actions (the existing hooks stay on the moved nodes); from `md` the
    current dropdowns are unchanged.
  - Reordering by touch (Review Focus 3): module, unit and item lists get ▲ ▼ move buttons under `pointer-coarse:`
    that call the same reorder path as the drag, with focus kept and an `aria-live` announcement; mouse drag is
    unchanged. `touch-action` is set so a vertical scroll is not swallowed by a drag handle.
  - `module.php` top bar wraps its actions; the module card's status badge and "Module details" label shrink below
    `sm`; padding `p-5 sm:p-8 lg:p-10`. `learning-item-panel.php` (instructor editor sidebar) keeps Phase 4's
    Contents dropdown below `lg`, and its `lg:overflow-y-auto` / sticky offset is checked at 1024–1279 (carry-over):
    the heading does not slide over rows.

- [ ] **Step 1: Write the failing tests.** Vitest: `a move button reorders modules/units/items through the drag's
  persist path`. PHPUnit: the action partials carry 44px classes and the move buttons. Playwright (as instructor,
  375, wait for the Modules tab to load): the ⋯ menu opens as a sheet and an action runs (e.g. rename opens its
  dialog), moving the second module up swaps it and persists; a 40-character module title does not overflow at
  320 (Review Focus 4); `SCREENS` rows `instructor-modules` and `instructor-module`.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests, then `tests/e2e/modules.spec.js`, `tests/e2e/module-dependencies.spec.js`,
  `tests/e2e/module-publish.spec.js` and `tests/e2e/content-transfer.spec.js`, one file at a time.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): module management action sheets and touch reordering on phones`.

---

### Task 8: Learning item editors

**Files:**
- Modify: `app/Views/pages/courses/features/modules/learning-item-editor.php`,
  `learning-items/editor/{reading,video,pdf,assignment,quiz,discussion,link}.php`, `partials/learning-item-header.php`
  (instructor parts), `partials/modals/learning-item-create.php`, `partials/modals/learning-item-dependencies.php`,
  `partials/modals/publish-fields.php`
- Test: `tests/feature/LearningItemEditorResponsiveTest.php` (new, no-DB), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Produces: the editors use the fluid page padding and stack their two-column layouts below `lg`; the reading
  editor's block toolbar wraps and its buttons are 44px on touch; the video and PDF editors' upload areas and
  previews are `max-w-full` (`aspect-video` kept) with the file name truncating; publish and schedule fields use
  the form-field sizes; the item-create dialog opens full-screen below `sm` via the Phase 3 auto rule (no opt-out
  unless it holds no visible field). The editor's save/publish bar is pinned below `lg` like Task 5's footer
  (reuse its classes). Editors for assignment and quiz items stack their settings.

- [ ] **Step 1: Write the failing tests.** PHPUnit: each editor file renders its form with the stacking classes
  and the save bar; the create dialog is auto full-screen (no `variant` forced). Playwright: `SCREENS` rows
  `instructor-item-editor-reading` and, if the seed has them, `-video` / `-pdf` (path function: open the first
  reading item of IT101-A in edit mode; say in the report which types the seed lacks); at 375 the toolbar wraps
  and the save bar is pinned; a 40-character item title does not overflow at 320.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests, then `tests/e2e/modules.spec.js` and `tests/e2e/module-publish.spec.js`.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): learning item editors stack and pin their actions on phones`.

---

### Task 9: Course Workspace instructor parts and the sticky-bar carry-overs

**Files:**
- Modify: `app/Views/pages/courses/features/overview/partials/{needs-attention,course-info,progress,recent-activity,upcoming-deadlines,continue-learning,metrics}.php`
  (instructor variants), `features/announcements/{index.php,partials/*}` (the composer and instructor controls),
  `features/discussions/{index,thread}.php`, `features/discussions/partials/*` (instructor/moderation controls),
  `features/resources/{index.php,partials/*}` (the resource manager),
  `app/Views/pages/courses/index.php` (the `lg:overflow-y-auto` page card),
  `app/Views/pages/courses/features/instructor/` JS if it renders an instructor tab, `resources/css/app.css`
- Test: `tests/feature/CourseWorkspaceInstructorTest.php` (new, no-DB), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Produces: the instructor overview cards use container queries (`@container`, `@md:` columns) and the metric
  cards of Phase 3; the announcement composer (editor toolbar, attachments, audience picker) wraps and its
  buttons are 44px on touch, the hover-only controls follow the Phase 4 announcement-card rule (visible under
  `pointer-coarse:`); the resource manager's file rows become phone list rows (or wrap) with the actions in the
  row-actions sheet; discussion moderation controls are 44px. **Carry-over (check each, fix where it
  reproduces):** the `lg:overflow-y-auto` page cards (`courses/index.php`, `learning-item-panel.php`, and the
  dashboard, schedule, calendar pages) have no sticky bar hidden under the header between 1024 and 1279 and at a
  short desktop (1366×657): state the result per page in the report; `admin/home`, `chairperson-index`,
  `monitor-program`, `dean/courses`, `institution/structure` are Phases 6 and 7.

- [ ] **Step 1: Write the failing tests.** PHPUnit: the overview partials and the resource rows carry the container
  and 44px classes. Playwright: `SCREENS` rows `instructor-course-overview`, `instructor-announcements`,
  `instructor-resources`, `instructor-discussions`; a test at 1366×657 and 1100×768 that no sticky bar on the
  checked pages sits under the header.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new tests, then `tests/e2e/announcements.spec.js` and `tests/e2e/specific-students.spec.js`.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): instructor course overview, announcements, resources and discussions on phones`.

---

### Task 10: Instructor modals

**Files:**
- Modify: every dialog file under `app/Views/pages/courses/features/assessments/partials/modals/`,
  `features/modules/partials/modals/`, `features/grades/partials/modals/` and
  `app/Views/pages/courses/features/assessments/partials/modals/ai-generate.php` that fails the sweep below
  (list the files you changed in the report)
- Test: `tests/feature/InstructorModalsResponsiveTest.php` (new, no-DB), `tests/e2e/responsive.spec.js`

**Interfaces:**
- Consumes: Phase 3's dialog contract (`variant` auto: a form with a visible field is full-screen below `sm`;
  `variant => 'default'` opts a near-single-field dialog out; confirms stay small alerts) and
  `shared/dialog.js` (`data-dialog-pin-actions`).
- Produces: every instructor modal, opened at 375 and 834, has no horizontal overflow, ≥44px controls on touch,
  readable contrast in light and dark, and (for form dialogs) a pinned primary action. Wide bodies (the AI
  generate settings, the import/export question lists, the rubric editors with criteria rows, grade curve, grade
  sheet import preview) scroll their own content: wide previews inside `[data-scroll-x]`. Dialogs that hold one
  short field or only a confirmation opt out of full-screen with `'variant' => 'default'`.

- [ ] **Step 1: Write the failing tests.** Playwright: a `SCREENS` row per host page gets a `dialogs` list with the
  ids of its modals (the sweep opens each at the touch sizes and runs `expectTouchTargets`, `expectNoPanelOverflow`
  and axe on it): assessments page (create, delete), editor (assignees, quiz password, submission type), quiz
  builder (question settings, AI generate, import, export, rubric selector/editor), Grade Work (regrade), gradebook
  (curve, grade-sheet import, override), modules (module editor, unit editor, item create, dependencies, content
  transfer). PHPUnit: dialogs with one short field carry `'variant' => 'default'` (list them in the report).
- [ ] **Step 2: Run the sweep and see which dialogs fail; list them.**
- [ ] **Step 3: Fix each failing dialog's file** minimally.
- [ ] **Step 4: Run** the sweep (smoke, then full in chunks) and the e2e that drive these dialogs
  (`assessment-editor.spec.js`, `export-modal.spec.js`, `content-transfer.spec.js`, `module-dependencies.spec.js`,
  `specific-students.spec.js`), one file at a time.
- [ ] **Step 5: Hand over the commit message:** `feat(responsive): instructor modals fit phones, wide previews scroll inside the dialog`.

---

### Task 11: Phase wrap-up

- [ ] **Step 1: Automated checks.** `npm test` (the calendar stale-date test fails on `main` too), `npm run lint`,
  `npm run build`, `php -l` on changed PHP, then `vendor/bin/phpunit tests/feature` once (MySQL up).
- [ ] **Step 2: Sweeps.** `npx playwright test responsive.spec.js --project=chromium`, then `RESPONSIVE_FULL=1`
  in chunks by size (`@320x640`, `@375x812`, `@430x932`, `@768x1024`, `@834x1112`, `@1024x768`, `@1366x768`,
  `@1440x900`, `@1920x1080`, landscape, zoom200; light and dark), then `--project=webkit`. Fix real failures in
  the owning task's files and say so; do not loosen a check.
- [ ] **Step 3: Regression batches**, one file at a time: `grading.spec.js`, `gradebook.spec.js`,
  `assessment-editor.spec.js`, `assignments.spec.js`, `quizzes.spec.js`, `quiz-security.spec.js`,
  `modules.spec.js`, `module-dependencies.spec.js`, `module-publish.spec.js`, `content-transfer.spec.js`,
  `export-modal.spec.js`, `specific-students.spec.js`, `announcements.spec.js`, `monitor-program.spec.js`,
  `parent.spec.js`, `global-grades-student.spec.js`. Rerun a failure once; compare with `main` before blaming this
  phase (flaky: TC-ASG-01).
- [ ] **Step 4: Review the student, chairperson and dean views** of every shared file this phase changed at `lg`
  and above (dashboard, grade work and score panel, quiz builder, assessment editor and overview, gradebook,
  modules, announcements, resources, discussions, dialogs): they look the same as on `main`. A short note per
  file.
- [ ] **Step 5: Hand over** the summary, the commit messages in order, the baseline step (push; Actions →
  Responsive → Run workflow with `update_snapshots`), and a real-device checklist (iPhone Safari, Android Chrome):
  - Instructor dashboard tabs switch and the To grade dot shows.
  - Grade Work: the score bar stays above the home bar, Rubric expands upward, the keyboard never hides the
    field you are typing in, "Save, next" works; the divider drags on a tablet in landscape.
  - Quiz Builder: "Add question" expands the palette, a question moves up and down, Publish is reachable.
  - Gradebook: the roster scrolls sideways with the student column pinned; score inputs are comfortable to tap.
  - Modules: the ⋯ sheet opens and a module can be moved up and down.
  - Dark mode on Grade Work, the gradebook and the Quiz Builder.
- [ ] **Step 6: Carry into Phase 6:** the chairperson and dean views of every shared file touched here, the
  chairperson and dean dashboards and global grades, `chairperson-index`, `monitor-program`, `dean/courses`,
  `admin/home` and `institution/structure` page cards; Linux baselines for the new screens.
