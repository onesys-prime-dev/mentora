# Responsive Phase 1: Foundation. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the shared base that phases 2–7 build on: fluid/motion tokens, extra breakpoints, `dvh` and safe areas,
page View Transitions, the dialog variants (bottom sheet, full-screen form, full-screen menu), an expandable action
bar, a viewport-clamp helper, and the responsive Playwright harness with CI-enforced baselines.

**Architecture:** Mostly CSS in `resources/css/app.css` (`@theme` tokens + `@utility`), with Tailwind v4's built-in
`hover:` (already `@media (hover:hover)`) and `pointer-coarse:` variants doing the touch/mouse split. The new
interactive pieces extend existing modules (`shared/dialog.js` + `components/dialog.php`) rather than adding a
parallel system; two small new modules (`action-bar.js`, `clamp-panel.js`) cover what dialogs can't. A new
`ui-kit` section demonstrates every primitive and is the phase's e2e target.

**Tech Stack:** CodeIgniter 4 views, Tailwind v4.3 (`@tailwindcss/vite`), vanilla ES modules, Vitest 5 + jsdom,
Playwright 1.64 + `@axe-core/playwright`.

**Spec:** `docs/superpowers/specs/2026-11-09-responsive-ui-design.md`

## Global Constraints

- No new npm or composer dependencies.
- Branch `feat/responsive-1-foundation` off `main`. **Do not commit.** Each task ends by handing over a commit
  message (repo rule); the product owner commits.
- Brand colours only through `brand` tokens (`bg-brand`, `text-brand`, `ring-brand/50` …), never hex for brand.
- Every new surface works under `html[data-theme="dark"]` using the existing `--dk-*` variables.
- Existing markup contracts of `shared/dialog.js` (`data-dialog`, `data-dialog-open`, `data-dialog-close`,
  `data-dialog-form`, `data-confirm-*`) and all existing Vitest + e2e specs keep passing unchanged.
- Motion: enter `cubic-bezier(.34,1.45,.64,1)`, exit `cubic-bezier(.4,0,1,1)`; durations fast ≈180ms,
  base ≈380ms, slow ≈480ms; `prefers-reduced-motion: reduce` → 0ms, no scale, no stagger, no View Transitions.
- Body text stays 16px. Fluid only: h1 24→32px, h2 20→24px, metric 28→40px over 320→1440px.
- Phone inputs ≥16px font; touch targets ≥44×44 under `pointer: coarse`.
- Views are PHP; JS modules follow the house comment style (a header block saying what the module does and its
  markup contract).

## Review Focus

1. **Dialog closes while its exit animation is still running** (double-click ×, Esc then backdrop): it must end
   hidden exactly once, focus returned once, body scroll unlocked. Test in Task 4.
2. **Reduced motion or no Web Animations API:** close must be synchronous (jsdom, old Safari, reduced motion), or
   existing dialog tests and screen readers break. Test in Task 4.
3. **Swipe on a sheet that started on a scrollable body:** dragging inside the list must scroll, not close; only
   the handle/header starts a dismiss. Test in Task 4.
4. **Panel wider than the viewport** (320px phone, 384px panel): clamp must cap width and keep both edges inside,
   not shift it off the left edge. Test in Task 6.
5. **SPA content swap vs. page navigation:** the course SPA crossfade must stay content-only (no full-root fade)
   while normal navigations get the root crossfade. Checked in Task 3's e2e step.

---

### Task 1: Tokens, breakpoints and utilities

**Files:**
- Modify: `resources/css/app.css` (the `@theme` block at the top, plus a new `@utility` group after it)
- Test: `tests/js/shared/tokens.test.js` (new)

**Interfaces:**
- Produces (Tailwind utilities later tasks and phases use):
  - Breakpoints `xs:` (25rem / 400px) and `3xl:` (120rem / 1920px).
  - `text-fluid-h1`, `text-fluid-h2`, `text-fluid-metric`.
  - `px-gutter`, `gap-fluid` (and every spacing utility for `gutter` / `fluid`).
  - `ease-spring`, `ease-exit`; CSS variables `--motion-fast`, `--motion-base`, `--motion-slow`, used as
    `duration-(--motion-base)`.
  - `pt-safe`, `pb-safe`, `pl-safe`, `pr-safe` (padding = `env(safe-area-inset-*)`), `pb-safe-4` (=
    `max(1rem, env(safe-area-inset-bottom))`).
  - `max-w-reading` (70ch), `max-w-shell` (105rem / 1680px).

