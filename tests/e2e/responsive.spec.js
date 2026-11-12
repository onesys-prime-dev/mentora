// The responsive sweep: every screen in SCREENS at every window size (support/responsive.js), checked for
// sideways page scroll, touch targets (phone and tablet sizes), axe findings and, on Linux, a screenshot
// baseline. A normal run checks the smoke sizes in light; RESPONSIVE_FULL=1 (the nightly Responsive
// workflow) checks the whole matrix in light and dark, plus 200% zoom. The WebKit project runs the 375px
// phone only, with the iPhone's own touch.
//
// Later phases append to SCREENS. `as` is an account key from support/sign-in.js (null: signed out);
// `path` is a string or an async (page) => string, for a screen whose address has to be looked up first;
// `scope` limits the touch-target and axe checks to part of the page; `overflowScope` limits the overflow
// check to one element (the shell screens own the frame, not the page content; without it the whole page is
// checked); `dialogs` are the ids of dialogs inside the scope to open and check at the touch sizes where
// their opener shows (the ☰ menu has none from lg up).
import { expect, test } from "@playwright/test";
import { expectAccessible, expectInViewport, expectNoPageOverflow, expectNoPanelOverflow, expectTouchTargets, setTheme, sizes, snap, zoom200 } from "./support/responsive.js";
import { runSql } from "./support/database.js";
import { signInAsAdmin } from "./support/admin.js";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { accounts, submitLoginForm } from "./support/sign-in.js";
import { disableMessages, enableMessages } from "./support/messages.js";

const SCREENS = [
    { name: "login", path: "/login", as: null },
    {
        name: "ui-kit",
        path: "/ui-kit",
        as: "student",
        scope: "[data-responsive-kit]",
        dialogs: ["kit-fullscreen", "kit-sheet", "kit-menu", "kit-resp-confirm", "kit-comp-form"],
    },
    // Header panels are not dialogs; the shell tests below open them.
    { name: "student-shell", path: "/notifications", as: "student", scope: "[data-app-header]", overflowScope: "[data-app-header]" },
    {
        name: "institution-shell",
        path: "/institution/dashboard",
        as: "institutionAdmin",
        scope: "[data-app-header], [data-institution-menu]",
        overflowScope: "[data-app-header]",
        dialogs: ["nav-menu"],
    },
    { name: "student-dashboard", path: "/student/dashboard", as: "student", scope: "main" },
    { name: "student-courses", path: "/student/courses", as: "student", scope: "main" },
    { name: "student-course-overview", path: (page) => coursePath(page, "overview"), as: "student", scope: "main" },
    { name: "student-course-announcements", path: (page) => coursePath(page, "announcements"), as: "student", scope: "main" },
    { name: "student-module-item", path: moduleItemPath, as: "student", scope: "main" },
    // The seed's reading item the clean-slate student can open first. There is no PDF row: the only PDFs in
    // student5's courses (IT101-A "Parts of a Computer", "Data Types Cheat Sheet") sit behind prerequisites, and
    // IT102-A's PDFs are in a course student5 is not enrolled in, so this student cannot open one.
    { name: "student-item-reading", path: (page) => moduleItemPath(page, READING_TITLE), as: "student", scope: "main" },
    // Scoped to the loaded list, so open() waits for it instead of checking the course skeleton.
    { name: "student-assessments", path: (page) => coursePath(page, "assessments"), as: "student", scope: "[data-assessments-overview]" },
    // Scoped to main once calendar.js has drawn the month, so open() never checks the server skeleton.
    { name: "student-calendar", path: "/student/calendar", as: "student", scope: "main:has([data-calendar-cell])" },
    { name: "student-schedule", path: "/student/schedule", as: "student", scope: "main" },
    // The menu entry redirects to the first child's calendar (/parent/children/{id}/calendar).
    { name: "parent-calendar", path: "/parent/calendar", as: "parent", scope: "main:has([data-calendar-cell])" },
    // Scoped to main once the course rows have rendered.
    { name: "student-grades", path: "/student/grades", as: "student", scope: "main:has([data-grades-row])" },
    { name: "parent-grades", path: "/parent/grades", as: "parent", scope: "main:has([data-grades-row])" },
    // Messages ships off: the file switches it on once (beforeAll). Scoped to the app once its list has rows.
    { name: "student-messages", path: "/messages", as: "student", scope: "#messages-app:has([data-role=rows] button)" },
    { name: "student-notifications", path: "/notifications", as: "student", scope: "[data-notifications-page]" },
    { name: "student-announcements", path: "/student/announcements", as: "student", scope: "main" },
    { name: "parent-dashboard", path: "/parent/dashboard", as: "parent", scope: "main:has([data-parent-course])" },
    // #course-content loses aria-busy once the tab's fragment has loaded.
    { name: "parent-course", path: parentCoursePath, as: "parent", scope: "main:has(#course-content:not([aria-busy]))" },
    { name: "parent-profile", path: "/parent/profile", as: "parent", scope: "main" },
    { name: "student-profile", path: "/student/profile", as: "student", scope: "main" },
];

// Messages on for every screen in this file, so each one (its header's Messages icon, the parent course tab) looks
// the same whatever the test order or -g filter; the sync of the course chats is slow, so it runs once.
test.beforeAll(() => enableMessages());

// The other specs share this database and expect Messages off and no chats left (as messages.spec.js leaves it).
test.afterAll(() => disableMessages());

/** The first course card on the parent's dashboard (the address may carry /index.php, so the match is not anchored). */
async function parentCoursePath(page) {
    await page.goto("/parent/dashboard");
    const href = await page.locator("[data-parent-course] a").first().getAttribute("href");

    return new URL(href, page.url()).pathname;
}

const READING_TITLE = "Welcome to Introduction to Computing";

/** The first course on the student's course list, opened on one of its tabs. */
async function coursePath(page, feature) {
    await page.goto("/student/courses");
    const href = await page.locator('ul[aria-label="Your courses"] a[href*="/student/courses/"]').first().getAttribute("href");

    // Not anchored: the address may carry /index.php before /student (the e2e server's indexPage).
    return `${new URL(href, page.url()).pathname.match(/\/student\/courses\/\d+\/[^/]+/)[0]}/${feature}`;
}

/**
 * The first course's first module, opened on the first item the student can open (the address the Overview
 * links use: the Modules tab with ?item=).
 */
async function moduleItemPath(page, title) {
    const modules = await coursePath(page, "modules");
    await page.goto(modules);

    const card = page.locator("[data-module-wrapper]").first();
    const unlocked = page.locator("[data-learning-item-wrapper]:not([data-locked])");
    const items = title ? unlocked.filter({ hasText: title }) : unlocked;
    // A click that lands before the page script has attached does nothing, so the step repeats until it takes.
    await expect(async () => {
        const content = card.locator("[data-module-content]").first();
        if (!((await content.getAttribute("class")) ?? "").includes("max-h-[2000px]")) {
            await card.locator("[data-module]").first().click({ timeout: 2_000 });
        }
        await card.getByRole("button", { name: "Go to module" }).click({ timeout: 3_000 });
        await expect(items.first()).toBeAttached({ timeout: 3_000 });
    }).toPass({ timeout: 30_000 });

    return `${modules}?item=${await items.first().getAttribute("data-learning-item")}`;
}

