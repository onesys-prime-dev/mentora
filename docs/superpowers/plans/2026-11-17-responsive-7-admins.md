# Responsive Phase 7: Institution Admin and Platform Admin Screens. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every screen the institution administrator and the platform administrator use work from 280px to
2560px: both home pages, the institution's structure, terms, offerings, sections, people, courses, reports,
settings and integrations pages, offering schedule and copy, and the platform admin's institutions, users, plans,
monitoring, flags, audit, backups, archive and settings pages, with every dialog they open.

**Architecture:** Phases 1 to 6 built the pieces (`components/data-table.php` with phone rows and the row-actions
sheet, `[data-scroll-x]` pinned grids, auto full-screen form dialogs, the expandable action bar, phone tabs,
container-query cards, the three-tier side menus). This phase applies them to the admin views and adds no new shared
module. The side menus themselves are done (Phase 2). Admin pages keep their desktop layout from `lg` up; changes
are `max-lg:`, `max-md:` or container-query rules. Both admins scroll normally below the lock (≥80rem wide and
≥47.5rem tall).

**Tech Stack:** CodeIgniter 4 views, Tailwind v4.3, vanilla ES modules, Vitest + jsdom, PHPUnit (CIUnit feature
tests), Playwright + axe.

**Spec:** `docs/superpowers/specs/2026-11-09-responsive-ui-design.md` (§4 side menus, §5 tables, §6 dashboards and
the lock, §8 dialogs and forms, §10 type and spacing, §11 edge conditions, §12 phase 7)

## Global Constraints

- Prerequisite: Phase 6 is committed (`feat/responsive-6-chairperson-dean` at 536992f6). Branch
  `feat/responsive-7-admins` off it (already created). **Do not commit.** Each task ends by handing over a commit
  message.
- No new npm or composer dependencies.
- Brand colour only through tokens; dark mode uses the existing `--dk-*` mapping, which covers plain classes such
  as `.bg-white`, not responsive-prefixed variants (an opaque base class has to be there). A new dark mapping must
  be scoped to the admin hooks, because the unlayered mappings beat utilities and leak into every role.
- Touch targets ≥44×44 under `pointer: coarse` (`pointer-coarse:min-h-11 pointer-coarse:min-w-11`); phone inputs
  ≥16px (`text-base`, may drop to `sm:text-sm`); correct `inputmode` / `autocomplete`.
- Motion uses the Phase 1 tokens; reduced motion means 0ms. Hover effects only under `(hover: hover)`. No
  scroll-reveal animations.
- Every `view()` of a component or partial passes `['saveData' => false]`.
- Shared views stay shared: every role shares `components/*`, and the admin pages reuse the same dialog and table
  components as the staff pages. A change that is only for phones, tablets or narrow containers uses `max-lg:`,
  `max-md:` or container queries. Do not change what any role sees at `lg` and above; where a shared view must
  change for everyone, say so in the task report. Contrast and 44px fixes found by the sweep are allowed everywhere
  (say so).
- Print and export views (`institution/export.php`, `institution/report-offering.php` print, CSV/PDF downloads) are
  not changed and are verified unbroken.
- Keep every existing hook (`data-admin-menu`, `data-institution-menu`, `data-health*`, `data-check`,
  `data-status-count`, `data-total`, `data-institution`, `[data-dialog*]`, `[data-action-bar*]`,
  `[data-scroll-x]`, `[data-phone-*]`). Existing Vitest, PHPUnit and e2e specs must not be edited except where a
  task says an assertion changes. The institution routes behind the `readonly` filter stay read-only: a change must
  never add a write to them. Destructive actions keep their confirm step and the platform admin's password
  re-confirmation (`admin/reauth`): never move a button out of its form or hide a confirm.
- Breakpoints: `sm` 40rem, `md` 48rem, `lg` 64rem, `xl` 80rem, `xs` 25rem, `3xl` 120rem; `lock:` = ≥80rem wide and
  ≥47.5rem tall. Fluid type `text-fluid-h1/h2/metric`, spacing `px-gutter`, `gap-fluid`; fluid page padding
  `p-5 sm:p-8 lg:p-10`.
- Hand-written list tables move onto `components/data-table.php`; grid tables get `[data-scroll-x]` (sticky first
  column where the first column names the row, and every cell of that column carries its own `sticky left-0`). A
  data-table column that holds a live control needs a `role` or an edit path (form controls render as disabled
  read-only copies on phones).
- CSS cascade rule: container-query (`@…:`) rules come after breakpoint (`lg:`) rules in the built CSS, so a pair
  on the same property with different values must be guarded (`max-lg:@xs:…`); and a container query never matches
  the element that carries `@container`, only a descendant.