- [ ] **Step 1: Write the failing test.** `tests/js/shared/tokens.test.js` reads `resources/css/app.css` as text
  (`node:fs`) and asserts it declares each token with the exact value:

```js
const css = readFileSync(resolve(__dirname, "../../../resources/css/app.css"), "utf8");
it.each([
  ["--breakpoint-xs", "25rem"], ["--breakpoint-3xl", "120rem"],
  ["--text-fluid-h1", "clamp(1.5rem, 1.357rem + 0.714vw, 2rem)"],
  ["--text-fluid-h2", "clamp(1.25rem, 1.179rem + 0.357vw, 1.5rem)"],
  ["--text-fluid-metric", "clamp(1.75rem, 1.536rem + 1.071vw, 2.5rem)"],
  ["--spacing-gutter", "clamp(1rem, 4vw, 2.5rem)"],
  ["--spacing-fluid", "clamp(0.75rem, 0.536rem + 1.071vw, 1.5rem)"],
  ["--ease-spring", "cubic-bezier(.34,1.45,.64,1)"], ["--ease-exit", "cubic-bezier(.4,0,1,1)"],
  ["--motion-fast", "180ms"], ["--motion-base", "380ms"], ["--motion-slow", "480ms"],
  ["--max-width-reading", "70ch"], ["--max-width-shell", "105rem"],
])("declares %s: %s", (name, value) => expect(css).toContain(`${name}: ${value};`));
it("zeroes motion for reduced-motion users", () =>
  expect(css).toMatch(/prefers-reduced-motion: reduce\)[\s\S]*--motion-base: 0ms/));
```

