# Responsive Phase 3: Shared Components. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the shared interface components (`app/Views/components/*` and `resources/js/shared/*`) responsive, so
that phases 4–7 get phone, tablet and desktop behaviour from the component without per-page work. List tables get
phone list rows plus a row-actions sheet, grid tables get a pinned-scroll container, form dialogs become
full-screen pages on phones, and forms, steppers, cards, tabs, toolbars and pagination adapt to their space.

**Architecture:** Extend the existing components in place. The new parameters are optional and default to today's
output, so every existing caller keeps working. Two small JS modules are new (`row-actions.js` for the phone
actions sheet, `scroll-x.js` for the pinned-scroll edge fades). Both are built on the Phase 1 sheet dialog
(`components/dialog.php` + `shared/dialog.js`) and the Phase 2 `lock:` variant. Layout inside a component uses
container queries (`@container`, `@sm:`), and viewport media queries are used only where the whole page must change.

**Tech Stack:** CodeIgniter 4 views, Tailwind v4.3, vanilla ES modules, Vitest + jsdom, PHPUnit (CIUnit feature
tests), Playwright + axe.

**Spec:** `docs/superpowers/specs/2026-11-09-responsive-ui-design.md` (§5 tables, §8 dialogs and forms, §10 type
and spacing, §11 edge conditions, §12 phase 3)

## Global Constraints

- Prerequisite: Phases 1 and 2 are committed (`feat/responsive-2-shell` at 5b377572). Branch
  `feat/responsive-3-components` off it. **Do not commit.** Each task ends by handing over a commit message
  (repo rule).
- No new npm or composer dependencies.
- Brand colour only through tokens; dark mode uses the existing `--dk-*` mapping. Every new surface looks right
  under `html[data-theme="dark"]`. Remember that the dark mapping covers plain classes such as `.bg-white`, not
  responsive-prefixed variants like `xl:bg-white/90`: an opaque base class has to be there.
- Touch targets ≥44×44 under `pointer: coarse` (`pointer-coarse:min-h-11 pointer-coarse:min-w-11`); phone inputs
  ≥16px (`text-base`, may drop to `sm:text-sm`); correct `inputmode` / `autocomplete` where the field type implies
  one.
- Motion uses the Phase 1 tokens (`--motion-fast/base/slow`, `--ease-spring`, `--ease-exit`); reduced motion
  means 0ms. Hover effects only under `(hover: hover)`.
- Every `view()` of a component passes `['saveData' => false]`.
- Keep every existing hook: `[data-table]`, `[data-bulk-bar]`, `[data-select-all]`, `[data-row-select]`,
  `[data-selected-count]`, `[data-bulk-form]`, `[data-tabs]`, `[data-stepper]` with `data-step` / `data-state`,
  `[data-field]` / `[data-field-error]`, `[data-dialog*]`. Existing Vitest, PHPUnit and e2e specs must not be edited
  except where a task says an assertion changes.
- Breakpoints: `sm` 40rem, `md` 48rem, `lg` 64rem, `xl` 80rem, `xs` 25rem, `3xl` 120rem; `lock:` = ≥80rem wide and
  ≥47.5rem tall.
- Pages that call these components are owned by phases 4–7. This phase edits a page file only where a task names
  it. Migrating the ~21 hand-written list tables onto `data-table.php` happens in the phase that owns each screen.

## Review Focus

1. **A table with one column, or with every column marked low priority:** the md–lg table must still show at
   least the first column, and the phone row must still have a title. Task 1's PHPUnit case.
2. **A row with actions but no label column (or an empty primary cell):** the ⋯ sheet's title falls back to the
   row's `label`, then to "Row". Task 2.
3. **Actions that open another dialog or submit a form from inside the sheet:** the action still works, the sheet
   closes, the focus returns sensibly, and the moved nodes return to their row. Task 2's Vitest and e2e cases.
4. **A form dialog that holds only a hidden-field form (a confirm posted as a form):** it stays a small dialog,
   not a full-screen page. Task 4's PHPUnit case.
