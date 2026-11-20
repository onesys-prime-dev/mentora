# Responsive Phase 2: App Shell and Auth. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the frame around every page responsive. That frame is the header, both side menus, the shell's
height lock, gutters and safe areas, plus the auth pages. It must work from 280px to 2560px using the patterns
the product owner chose in the mockups.

**Architecture:** Mostly markup and Tailwind classes on the existing partials, with no new layout system. Four
things come from Phase 1 and are reused: the `menu` dialog variant (`components/dialog.php` +
`shared/dialog.js`) for the phone menu, `clampToViewport` for the header dropdowns at `sm`+, the
`px-gutter` / `max-w-shell` / `*-safe` tokens, and the motion tokens. One new Tailwind custom variant, `lock:`,
replaces the width-only `lg:` lock with the spec's width-and-height rule. The phone header reuses the existing
account panel as the ⋯ overflow menu, so there's no second account menu.

**Tech Stack:** CodeIgniter 4 views, Tailwind v4.3 (`@tailwindcss/vite`), vanilla ES modules, Vitest + jsdom,
PHPUnit (CIUnit feature tests), Playwright + axe.

**Spec:** `docs/superpowers/specs/2026-11-09-responsive-ui-design.md` (§3 header, §4 side menu, §6 lock rule,
§10 gutter, §11 edge conditions, §12 phase 2)

## Global Constraints

- Prerequisite: Phase 1 is committed on `feat/responsive-1-foundation` (181e370b). Branch
  `feat/responsive-2-shell` off it. **Do not commit.** Each task hands over a commit message (repo rule).
- No new npm or composer dependencies.
- Brand colour comes only through tokens (`bg-brand`, `text-brand`, …). Dark mode uses the existing `--dk-*`
  mapping. Every new surface must look right under `html[data-theme="dark"]`.
- Touch targets are ≥44×44 under `pointer: coarse` (`pointer-coarse:min-h-11 pointer-coarse:min-w-11`). Phone
  inputs are ≥16px (`text-base`).
- Motion: enter uses `var(--motion-base)` + `var(--ease-spring)`, exit uses `var(--motion-fast)` +
  `var(--ease-exit)`, and reduced motion means 0ms (the tokens already do this).
- Every `view()` call that passes component data uses `['saveData' => false]`. CI4 shares view data, and Phase 1
  hit this exact bug.
- Keep the existing ids and hooks that JS and e2e use: `#notifications-button`, `#notifications-panel`,
  `#app-menu-button`, `#app-menu-panel`, `#profile-menu-button`, `#profile-menu-panel`, `[data-theme-toggle]`,
  `[data-messages-badge]`, `[data-notifications-badge]`, `[data-institution-menu]`, `[data-menu-collapse]`,
  `[data-app-header]`, `--app-header-h`. Existing e2e specs must not be edited.
- Breakpoints: `sm` 40rem, `md` 48rem, `lg` 64rem, `xl` 80rem, plus Phase 1's `xs` 25rem and `3xl` 120rem.

## Review Focus

1. **Header on a 320px phone with an institution logo, a long page title and 99+ badges:** the header stays on
   one line, the title truncates, and no icon is pushed off-screen. Task 2's e2e check at 320.
2. **A header dropdown opened at 640–700px** (just above `sm`, where the panels go back to anchored dropdowns):
   the panel stays inside the viewport. `clampToViewport` is called on open, and Task 2 has a Vitest case.
3. **An institution admin on a 1024×768 laptop** with an announcement banner showing: the rail stays usable,
   expanding it covers the page without moving it, and the page scrolls because the lock doesn't apply below
   1280. Task 3 e2e checks that `main`'s left edge doesn't move.
4. **The ⋯ menu on a phone for a user with one role and no Messages access** (Messages off by feature flag): the
   panel shows only what that user has, and the ⋯ dot never shows. Task 2's PHPUnit case.
5. **Escape or Back while the phone menu is open:** it closes, and focus returns to ☰. This comes from the
   Phase 1 dialog contract; Task 3's e2e step covers it.

---

### Task 1: Shell lock rule, gutter, max width, safe areas, scrolled header