- [ ] **Step 2: Run it.** `npx vitest run tests/js/shared/tokens.test.js`. Expected: FAIL (tokens missing).
- [ ] **Step 3: Add the tokens.** Breakpoints, text, spacing, ease and max-width go in `@theme`. `--motion-*`
  go on `:root` (they aren't a Tailwind namespace), with a `@media (prefers-reduced-motion: reduce) { :root {
  --motion-fast: 0ms; --motion-base: 0ms; --motion-slow: 0ms; } }` override. Each `--text-fluid-*` gets a matching
  `--text-fluid-*--line-height` (1.25 for h1/h2, 1.1 for metric). The safe-area utilities are `@utility pt-safe {
  padding-top: env(safe-area-inset-top); }` etc. Add one comment line per group saying why it exists.
- [ ] **Step 4: Run.** `npx vitest run tests/js/shared/tokens.test.js` passes, and `npm run build` succeeds.
- [ ] **Step 5: Hand over the commit message.** `feat(css): fluid type, gutter, motion and safe-area tokens for responsive layouts`

---

### Task 2: Viewport meta, `dvh` everywhere

**Files:**
- Modify: `app/Views/layouts/partials/head.php` (viewport meta)
- Modify: `app/Views/layouts/app.php:28-29`, `app/Views/layouts/admin.php:34,36,46`, `app/Views/layouts/auth.php:11`,
  `app/Views/pages/auth/{login,invitation,invitation-problem,password-reset,two-factor-show,two-factor-verify}.php`
- Test: `tests/js/shared/views-guard.test.js` (new)

**Interfaces:**
- Produces: every page has `viewport-fit=cover` (so `env(safe-area-inset-*)` works) and
  `interactive-widget=resizes-content` (so the keyboard shrinks the layout viewport and sticky composers stay
  visible).

- [ ] **Step 1: Write the failing test.** `views-guard.test.js` walks `app/Views` recursively (`node:fs`
  `readdirSync(…, { recursive: true })`, `.php` only) and asserts:
  - no file matches `/\b(min-)?h-screen\b/`, ignoring PHP comment lines (`^\s*(\*|//)`);
  - `head.php` contains `content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content"`.
- [ ] **Step 2: Run it.** `npx vitest run tests/js/shared/views-guard.test.js`. Expected: FAIL, listing the 9
  offending files.
- [ ] **Step 3: Replace the classes.** `min-h-screen`→`min-h-dvh` and `h-screen`→`h-dvh`, prefixes kept
  (`lg:h-screen`→`lg:h-dvh`). Update the viewport meta. Leave the lock logic (`$locked`) itself unchanged; phase 2
  changes the rule.
- [ ] **Step 4: Run.** The guard test passes; `npm test` is all green; `npm run e2e -- tests/e2e/auth-login.spec.js`
  passes.
- [ ] **Step 5: Hand over the commit message.** `fix(layout): use dynamic viewport units and enable safe-area insets`

---

### Task 3: Page View Transitions

**Files:**
- Modify: `resources/css/app.css` (the "Course SPA content swaps" block, about lines 140–178)

**Interfaces:**
- Consumes: `--motion-base`, `--ease-spring`, `--ease-exit` (Task 1).
- Produces: a cross-fade on every same-origin page navigation. The course SPA swap (`.spa-transitioning` from
  `shared/spa/swap.js`) keeps its content-only fade.

- [ ] **Step 1: Change the CSS.**
  - Add `@view-transition { navigation: auto; }`.
  - Root old/new animations: fade out over `var(--motion-fast)` with `--ease-exit`, and fade in over
    `var(--motion-base)` with `--ease-spring` (opacity only; no transform on root, so no stacking-context trap).
  - Scope the existing `animation: none` root rule to `html:has(.spa-transitioning)::view-transition-old(root),
    html:has(.spa-transitioning)::view-transition-new(root)`, so only SPA swaps suppress the root fade.
  - Wrap it in `@media (prefers-reduced-motion: reduce) { @view-transition { navigation: none; } }`.
- [ ] **Step 2: Verify.** `npm run build` succeeds. Then run the existing course SPA e2e
  (`npm run e2e -- tests/e2e/content-transfer.spec.js`) and `tests/js/shared/spa` unit tests; both pass unchanged.
- [ ] **Step 3: Manual check (Chrome).** Navigate dashboard → courses: the whole page cross-fades. Switch a course
  tab: only the content card fades. With DevTools "prefers-reduced-motion: reduce": no fade.
- [ ] **Step 4: Hand over the commit message.** `feat(css): cross-fade page navigations with View Transitions`

---

### Task 4: Dialog variants (sheet, full-screen form, full-screen menu) and animated close

**Files:**
- Modify: `resources/js/shared/dialog.js` (`createDialog`: open/close, plus a swipe handler)
- Modify: `app/Views/components/dialog.php` (new params `variant`, `topAction`)
- Modify: `app/Views/components/confirm.php` (always centered: `items-center` at every width, `p-4`)
- Modify: `resources/css/app.css` (new `@layer components` block for `[data-dialog-variant]`)
- Test: `tests/js/shared/dialog.test.js` (new `describe` blocks)

**Interfaces:**
- Produces:
  - `components/dialog.php` param `variant`: `'default' | 'sheet' | 'fullscreen' | 'menu'` (default `'default'`),
    rendered as `data-dialog-variant="…"` on the `[data-dialog]` root.
    - `sheet`: bottom sheet at every width below `sm`, centered dialog from `sm` up. Drag handle
      `[data-dialog-handle]` on top. Max height `min(90dvh, 100%)`, `pb-safe-4`.
    - `fullscreen`: below `sm`, fills the screen. The top bar is a 3-column grid: Cancel (`data-dialog-close`,
      text button) | title | `topAction`. The footer is hidden. From `sm` up it's the normal centered dialog.
    - `menu`: below `lg`, a full-screen panel entering from the left (`pt-safe`), with no centered form. Phase 2
      fills it with the tile grid.
  - `components/dialog.php` param `topAction`: `['label' => string, 'form' => string /* form id */]`. It renders
    `<button type="submit" form="{form}" class="sm:hidden …">{label}</button>` in the top bar. Fullscreen variant
    only.
  - The `[data-dialog]` root gets `data-state="open"` while open and `data-state="closing"` while its exit
    animation runs. CSS animates on these: enter uses `--motion-base`/`--ease-spring`; sheet and fullscreen slide
    (translateY), menu slides translateX, default/confirm fade with scale .9→1. Exit uses `--motion-fast`/`--ease-exit`.
  - Behaviour: `close()` sets `data-state="closing"`, then hides when the root's animations finish. It's
    synchronous when `el.getAnimations` is missing, returns no running animations, or reduced motion matches. A
    second close while closing is a no-op.
  - Swipe: on `sheet`, a pointer drag that **starts on `[data-dialog-handle]` or the header row** and moves down
    ≥ 80px (or ≥ 40px within 250ms) closes it. Drags that start in the scrolling body never close it. While
    dragging, the panel follows the finger (`translate`) with transitions off; released short of the threshold,
    it springs back.

- [ ] **Step 1: Write the failing tests** (in `dialog.test.js`, reusing its `page()` helper):

```js
describe("dialog variants", () => {
  it("marks an open dialog data-state=open and removes it on close", …);           // open → "open"; close → hidden, no data-state
  it("closes synchronously when nothing animates (jsdom, reduced motion)", …);   // el.getAnimations undefined → hidden right after close click
  it("waits for the exit animation, then hides once", async () => {
    // stub el.getAnimations = () => [{ finished: Promise.resolve() }]
    // click close twice → data-state "closing", still visible; await tick → hidden; opener focused once; body unlocked
  });
  it("closes a sheet when the handle is dragged down 80px", …);     // pointerdown/move/up on [data-dialog-handle], clientY 100→190 → hidden
  it("springs back when the drag is short", …);                     // 100→140 slow (performance.now stubbed +400ms) → still open, inline translate cleared
  it("ignores drags that start in the body", …);                    // pointer events on an <ul> inside the panel, 100→300 → still open
});
```

  Add a PHP feature test in `tests/feature/DialogComponentTest.php` (new, CIUnit `view()` render). It
  asserts that `variant: 'fullscreen'` with `topAction` renders `data-dialog-variant="fullscreen"` and
  `<button type="submit" form="ann-form"`, and that the default variant renders no `data-dialog-variant`.
- [ ] **Step 2: Run.** `npx vitest run tests/js/shared/dialog.test.js` and
  `vendor/bin/phpunit tests/feature/DialogComponentTest.php`. Expected: new cases FAIL; existing ones still
  PASS.
- [ ] **Step 3: Implement.** In `dialog.js`, add `finishClose()` (the current `close` body) and a new `close()`
  that does the closing state and animation wait. Add `bindSwipe(el)`, called from `openDialog` when the variant
  is `sheet` and removed on close. In `dialog.php`, add the variant classes, the handle and the top bar. Add the
  CSS block, including dark-mode surfaces (`--dk-surface`, `--dk-line`).
- [ ] **Step 4: Run.** `npm test` and the PHP test pass; then run `npm run e2e` (the full suite, since dialogs
  are everywhere); it passes.
- [ ] **Step 5: Hand over the commit message.** `feat(dialog): sheet, full-screen form and menu variants with animated close and swipe-to-dismiss`

---

### Task 5: Expandable action bar

**Files:**
- Create: `resources/js/shared/action-bar.js`
- Modify: `resources/js/app.js` (import it with the other shared modules)
- Modify: `resources/css/app.css` (`[data-action-bar]` styles)
- Test: `tests/js/shared/action-bar.test.js`

**Interfaces:**
- Markup contract (Grade Work and Quiz Builder use it in phase 5):

```html
<div data-action-bar class="sticky bottom-0 …">
  <div id="rubric-panel" data-action-bar-panel>…criteria…</div>      <!-- expands upward -->
  <div class="flex …"> …total… <button data-action-bar-toggle aria-controls="rubric-panel" aria-expanded="false">Rubric</button> …primary… </div>
</div>
```

- Behaviour: the toggle flips `aria-expanded` and the bar's `data-open`; Escape closes it (focus back on the
  toggle); opening one bar closes any other open bar. CSS animates the panel with `grid-template-rows: 0fr → 1fr`
  over `--motion-base`/`--ease-spring`, uses `pb-safe-4`, and the panel's `max-height` is `min(60dvh, 28rem)` with
  internal scroll. The panel's content is `inert` while it's collapsed.