const FULL = process.env.RESPONSIVE_FULL === "1";
const THEMES = FULL ? ["light", "dark"] : ["light"];

/** Phones and tablets (up to a 1024px iPad held sideways) are touched; the larger sizes use a mouse. */
const isTouch = (size) => size.w <= 1024;

/** Signs in as the screen's account (if any) and opens the screen in the given theme. */
async function open(page, screen, theme) {
    await setTheme(page, theme);

    if (screen.as) {
        const { email, password } = accounts[screen.as];
        await submitLoginForm(page, email, password);
        await expect(page).not.toHaveURL(/\/login$/);
    }

    await page.goto(typeof screen.path === "function" ? await screen.path(page) : screen.path);
    // Otherwise a 404 or a wrong page passes the scoped checks with nothing to check.
    await expect(page.locator(screen.scope ?? "body").first()).toBeVisible();
}

/**
 * Clicks a control until its dialog is open. A click that lands before the page's script has attached does
 * nothing (a slow machine, such as a CI runner, hits this), so it is repeated until it takes.
 */
async function openDialog(trigger, dialog) {
    await expect(async () => {
        if (!(await dialog.isVisible())) {
            await trigger.click({ timeout: 2_000 });
        }

        await expect(dialog).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
}

for (const size of sizes()) {
    test.describe(`@${size.name}`, () => {
        test.use({
            viewport: { width: size.w, height: size.h },
            ...(isTouch(size) ? { hasTouch: true, isMobile: true } : {}),
        });

        for (const screen of SCREENS) {
            for (const theme of THEMES) {
                test(`${screen.name} @${size.name} ${theme}`, async ({ page }, testInfo) => {
                    test.skip(testInfo.project.name === "webkit" && size.w !== 375, "WebKit runs the 375px phone only");

                    await open(page, screen, theme);

                    await expectNoPageOverflow(page, screen.overflowScope);
                    if (isTouch(size)) {
                        await expectTouchTargets(page, screen.scope);
                    }
                    await expectAccessible(page, screen.scope);
                    await snap(page, `${screen.name}-${size.name}-${theme}`);

                    if (!isTouch(size)) {
                        return;
                    }

                    for (const id of screen.dialogs ?? []) {
                        const dialog = page.locator(`#${id}`);
                        const trigger = page.locator(`:is(${screen.scope ?? "body"}) [data-dialog-open="${id}"]`);
                        // The ☰ menu's opener is lg:hidden; below lg every opener must show.
                        if (size.w >= 1024 && !(await trigger.isVisible())) {
                            continue;
                        }
                        await expect(trigger, `#${id}'s opener`).toBeVisible();
                        await openDialog(trigger, dialog);

                        await expectTouchTargets(page, `#${id}`);
                        await expectNoPageOverflow(page, screen.overflowScope);
                        await expectNoPanelOverflow(dialog);

                        await page.keyboard.press("Escape");
                        await expect(dialog).toBeHidden();
                    }
                });
            }
        }
    });
}

// The animated close, which the sweep (reduced motion) never plays: every way out ends hidden, with focus
// back on the opener and the page scrollable again, including a fast double click on ×.
test.describe("dialog close with motion", () => {
    test.use({ reducedMotion: "no-preference", viewport: { width: 1366, height: 768 } });

    test("ui-kit dialog closes by ×, Escape, backdrop and a double ×", async ({ page }, testInfo) => {
        test.skip(testInfo.project.name === "webkit", "WebKit runs the 375px phone only");

        await open(page, SCREENS[1], "light");
        const opener = page.locator('[data-responsive-kit] [data-dialog-open="kit-fullscreen"]');
        const dialog = page.locator("#kit-fullscreen");
        const close = dialog.locator("[data-dialog-close]").first();

        const closes = {
            "×": () => close.click(),
            Escape: () => page.keyboard.press("Escape"),
            backdrop: () => dialog.click({ position: { x: 5, y: 5 } }),
            "double ×": () => close.dblclick(),
        };

        for (const [how, act] of Object.entries(closes)) {
            await openDialog(opener, dialog);
            await act();

            await expect(dialog, how).toBeHidden();
            await expect(opener, how).toBeFocused();
            await expect(page.locator("body"), how).not.toHaveClass(/\boverflow-hidden\b/);
        }
    });
});

// The shell's own checks (header, side menus, lock), each at the one size it is about.
test.describe("shell", () => {
    test.skip(({ browserName }) => browserName === "webkit", "WebKit runs the 375px phone sweep only");

    const signIn = async (page, as, path) => open(page, { path, as, scope: "[data-app-header]" }, "light");
    const phone = (width, height) => ({ viewport: { width, height }, hasTouch: true, isMobile: true });

    test.describe("320 phone", () => {
        test.use(phone(320, 640));

        test("phone header fits at 320", async ({ page }) => {
            // A page with no <h1> of its own, so the header shows its title (layouts/app.php).
            await signIn(page, "student", "/student/schedule");
            const title = page.locator("[data-header-title]");
            await expect(title).toBeVisible();
            await title.evaluate((el) => {
                el.textContent = "A very long page title ".repeat(6).slice(0, 120);
            });

            await expectInViewport(page, '[data-profile-menu-trigger][aria-label^="More"]');
            await expectInViewport(page, "#notifications-button");
            await expectInViewport(page, "[data-brand-logo] img >> visible=true");
            const { height } = await page.locator("[data-app-header]").boundingBox();
            // One line is 69px; a header that wraps to a second line is over 100px.
            expect(height, "the phone header is one line").toBeLessThanOrEqual(80);
        });
    });

    for (const [w, h] of [[375, 812], [700, 900]]) {
        test.describe(`${w} panels`, () => {
            // Read the panel's rect after it has settled, not mid-animation.
            test.use({ ...phone(w, h), reducedMotion: "reduce" });

            test(`⋯ and bell panels stay on screen at ${w}`, async ({ page }) => {
                await signIn(page, "student", "/student/dashboard");

                for (const [trigger, panel] of [
                    ["[data-profile-menu-trigger] >> visible=true", "#profile-menu-panel"],
                    ["#notifications-button", "#notifications-panel"],
                ]) {
                    await openDialog(page.locator(trigger), page.locator(panel));
                    await expectInViewport(page, panel);
                    await page.keyboard.press("Escape");
                    await expect(page.locator(panel), panel).toBeHidden();
                }
            });
        });
    }

    test.describe("1024 rail", () => {
        test.use({ viewport: { width: 1024, height: 768 } });

        test("rail overlay does not move the page at 1024", async ({ page }) => {
            await signIn(page, "institutionAdmin", "/institution/dashboard");
            const main = page.locator("main");
            const before = (await main.boundingBox()).x;

            await page.locator("[data-institution-menu]").hover();
            await expect.poll(async () => (await page.locator("[data-institution-menu] [data-side-menu-panel]").boundingBox()).width).toBeGreaterThanOrEqual(240);

            expect((await main.boundingBox()).x, "main's left edge").toBe(before);
        });
    });

    // The system administrator signs in with an emailed code, which the SCREENS `as` sign-in cannot do.
    test.describe("1024 admin rail on touch", () => {
        test.use({ viewport: { width: 1024, height: 768 }, hasTouch: true, isMobile: true });

        test("admin-shell: the peek button opens the rail and a group link is reachable", async ({ page }) => {
            await signInAsAdmin(page);
            const menu = page.locator("[data-admin-menu]");
            const toggle = menu.locator("[data-menu-collapse]");
            const link = menu.locator('a[href$="/admin/flags"]');

            await expect(link).toBeHidden();
            await expect(toggle).toHaveAttribute("aria-label", "Expand the menu");
            await expectTouchTargets(page, "[data-admin-menu]");

            await expect(async () => {
                await toggle.tap({ timeout: 2_000 });
                await expect(menu).toHaveAttribute("data-peek", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            await expect(toggle).toHaveAttribute("aria-label", "Collapse the menu");
            await expect.poll(async () => (await menu.locator("[data-side-menu-panel]").boundingBox()).width).toBeGreaterThanOrEqual(240);

            await menu.locator('[data-menu-group="Platform"] summary').tap();
            await expect(link).toBeVisible();
            await expectTouchTargets(page, "[data-admin-menu]");
            await expectInViewport(page, '[data-admin-menu] a[href$="/admin/flags"]');
            await expectNoPageOverflow(page);

            await link.tap();
            await expect(page).toHaveURL(/\/admin\/flags$/);
        });
    });

    test("lock applies only when wide and tall", async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        await signIn(page, "student", "/student/dashboard");
        const overflowY = () => page.locator("body").evaluate((el) => globalThis.getComputedStyle(el).overflowY);

        expect(await overflowY(), "1366×768").toBe("hidden");
        for (const [width, height] of [[1366, 700], [1024, 900]]) {
            await page.setViewportSize({ width, height });
            expect(await overflowY(), `${width}×${height}`).not.toBe("hidden");
        }
    });

    test("a locked page fits main and scrolls inside its cards", async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        await signIn(page, "student", "/student/dashboard");

        const m = await page.locator("main").evaluate((main) => {
            const root = [...main.firstElementChild.children].find((el) => el.tagName === "DIV");
            const scrollers = [...root.querySelectorAll("*")].filter((el) => /auto|scroll/.test(globalThis.getComputedStyle(el).overflowY) && el.scrollHeight > el.clientHeight);

            return { root: root.getBoundingClientRect().bottom, main: main.getBoundingClientRect().bottom, scrollers: scrollers.length };
        });

        expect(m.root, "the page root's bottom against main's").toBeLessThanOrEqual(m.main + 1);
        expect(m.scrollers, "cards that scroll their own overflow").toBeGreaterThan(0);
    });

    test.describe("375 phone menu", () => {
        test.use(phone(375, 812));

        test("phone menu opens and closes", async ({ page }) => {
            await signIn(page, "institutionAdmin", "/institution/dashboard");
            const opener = page.locator('[data-dialog-open="nav-menu"]');
            const menu = page.locator("#nav-menu");

            await openDialog(opener, menu);
            await expectTouchTargets(page, "#nav-menu");
            await expect(menu.locator("[data-menu-tile]").first()).toBeVisible();

            await page.keyboard.press("Escape");
            await expect(menu).toBeHidden();
            await expect(opener).toBeFocused();
        });
    });
});

// The shared components, on /ui-kit's Components section, each checked at the size it is about. The sweep
// above already runs touch targets, axe, overflow and snapshots over the section at every size.
test.describe("components", () => {
    const kit = SCREENS.find((s) => s.name === "ui-kit");
    const people = '[data-kit="people"] [data-table]';
    const grid = '[data-kit="grid"] [data-scroll-x][data-sticky-first]';
    const panelBox = (dialog) => dialog.locator("[data-dialog-panel]").boundingBox();

    // WebKit runs these too: they are the 375px phone, where Safari's own table and sticky handling matter.
    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("list table becomes list rows on a phone", async ({ page }) => {
            await open(page, kit, "light");
            const table = page.locator(people);
            const sheet = page.locator("#kit-people-actions");

            // The heading row is still there for a screen reader, but takes no room.
            await expect(table.locator("thead")).toHaveCSS("position", "absolute");
            expect((await table.locator("thead").boundingBox()).height).toBeLessThanOrEqual(1);

            const panels = table.locator("tbody[data-panel]");
            await expect(panels).toHaveCount(2);
            await expect(panels.first()).toHaveCSS("display", "block");
            await expect(panels.locator("[data-group-name]")).toHaveText(["Section A", "Section B"]);
            await expect(panels.first().locator("[data-group-heading]")).toBeVisible();
            await expect(table.locator('[data-row] > [data-role="primary"]').first()).toHaveText("Ann Reyes");
            await expect(table.locator('[data-row] > [data-label="Email"]').first()).toBeHidden();
            await expect(table.locator('[data-row] > [data-role="actions"]').first()).toBeHidden();

            await openDialog(table.locator("[data-row-actions-open]").first(), sheet);
            await expect(sheet.locator("[data-dialog-title]")).toHaveText("Ann Reyes");
            await expect(sheet.locator("[data-row-actions-fields]")).toContainText("Email");
            await expect(sheet.locator("[data-row-actions-fields]")).toContainText("ann.reyes@example.edu");
            await expect(sheet.locator("[data-row-actions-fields]")).toContainText("Last active");
            await expect(sheet.locator("[data-row-actions-slot] button")).toHaveText(["Send a message", "Remove from section"]);
            await expectTouchTargets(page, "#kit-people-actions");
            await expectNoPanelOverflow(sheet);
        });

        test("row action returns its nodes on close", async ({ page }) => {
            await open(page, kit, "light");
            const row = page.locator(`${people} [data-row]`).first();
            const more = row.locator("[data-row-actions-open]");
            const sheet = page.locator("#kit-people-actions");

            await openDialog(more, sheet);
            await expect(row.locator('[data-role="actions"] button')).toHaveCount(0);

            await page.keyboard.press("Escape");
            await expect(sheet).toBeHidden();
            await expect(row.locator('[data-role="actions"] button')).toHaveText(["Send a message", "Remove from section"]);
            await expect(sheet.locator("[data-row-actions-slot] > *")).toHaveCount(0);
            await expect(more).toBeFocused();
        });

        test("grid scroll pins the first column", async ({ page }) => {
            await open(page, kit, "light");
            const scroller = page.locator(grid);
            await scroller.scrollIntoViewIfNeeded();

            const room = await scroller.evaluate((el) => el.scrollWidth - el.clientWidth);
            expect(room, "the grid is wider than a phone").toBeGreaterThanOrEqual(200);
            await expect(scroller).not.toHaveAttribute("data-fade-start");
            const nextLeft = () => scroller.evaluate((el) => el.querySelector("tr[data-roles-row] > :nth-child(2)").getBoundingClientRect().left);
            const before = await nextLeft();

            await scroller.evaluate((el) => (el.scrollLeft = 200));
            await expect(scroller).toHaveAttribute("data-fade-start");

            const edges = await scroller.evaluate((el) => ({
                box: el.getBoundingClientRect().left + el.clientLeft,
                head: el.querySelector("thead tr > :first-child").getBoundingClientRect().left,
                cell: el.querySelector("tr[data-roles-row] > :first-child").getBoundingClientRect().left,
            }));
            const after = await nextLeft();
            expect(Math.abs(edges.cell - edges.box), "the first column's cell against the grid's left edge").toBeLessThanOrEqual(1);
            expect(Math.abs(edges.head - edges.box), "the first column's heading against the grid's left edge").toBeLessThanOrEqual(1);
            expect(Math.abs(before - after - 200), `the next column moved left by the scroll (${Math.round(before)} to ${Math.round(after)})`).toBeLessThanOrEqual(1);

            // At the far end: the pinned column keeps its shadow; the section heading, which spans the whole row,
            // has none (it would show as a stray shadow at the table's right edge).
            await scroller.evaluate((el) => (el.scrollLeft = el.scrollWidth));
            await expect(scroller).not.toHaveAttribute("data-fade-end");
            const shadows = await scroller.evaluate((el) => ({
                cell: globalThis.getComputedStyle(el.querySelector("tr[data-roles-row] > :first-child")).boxShadow,
                section: globalThis.getComputedStyle(el.querySelector("tr[data-roles-section-head] > :first-child")).boxShadow,
            }));
            expect(shadows.cell, "the pinned column's shadow").not.toBe("none");
            expect(shadows.section, "the section heading's shadow").toBe("none");
        });

        test("the active tab's underline overlaps the strip's border", async ({ page }) => {
            await open(page, kit, "light");
            const edges = await page.locator('[data-kit="tabs"] nav[data-tabs]').evaluate((nav) => ({
                nav: nav.getBoundingClientRect().bottom,
                tab: nav.querySelector('[aria-current="page"]').getBoundingClientRect().bottom,
            }));
            expect(Math.abs(edges.tab - edges.nav), `the underline ends at ${edges.tab}, the border at ${edges.nav}`).toBeLessThanOrEqual(0.5);
        });

        test("a metric card keeps its icon at 250px wide", async ({ page }) => {
            await open(page, kit, "light");
            const shown = await page.locator('[data-kit="cards"]').evaluate((cards) => {
                const slot = globalThis.document.createElement("div");
                slot.style.width = "250px";
                cards.append(slot);

                return [...cards.querySelectorAll(".\\@container")].slice(0, 2).map((card) => {
                    slot.append(card);

                    return card.querySelector("svg").getBoundingClientRect().width > 0;
                });
            });
            expect(shown, "both cards' icons show (default and prominent)").toEqual([true, true]);
        });

        test("stepper shows the summary on a phone", async ({ page }) => {
            await open(page, kit, "light");
            const stepper = page.locator('[data-kit="stepper"]');
            const summary = stepper.locator("[data-stepper-summary]");

            await expect(summary).toBeVisible();
            await expect(summary).toContainText("Step 2 of 3");
            await expect(summary).toContainText("Check the rows");
            await expect(summary.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "2");
            // The full list stays for a screen reader, with its hooks.
            await expect(stepper.locator("ol[data-stepper]")).toHaveCSS("position", "absolute");
            await expect(stepper.locator('[data-step="2"]')).toHaveAttribute("data-state", "current");
        });
    });

    test.describe("laptop and tablet", () => {
        test.skip(({ browserName }) => browserName === "webkit", "WebKit runs the 375px phone only");

        test("priority columns hide at md and show again at lg", async ({ page }) => {
            await page.setViewportSize({ width: 800, height: 900 });
            await open(page, kit, "light");
            const table = page.locator(people);
            const heading = (label) => table.locator("thead th", { hasText: label });
            const cell = (label) => table.locator(`[data-row] > [data-label="${label}"]`).first();

            await expect(heading("Program")).toBeVisible();
            await expect(heading("Email")).toBeHidden();
            await expect(cell("Email")).toBeHidden();
            await expect(heading("Last active")).toBeHidden();

            await page.setViewportSize({ width: 1100, height: 900 });
            await expect(heading("Email")).toBeVisible();
            await expect(cell("Email")).toBeVisible();
            await expect(heading("Last active"), "priority 3 waits for xl").toBeHidden();
        });

        test("form dialog is full screen on a phone and centered on a laptop", async ({ page }) => {
            await page.setViewportSize({ width: 375, height: 812 });
            await open(page, kit, "light");
            const form = page.locator("#kit-comp-form");
            const confirm = page.locator("#kit-resp-confirm");

            for (const [w, h] of [[375, 812], [1366, 768]]) {
                await page.setViewportSize({ width: w, height: h });

                await openDialog(page.locator('[data-kit="form-dialog"] [data-dialog-open="kit-comp-form"]'), form);
                const r = await panelBox(form);
                if (w === 375) {
                    expect(r.x <= 1 && r.y <= 1 && r.width >= w - 1 && r.height >= h - 1, `the form's panel at ${JSON.stringify(r)} fills ${w}×${h}`).toBe(true);
                    // The form's own submit row is pinned to the bottom, and its Cancel gives way to the top bar's.
                    const submit = await form.getByRole("button", { name: "Create course" }).boundingBox();
                    expect(submit.y + submit.height, "the pinned submit button's bottom").toBeGreaterThan(h - 100);
                    await expect(form.locator("[data-dialog-body] [data-dialog-close]")).toBeHidden();
                } else {
                    expect(r.width, "the form's panel width").toBeLessThan(w * 0.6);
                    expect(Math.abs(r.x + r.width / 2 - w / 2), "the form's panel is centered").toBeLessThanOrEqual(2);
                }
                await page.keyboard.press("Escape");
                await expect(form).toBeHidden();

                await openDialog(page.locator('[data-responsive-kit] [data-dialog-open="kit-resp-confirm"]'), confirm);
                const c = await panelBox(confirm);
                expect(c.width * c.height, `the confirm (${Math.round(c.width)}×${Math.round(c.height)}) stays small at ${w}`).toBeLessThan(0.5 * w * h);
                await page.keyboard.press("Escape");
                await expect(confirm).toBeHidden();
            }
        });

        // A dialog body is a query container (form-grid); a dropdown in it must still show whole.
        test("a dropdown inside a form dialog is not clipped", async ({ page }) => {
            await page.setViewportSize({ width: 375, height: 812 });
            await open(page, kit, "light");
            const form = page.locator("#kit-comp-form");
            const dropdown = form.locator("[data-kit-dropdown]");

            for (const [w, h] of [[375, 812], [1366, 768]]) {
                await page.setViewportSize({ width: w, height: h });
                await openDialog(page.locator('[data-kit="form-dialog"] [data-dialog-open="kit-comp-form"]'), form);
                await dropdown.locator("summary").click();

                const last = dropdown.getByRole("button", { name: "Instructors only" });
                await expect(last).toBeVisible();
                const onTop = await last.evaluate((el) => {
                    const r = el.getBoundingClientRect();

                    return el.contains(globalThis.document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
                });
                expect(onTop, `the dropdown's last item shows at ${w}`).toBe(true);

                await page.keyboard.press("Escape");
                await page.keyboard.press("Escape");
                await expect(form).toBeHidden();
            }
        });

        test("a form in a narrow container stacks", async ({ page }) => {
            await page.setViewportSize({ width: 1366, height: 768 });
            await open(page, kit, "light");
            const columns = (which) => page.locator(`[data-kit-form="${which}"] .form-grid`).evaluate((el) => globalThis.getComputedStyle(el).gridTemplateColumns.split(" ").length);

            expect(await columns("narrow"), "columns in the narrow card").toBe(1);
            expect(await columns("wide"), "columns in the wide card").toBe(2);
        });
    });
});

// The student dashboard's phone tabs (components/phone-tabs): Today, Courses, Updates below lg; the whole grid from lg up.
test.describe("student", () => {
    const dashboard = SCREENS.find((s) => s.name === "student-dashboard");
    // A card by its heading (key-metrics also has a "Pending Tasks" label, in smaller type).
    const card = (page, title) => page.locator("main span.text-xl").filter({ hasText: new RegExp(`^\\s*${title}\\s*$`) });

    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("only Today shows, and Courses switches to the course list", async ({ page }) => {
            await open(page, dashboard, "light");

            const tabs = page.getByRole("tablist", { name: "Dashboard sections" });
            await expect(tabs.getByRole("tab")).toHaveText(["Today", "Courses", "Updates"].map((t) => new RegExp(`^\\s*${t}`)));
            await expect(card(page, "Pending Tasks")).toBeVisible();
            await expect(card(page, "Reminders")).toBeVisible();
            await expect(card(page, "My Courses")).toBeHidden();
            await expect(card(page, "Announcements")).toBeHidden();

            // A tap that lands before the script has attached does nothing, so it is repeated until it takes.
            const courses = tabs.getByRole("tab", { name: /^Courses/ });
            await expect(async () => {
                await courses.tap({ timeout: 2_000 });
                await expect(courses).toHaveAttribute("aria-selected", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });

            await expect(card(page, "My Courses")).toBeVisible();
            await expect(card(page, "Quick Access")).toBeVisible();
            await expect(card(page, "Pending Tasks")).toBeHidden();
        });

        test("the task list shows at most five rows, with View all past that", async ({ page }) => {
            await open(page, dashboard, "light");

            const rows = page.locator("[data-task-row]");
            const total = await rows.count();
            // The seed decides how many tasks there are; with more than five, View all leads to the rest.
            await expect(rows.filter({ visible: true })).toHaveCount(Math.min(total, 5));
            const viewAll = page.locator("main [data-view-all]").filter({ hasText: "View all" }).first();
            if (total > 5) {
                await expect(viewAll).toBeVisible();
                await expect(viewAll).toHaveAttribute("href", /\/student\/calendar$/);
            }
        });
    });

    test.describe("1366 laptop", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("no tabs, every widget shows", async ({ page }) => {
            await open(page, dashboard, "light");

            await expect(page.locator("[data-phone-tabs]")).toBeHidden();
            await expect(page.locator("main").getByText("Courses Enrolled", { exact: true })).toBeVisible();
            for (const title of ["Pending Tasks", "Reminders", "My Courses", "Quick Access", "Announcements"]) {
                await expect(card(page, title), title).toBeVisible();
            }
            for (const link of await page.locator("main [data-view-all]").all()) {
                await expect(link).toBeHidden();
            }
        });
    });

    test.describe("parent 375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("Courses tab shows the cards and the page does not overflow", async ({ page }) => {
            await open(page, SCREENS.find((s) => s.name === "parent-dashboard"), "light");

            const tabs = page.getByRole("tablist", { name: "Dashboard sections" });
            await expect(tabs.getByRole("tab")).toHaveText(["Attention", "Courses", "Deadlines"].map((t) => new RegExp(`^\\s*${t}`)));
            await expect(page.locator("[data-parent-course]").first()).toBeHidden();

            const courses = tabs.getByRole("tab", { name: /^Courses/ });
            await expect(async () => {
                await courses.tap({ timeout: 2_000 });
                await expect(courses).toHaveAttribute("aria-selected", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });

            await expect(page.locator("[data-parent-course]").first()).toBeVisible();
            await expect(page.locator("[data-parent-deadlines]")).toBeHidden();
            await expectNoPageOverflow(page);
        });
    });

    // The Course Workspace frame (pages/courses/view.php): the feature tab strip scrolls and keeps its tab in view.
    test.describe("375 course workspace", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });
        const overview = SCREENS.find((s) => s.name === "student-course-overview");
        const box = (locator) => locator.evaluate((el) => el.getBoundingClientRect().toJSON());

        test("the tab strip scrolls and the selected tab stays in view", async ({ page }) => {
            await open(page, SCREENS.find((s) => s.name === "student-course-announcements"), "light");

            const strip = page.getByRole("tablist", { name: "Course sections" });
            expect(await strip.evaluate((el) => el.scrollWidth > el.clientWidth)).toBe(true);

            // Clicked from script, not tapped: a tap would scroll the tab into view itself and prove nothing.
            const last = strip.getByRole("tab").last();
            await strip.evaluate((el) => { el.scrollLeft = 0; });
            expect((await box(last)).right).toBeGreaterThan(375);
            await expect(async () => {
                await last.evaluate((el) => el.click());
                await expect(last).toHaveAttribute("aria-selected", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });

            await expect.poll(async () => {
                const r = await box(last);

                return r.left >= 0 && r.right <= 376;
            }).toBe(true);
        });

        test("the banner folds on scroll without covering the tab strip", async ({ page }) => {
            await open(page, overview, "light");

            const sticky = page.locator("[data-course-sticky]");
            await page.evaluate(() => globalThis.scrollTo(0, 600));
            await expect(sticky).toHaveAttribute("data-collapsed", "true");

            const strip = page.getByRole("tablist", { name: "Course sections" });
            const header = await box(page.locator("[data-app-header]").first());
            const banner = await box(sticky.locator(":scope > div").first());
            const tabs = await box(strip);
            expect(tabs.top).toBeGreaterThanOrEqual(header.bottom - 1);
            expect(tabs.top).toBeGreaterThanOrEqual(banner.bottom - 1);
            expect(tabs.bottom).toBeLessThanOrEqual(812);
        });
    });

    // Messages below md (pages/messages/phone-nav.js): the thread is pushed over the list with a history entry.
    test.describe("375 messages", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });
        const messages = SCREENS.find((s) => s.name === "student-messages");
        const app = (page) => page.locator("#messages-app");

        /** Taps the first conversation until its thread has loaded (a tap before the script attaches does nothing). */
        async function openFirst(page) {
            const row = page.locator("[data-role=rows] button[data-id]").first();
            await expect(async () => {
                if ((await app(page).getAttribute("data-view")) !== "thread") {
                    await row.tap({ timeout: 2_000 });
                }
                await expect(app(page)).toHaveAttribute("data-view", "thread", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            await expect(page.locator("[data-state=thread-loading]")).toBeHidden();
        }

        test("a conversation pushes the thread over an inert list, and the browser's Back returns", async ({ page }) => {
            await open(page, messages, "light");
            const list = page.locator("[data-pane=list]");
            const pane = page.locator("[data-pane=thread]");
            const address = page.url();

            const id = await page.locator("[data-role=rows] button[data-id]").first().getAttribute("data-id");
            await openFirst(page);
            await expect(pane).toBeVisible();
            // The tapped row is inert now, so focus moves into the thread, not to <body>.
            await expect(page.locator("[data-role=back]")).toBeFocused();
            await expect(list).toHaveAttribute("inert", "");
            await expect(list).toHaveAttribute("aria-hidden", "true");
            await expect(list).toBeHidden();

            await page.goBack();
            await expect(app(page)).toHaveAttribute("data-view", "list");
            expect(page.url()).toBe(address);
            await expect(list).toBeVisible();
            await expect(list).not.toHaveAttribute("inert", "");
            await expect(pane).toBeHidden();
            await expect(pane).toHaveAttribute("inert", "");
            await expect(page.locator(`[data-role=rows] button[data-id="${id}"]`)).toBeFocused();
            await expectNoPageOverflow(page);
        });

        test("Back from a deep-linked thread lands on the list, not the page before", async ({ page }) => {
            await open(page, messages, "light");
            const id = await page.locator("[data-role=rows] button[data-id]").first().getAttribute("data-id");

            // The browser's Back.
            await page.goto("/student/dashboard");
            await page.goto(`/messages/${id}`);
            await expect(app(page)).toHaveAttribute("data-view", "thread");
            await expect(page.locator("[data-role=back]")).toBeFocused();
            await page.goBack();
            await expect(page).toHaveURL(/\/messages$/);
            await expect(app(page)).toHaveAttribute("data-view", "list");
            await expect(page.locator("[data-pane=list]")).toBeVisible();

            // A reload of the deep link still opens the thread.
            await page.goto(`/messages/${id}`);
            await page.reload();
            await expect(app(page)).toHaveAttribute("data-view", "thread");

            // The thread's own Back.
            const back = page.locator("[data-role=back]");
            await expect(async () => {
                await back.tap({ timeout: 2_000 });
                await expect(app(page)).toHaveAttribute("data-view", "list", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            await expect(page).toHaveURL(/\/messages$/);
            await expect(page.locator("[data-pane=list]")).toBeVisible();
        });

        test("the composer stays inside the window when focused, and the page itself does not scroll", async ({ page }) => {
            await open(page, messages, "light");
            await openFirst(page);

            await page.locator("[data-role=body]").focus();
            await expectInViewport(page, "[data-role=composer]");
            expect(await page.evaluate(() => globalThis.document.documentElement.scrollHeight - globalThis.innerHeight)).toBeLessThanOrEqual(1);
        });
    });
});

// The learning item view (modules/learning-item-view.php): below lg the module outline is a "Contents" dropdown
// above the item, so the item gets the width; from lg up it is the sidebar.
test.describe("student module item", () => {
    const item = SCREENS.find((s) => s.name === "student-module-item");
    const contents = (page) => page.locator("details[data-contents]");
    const column = (page) => page.locator("[data-learning-item-panel] + div");

    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("the item has the width, and Contents lists the units and loads the chosen item", async ({ page }) => {
            await open(page, item, "light");

            // The 320px outline used to stay beside the item, leaving it ~80px and hiding the breadcrumb.
            expect(await column(page).evaluate((el) => el.getBoundingClientRect().width)).toBeGreaterThan(300);
            const trail = page.getByRole("navigation", { name: "Breadcrumb" });
            await expect(trail.locator('[aria-current="page"]')).toBeVisible();
            expect(await trail.evaluate((el) => el.getBoundingClientRect().width)).toBeGreaterThan(250);

            const summary = contents(page).locator("summary");
            await expect(summary).toContainText("Contents");
            const panel = page.locator("[data-contents-panel]");
            await expect(panel).toBeHidden();

            await summary.tap();
            await expect(contents(page)).toHaveAttribute("open", "");
            await expect(panel).toBeVisible();
            expect(await panel.locator("[data-learning-unit]").count()).toBeGreaterThan(0);
            await expectNoPageOverflow(page);

            // Another item the student can open (not the current one, not locked).
            const current = new URL(page.url()).searchParams.get("item");
            const other = panel.locator(`[data-panel-item]:not([data-locked]):not([data-panel-item="${current}"])`).first();
            await expect(other).toBeVisible();
            const id = await other.getAttribute("data-panel-item");
            const title = (await other.locator("[data-item-title]").innerText()).trim();

            await other.tap();
            await expect(page).toHaveURL(new RegExp(`[?&]item=${id}(&|$)`));
            await expect(page.locator("[data-item-header-title]")).toHaveText(title);
            await expect(contents(page)).not.toHaveAttribute("open", "");
            await expect(page.locator("[data-contents-panel]")).toBeHidden();
        });

        test("Escape closes Contents", async ({ page }) => {
            await open(page, item, "light");

            await contents(page).locator("summary").tap();
            await expect(page.locator("[data-contents-panel]")).toBeVisible();
            await page.keyboard.press("Escape");
            await expect(page.locator("[data-contents-panel]")).toBeHidden();
        });

        test("Previous and Continue stay at the bottom of the window", async ({ page }) => {
            await open(page, item, "light");

            const footer = page.locator("[data-learning-item-footer]");
            await expect(footer).toBeVisible();
            const box = await footer.evaluate((el) => el.getBoundingClientRect().toJSON());
            // Pinned to the bottom edge (or resting above it when the item is shorter than the window).
            expect(box.bottom).toBeLessThanOrEqual(812 + 1);
            expect(await footer.evaluate((el) => globalThis.getComputedStyle(el).position)).toBe("sticky");
        });
    });

    // Review Focus 5: a reading with one very long unbroken word (and a code-like token) must not widen the page.
    for (const width of [320, 375]) {
        test.describe(`${width} reading with unbroken text`, () => {
            test.use({ viewport: { width, height: 812 }, hasTouch: true, isMobile: true });

            test("only the text wraps; the page does not widen", async ({ page }) => {
                const reading = SCREENS.find((s) => s.name === "student-item-reading");
                const where = `WHERE title = '${READING_TITLE}' AND item_type = 'reading'`;
                const blocks = [
                    { type: "paragraph", content: [{ text: "Unbroken".repeat(80) }] },
                    { type: "paragraph", content: [{ text: "const_value=object.method(argument_one,argument_two);".repeat(12) }] },
                ];
                const content = JSON.stringify({ blocks });

                runSql("DROP TABLE IF EXISTS t6_reading_backup");
                runSql(`CREATE TABLE t6_reading_backup AS SELECT id, content FROM learning_items ${where}`);
                try {
                    runSql(`UPDATE learning_items SET content = '${content}' ${where}`);
                    await open(page, reading, "light");

                    await expect(page.locator(".reading-content")).toContainText("UnbrokenUnbroken");
                    await expectNoPageOverflow(page);
                    // The text wrapped inside the column instead of spilling out of it.
                    expect(await page.locator(".reading-content").evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
                } finally {
                    runSql("UPDATE learning_items li JOIN t6_reading_backup b ON b.id = li.id SET li.content = b.content");
                    runSql("DROP TABLE IF EXISTS t6_reading_backup");
                }
            });
        });
    }

    test.describe("1366 laptop", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("the outline is the sidebar, with no Contents control", async ({ page }) => {
            await open(page, item, "light");

            await expect(contents(page).locator("summary")).toBeHidden();
            await expect(page.locator("[data-contents-panel]")).toBeVisible();
            await expect(page.locator("[data-contents-panel] [data-panel-item]").first()).toBeVisible();
            const aside = await page.locator("[data-learning-item-panel]").evaluate((el) => el.getBoundingClientRect().toJSON());
            expect(Math.round(aside.width)).toBe(320);
            expect((await column(page).evaluate((el) => el.getBoundingClientRect().toJSON())).left).toBeGreaterThanOrEqual(aside.right - 1);
        });
    });
});

