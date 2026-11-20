# Responsive UI across every screen size: design

Date: 2026-11-09. Status: draft for approval.
Mockups (chosen options): `.superpowers/brainstorm/1154-1794192272/content/01…08-*.html` (git-ignored, local only).

## Intent

Mentora's 342 view templates were built desktop-first. About half of the page templates (178) carry no responsive
class at all, the tablet range (768–1023) is barely handled (`md:` appears 65 times against 299 `lg:`), there is no
fluid sizing and no container query anywhere, 22 of 40 tables have no overflow wrapper, and `h-screen` (18 uses)
breaks on mobile browsers. Students and parents are the users most likely to be on phones.

The goal is that every screen works and feels designed at any width from 280px to 2560px, in the same design language
the student and instructor screens already use (brand blue tokens, Poppins/Montserrat/Parkinsans, glass header, white
cards with `border-[#A9A9A9]/50`, `rounded-2xl`), light and dark.

### Goals

- No page-level horizontal scroll at any supported width (grid tables scroll inside their own container only).
- Phone-native patterns on phones, not shrunk desktop: one-line header, full-screen menu, list rows, tabs,
  full-screen forms, sticky action bars.
- Smooth, deliberate motion with one shared motion system.
- WCAG 1.4.4 / 1.4.10 reflow at 320 CSS px and 200% zoom; 44px touch targets on touch devices.
- Automated checks that stop later phases from breaking earlier ones.

### Non-goals

- No visual redesign, new colour palette or new fonts. Desktop (≥1280) should look the same as today except where
  the fluid tokens smooth it.
- No new print work (existing print views must just keep working).
- No native app / PWA work.

### Anti-goals (a failure even if it "works")

- Adding a UI component library (Flowbite, daisyUI, Preline) or a second interactivity framework (Alpine).
- Phone screens that are the desktop squeezed: tiny tables, side-by-side panes at 375px.
- Scroll-triggered reveal animations on app screens; motion that ignores `prefers-reduced-motion`.
- Hover-only affordances that leave "sticky hover" after a tap.
- Slower pages: no layout-shift regressions, no heavy JS on first paint (the speed anti-goal from Global Grades holds).

## Constraints

- Stack: CodeIgniter 4 views (PHP), Tailwind v4 via `@tailwindcss/vite`, vanilla ES modules in `resources/js`,
  Vitest + jsdom, Playwright + `@axe-core/playwright`. **No new dependencies.**
- Existing shared modules must be extended, not replaced: `shared/dialog.js` (div `role="dialog"` contract with
  `data-dialog-open` / `data-dialog-close` / `data-dialog-form`), `shared/data-table.js`, `shared/institution-menu.js`,
  `shared/notifications-menu.js`, `shared/profile-menu.js`, `shared/app-menu.js`, `shared/details-menu.js`,
  `shared/spa/swap.js`, `shared/theme.js`. Their markup contracts and existing e2e specs must keep passing.
- Dark mode: every new surface works under `html[data-theme="dark"]` using the existing `--dk-*` tokens in `app.css`.
- Institution brand overrides (`brand-tokens.js`) must keep working: new styles use `brand` tokens, never hex.
- Repo rules: work on branches, Claude does not commit (hands over commit messages), UI must match the existing
  design language.

## Decisions (made by the product owner)

### 1. Device matrix

Sweep widths: **320, 375, 430, 768, 834, 1024, 1366, 1440, 1920**, plus **667×375 landscape** and **320 at 200% zoom**.
Breakpoints: Tailwind defaults (`sm` 640, `md` 768, `lg` 1024, `xl` 1280, `2xl` 1536) plus custom **`xs` ≈ 400px** and
**`3xl` ≈ 1920px**. Phones are the design priority. Fluid sizing covers the space between breakpoints.

### 2. Technique

Tailwind v4 native, hybrid:
- **Fluid tokens** (`clamp()`) in `@theme` for headings, gutters and gaps.
- **Container queries** (`@container`, `@sm:`/`@md:`…) for components that sit in slots of varying width (cards,
  metric tiles, course cards, dashboard partials, forms inside dialogs).
- **Media queries** only for layout switches (nav tier, table→rows, column counts, pane patterns).
- **Vanilla JS** for new interactive pieces, built on the existing module conventions. Native Popover API where it
  fits; a small viewport-aware positioning helper for dropdowns near screen edges.

### 3. Header (every role, every screen). Mockup 01, option C