- Exports: `bindActionBars(root = document): void` (called on import, and re-callable after SPA swaps).

- [ ] **Step 1: Write the failing tests.** "toggle opens and sets aria-expanded=true and data-open", "Escape
  closes and focuses the toggle", "opening a second bar closes the first", "collapsed panel is inert".
- [ ] **Step 2: Run.** `npx vitest run tests/js/shared/action-bar.test.js`. Expected: FAIL (module missing).
- [ ] **Step 3: Implement** `action-bar.js`, using event delegation on `document` the way `details-menu.js` does,
  with the house header comment.
- [ ] **Step 4: Run.** The tests pass and `npm run lint` is clean.
- [ ] **Step 5: Hand over the commit message.** `feat(ui): expandable sticky action bar`

---

### Task 6: Viewport clamp for dropdown panels

**Files:**
- Create: `resources/js/shared/clamp-panel.js`
- Test: `tests/js/shared/clamp-panel.test.js`

**Interfaces:**
- Produces: `clampToViewport(panel: HTMLElement, margin = 12): void`. It measures `panel.getBoundingClientRect()`
  against `document.documentElement.clientWidth`:
  - If the panel is wider than `clientWidth - 2*margin`, it sets `max-width` to that value and re-measures.
  - It then sets `translate: Xpx 0`, so `left ≥ margin` and `right ≤ clientWidth - margin`.
  - It clears any previous translate first, so it's safe to call on every open.
  - Phase 2 calls it from `notifications-menu.js`, `profile-menu.js`, `app-menu.js` and `filter-dropdown`.