- Lessons from Phases 4 to 6: the e2e server's links start with `/index.php`, so never anchor a regex on
  `/institution` or `/admin`; the sweep's `open()` waits only for `main`, so scope a row to loaded content; axe
  contrast and 44px failures on pre-existing greys and small links are real; `expectTouchTargets` skips
  `display:inline` elements, so measure inline links explicitly; a form control tied to a form by `form="…"` is
  fine; focus must stay inside a dialog after an action; a sort header link below `md` stays in the sr-only
  heading row (it is revealed on focus), never replaced by a plain label.
- Line endings: check `git ls-files --eol <file>` before editing, keep each file's own, new files LF, never
  `sed -i` a CRLF file (edit raw bytes).
- The platform admin signs in with an emailed code (`tests/e2e/support/admin.js`), not the sweep's `as` password
  sign-in. The e2e admin account is created by `tests/e2e/support/serve.js`.

## Review Focus

- A platform-admin or institution-admin list with 0 rows and with a very long institution, user or course name
  (no overflow; the empty state is readable at 280px).
- A destructive action (end an assignment, archive or delete an institution, deactivate a user, close a term) on a
  phone: the confirm dialog fits, its confirm button is reachable above the keyboard and the password re-confirm
  page still works.
- A page whose form has many fields (institution settings with 12 forms, user show with 7, integrations): the save
  buttons of one form never submit another, and the keyboard never hides the field being typed in.
- Offering schedule and copy: the checkbox and date grids at 320px; a live control inside a pinned table must stay
  reachable and 44px on touch.
- The admin menus at 1024 on touch (the rail) and in a short landscape phone (667×375): content is not covered by a
  fixed bar.

---

## File Structure

Admin views live in `app/Views/pages/admin/**` and `app/Views/pages/institution/**`. Page-specific scripts are
`resources/js/pages/admin/**` and `resources/js/pages/institution/**` (find the one a view loads before changing
it; keep hooks). Tests: `tests/e2e/responsive.spec.js` (rows and checks), `tests/e2e/support/sign-in.js`,
PHPUnit under `tests/feature/` (one new file per two tasks, named in the task), Vitest under `tests/js/pages/`.
`resources/css/app.css` only for scoped dark mappings.

### Task 1: Harness, admin sign-in in the sweep and diagnostic rows

**Files:**
- Modify: `tests/e2e/responsive.spec.js`, `tests/e2e/support/sign-in.js`
- Create: the baseline list in `.superpowers/sdd/2026-11-17-responsive-7-admins/task-1-report.md`

**Interfaces:**
- Produces: `SCREENS` rows with `as: "institutionAdmin"` (existing password sign-in) and `as: "systemAdmin"`
  (new: the sweep's `signIn` branches on it and calls `signInAsAdmin(page)` from `support/admin.js`, then
  `page.goto(path)`); a row per screen in the list below; a `RF7` marker convention for seeded rows (`runSql`
  returns no ids, so cleanup is by unique marker, before the test and in `finally`, plus a file-level `beforeAll`).

- [ ] **Step 1:** Make `systemAdmin` work in the sweep's sign-in helper without touching other roles. A proof test:
  `admin-shell` still passes, and a new `admin-home` row loads `/admin/dashboard`.
- [ ] **Step 2:** Add one row per screen, `scope: "main"` unless a task names a tighter scope, with these paths
  (`/institution/...` as `institutionAdmin`, `/admin/...` as `systemAdmin`):
  - institution: `dashboard`, `structure`, `terms`, `offerings`, `offerings/{id}/schedule`, `terms/copy`,
    `terms/{id}/close`, `sections`, `sections/{id}`, `enrolments/offering/{id}`, `people`, `parents`, `courses`,
    `roles`, `upload`, `export`, `reports`, `reports/courses`, `reports/at-risk`, `integrations`, `settings`,
    `settings/parents`, `subscription`, `audit`. Ids come from the seeded data via a helper like `coursePath`
    (find the first offering/section in the DB); a path that redirects is checked and the row points at the final
    page. A route the account may not open (permission filter) gets no row: say which in the report.
  - platform admin: `dashboard`, `monitoring`, `audit-log`, `roles`, `flags`, `institutions`, `institutions/new`,
    `institutions/{id}`, `institutions/{id}/edit`, `institutions/{id}/users`, `institutions/{id}/audit`,
    `institutions/{id}/subscription`, `institutions/{id}/integrations`, `institutions/{id}/flags`,
    `institutions/{id}/onboarding`, `institutions/{id}/onboarding/subscription`,
    `institutions/{id}/onboarding/administrator`, `plans`, `plans/new`, `plans/{id}/edit`, `users`, `users/new`,
    `users/{id}`, `settings/general`, `settings/security`, `backups`, `archive`.