**Files:**
- Modify: `resources/css/app.css` (add `@custom-variant lock`, the header scrolled state)
- Modify: `app/Views/layouts/app.php` (`$bodyHeight`, `$shellHeight`, `$mainGutter`, `$mainSpacing`, `main`
  wrapper, the inline header script)
- Modify: `app/Views/layouts/admin.php` (the same lock and gutter treatment)
- Test: `tests/feature/AppShellLayoutTest.php` (new), `tests/js/shared/tokens.test.js` (one case)

**Interfaces:**
- Produces:
  - Tailwind variant `lock:`, defined as `@custom-variant lock (@media (min-width: 80rem) and (min-height:
    47.5rem));`. That's spec §6: ≥1280 wide **and** ≥760 tall.
  - `[data-app-header][data-scrolled]` CSS state, set by the layout's inline script when `scrollY > 4` (or, in a
    locked shell, when `main.scrollTop > 4`).
  - The layout's default gutter is the string `px-gutter xl:px-40` without a menu and `px-gutter` with one.
    Pages that pass their own `$mainGutter` keep it.

- [ ] **Step 1: Write the failing tests.**
  - `tokens.test.js`: `declares the lock variant`, which asserts that `app.css` contains
    `@custom-variant lock (@media (min-width: 80rem) and (min-height: 47.5rem));`.
  - `AppShellLayoutTest` (sign in as the demo student, GET the student dashboard, which is a `fullHeight`
    page; follow the house pattern in `tests/feature/DeanSignoffPageTest.php`):
    - `testTheLockedShellOnlyLocksWhenWideAndTall`: the body `class` contains `lock:h-dvh` and
      `lock:overflow-hidden`, and does **not** contain `lg:h-dvh` or `lg:overflow-hidden`.
    - `testMainUsesTheGutterTokenAndShellWidth`: `<main` has `px-gutter`, its first child wrapper has
      `mx-auto w-full max-w-shell`, and nothing contains `xl:px-40` together with `lg:px-16` (the old chain).
    - `testTheHeaderClearsTheNotch`: `[data-app-header]` contains `pt-safe`.
    - `testTheShellClearsLandscapeNotches`: the shell wrapper (the `flex flex-col` div around header and
      `main`) has `pl-safe pr-safe`, so the header and page both clear a side notch without padding twice. The
      gutter stays on `main`, and the header keeps its own `px-3 sm:px-6`.
  - For the institution admin (institution dashboard): `testTheMenuShellUsesTheSameLockRule` asserts `lock:`
    classes on body and `main`, and no `lg:overflow-hidden`.
- [ ] **Step 2: Run them.** `vendor/bin/phpunit tests/feature/AppShellLayoutTest.php` and `npx vitest run
  tests/js/shared/tokens.test.js`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - Every `lg:` in the lock strings of `app.php` and `admin.php` becomes `lock:`. That covers body
    `lock:h-dvh lock:overflow-hidden`, shell `lock:h-full lock:min-h-0`, `main` `lock:overflow-y-auto`,
    `lock:min-h-0` and the `lg:pb-6 lg:pt-2` spacing.
  - Wrap `$pageContent` in `<div class="mx-auto w-full max-w-shell">` inside `main`.
  - Add the safe-area paddings. The header scrolled state is `shadow-md backdrop-blur-md` added via
    `[data-scrolled]`, with a `transition` on `box-shadow` over `var(--motion-fast)`.
  - Update the docblock comments in `app.php` that explain the lock and the gutter, since they describe the old
    `lg` rule.
- [ ] **Step 4: Run.** Both tests pass, `npm run build` succeeds, and `vendor/bin/phpunit tests/feature` has no
  new failures.
- [ ] **Step 5: Hand over the commit message.** `feat(layout): lock the shell only when wide and tall, fluid gutter, max width and safe areas`

---

### Task 2: Phone header and the ⋯ overflow menu

**Files:**
- Modify: `app/Views/layouts/partials/header.php`
- Modify: `resources/js/shared/profile-menu.js` (more than one trigger)
- Modify: `resources/js/shared/messages-badge.js` (drive the ⋯ dot)
- Modify: `resources/js/shared/notifications-menu.js`, `resources/js/shared/app-menu.js`,
  `resources/js/shared/profile-menu.js` (call `clampToViewport` on open)