// The courses grid follows its container width, not the viewport.
test.describe("student courses", () => {
    const courses = SCREENS.find((s) => s.name === "student-courses");
    /**
     * How many columns the courses grid lays out. Read from the grid, not by counting cards in the first row: the
     * seeded student has only two current courses, so a row of cards can never show three or four columns.
     */
    const gridColumns = (page) =>
        page.locator('ul[aria-label="Your courses"]').evaluate((ul) => globalThis.getComputedStyle(ul).gridTemplateColumns.split(" ").length);

    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("one column", async ({ page }) => {
            await open(page, courses, "light");

            expect(await gridColumns(page)).toBe(1);
        });

        test("a very long title wraps instead of widening the page at 320", async ({ page }) => {
            await open(page, courses, "light");
            const original = (await page.locator('ul[aria-label="Your courses"] a [id^="course-title-"]').first().innerText()).trim();
            const long = "Supercalifragilisticexpialidocious".repeat(4);

            runSql(`UPDATE courses SET course_title = '${long}' WHERE course_title = '${original.replaceAll("'", "''")}'`);
            try {
                await page.setViewportSize({ width: 320, height: 640 });
                await page.reload();
                await expect(page.getByText(long).first()).toBeVisible();
                await expectNoPageOverflow(page);
            } finally {
                runSql(`UPDATE courses SET course_title = '${original.replaceAll("'", "''")}' WHERE course_title = '${long}'`);
            }
        });
    });

    // The container is the viewport less CourseController's gutter (sm:px-8, xl:px-20, 2xl:px-40 each side) and
    // the panel's p-8: 768 -> 640px (@md, 2 columns); 1024 -> 896px (@3xl, 3 columns); 1366 -> 1142px and
    // 1600 -> 1216px (@5xl, 4 columns).
    for (const [width, height, columns] of [[768, 1024, 2], [1024, 768, 3], [1366, 768, 4], [1600, 900, 4]]) {
        test.describe(`${width} wide`, () => {
            test.use({ viewport: { width, height } });

            test(`${columns} columns`, async ({ page }) => {
                await open(page, courses, "light");

                expect(await gridColumns(page)).toBe(columns);
            });
        });
    }
});