- [ ] **Step 3:** Run the new rows on `@375x812` and `@1366x768` only (one runner, MySQL pinged first). Record every
  failure by row and cause in the report as the diagnostic baseline that the later tasks work down. No fixes here.
  Expected: Phase 1-6 rows still pass.
- [ ] **Step 4:** Commit message handed over.

### Task 2: Institution home, subscription, roles, audit, export

**Files:**
- Modify: `app/Views/pages/institution/{home,subscription,roles,audit,export,no-institution}.php`
- Create: `tests/feature/InstitutionPagesResponsiveTest.php` (Task 3 to 5 append methods)

- [ ] **Step 1: Failing PHPUnit** `InstitutionPagesResponsiveTest::testHomeCardsStackOnAPhone` renders `home` with
  the repo's feature-test pattern and asserts the stat grid has a one-column phone base, every list has an empty
  state, and the audit and subscription tables are `components/data-table` (`data-table` marker).
- [ ] **Step 2:** Home: stat and shortcut cards collapse to 1 column below `sm`, 2 columns to `lg`, the container
  rule applies where a card sits beside another (`@container` on the wrapper, not the card); long names truncate with
  a title. Subscription and audit: on `components/data-table` (audit keeps its filter bar and export link; filters
  collapse as in the staff audit log from Phase 6). Roles: a read-only grid, `[data-scroll-x]` with a pinned role
  column if it is a matrix. Export: the form stacks, the primary button is 44px on touch.
- [ ] **Step 3:** Sweep rows for these pages pass at all matrix sizes in light and dark; axe clean; touch targets
  44px on touch; no page or panel overflow.
- [ ] **Step 4:** Commit message handed over.

### Task 3: Structure page and its dialogs

**Files:**
- Modify: `app/Views/pages/institution/structure.php` (609 lines, 6 dialogs, 4 forms); the script it loads
- Test: append to `InstitutionPagesResponsiveTest.php`; e2e `institution-structure.spec.js` must keep passing

- [ ] **Step 1: Failing PHPUnit** `testStructureDialogsUseTheRightVariant`: dialogs with a single short field
  (`Assign a dean`, `Assign a chairperson`) pass `'variant' => 'default'` (bottom dialog); dialogs with several
  fields stay full-screen on phones; every dialog keeps its `id`.
- [ ] **Step 2:** **Ruling (the Phase 3 open question):** the two near-single-field dialogs go back to bottom
  dialogs by adding `'variant' => 'default'`; this is one line each and reversible. People's "Change roles" dialog
  is handled in Task 6.
- [ ] **Step 3:** The college / department / program cards: header actions wrap or collapse into a `⋯` menu on
  phones (44px on touch); program rows are list rows, not a table; expanding a card never shifts the header; the
  college and department selectors are native selects or the existing filter dropdown.
- [ ] **Step 4:** Sweep rows (`institution-structure` plus one row per dialog via `dialogs: [{id, open}]`) pass in
  light and dark; dialog focus stays inside after the action. `institution-structure.spec.js` passes.
- [ ] **Step 5:** Commit message handed over.

### Task 4: Terms, offerings, sections, enrolments

**Files:**
- Modify: `app/Views/pages/institution/{terms,term-close,offerings,sections,section,roster}.php`
- Test: append to `InstitutionPagesResponsiveTest.php`

- [ ] **Step 1: Failing PHPUnit** `testListsAreDataTablesWithRoleAndActions`: terms, offerings, sections and roster
  render through `components/data-table`; a column with a live control has a `role` or an edit path; `term-close`
  keeps its confirm and the destructive button is inside the form.
- [ ] **Step 2:** Convert the hand-written list markup to `components/data-table` with the row-actions sheet for the
  per-row actions; the term-close page's counts become a stacked summary; the roster's checkbox column is a pinned
  `[data-scroll-x]` grid if the table is wide; filters follow the Phase 4 filter-dropdown pattern.
- [ ] **Step 3:** Offering and section create/edit dialogs: full-screen forms on phones with the pinned primary
  action (`data-dialog-pin-actions`), 16px fields; a dialog that holds one field is `variant => default`.
- [ ] **Step 4:** Sweep rows pass; dialogs open through `dialogs:`; a phone-row check at 375 for terms and
  offerings; empty state at 280.
- [ ] **Step 5:** Commit message handed over.