5. **A container narrower than the page (a form in a dialog at 1366px):** the form stacks into one column because
   the container is narrow, not because the viewport is. Task 5's e2e case.

---

### Task 1: Data table, phone list rows and column priority

**Files:**
- Modify: `app/Views/components/data-table.php`
- Modify: `resources/css/app.css` (a `@layer components` block for the list-row look)
- Test: `tests/feature/DataTableComponentTest.php` (new)

**Interfaces:**
- Produces (new optional params; everything absent renders as today at ≥md):
  - Column key `priority`: `1` (default) always shown at ≥md, `2` shown from `lg`, `3` shown from `xl`. A lower
    priority is hidden with `max-lg:hidden` / `max-xl:hidden` on its `th` and `td`, and only from `md` up. On
    phones, priority doesn't matter; the phone row is built from roles.
  - Column key `role`: `'primary' | 'secondary' | 'status' | 'avatar'`. Defaults when no column sets a role: the
    first column is `primary`, the second `secondary`, and a column whose key is `status` (or whose cell is a
    `status-badge` markup) is `status`. At most one `primary`; extra roles of the same kind are ignored in order.
  - Row key `group` (string): consecutive rows with the same group render in one panel with a small heading
    (`text-[11px] font-semibold text-gray-500`) and a count, on phones only. At ≥md the heading is hidden and the
    table is unchanged. A table with no `group` keys renders a single panel.
  - Param `phoneRows` (bool, default true): `false` keeps the previous stacked-card output below `md` (an escape
    hatch for any table that doesn't fit; no caller sets it in this phase).
- Phone layout below `md` (spec §5, mockup 03 option C): one white `rounded-2xl` panel per group of thin rows. Each
  row is: optional select checkbox · avatar/initials (role `avatar`, or the first two letters of the primary
  text) · primary text (truncates) with a second line holding the status badge and the `secondary` text · the ⋯
  button from Task 2 when the row has `actions`. The row's other columns are not shown on the row; Task 2's sheet
  lists them.
- `fill` mode (the table fills the height its parent gives it): the scroll behaviour is keyed to the shell lock,
  not to `lg`. The wrapper is `lock:min-h-0 lock:flex-1 lock:overflow-y-auto` and `overflow-clip` otherwise
  (`overflow-clip`, not `overflow-hidden`, so the wrapper isn't a scroll container and the pinned heading row can
  stick to the window). The heading row's offset is `sticky top-[var(--app-header-h,0px)] lock:top-0`.

- [ ] **Step 1: Write the failing tests** in `DataTableComponentTest` (render the component with `view()` and parse
  it with DOMDocument/XPath, the house pattern in `tests/feature/DialogComponentTest.php`):
  - `testDefaultColumnsKeepTheirTableOutputAtMd`: a 3-column table with no new params still has the `<table>`,
    `<thead>`, one `<th>` per column and the `max-md:sr-only` heading.
  - `testPriorityColumnsHideBelowTheirBreakpoint`: a priority-2 column's `th` and `td` carry `max-lg:hidden`, a
    priority-3 one `max-xl:hidden`, and priority 1 carries neither.
  - `testTheFirstColumnIsNeverHidden`: priority 3 on the first column is clamped to 1 (Review Focus 1).
  - `testPhoneRowUsesRoles`: with explicit roles, the phone row's primary/secondary/status texts come from the
    named columns.
  - `testDefaultRolesWhenUnset`: first column is primary, second secondary, a `status` key is status.
  - `testGroupsRenderPanelsWithHeadingAndCount`: rows with two distinct `group` values produce two panels, each
    with its heading and count; a table with no groups produces one panel and no heading.
  - `testFillUsesTheLockVariantAndClip`: with `fill`, the wrapper carries `lock:flex-1` and `overflow-clip`, no
    `lg:overflow-y-auto`, and the heading cells carry `top-[var(--app-header-h,0px)]`.
  - `testPhoneRowsFalseKeepsStackedCards`: `phoneRows => false` renders the old `max-md:` stacked-card classes.
- [ ] **Step 2: Run them.** `vendor/bin/phpunit tests/feature/DataTableComponentTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** the params and markup in `data-table.php`, with the header comment updated to document
  them. Keep one `<table>` (one markup, two layouts); the phone panel is made of per-group `<tbody>` elements.
- [ ] **Step 4: Run.** The new tests pass, along with the existing data-table callers' feature tests: run
  `vendor/bin/phpunit tests/feature` once at the end of the task. Then `npm run build`.
- [ ] **Step 5: Hand over the commit message.** `feat(data-table): phone list rows, grouped panels, column priority and a lock-aware fill mode`

---

### Task 2: Row-actions sheet

**Files:**
- Create: `resources/js/shared/row-actions.js`
- Modify: `app/Views/components/data-table.php` (the ⋯ button and the shared sheet)
- Modify: `resources/js/app.js` (import the module)
- Test: `tests/js/shared/row-actions.test.js` (new), `tests/feature/DataTableComponentTest.php` (extend)

**Interfaces:**
- Consumes: `components/dialog.php` with `'variant' => 'sheet'` and the `shared/dialog.js` open/close/focus
  contract (Phase 1); Task 1's phone rows.
- Produces:
  - Markup: on a table whose rows have `actions`, each phone row renders `<button type="button"
    data-row-actions-open aria-haspopup="dialog" aria-label="Actions for {label}">⋯</button>` (`md:hidden`,
    44px), and the table renders one sheet dialog `#{tableId}-actions` (variant `sheet`, title set from JS). The
    `{tableId}` comes from a new optional param `id` (default: a per-request counter, as `form-field` does).
  - The sheet body holds, in order: the row's remaining fields as label/value lines (every column not shown on
    the phone row), then the row's actions. This is my call: the spec's phone row drops columns, so the sheet
    keeps the information reachable.
  - `bindRowActions(root: HTMLElement = document): void`. Opening a ⋯ **moves** (not clones) the row's actions
    cell children into the sheet body and records where they came from. Closing the sheet (Escape, backdrop,
    swipe, ×) moves them back, so event handlers, form state and ids survive. Clicking a link, or a button that
    isn't a submit, inside the sheet closes the sheet first and then lets the action proceed (an action that
    opens another dialog must see the sheet gone). A submit button submits its form first and the sheet closes
    on `dialog:close`/navigation. Focus returns to the ⋯ that opened it.
  - The destructive action (an element with `data-destructive` or a `text-[#C33939]` class) is separated by a
    divider and keeps its red text. Callers don't need to change; this works from those hooks if present.
  - Title fallback order: the row's primary cell text, then `row['label']`, then `"Row"` (Review Focus 2).