// Quiz taking on a phone (assessments/quiz-answer-form.php). Data: IT101-A "Quiz 1: Variables and Data Types" (30 minutes,
// two attempts, an essay last). Its item waits for the two before it (TC-QUIZ-01 works through that); the lock is not what
// this is about, so it is lifted for the test and put back. Afterwards student5's unfinished attempts at it are deleted
// (an attempt left over from a failed run would otherwise be resumed and change the next one), as quiz-security.spec.js
// cleans up its own (the test's own data, not something the app offers).
test.describe("student quiz", () => {
    const QUIZ = "Quiz 1: Variables and Data Types";
    const box = (locator) => locator.evaluate((el) => el.getBoundingClientRect().toJSON());

    test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

    test("375 the focused last answer stays clear of the sticky submit bar", async ({ page }) => {
        runSql(`UPDATE learning_items SET prerequisite_mode = 'none' WHERE title = '${QUIZ}'`);
        try {
            await signInAs(page, accounts.student, "student");
            await openCourse(page, "student", "IT101", "A");
            await openTab(page, "Assessments");
            await page.locator("[data-assessment-card]").filter({ hasText: QUIZ }).first().click();

            // The assessment's own page first: the score panel sits under the quiz, not beside it.
            const take = page.locator("[data-take-quiz]");
            await expect(take).toBeVisible();
            await expectNoPageOverflow(page);
            await take.click();

            const form = page.locator("#quiz-answer-form");
            await expect(form).toBeVisible();
            await expect(page.locator("[data-quiz-timer] [data-quiz-timer-value]")).not.toHaveText("--:--");
            await expectNoPageOverflow(page);
            await expectTouchTargets(page, "#quiz-answer-form");
            await page.evaluate(() => globalThis.scrollTo(0, 0));
            await expectInViewport(page, "[data-submit-quiz]");

            // An open soft keyboard (interactive-widget=resizes-content) leaves the top ~500px. The worst case is the
            // focused field resting on the bottom edge of what is left; the browser then brings it into view, which
            // scrollIntoView "nearest" stands in for. It honours the field's scroll margin; without one, nothing moves
            // and the pinned bar covers the field.
            await page.setViewportSize({ width: 375, height: 500 });
            const last = form.locator("[data-quiz-text-input]").last();
            await last.focus();
            await last.evaluate((el) => globalThis.scrollBy(0, el.getBoundingClientRect().bottom - globalThis.innerHeight));
            await last.evaluate((el) => el.scrollIntoView({ block: "nearest" }));

            const field = await box(last);
            const bar = await box(form.locator("[data-submit-quiz]").locator("xpath=.."));
            expect(field.bottom <= bar.top || field.top >= bar.bottom, `the field (${Math.round(field.top)}-${Math.round(field.bottom)}) against the bar (${Math.round(bar.top)}-${Math.round(bar.bottom)})`).toBe(true);
            expect(bar.bottom, "the bar is pinned in the window").toBeLessThanOrEqual(501);
            await expectInViewport(page, "[data-submit-quiz]");
            await expectNoPageOverflow(page);

            // Down at the last question the top bar's timer has scrolled away; the pinned bar's copy shows the time
            // left, and it counts down.
            const copy = form.locator("[data-quiz-timer-copy] [data-quiz-timer-value]");
            await expectInViewport(page, "[data-quiz-timer-copy]");
            const shown = await copy.innerText();
            expect(shown).toMatch(/^\d+:\d{2}$/);
            await expect(copy).not.toHaveText(shown, { timeout: 3_000 });
        } finally {
            runSql(
                "DELETE s FROM assessment_submissions s JOIN assessments a ON a.id = s.assessment_id " +
                    "JOIN auth_identities ai ON ai.user_id = s.student_id AND ai.type = 'email_password' " +
                    `WHERE a.title = '${QUIZ}' AND ai.secret = '${accounts.student.email}' AND s.status = 'In Progress'`
            );
            runSql(`UPDATE learning_items SET prerequisite_mode = 'previous' WHERE title = '${QUIZ}'`);
        }
    });
});