### Task 5: Offering schedule and offering copy

**Files:**
- Modify: `app/Views/pages/institution/{offering-schedule,offering-copy}.php`; their scripts
- Test: append to `InstitutionPagesResponsiveTest.php`

- [ ] **Step 1: Failing PHPUnit** `testScheduleAndCopyGridsArePinned`: both tables sit in `[data-scroll-x]`, the
  first-column cells carry `sticky left-0`, every checkbox and time input has an accessible name.
- [ ] **Step 2:** Schedule: day/time rows become a stacked card per row below `md` (day, start, end, room as
  labelled fields) and keep the table from `md` up; add/remove-row buttons are 44px on touch. Copy: the source and
  target filters stack; the offering list is a pinned scroll grid with a select-all checkbox that stays reachable;
  the primary "Copy" bar is an action bar pinned at the bottom with `pb-safe-4` on phones.
- [ ] **Step 3:** Sweep rows pass; a check at 320 that the checkbox column stays pinned and 44px on touch and the
  copy button is above the keyboard when a date input is focused.
- [ ] **Step 4:** Commit message handed over.

### Task 6: People, parents, courses, upload, integrations, settings

**Files:**
- Modify: `app/Views/pages/institution/{people,parents,courses,upload,integrations,settings}.php`; their scripts
- Test: append to `InstitutionPagesResponsiveTest.php`

- [ ] **Step 1: Failing PHPUnit** `testPeopleAndSettingsAreMobileSafe`: people and parents render on
  `components/data-table` with row actions; the "Change roles" dialog passes `'variant' => 'default'` if it holds
  one field (otherwise stays full-screen); settings keeps its 12 forms but the tab bar is a scrolling `[data-scroll-x]`
  strip, every form has its own submit and none is nested.
- [ ] **Step 2:** People: tabs and filters as in the staff lists; invite/add dialogs full-screen with the pinned
  action; the role chips wrap. Courses: list on data-table with the row-actions sheet. Upload: the file drop
  area is a 44px tap target, the preview table is a pinned grid, the error list is readable at 320. Integrations
  and Settings: forms stack to one column below `md` (`@container` on the section wrapper), switches and toggles are
  44px on touch, the save button of each form is its own, below the fields, not sticky across forms.
- [ ] **Step 3:** Sweep rows and dialogs pass in light and dark; touch targets; focus returns to the opener.
- [ ] **Step 4:** Commit message handed over.

### Task 7: Institution reports

**Files:**
- Modify: `app/Views/pages/institution/{reports,report-offering}.php`
- Test: append to `InstitutionPagesResponsiveTest.php`

- [ ] **Step 1: Failing PHPUnit** `testReportsTablesScrollOrStack`: report tables are `components/data-table` or
  `[data-scroll-x]` grids; the report-offering print view is untouched (its `@media print` rules and the print
  button remain).
- [ ] **Step 2:** Reports: the tab strip scrolls, charts or bars scale to the container (`@container`), tables
  become phone rows. At-risk list: row per student with the risk chip; filters in the filter dropdown.
- [ ] **Step 3:** Sweep rows (`reports`, `reports/courses`, `reports/at-risk`, offering report) pass at all sizes.
  Print preview check is a note in the report.
- [ ] **Step 4:** Commit message handed over.

### Task 8: Platform admin home, monitoring, audit, roles, flags, backups, archive

**Files:**
- Modify: `app/Views/pages/admin/{home,monitoring,audit-log,roles,backups,archive}.php`,
  `app/Views/pages/admin/flags/{index,institution}.php`; the scripts they load (monitoring polls `monitoring/live`)
- Create: `tests/feature/AdminPagesResponsiveTest.php` (Task 9 appends methods)

- [ ] **Step 1: Failing PHPUnit** `AdminPagesResponsiveTest::testHomeAndMonitoringCellsAreFixedHeight`: the health
  cells and snapshot tiles keep their fixed heights (`h-16`, `h-20`, `h-24`) so a live refresh never shifts the
  layout; the monitoring institution table is a data-table or a `[data-scroll-x]` grid with a pinned name column;
  every list has an empty state.
- [ ] **Step 2:** Home and monitoring: health grid `grid-cols-2 sm:grid-cols-3 lg:grid-cols-6` stays (it already
  adapts); verify at 280 and 2560; the activity table on home becomes data-table rows; monitoring's live refresh
  keeps focus and scroll. Audit log: filters collapse into the filter dropdown below `lg`, the export link is 44px on
  touch, the table is a data-table (already). Flags: the toggle rows are list rows with 44px switches. Backups and
  archive: data-table with the row actions sheet; the destructive "restore" and "purge" actions keep their confirm
  and re-auth.