- [ ] **Step 1: Write the failing tests** (stub `getBoundingClientRect` and `clientWidth`):
  - "leaves a panel that fits untouched" (translate `""`);
  - "shifts a panel overflowing the right edge left" (viewport 375, rect left 200 width 288 → translate `-125px 0`);
  - "shifts a panel overflowing the left edge right";
  - "caps a panel wider than the viewport" (viewport 320, width 384 → max-width `296px`, left = 12);
  - "re-clamps from scratch on a second call".
- [ ] **Step 2: Run.** `npx vitest run tests/js/shared/clamp-panel.test.js`. Expected: FAIL.
- [ ] **Step 3: Implement** `clampToViewport`.
- [ ] **Step 4: Run.** The tests pass.
- [ ] **Step 5: Hand over the commit message.** `feat(ui): keep dropdown panels inside the viewport`

---

### Task 7: UI-kit "Responsive" section

**Files:**
- Modify: `app/Views/pages/ui-kit/index.php` (new section at the end)
- Modify: `app/Controllers/UiKitController.php` only if the section needs data (it shouldn't)

**Interfaces:**
- Consumes: Tasks 1, 4, 5 and 6.
- Produces: a page at `/ui-kit` that the Task 8 harness visits. It contains:
  - a fluid `h1`/`h2`/metric sample (`text-fluid-*`);
  - a `px-gutter` + `gap-fluid` card grid that uses `@container` cards (`@sm:flex-row`);
  - one button per dialog variant (`data-dialog-open`) with real content: a "New announcement" full-screen form
    with `topAction`, a row-actions sheet with 4 actions, a menu panel with placeholder tiles, and a confirm;
  - an expandable action bar ("17 / 20" total, Rubric toggle, "Save, next");
  - every interactive control uses `pointer-coarse:min-h-11 pointer-coarse:min-w-11`.
  Copy follows the spec's examples (sentence case, verbs on buttons).

- [ ] **Step 1: Build the section** with existing components (`components/dialog.php`, `components/confirm.php`,
  card markup from `components/metric-card.php`).
- [ ] **Step 2: Manual check.** At 320, 375, 834 and 1440 in Chrome DevTools (touch emulation on for the first
  three), each variant opens, animates, closes, and has no horizontal page scroll. Check both light and dark
  (`theme.js` toggle).
- [ ] **Step 3: Hand over the commit message.** `feat(ui-kit): responsive foundation showcase`

---

### Task 8: Responsive Playwright harness, WebKit project and CI baselines

**Files:**
- Create: `tests/e2e/support/responsive.js`
- Create: `tests/e2e/responsive.spec.js`
- Modify: `playwright.config.js` (projects, snapshot settings)
- Modify: `.github/workflows/ci.yml` (`e2e` job: install `chromium webkit`)
- Create: `.github/workflows/responsive.yml`
- Modify: `tests/e2e/README.md` (how to run the sweep and update baselines)

**Interfaces:**
- `support/responsive.js` exports:
  - `SMOKE = [{w:375,h:812}, {w:834,h:1112}, {w:1366,h:768}]`
  - `FULL = [320×640, 375×812, 430×932, 768×1024, 834×1112, 1024×768, 1366×768, 1440×900, 1920×1080]` + `LANDSCAPE = {w:667,h:375}`
  - `sizes(): Array<{w,h,name}>`, which returns FULL + landscape when `process.env.RESPONSIVE_FULL === "1"`, else SMOKE
  - `async expectNoPageOverflow(page)`, which asserts `document.documentElement.scrollWidth <= innerWidth`,
    exempting elements inside `[data-scroll-x]` (the grid-table scrollers phases 3+ mark)
  - `async expectTouchTargets(page)`, which collects visible `a[href], button, [role=button], input:not([type=hidden]), select, textarea, summary`
    whose computed `display` is not `inline`, and asserts each one is ≥ 44×44 and its rect is inside the
    viewport. It reports the offenders by an `outerHTML` snippet.
  - `async expectAccessible(page)`, which runs axe with `wcag2a, wcag2aa, wcag21aa, wcag22aa` and fails on
    serious/critical (same filter as `dean.spec.js`)
  - `async setTheme(page, "light" | "dark")`, which uses `addInitScript` to set `localStorage["mentora-theme"]`
  - `async zoom200(page)`, which uses viewport 640×800 and `document.documentElement.style.zoom = "2"` (an
    effective 320 CSS px, the WCAG 1.4.10 reflow case)
  - `async snap(page, name)`, which calls `expect(page).toHaveScreenshot(name + ".png", { fullPage: true,
    animations: "disabled", maxDiffPixelRatio: 0.01 })` only when `process.platform === "linux"` and otherwise
    returns
- `responsive.spec.js`: a table `SCREENS = [{ name, path, as }]` (`as` = an account key from `sign-in.js` or `null`).
  Phase 1 entries are `login` (`/login`, `null`) and `ui-kit` (`/ui-kit`, `student`). For each screen × size ×
  theme (dark only when FULL), one test titled `${name} @${size.name} ${theme}`. The phone and tablet sizes use
  `hasTouch: true, isMobile: true` via `test.use` inside a `describe` per size class. Each test does: overflow,
  touch targets (touch sizes only), axe, snap. Plus one `zoom200` test per screen (FULL only), which checks
  overflow only. Later phases only append to `SCREENS`.
- `playwright.config.js`:
  - Keep `chromium`.
  - Add `{ name: "webkit", testMatch: "responsive.spec.js", use: { ...devices["iPhone 13"] } }`. On WebKit the
    spec uses only the SMOKE 375 size and the device's own touch.
  - Add `snapshotPathTemplate: "{testDir}/__screenshots__/{testFilePath}/{projectName}/{arg}{ext}"` and
    `updateSnapshots: "none"`, so CI fails on missing or different baselines.
- `.github/workflows/responsive.yml`:
  - Triggers: `schedule` (nightly, `0 18 * * *` UTC = 2am Manila) and `workflow_dispatch` with a boolean input
    `update_snapshots`.
  - The job copies the `e2e` job's services, env and setup steps. It runs
    `RESPONSIVE_FULL=1 npx playwright test responsive.spec.js` (adding `--update-snapshots=all` when the input is
    true).
  - When updating, it uploads `tests/e2e/__screenshots__` as an artifact named `responsive-baselines`, which the
    product owner downloads and commits.

- [ ] **Step 1: Write the harness and spec** as specified, with the Phase 1 `SCREENS`.
- [ ] **Step 2: Run locally.** `npx playwright test responsive.spec.js --project=chromium`. Expected: PASS on
  Windows (snapshots skipped). If the login page fails overflow or touch targets, fix it in
  `pages/auth/login.php`: wrap long text, `pointer-coarse:min-h-11` on its links and buttons, and `text-base` on
  the phone inputs. That's the only page fix in this phase.
- [ ] **Step 3: Run the full matrix locally.** `RESPONSIVE_FULL=1 npx playwright test responsive.spec.js --project=chromium`. Expected: PASS.
- [ ] **Step 4: Run WebKit.** `npx playwright install webkit`, then `npx playwright test --project=webkit`.
  Expected: PASS.
- [ ] **Step 5: Run the whole e2e suite** (`npm run e2e`) to confirm nothing else changed.
- [ ] **Step 6: Hand over the commit message and the baseline steps.** Commit message:
  `test(e2e): responsive sweep with axe, touch-target and overflow checks, WebKit project and CI baselines`.
  Tell the product owner: after pushing the branch, run **Actions → Responsive → Run workflow** with
  `update_snapshots` ticked, download `responsive-baselines`, unzip it into `tests/e2e/__screenshots__`, review the
  images, and commit them. CI's e2e job will fail on this branch until that's done; that's intended.

---

### Task 9: Phase wrap-up

- [ ] **Step 1:** `npm test`, `npm run lint`, `npm run build`, `vendor/bin/phpunit`, `npm run e2e`. All pass.
- [ ] **Step 2:** Real-device checklist for the product owner (iPhone Safari and an Android Chrome):
  - In `/ui-kit`, the full-screen form's top bar sits below the notch.
  - The sheet's buttons clear the home bar.
  - Typing in the form doesn't zoom the page.
  - Swipe-down on the sheet handle closes it; a swipe in its list scrolls.
  - The action bar stays above the home bar.
  - Page-to-page navigation cross-fades on Android Chrome. iOS Safari 18.2+ also fades; older iOS doesn't, and
    that's fine.
- [ ] **Step 3:** Hand over a short summary of what landed, and the commit messages in order.