// The calendar and the schedule on a phone (pages/calendar, pages/schedule).
test.describe("calendar and schedule", () => {
    const calendar = SCREENS.find((s) => s.name === "student-calendar");
    const schedule = SCREENS.find((s) => s.name === "student-schedule");
    const box = (locator) => locator.evaluate((el) => el.getBoundingClientRect().toJSON());

    test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

    test("375 calendar cells show dots, today opens the day sheet and a past day does nothing", async ({ page }) => {
        await open(page, calendar, "light");

        // The seed puts events in the current month: their cells show dots, never the title chips.
        const withEvents = page.locator("[data-calendar-cell]:has([data-calendar-dot])").first();
        await expect(withEvents.locator("[data-calendar-dots]")).toBeVisible();
        await expect(withEvents.locator("[data-calendar-chips]")).toBeHidden();

        const today = await page.locator("#calendar-app").getAttribute("data-today");
        const modal = page.locator("#calendar-modal");

        await page.locator(`[data-calendar-cell="${today}"]`).tap();
        await expect(modal).toBeVisible();
        await expect(modal.locator("[data-calendar-action]").first()).toBeVisible();
        await expectInViewport(page, "[data-calendar-modal-panel]");
        // A bottom sheet: full width, resting on the bottom edge.
        const panel = await box(modal.locator("[data-calendar-modal-panel]"));
        expect(Math.round(panel.width)).toBe(375);
        expect(Math.round(panel.bottom)).toBe(812);
        await expectTouchTargets(page, "#calendar-modal");

        // Escape closes the sheet and gives focus back to the tapped day.
        await expect(modal.locator("[data-calendar-modal-close]")).toBeFocused();
        await page.keyboard.press("Escape");
        await expect(modal).toBeHidden();
        await expect(page.locator(`[data-calendar-cell="${today}"]`)).toBeFocused();

        await expectNoPageOverflow(page);
    });

    // CalendarDemoSeeder dates its announcements and deadlines from today, so the days just before today carry events.
    test("375 a past day with events opens its Day view listing them, with nothing to add", async ({ page }) => {
        await open(page, calendar, "light");

        const past = page.locator("[data-calendar-cell][data-calendar-past]:has([data-calendar-dot])").last();
        await expect(past).toHaveCount(1);
        const date = await past.getAttribute("data-calendar-cell");
        const shown = await past.locator("[data-calendar-dot]").count();

        await past.tap();

        await expect(page.locator('[data-calendar-view="day"]')).toHaveClass(/bg-brand/);
        await expect(page.locator("[data-calendar-event]").first()).toBeVisible();
        expect(await page.locator("[data-calendar-event]").count()).toBeGreaterThanOrEqual(shown);
        await expect(page.locator("[data-calendar-add]")).toHaveCount(0);
        await expect(page.locator("#calendar-modal")).toBeHidden();
        const [y, , d] = date.split("-").map(Number);
        await expect(page.locator("[data-calendar-label]")).toContainText(`${d}, ${y}`);
    });

    test("375 schedule grid scrolls in its box with the hours pinned", async ({ page }) => {
        await open(page, schedule, "light");

        const scroller = page.locator("[data-scroll-x][data-sticky-first]");
        await expect(scroller).toBeVisible();

        // It opens on today's column (the week runs Monday to Sunday, so today is always in it).
        const todayColumn = page.locator('[data-scroll-x] [aria-current="date"]');
        await expect(todayColumn).toHaveCount(1);
        const column = await box(todayColumn);
        const frame = await box(scroller);
        expect(column.left >= frame.left + 79 && column.right <= frame.right + 1, "today's column is in view").toBe(true);

        await scroller.evaluate((el) => {
            el.scrollLeft = 0;
            el.dispatchEvent(new Event("scroll"));
        });
        await expect(scroller).toHaveAttribute("data-fade-end", "");

        const rail = scroller.locator(".grid [data-sticky-col]").last();
        const before = await box(rail);
        await scroller.evaluate((el) => {
            el.scrollLeft = 200;
            el.dispatchEvent(new Event("scroll"));
        });
        await expect(scroller).toHaveAttribute("data-fade-start", "");
        expect(await scroller.evaluate((el) => el.scrollLeft)).toBeGreaterThan(0);
        expect(Math.round((await box(rail)).left)).toBe(Math.round(before.left));

        await expectNoPageOverflow(page);
    });
});

// The student's course rows on a phone: the opened breakdown scrolls inside its own box, never the page.
test.describe("grades breakdown", () => {
    test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

    test("the breakdown grid scrolls inside its box at 375", async ({ page }) => {
        await open(page, SCREENS.find((s) => s.name === "student-grades"), "light");

        const toggle = page.locator("[data-grades-row] [data-disclosure]").first();
        const grid = page.locator("[data-grades-row] [data-scroll-x]").first();
        // A tap before the page script attaches does nothing, so it repeats until the breakdown has loaded.
        await expect(async () => {
            if (!(await grid.isVisible())) {
                await toggle.click({ timeout: 2_000 });
            }
            await expect(grid).toBeVisible({ timeout: 3_000 });
        }).toPass({ timeout: 20_000 });

        await expect(grid).toHaveAttribute("data-sticky-first", "");
        await expectNoPageOverflow(page);
    });
});

if (FULL) {
    for (const screen of SCREENS) {
        test(`${screen.name} @zoom200`, async ({ page }, testInfo) => {
            test.skip(testInfo.project.name === "webkit", "WebKit runs the 375px phone only");

            await open(page, screen, "light");
            await zoom200(page);

            await expectNoPageOverflow(page, screen.overflowScope);
        });
    }
}