- Modify: `resources/css/app.css` (`[data-header-panel]` enter animation)
- Test: `tests/js/shared/menus.test.js` (extend), `tests/js/shared/messages-badge.test.js` (extend),
  `tests/feature/HeaderPhoneLayoutTest.php` (new)

**Interfaces:**
- Consumes: `clampToViewport(panel, margin = 12)` from `resources/js/shared/clamp-panel.js` (Phase 1).
- Produces (spec §3, mockup 01 option C):
  - **Below `sm`, one line:** [☰ from Task 3, when the role has a side menu] · logomark
    (`assets/images/logos/logo.png`, `h-8`, `sm:hidden`) · page title (`min-w-0 truncate text-base
    sm:text-2xl`) · bell · ⋯. The wordmark and institution logo get `max-sm:hidden`. The Messages link, the
    dark-mode button, the Apps button and the avatar/name button all get `max-sm:hidden`. The header padding
    becomes `px-3 sm:px-6`.
  - **The ⋯ button:** `<button type="button" data-profile-menu-trigger aria-controls="profile-menu-panel"
    aria-expanded="false" aria-label="More" class="sm:hidden …44px…">`. It contains a dot `<span
    data-messages-dot hidden …>`, rendered only when `messages_tile($userRole)` isn't null. The existing
    `#profile-menu-button` also gets `data-profile-menu-trigger`.
  - **Inside `#profile-menu-panel`**, a phone-only block (`sm:hidden`) at the top. It has rows in this order:
    - **Messages:** a link with a `[data-messages-badge]` count, only when the tile exists.
    - **Dark mode:** a `<button data-theme-toggle …>`. theme.js already binds every `[data-theme-toggle]`.
    - **Apps:** a `<details>` whose body renders the same app tiles as `#app-menu-panel`, from the same loop and
      partial with `saveData => false`. It's omitted for `institutional_admin`, who has no Apps menu.
    The existing account content follows unchanged.
  - **Header panels** (`#notifications-panel`, `#app-menu-panel`, `#profile-menu-panel`) carry
    `data-header-panel`. Below `sm` they are `max-sm:fixed max-sm:inset-x-2.5
    max-sm:top-[calc(var(--app-header-h)+0.5rem)] max-sm:w-auto max-sm:max-h-[60dvh] max-sm:overflow-y-auto`.
    From `sm` up they stay anchored (`absolute right-0 top-full`), and the JS calls `clampToViewport(panel)`
    after un-hiding.
  - **CSS:** `[data-header-panel]:not(.hidden)` animates `header-panel-in` (opacity 0 → 1, `scale: .9 → 1`,
    `transform-origin: top right`) over `var(--motion-base) var(--ease-spring)`. `shortcut:` close is instant
    (`hidden`). Upgrade it to an exit animation if the product owner wants one.
  - **`profile-menu.js`** binds every `[data-profile-menu-trigger]`:
    - Each trigger toggles the shared panel and keeps its own `aria-expanded` in sync.
    - Opening closes no other header panel; that's the existing behaviour.
    - Outside-click checks the panel and all triggers.
    - Escape returns focus to the trigger that opened the panel.
  - **`messages-badge.js`** also toggles `[data-messages-dot]` (hidden when the count is 0).

- [ ] **Step 1: Write the failing tests.**
  - `menus.test.js`:
    - `the ⋯ trigger opens the account panel and sets its own aria-expanded`
    - `outside click ignores clicks on either trigger`
    - `Escape returns focus to the trigger that opened it`
    - `opening a header panel clamps it to the viewport`: stub `clampToViewport` with `vi.mock` and assert it
      was called with the panel, for each of the three menus.
  - `messages-badge.test.js`: `the ⋯ dot shows when unread > 0 and hides at 0`.
  - `HeaderPhoneLayoutTest`:
    - student: ⋯ is present with `aria-controls="profile-menu-panel"`; Messages, theme, Apps and the avatar
      button each carry `max-sm:hidden`; the panel's phone block contains `data-theme-toggle` and the Apps
      `<details>`.
    - institution admin: no Apps `<details>` in the phone block.
    - Messages off (turn the feature flag off the way `tests/feature` does elsewhere for `messages`): no
      `data-messages-dot` and no Messages row (Review Focus 4).
    - All three header panels carry `data-header-panel` and `max-sm:fixed`.