- [ ] **Step 1: Write the failing tests.**
  - `row-actions.test.js`:
    - `opening moves the actions into the sheet and closing moves them back` (identity: the same node objects)
    - `the sheet title is the row's primary text, then its label, then "Row"`
    - `Escape closes the sheet and returns the nodes and the focus to the ⋯`
    - `a non-submit action closes the sheet before it runs` (spy: the sheet is hidden when the action's click
      handler runs)
    - `a submit action keeps its form values` (the form's `FormData` is unchanged after a round trip)
    - `two rows do not mix their actions` (open row 1, close, open row 2)
  - `DataTableComponentTest`: `testRowsWithActionsRenderTheOpenerAndOneSheet`, `testNoActionsNoSheet`.
- [ ] **Step 2: Run them.** `npx vitest run tests/js/shared/row-actions.test.js` and `vendor/bin/phpunit
  tests/feature/DataTableComponentTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** `bindRowActions` (delegated on `document`, like `details-menu.js`, so it works after
  SPA swaps) and the markup.
- [ ] **Step 4: Run.** The tests pass, along with `npm test`, `npm run lint` and `npm run build`.
- [ ] **Step 5: Hand over the commit message.** `feat(data-table): row-actions bottom sheet on phones that keeps the row's fields and actions reachable`

---

### Task 3: Grid-scroll container and the permission grid

**Files:**
- Create: `resources/js/shared/scroll-x.js`
- Modify: `resources/css/app.css` (the `[data-scroll-x]` rules, `@layer components`)
- Modify: `resources/js/app.js` (import)
- Modify: `app/Views/components/permission-grid.php`
- Test: `tests/js/shared/scroll-x.test.js` (new), `tests/feature/PermissionGridComponentTest.php` (new)

**Interfaces:**
- Produces (spec §5 grid tables; reused by phase 5's gradebooks):
  - Markup contract: `<div data-scroll-x tabindex="0" role="region" aria-label="…">` containing a `<table>`.
    Optional `data-sticky-first` on the container pins the first column; the `thead` is pinned by the table's
    own `sticky top-0` cells (as `permission-grid` already does).
  - CSS: the container scrolls horizontally (`overflow-x-auto`, momentum scrolling), the first column cells get
    `sticky left-0 z-10` with an opaque `bg-white` (and a dark-mode equivalent via the plain class), and
    left/right edge fades appear as `::before`/`::after` shadows driven by `data-fade-start` / `data-fade-end`.
  - `bindScrollX(root = document): void` sets `data-fade-start` / `data-fade-end` from `scrollLeft`, updates on
    scroll and on `ResizeObserver` size changes, and is safe to call again after SPA swaps.
  - Inputs/controls inside a `[data-scroll-x]` get `pointer-coarse:min-h-11 pointer-coarse:min-w-11`.
  - `permission-grid.php` uses the container in both modes. The scrollable mode keeps its existing vertical
    scroll and sticky headers; in the non-scrollable mode the wrapper becomes `[data-scroll-x]` instead of a bare
    `overflow-x-auto`. The permission cell buttons grow to 44px under a coarse pointer.
- [ ] **Step 1: Write the failing tests.**
  - `scroll-x.test.js` (stub `scrollLeft`, `scrollWidth`, `clientWidth`):
    - `no fades when the content fits`
    - `only the end fade at scrollLeft 0`
    - `both fades in the middle`
    - `only the start fade at the end`
    - `a resize recomputes the fades`
    - `binding twice does not double the listeners`
  - `PermissionGridComponentTest`: both modes render a `[data-scroll-x]` container with `tabindex="0"`,
    `role="region"` and a label, the first column cells carry the sticky classes, and the cell buttons carry the
    coarse sizing classes.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** The tests pass, along with `vendor/bin/phpunit tests/feature/AdminRolesPageTest.php
  tests/feature/InstitutionRolesPageTest.php` (find the exact roles-page test names by grep), `npm test`, `npm run lint`
  and `npm run build`.
- [ ] **Step 5: Hand over the commit message.** `feat(ui): pinned horizontal scroll container with edge fades; permission grid uses it`

---

### Task 4: Form dialogs become full-screen pages on phones

**Files:**
- Modify: `app/Views/components/dialog.php`, `app/Views/components/confirm.php`, `app/Views/layouts/partials/reauth-dialog.php`
- Modify: `resources/css/app.css` (the fullscreen footer rules)
- Test: `tests/feature/DialogComponentTest.php` (extend), `tests/js/shared/views-guard.test.js` (one case)

**Interfaces:**
- Produces (spec §8; the product owner chose full-screen forms on phones, confirm dialogs stay small):
  - `variant` absent now means **auto**: a dialog whose `$body` holds a form with at least one visible field
    (an `<input>` that isn't `type="hidden"`, a `<textarea>` or a `<select>`) renders as `fullscreen`. Everything
    else renders as before. `'variant' => 'default'` forces the old bottom dialog; `centered` forces a centered
    one. This is my call: it applies the owner's decision to all 49 existing dialog calls without editing the
    pages. The ones that must stay small opt out explicitly.
  - On a fullscreen dialog **without** `topAction`: the footer is a sticky bottom bar on phones (`sticky
    bottom-0`, `bg-white`, `pb-safe-4`, a top border), and `[data-dialog-close]` buttons inside it are hidden on
    phones (the top bar already has Cancel). On a dialog **with** `topAction` the footer stays hidden on phones,
    as today.
  - `confirm.php` passes `'variant' => 'default'` and `centered` (already centered): it never goes full-screen,
    even though its body has a type-to-confirm input. `reauth-dialog.php` is a short password prompt: it gets
    `'centered' => true`.
  - Guard: a Vitest case in `views-guard.test.js`, `component calls that pass dialog-shaping keys also pass
    saveData`: for every `view('components/…', [ … ])` call in `app/Views` that contains any of the keys
    `'variant'`, `'centered'`, `'topAction'` or `'size'`, the call's options must contain `saveData`. This pins
    the leak that bit Phase 1, and matters more now that `variant` absent means auto.
- [ ] **Step 1: Write the failing tests.** In `DialogComponentTest`:
  - `testAFormWithAFieldRendersFullscreen`
  - `testAHiddenFieldsOnlyFormStaysADefaultDialog` (Review Focus 4)
  - `testExplicitDefaultOptsOut`
  - `testCenteredWinsOverAuto`
  - `testFullscreenFooterIsStickyAndHidesFooterCancelOnPhones`
  - `testConfirmNeverGoesFullscreen`
  - `testAutoStaysDefaultWhenThereIsNoForm`
  Plus the views-guard case. It will fail on any existing call that passes those keys without `saveData`: fix
  those calls in this task (a one-line change each) and list them in the report.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.** Also run the existing dialog-using feature tests (`vendor/bin/phpunit tests/feature`
  once at the end), because dialog markup assertions in page tests may change. A changed assertion must be
  reported, not silently loosened.
- [ ] **Step 4: Run.** The tests pass, along with the e2e specs that open dialogs: `npx playwright test
  tests/e2e/institutional-admin.spec.js tests/e2e/dean.spec.js tests/e2e/follow-ups.spec.js --project=chromium`
  (find the spec names by grep). At the desktop viewport the dialogs look as before.
- [ ] **Step 5: Hand over the commit message.** `feat(dialog): form dialogs fill the screen on phones with a sticky primary action; confirms stay small`

---

### Task 5: Form fields, toolbar, stepper and pagination

**Files:**
- Modify: `app/Views/components/form-field.php`, `app/Views/components/toolbar.php`,
  `app/Views/components/stepper.php`, `app/Views/components/pagination.php`
- Modify: `resources/css/app.css` (a `.form-grid` container-query component)
- Test: `tests/feature/FormComponentsTest.php` (new)

**Interfaces:**
- Produces:
  - **`form-field.php`:** controls are `text-base sm:text-sm` and `pointer-coarse:min-h-11`; new optional params
    `inputmode` and `autocomplete` (escaped, emitted only when set), with defaults derived from `type` when unset:
    `email` → `inputmode="email" autocomplete="email"`, `number` → `inputmode="numeric"`, `password` →
    `autocomplete="current-password"`. A caller's explicit value wins. Checkbox rows get a 44px hit area under a
    coarse pointer.
  - **`.form-grid`:** `@container` with `grid-template-columns: 1fr` below a container width of 28rem, two
    columns from there. A field's `span` param keeps working. Defined as a CSS component, used by
    `components/dialog.php`'s body wrapper (`@container`) and documented for page forms to adopt. The dialog's
    body wrapper becomes a query container so a form in a narrow dialog stacks even on a wide screen (Review
    Focus 5).
  - **`toolbar.php`:** fields are `text-base sm:text-sm` and 44px under coarse; one column on phones; the Apply
    button is full width on phones. The `inline` layout is unchanged from `xl`.
  - **`stepper.php`:** below `sm`, only the current step shows as "Step N of M" with its label and a thin progress
    bar (`role="progressbar"` with `aria-valuenow`/`aria-valuemax`); the full list stays from `sm` up. The
    `data-stepper` / `data-step` / `data-state` hooks stay on the full list (visually hidden below `sm`, not
    removed), so scripts keep working.
  - **`pagination.php`:** Previous/Next are 44px under coarse and share the row on phones (`flex-1`), with the page
    count above them.
- [ ] **Step 1: Write the failing tests** (`FormComponentsTest`, render and parse as in Task 1):
  - `testFormFieldInputsAre16pxOnPhonesAnd44pxOnTouch`
  - `testEmailAndNumberDefaultsAndExplicitWins`
  - `testToolbarFieldsAreStackedAndLargeOnPhones`
  - `testStepperKeepsItsHooksAndAddsThePhoneSummary`: `data-step` count unchanged, plus a `role="progressbar"`
    element with the right `aria-valuenow`.
  - `testPaginationTargets`
  - a Vitest case in `tests/js/shared/tokens.test.js` that `app.css` contains the `.form-grid` rule with
    `container-type: inline-size` semantics (an `@container` query at 28rem).
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** The tests pass, along with `npm test`, `npm run lint`, `npm run build` and `vendor/bin/phpunit
  tests/feature` once at the end.
- [ ] **Step 5: Hand over the commit message.** `feat(forms): phone-sized fields, container-query form grid, compact stepper and touch-sized pagination`

---

### Task 6: Cards, page head, tabs, breadcrumb and empty state

**Files:**
- Modify: `app/Views/components/metric-card.php`, `app/Views/components/app-tile.php`,
  `app/Views/components/page-head.php`, `app/Views/components/page-header.php`,
  `app/Views/components/tabs.php`, `app/Views/components/breadcrumb.php`, `app/Views/components/empty-state.php`,
  `app/Views/components/skeleton.php`
- Test: `tests/feature/LayoutComponentsTest.php` (new)

**Interfaces:**
- Produces:
  - **`metric-card.php`:** the card is a query container (`@container`); the value uses `text-fluid-metric` for the
    prominent size and `text-2xl` otherwise (spec §10), the icon bubble hides below a container width of 14rem
    (`@max-[14rem]:hidden`), and the interactive card gets `pointer-coarse` feedback (an `active:` scale) while
    the hover lift moves under `(hover: hover)`.
  - **`app-tile.php`:** hover effects only under `(hover: hover)`; the tile label wraps to two lines; the hit area
    is ≥44px.
  - **`page-head.php` and `page-header.php`:** the `<h1>` uses `text-fluid-h1`; the actions wrap under the title on
    phones and each button grows to full width below `xs`.
  - **`tabs.php`:** below `md` the tab list is a single-row horizontal scroller (`[data-scroll-x]` from Task 3,
    no wrapping) with edge fades, each tab ≥44px under coarse, and the active tab scrolls into view on load
    (a few lines in `scroll-x.js`: `bindScrollX` scrolls `[aria-current="page"]` into view on init). From `md` up
    it wraps as today.
  - **`breadcrumb.php`:** below `sm` the trail is one line, the last crumb is always shown and truncates, and the
    earlier crumbs collapse to an ellipsis link that expands inline (a `<details>`, no script). It keeps the
    sticky behaviour from Phase 2.
  - **`empty-state.php`:** fluid vertical padding (`py-fluid`-style, from `gap-fluid` / the spacing token) and a
    full-width action button on phones.
  - **`skeleton.php`:** a `variant` param `'rows' | 'list'` (default `rows`); `list` draws phone-shaped list rows
    (avatar circle, two text lines, a short badge), used by the data-table loading state on phones.
- [ ] **Step 1: Write the failing tests** (`LayoutComponentsTest`): one case per bullet that can be asserted on
  markup (`testMetricCardIsAContainerWithFluidValue`, `testTabsScrollOnPhones`, `testBreadcrumbCollapsesEarlierCrumbs`,
  `testSkeletonListVariant`, `testPageHeadTitleIsFluid`), plus
  `tests/js/shared/scroll-x.test.js::scrolls the current tab into view on init` (extend Task 3's file).
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run.** The tests pass, along with `npm test`, `npm run lint`, `npm run build` and `vendor/bin/phpunit
  tests/feature` once at the end.
- [ ] **Step 5: Hand over the commit message.** `feat(components): container-query cards, fluid page heads, scrolling tabs, collapsing breadcrumbs and phone list skeletons`

---

### Task 7: UI-kit showcase and responsive sweep for the components

**Files:**
- Modify: `app/Views/pages/ui-kit/index.php`
- Modify: `tests/e2e/responsive.spec.js`, `tests/e2e/support/responsive.js`

**Interfaces:**
- Consumes: the Phase 1/2 harness (`SCREENS` rows `{ name, path, as, scope?, overflowScope?, dialogs? }`,
  `expectNoPageOverflow`, `expectTouchTargets`, `expectAccessible`, `snap`, `setTheme`, `sizes()`).
- Produces:
  - **ui-kit:** the `[data-responsive-kit]` root gains a "Components" section holding: a grouped data table with
    row actions and a priority column; a `[data-scroll-x]` grid with a pinned first column; a form dialog (auto
    full-screen) and the existing confirm; a form in a narrow container; the stepper; tabs; pagination; two
    metric cards of different widths; a breadcrumb; an empty state. Real copy, sentence case.
  - **New e2e tests in a `describe("components")` block:**
    - `list table becomes list rows on a phone`: at 375 the `<thead>` is `sr-only`, rows render as panels, and
      `⋯` opens the sheet with the row's fields and actions (touch targets pass inside the sheet).
    - `priority columns hide at md and show again at lg`: at 800 the priority-2 column is hidden, at 1100 shown.
    - `row action returns its nodes on close`: open ⋯, press Escape, assert the actions are back in the row.
    - `grid scroll pins the first column`: at 375 scroll the grid by 200px, the first column's x stays at the
      container's left edge, and `data-fade-start` is set.
    - `form dialog is full screen on a phone and centered on a laptop`: at 375 the panel's rect fills the viewport;
      at 1366 it doesn't. Confirm stays small at both.
    - `a form in a narrow container stacks`: at 1366 the narrow-container form has one column, the wide one two.
    - `stepper shows the summary on a phone`.
    - The existing sweep rows (touch targets, axe, overflow, snapshots) run over the new section at every size.
- [ ] **Step 1: Build the section and the tests.**
- [ ] **Step 2: Run** `npx playwright test responsive.spec.js --project=chromium`, then `RESPONSIVE_FULL=1 …`, then
  `--project=webkit`. Everything passes. Fix real failures only in Task 1–6 files, and say so.
- [ ] **Step 3: Run the specs of the pages that use these components most**, in small batches (MySQL on this
  machine is killed under memory pressure; check with `mysqladmin ping` first): `tests/e2e/institutional-admin.spec.js`,
  `tests/e2e/system-admin.spec.js`, `tests/e2e/dean.spec.js`, `tests/e2e/chairperson.spec.js`. Tests that also
  fail on main (TC-DEAN-03, TC-DEAN-04, TC-GGR-04, TC-PRT-02, TC-MSG-06, TC-MSG-08) may fail; rerun anything else once.
- [ ] **Step 4: Hand over the commit message and baseline note.**
  - Commit message: `test(e2e): responsive sweep and ui-kit showcase for the shared components`
  - Note: regenerate the Linux baselines (Actions → Responsive → Run workflow with `update_snapshots`).

---

### Task 8: Phase wrap-up

- [ ] **Step 1:** `npm test`, `npm run lint`, `npm run build` and `vendor/bin/phpunit tests/feature` pass. Run the
  Task 7 e2e batches once more.
- [ ] **Step 2:** A real-device checklist for the product owner (an iPhone in Safari and an Android phone in
  Chrome), on `/ui-kit` and on two real pages that use the components (the institution People list and a dean
  page):
  - A list becomes rows, ⋯ opens the sheet, and an action works and returns the row intact.
  - A form dialog is a full page with Cancel at the top left and the primary action pinned at the bottom (or top
    right when the dialog sets `topAction`), and typing doesn't zoom.
  - The permission grid scrolls sideways with its first column pinned and the fades showing.
  - Tabs scroll, and the active one is in view.
  - Landscape: the sticky footer and the sheet stay above the home bar.
- [ ] **Step 3:** Hand over the summary, the commit messages in order, and the baseline step. Carry into Phase 4:
  the seven page cards that still use `lg:overflow-y-auto` (list in the progress notes), now that `data-table`'s
  `fill` mode is lock-aware.