- [ ] **Step 3:** Sweep rows pass in light and dark; the `1024 admin rail on touch` test still passes; a live
  refresh at 375 does not move the viewport.
- [ ] **Step 4:** Commit message handed over.

### Task 9: Platform admin institutions, plans, users, settings

**Files:**
- Modify: `app/Views/pages/admin/institutions/{index,form,show,users,audit,integrations}.php`,
  `app/Views/pages/admin/partials/{institution-tabs,onboarding-steps,term-fields}.php`,
  `app/Views/pages/admin/onboarding/{administrator,subscription}.php`, `app/Views/pages/admin/subscription.php`,
  `app/Views/pages/admin/plans/{index,form}.php`, `app/Views/pages/admin/users/{index,new,show}.php`,
  `app/Views/pages/admin/settings/{general,security}.php`
- Test: append to `AdminPagesResponsiveTest.php`

- [ ] **Step 1: Failing PHPUnit** `testInstitutionPagesShareOneScrollingTabStrip`: `institution-tabs` is a
  `[data-scroll-x]` strip with the current tab marked `aria-current="page"`; onboarding steps use the stepper
  component and collapse to "Step n of m" below `sm`; `users/show` keeps its 7 forms un-nested.
- [ ] **Step 2:** Institutions index and users index on data-table with the row-actions sheet and filter dropdown;
  `show` cards use container queries; `users` (institution people) tables become data-table; `integrations`
  and `subscription` tables are pinned grids; forms stack to one column below `md` with 16px fields and 44px
  buttons; onboarding steps and plan forms follow the Phase 3 stepper and form rules; `users/show`'s destructive
  actions (deactivate, reset 2FA, end session) keep their confirm and re-auth and are reachable on a phone.
- [ ] **Step 3:** Sweep rows pass in light and dark at all sizes; the destructive confirm dialog fits at 375 and its
  confirm button is above the keyboard.
- [ ] **Step 4:** Commit message handed over.

### Task 10: Phase wrap-up

- [ ] **Step 1: Automated checks.** `npm test` (the calendar stale-date test fails on `main` too), `npm run lint`,
  `npm run build`, `php -l` on changed PHP, then `vendor/bin/phpunit tests/feature` once (MySQL up).
- [ ] **Step 2: Sweeps**, one runner at a time, MySQL pinged before each: `npx playwright test responsive.spec.js
  --project=chromium`, then `RESPONSIVE_FULL=1` one size per run in chunks (`@320x640`, `@375x812`, `@430x932`,
  `@768x1024`, `@834x1112`, `@1024x768`, `@1366x768`, `@1440x900`, `@1920x1080`, `@667x375`, `@zoom200`; light and
  dark), then `--project=webkit`. Memory stops are common here: rerun a crash once and do not loosen a check.
- [ ] **Step 3: Regression batches**, one file at a time: `institutional-admin.spec.js`,
  `institution-structure.spec.js`, `system-admin.spec.js`, `menu-placement.spec.js`, `auth-login.spec.js`,
  `parent.spec.js`. Known failures on `main`: TC-DEAN-03, TC-DEAN-04, TC-GGR-04, TC-PRT-02, TC-MSG-06; flaky
  TC-GGR-13 (compare before blaming this phase).
- [ ] **Step 4: Review the student, instructor, staff and admin views** of every shared file this phase changed at
  `lg` and above: they look the same as on `main`. A short note per file.
- [ ] **Step 5: Hand over** the summary, the commit messages in order, the baseline step (push; Actions →
  Responsive → Run workflow with `update_snapshots`; this is the Linux baseline for the whole stack, phases 1 to
  7), and a real-device checklist (iPhone Safari, Android Chrome):
  - Institution home: cards stack; structure dialogs open as bottom or full-screen as planned; keyboard never hides
    the field in focus.
  - Terms, offerings, people: phone rows and the row-actions sheet; the destructive confirm is reachable.
  - Offering schedule and copy: the checkbox grid scrolls with its first column pinned; the Copy bar is above the
    keyboard.
  - Platform admin: home and monitoring cells keep their height on live refresh; institution tabs scroll; users
    show's destructive actions ask to re-authenticate.
  - Dark mode on both homes, structure and monitoring.
- [ ] **Step 6: Carry-over:** Linux baselines for the whole stack; the Phase 3-6 follow-ups the user has not
  decided on (light-mode Completed button/badge green-on-pale-green about 2.8:1; `openItem` test helper should wait
  for the item to load).