- [ ] **Step 2: Run them.** `npx vitest run tests/js/shared/menus.test.js tests/js/shared/messages-badge.test.js`
  and `vendor/bin/phpunit tests/feature/HeaderPhoneLayoutTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** the markup, JS and CSS described above. The dot uses the same red as the existing
  badges.
- [ ] **Step 4: Run.**
  - The tests above pass, along with `npm test`, `npm run lint` and `npm run build`.
  - The header-touching e2e specs still pass. Find them with `grep -l "profile-menu\|app-menu\|notifications-button\|theme-toggle" tests/e2e/*.spec.js`.
- [ ] **Step 5: Hand over the commit message.** `feat(header): one-line phone header with a ⋯ menu and viewport-safe dropdowns`

---

### Task 3: Side menus in three tiers (institution admin and platform admin)

**Files:**
- Modify: `app/Views/layouts/partials/institution-menu.php`, `app/Views/layouts/partials/admin-menu.php`
- Modify: `resources/js/shared/institution-menu.js`
- Modify: `app/Views/layouts/app.php`, `app/Views/layouts/admin.php` (tell the header which menu exists)
- Modify: `app/Views/layouts/partials/header.php` (the ☰ button)
- Modify: `resources/css/app.css` (the rail overlay rules, in `@layer components`)
- Test: `tests/js/shared/institution-menu.test.js` (new), `tests/feature/SideMenuLayoutTest.php` (new)

**Interfaces:**
- Consumes: `components/dialog.php` with `'variant' => 'menu'` (Phase 1) and its `data-dialog-open` contract.
- Produces (spec §4, mockup 02, phone option C):
  - **Layout to header:** the layout sets `$this->setVar('navMenuId', 'nav-menu')` when it renders a side menu
    (`institutional_admin` in `app.php`; always in `admin.php`), and `null` otherwise.
  - **The ☰ button:** when `navMenuId` is set, `header.php` renders it first: `<button type="button"
    data-dialog-open="nav-menu" aria-label="Open the menu" class="lg:hidden …44px…">`.
  - **≥1280 (`xl`):** the full 256px sidebar, as today. The institution menu keeps its collapse toggle
    (`[data-menu-collapse]`, the `mentora-institution-menu` storage key).
  - **1024–1279 (`lg` to `xl`):**
    - The sidebar is always the 72px rail, and it's `sticky top-[var(--app-header-h)]
      h-[calc(100dvh-var(--app-header-h))]` inside a 72px slot, so the page never reflows.
    - Labels are hidden, and the icons get `title` tooltips.
    - Hovering it, focusing inside it, or `data-peek="true"` widens it to 256px as an overlay: absolutely
      positioned, `z-40`, with a shadow, and `main`'s left edge doesn't move. The width animates over
      `var(--motion-base) var(--ease-spring)`.
    - The collapse button becomes the peek toggle in this range.
    - `data-peek` clears on Escape, on an outside click, or when the viewport crosses `xl`.
  - **Below 1024:**
    - The sidebar is `max-lg:hidden`.
    - Each menu partial also renders `view('components/dialog', ['id' => 'nav-menu', 'variant' => 'menu',
      'title' => 'Menu', 'body' => $tiles], ['saveData' => false])`.
    - `$tiles` is the same groups as the sidebar. Each group has a heading (`text-[11px] font-semibold
      text-gray-500`) and a `grid grid-cols-3 gap-2` of tiles.
    - Each tile is `<a href=… class="flex min-h-24 flex-col items-center justify-center gap-2 rounded-2xl
      bg-[#F6F8FC] p-2 text-center text-xs font-medium pointer-coarse:min-w-11 …">` with its icon and label.
    - The current page's tile is filled `bg-brand text-white` and has `aria-current="page"`.
    - Admin-menu tiles use their group's icon.
    - The old checkbox "Menu" bar and its `peer-checked` markup are removed from both partials.
  - **The platform admin menu at `lg`–`xl`:** the rail shows Home plus the four group icons. Peek shows the full
    `<details>` groups. `xl` is unchanged.
  - **`institution-menu.js`:**
    - Exports `bindSideMenu(menu: HTMLElement): void`, called on import for `[data-institution-menu]` and the
      new `[data-admin-menu]` attribute on the admin `<nav>`.
    - Keeps today's collapse behaviour at `xl`, and adds peek at `lg`–`xl` (it reads
      `matchMedia("(min-width: 64rem) and (max-width: 79.999rem)")`).

- [ ] **Step 1: Write the failing tests.**
  - `institution-menu.test.js`:
    - `at xl the toggle collapses and remembers it`: the existing behaviour, stub matchMedia.
    - `at lg the toggle sets data-peek and does not touch storage`
    - `Escape clears data-peek`
    - `an outside click clears data-peek`
    - `binds the admin menu too`
  - `SideMenuLayoutTest`, for the institution admin:
    - the page has `data-dialog-open="nav-menu"` and a `data-dialog-variant="menu"` dialog `#nav-menu`
    - the dialog has a tile with `aria-current="page"` for the current page
    - the sidebar `<nav>` carries `max-lg:hidden`
    - no `institution-menu-toggle` checkbox remains
  - `SideMenuLayoutTest`, for the platform admin (sign-in pattern from the existing admin feature tests): the
    same checks with `[data-admin-menu]`.
  - `SideMenuLayoutTest`, for a student: no `nav-menu` dialog and no ☰ button.
- [ ] **Step 2: Run them.** `npx vitest run tests/js/shared/institution-menu.test.js` and `vendor/bin/phpunit
  tests/feature/SideMenuLayoutTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement** as described above.
- [ ] **Step 4: Run.** The tests pass, along with `npm test`, `npm run lint` and `npm run build`. Then run the
  menu-touching e2e specs: `npx playwright test tests/e2e/institutional-admin.spec.js
  tests/e2e/system-admin.spec.js --project=chromium`.
- [ ] **Step 5: Hand over the commit message.** `feat(menu): icon rail with overlay on laptops and a full-screen tile menu on phones and tablets`

---

### Task 4: Auth pages

**Files:**
- Modify: `app/Views/pages/auth/{login,invitation,invitation-problem,password-reset,two-factor-show,two-factor-verify}.php`
  and any other view rendered with `layouts/auth`. Find them with `grep -rl "extend('layouts/auth')" app/Views`.
- Modify: `app/Views/layouts/auth.php` (safe areas)
- Test: `tests/js/shared/views-guard.test.js` (one case)

**Interfaces:**
- Produces: every auth card is `w-full max-w-sm`, with inner padding `px-6 xs:px-8`, and sits in an outer
  wrapper of `px-gutter pt-safe pb-safe`. Every input is `text-base sm:text-sm` or larger. Every button and link
  that isn't inline text gets `pointer-coarse:min-h-11`. No fixed pixel widths (`w-[NNNpx]`) remain.
- [ ] **Step 1: Write the failing test.** In views-guard: `auth pages carry no fixed pixel widths and no
  sub-16px phone inputs`. For each auth view it asserts:
  - no `/\bw-\[\d+px\]/`
  - every `<input` whose type isn't `hidden`, `checkbox` or `radio`, and every `<textarea`, has `text-base` or
    a `sm:text-` override paired with `text-base`
- [ ] **Step 2: Run it.** `npx vitest run tests/js/shared/views-guard.test.js`. Expected: FAIL. List the offenders.
- [ ] **Step 3: Fix** each auth view and `layouts/auth.php` as described above. The OTP code inputs on the
  two-factor pages also get `inputmode="numeric" autocomplete="one-time-code"` if they're missing.
- [ ] **Step 4: Run.** The guard passes, and `npm run e2e -- tests/e2e/auth-login.spec.js` passes.
- [ ] **Step 5: Hand over the commit message.** `feat(auth): fluid, safe-area and touch-friendly sign-in pages`

---

### Task 5: Responsive sweep for the shell

**Files:**
- Modify: `tests/e2e/responsive.spec.js`, `tests/e2e/support/responsive.js`

**Interfaces:**
- Consumes: the Phase 1 helpers `sizes()`, `expectNoPageOverflow`, `expectTouchTargets`, `expectAccessible`,
  `snap`, `setTheme`, and the `SCREENS` table (`{ name, path, as, scope?, dialogs? }`).
- Produces:
  - **New optional SCREENS key `overflowScope`.** When it's set, `expectNoPageOverflow` measures that element's
    `scrollWidth <= clientWidth` instead of the document's. That's because Phase 2 owns the frame, not the page
    content.
  - **New helper `expectInViewport(page, selector)`:** the element's rect is fully inside the viewport.
  - **New SCREENS rows:**
    - `student-shell`: path `/notifications`, `as: "student"`, `scope: "[data-app-header]"`,
      `overflowScope: "[data-app-header]"`. Its `dialogs` stays empty, because header panels aren't dialogs.
    - `institution-shell`: the institution admin's dashboard path, `as: "institutionAdmin"`,
      `scope: "[data-app-header], [data-institution-menu]"`, `overflowScope: "[data-app-header]"`,
      `dialogs: ["nav-menu"]`.
    - One row per reachable public auth page: login is already there; add forgot-password if it has a route.
      Use whole-page overflow and no scope.
  - **New tests in a `describe("shell")` block:**
    - `phone header fits at 320`: at 320×640 with touch, the ⋯ button, the bell and the logomark are each
      `expectInViewport`, and the header's height is less than 72px (one line). This is Review Focus 1. Before
      checking, set the page title to a 120-character string through `page.evaluate` on
      `[data-header-title]` if it exists.
    - `⋯ and bell panels stay on screen at 375 and 700`: open each panel, run `expectInViewport` on it, then
      press Escape. This is Review Focus 2.
    - `rail overlay does not move the page at 1024`: as the institution admin at 1024×768, read `main`'s
      bounding left edge, hover `[data-institution-menu]`, wait for its width to reach ≥240px, then assert
      `main`'s left edge hasn't changed. This is Review Focus 3.
    - `lock applies only when wide and tall`: as the student on the dashboard, at 1366×768 the body's
      computed `overflow-y` is `hidden`, and at 1366×700 and 1024×900 it isn't.
    - `phone menu opens and closes`: as the institution admin at 375, click ☰, assert `#nav-menu` is visible
      and its tiles pass `expectTouchTargets`, press Escape, then assert it's hidden and focus is on ☰. This
      is Review Focus 5.
- [ ] **Step 1: Add the helpers, rows and tests.**
- [ ] **Step 2: Run** `npx playwright test responsive.spec.js --project=chromium`, then
  `RESPONSIVE_FULL=1 npx playwright test responsive.spec.js --project=chromium`, then
  `npx playwright test --project=webkit`. Everything passes. Fix real failures in the Task 1–4 files only.
- [ ] **Step 3: Run the header- and menu-touching e2e specs, plus the auth spec.**
  `npx playwright test tests/e2e/auth-login.spec.js tests/e2e/institutional-admin.spec.js tests/e2e/system-admin.spec.js tests/e2e/messages.spec.js --project=chromium`.
  The known failures that also happen on main (TC-DEAN-03, TC-DEAN-04, TC-GGR-04, TC-PRT-02, TC-MSG-06,
  TC-MSG-08) may still fail. Anything else must pass, or rerun once to show it's flaky.
- [ ] **Step 4: Hand over the commit message and baseline note.**
  - Commit message: `test(e2e): responsive sweep for the header, side menus, shell lock and auth pages`
  - Note: the new screens need baselines. Run **Actions → Responsive → Run workflow** with `update_snapshots`
    on `feat/responsive-2-shell`, and commit the artifact.

---

### Task 6: Phase wrap-up

- [ ] **Step 1:** `npm test`, `npm run lint`, `npm run build` and `vendor/bin/phpunit` pass. Run the e2e specs
  from Task 5 Step 3 once more.
- [ ] **Step 2:** a real-device checklist for the product owner, on an iPhone (Safari) and an Android phone
  (Chrome):
  - **Header:** it's one line on the phone, the ⋯ menu shows Messages, Dark mode, Apps and the account, and the
    dot appears when there are unread messages.
  - **Notifications:** the panel drops below the header, scrolls, and doesn't cover the notch.
  - **Institution admin:** ☰ opens the tile menu, a tile navigates, and Back closes it.
  - **Landscape:** the header and menu avoid the notch.
  - **Sign-in:** there's no zoom on input focus, and the card fits at the smallest phone width.
  - **Laptop (1024–1279):** the rail expands over the page, and Escape tucks it back.
- [ ] **Step 3:** Hand over the summary, the commit messages in order, and the baseline step.