- **Below `sm`:** one line: [menu button if the role has a side menu] · Mentora logomark only · page title (truncates
  with ellipsis) · bell (with badge) · **⋯ overflow button**. The institution logo is hidden. Messages, dark mode,
  Apps and account move into the ⋯ menu; a red dot on ⋯ shows unread messages.
- **Header panels** (notifications, ⋯ menu) open as a **dropdown from the top**, full width minus 10px each side,
  max ~60% height, scrolling inside. Fade + scale from the top-right.
- **From `sm` up:** today's header (logo, institution logo, title, pill with Messages / bell / dark mode / Apps,
  account), with every dropdown clamped to the viewport (`max-w-[calc(100vw-1.5rem)]` everywhere, not just the bell).
- Icon buttons keep 44px hit areas on touch devices.

### 4. Side menu (institution admin, platform admin). Mockup 02, option C for phone/tablet

- **≥1280 (`xl`):** full 256px sidebar, collapsible to the 72px rail (as today, choice remembered).
- **1024–1279 (`lg`):** **72px icon rail by default**; hover, focus or the expand button slides it to 256px **over**
  the page (overlay with shadow, page doesn't reflow); leaving or tapping outside tucks it back unless pinned.
  Icons get tooltips while collapsed.
- **<1024:** a ☰ button in the header opens a **full-screen menu** sliding in from the left: header row (logo, role,
  ×), then the existing groups (Academics, Community, Insights, Institution) each as a **3-column grid of large tap
  tiles** (icon + label, current page filled with brand). Closes on ×, Esc, or picking a tile. Focus is trapped
  while open. Tiles use the real icon set (`icon()` helper), not emoji.

### 5. Tables. Mockup 03, option C for list tables

Two kinds:
- **List tables** (users, offerings, invitations, audit, submissions…):
  - **Below `md`:** become **grouped list rows**: one white rounded panel of thin rows (avatar/initials or icon ·
    primary text · one secondary line with status badge + one key field · ⋯ button). If the table already groups its
    data (e.g. by role, term, status), each group gets its own panel with a small group heading and count;
    otherwise there is one panel. ⋯ opens the **row-actions bottom sheet** (handle, item title, actions, destructive
    action in red).
  - **`md`–`lg`:** stays a table; low-priority columns hide.
  - Driven from `components/data-table.php` with a per-column `priority` (and a `primary` / `secondary` /
    `status` role for the phone row), so most tables get this without per-page work.
  - **Hand-written list tables are moved onto `data-table.php`** (product owner's decision). About 21 views use raw
    `<table>` markup. Each one is migrated in the phase that owns its screen, and the e2e selectors that depend on
    the old markup are updated in the same change. Grid tables are not migrated.
- **Grid tables** (gradebook, class summary, grade reports, schedules, permission grid): stay grids at all widths in
  a horizontal-scroll container with **sticky first column and sticky header row**, edge-fade shadows where more
  content exists, 44px inputs on touch.

### 6. Dashboards and viewport-locked pages. Mockup 06, option C for phones

- The `fullHeight` viewport lock applies only when **≥1280 wide and ≥760 tall** (`@media (min-width:1280px) and
  (min-height:760px)`). Otherwise the page scrolls and cards take natural height up to a sensible cap.
- Cards reflow via container queries (e.g. course cards 4-up → 2-up by their own width).
- **Phones:** the dashboard becomes **tabs** (segmented control under the header). First tab is the urgent work
  (students: **Today** = due soon; other roles: their needs-attention / pending items). Then the role's main object
  (students: **Courses**), then **Updates** (announcements, activity). A red dot marks a tab with unseen items.
  Lists inside tabs cap at ~5 with "View all". Each role's tab set is decided per role in the plan, following this
  rule: urgent → main work → updates.
- **≥1920:** content max-width ~1680px, centered; cards grow by fluid padding/type, not stretching.

### 7. Split-pane screens. Mockups 04 (option C) and 05 (option A)

- **≥1024:** side by side as today, pane widths fluid (`clamp`). Grade Work's divider is draggable, width remembered.
- **768–1023:** the secondary pane (outline, palette, rubric) becomes a toggleable panel that slides over the main
  pane.
- **<768:**
  - **Messages:** list → thread push. Tapping a conversation slides the thread in from the right (list shifts left
    behind it); back or swipe-right returns. Composer stays above the keyboard (`dvh` +
    `interactive-widget=resizes-content`).
  - **Grade Work:** submission fills the screen; a **sticky score bar** at the bottom shows the running total and
    "Save, next". Tapping "Rubric" expands the criteria (levels as 40px+ buttons, feedback box) **upward inside the
    bar**. No drag sheet.
  - **Quiz Builder:** same sticky-bar pattern: bottom bar with Save/Publish and "＋ Add question", which expands the
    question-type palette upward in the bar.
  - **Module / reading:** outline becomes a "Contents ▾" control at the top opening a dropdown panel (same style
    as header panels); prev/next sticky at the bottom.

### 8. Dialogs and forms. Mockup 07, option B for phones

- **≥640:** centered dialog, width `min(92vw, size)`, header and footer fixed, body scrolls; 150ms fade + scale-in.
- **<640:** form dialogs become **full-screen pages**: top bar with **Cancel** (left), title (center), **primary
  action** (right, bold); body scrolls. Slides up from the bottom.
- **Confirm dialogs** (`components/confirm.php`) stay **small centered alerts** at every width.
- **Forms:** single column on phones (container queries, so a form in a narrow container also stacks); inputs
  **≥16px font** on phones (prevents iOS zoom); 44px targets; correct `inputmode` / `autocomplete`; long page-level
  forms get a sticky submit footer.
- **Stepper:** "Step 2 of 4" + progress bar on phones.
- Row-action menus (⋯ in tables) are **bottom sheets** (short action lists, not forms).
- Implemented by extending `shared/dialog.js` (variants via data attributes), keeping its focus-trap, Esc,
  backdrop, scroll-lock, and form contracts.

### 9. Motion and interaction. Mockup 08, option B (Springy)

- Tokens (in `app.css`):
  - `--motion-fast` ≈ 180ms (press, hover, small fades), `--motion-base` ≈ 380ms (dropdowns, sheets, menu, tabs),
    `--motion-slow` ≈ 480ms (thread push, full-screen form, page-level panels).
  - **Entering:** spring ease with a small overshoot, `cubic-bezier(.34,1.45,.64,1)`. **Leaving:** plain ease-in
    `cubic-bezier(.4,0,1,1)`, no overshoot.
  - Press feedback: scale ~0.94 for icon buttons, a gentler ~0.98 for list rows. Dropdowns start at scale 0.9.
  - Menu tiles stagger in (~20ms apart).
  - The overshoot is for things that *arrive* (panels, sheets, tiles, tab indicator). Anything that tracks a finger
    or is a full-screen page uses the same durations without visible bounce if overshoot looks wrong at that size.
    The implementer tunes this on device.
- Hover styles only under `@media (hover:hover) and (pointer:fine)`; touch gets `active:` press feedback.
- **View Transitions:** cross-document `@view-transition { navigation: auto; }` for full page loads (~cross-fade),
  and `document.startViewTransition` around `spa/swap.js` swaps where supported. Unsupported browsers just skip it.
- Header gains a slightly stronger blur + shadow once the page is scrolled. Scrollers/tables show edge fades.
- **No scroll-reveal animations.** Skeletons (`components/skeleton.php`, `spa/skeleton-rows.js`) match each
  layout (row-shaped on phones).
- `prefers-reduced-motion: reduce`: all durations 0, no scale, no stagger, View Transitions off.

### 10. Type and spacing

- Body text fixed 16px everywhere; small/caption fixed 12–14px.
- Fluid: page `h1` 24→32px, `h2` 20→24px, dashboard metric numbers 28→40px (320→1440 range).
- One gutter token replaces the `px-4 sm:px-8 lg:px-16 xl:px-40` chain: `clamp(1rem, 4vw, 2.5rem)`, with a wider
  variant for menu-less pages up to `xl` matching today's desktop look; fluid card gaps 12→24px.
- Reading / long text max ~70ch.
- Exposed as utilities from `@theme` (e.g. `text-fluid-h1`, `px-gutter`, `gap-fluid`).

### 11. Edge conditions

- `viewport-fit=cover` + `env(safe-area-inset-*)` on header, sheets, sticky bars, full-screen menu and forms.
- Landscape phones: sheets ≤85dvh, sticky bars compact; the height-aware lock covers dashboards.
- Replace `h-screen` with `dvh` equivalents (`min-h-dvh`, `h-dvh`).
- 200% zoom / 320 CSS px: no page-level horizontal scroll.
- 280px (folded phones): usable, nothing overlapping or cut off; polish not required.
- Touch sizing keyed to `pointer: coarse`, independent of width (touch laptops get 44px targets).
- Print: existing print views (`dean/report-print.php`, grade reports, student report) verified unbroken.

### 12. Rollout. Seven stacked phases, one branch each

1. **Foundation:** tokens (fluid type, gutter, gaps, motion, `xs`/`3xl`), `dvh` + safe-area, hover/touch rules,
   View Transitions, shared modules (row-actions sheet, full-screen menu, edge-safe dropdown positioning,
   full-screen dialog variant, sticky expandable action bar, tabs-on-phone helper if needed).
2. **App shell + auth:** header (§3), side menu tiers (§4), lock rule (§6), auth pages (login, 2FA, invitation,
   password reset).
3. **Shared components:** `data-table` (§5), dialogs/confirm/forms/stepper (§8), metric/app tiles and cards with
   container queries, tabs, toolbar, filter dropdown, pagination, empty state, breadcrumb, page head.
4. **Student + parent screens:** dashboard tabs, courses, module/reading, quiz taking, calendar/schedule, grades,
   messages, notifications.
5. **Instructor screens:** course workspace, Grade Work, Quiz Builder, gradebook and other grid tables, assessments.
6. **Chairperson + dean:** dashboards and partials, global grades, reports.
7. **Institution admin + platform admin:** remaining admin pages, monitoring, offering schedule/copy.

Branch naming: `feat/responsive-<n>-<name>` (phase 1 `feat/responsive-1-foundation` off current `main`), each
stacked on the previous. Claude leaves changes uncommitted and supplies commit messages.

### 13. Verification, per phase

- New `tests/e2e/responsive.spec.js`: logs in as each relevant role, visits the phase's screens at every matrix size
  (plus landscape and 200% zoom) and asserts:
  - `document.documentElement.scrollWidth <= window.innerWidth` (grid-table scrollers exempt),
  - on touch emulation, every visible button/link is ≥44×44 and fully inside the viewport,
  - axe passes (including contrast), light and dark,
  - `toHaveScreenshot` baselines, accepted once the phase is approved and then guarding later phases.
- **Baselines live in CI (Linux), and CI enforces them** (product owner's decision). A manual `workflow_dispatch` job,
  "update responsive baselines", runs `--update-snapshots` on ubuntu-24.04 and uploads the snapshots. They're
  committed when a phase is approved. Normal CI runs fail when a screen drifts from its baseline. Locally (Windows)
  only the layout and axe assertions run; screenshot comparison is skipped off Linux.
- Vitest (jsdom) for every new/changed JS module: open, close, Esc, focus trap/return, swipe/back thresholds,
  dropdown edge-clamping.
- A short real-device checklist per phase for what emulation can't show (iOS keyboard + composer, safe areas, `dvh`
  with the address bar, momentum scrolling).
- Existing e2e specs keep passing.

## Left to the builder

- Exact token values within the stated ranges; spring curve tuning per element.
- Module boundaries/names in `resources/js/shared`, and whether to use the Popover API or the existing
  `details-menu.js` pattern for each dropdown.
- How `data-table.php` declares `priority` / row roles (keep the API small; default sensible when unset).
- Per-role dashboard tab sets, following §6's rule.
- Which existing templates need individual fixes after phases 1–3. The visual sweep finds them; the static counts
  above are the starting list.
- Screenshot-baseline thresholds and how the spec seeds test data (reuse `tests/e2e/support`).

## Calls made by Claude (not discussed; check these)

- **The admin-menu lock follows §6 too.** `layouts/app.php` locks the shell when `$fullHeight || $hasMenu`. Both
  conditions use the same height-aware rule (≥1280 wide and ≥760 tall). Below that, admin pages scroll normally,
  and on `lg` the overlay rail is `sticky` beside the scrolling page.
- **§4 covers both menus:** `layouts/partials/institution-menu.php` (institution admin, `layouts/app.php`) and
  `layouts/partials/admin-menu.php` (platform admin, `layouts/admin.php`). They share one implementation.
- **CI tiers:** every push runs a smoke subset of `responsive.spec.js` (375, 834 and 1366, light theme, one screen
  per role-area). The full matrix (all sizes, landscape, 200% zoom, light and dark) runs on `workflow_dispatch` and
  nightly. Each phase adds its screens to both lists.
- **WebKit:** a Playwright `webkit` project (iPhone device profile, already bundled with `@playwright/test`, no npm
  change) runs the responsive smoke subset only. It catches Safari differences in `dvh`, View Transitions and
  Popover early. The real-device checklist is still run by the product owner each phase.
