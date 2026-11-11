// The responsive sweep: every screen in SCREENS at every window size (support/responsive.js), checked for
// sideways page scroll, touch targets (phone and tablet sizes), axe findings and, on Linux, a screenshot
// baseline. A normal run checks the smoke sizes in light; RESPONSIVE_FULL=1 (the nightly Responsive
// workflow) checks the whole matrix in light and dark, plus 200% zoom. The WebKit project runs the 375px
// phone only, with the iPhone's own touch.
//
// Later phases append to SCREENS. `as` is an account key from support/sign-in.js (null: signed out);
// `scope` limits the touch-target and axe checks to part of the page; `overflowScope` limits the overflow
// check to one element (the shell screens own the frame, not the page content; without it the whole page is
// checked); `dialogs` are the ids of dialogs inside the scope to open and check at the touch sizes where
// their opener shows (the ☰ menu has none from lg up).
import { expect, test } from "@playwright/test";
import { expectAccessible, expectInViewport, expectNoPageOverflow, expectNoPanelOverflow, expectTouchTargets, setTheme, sizes, snap, zoom200 } from "./support/responsive.js";
import { signInAsAdmin } from "./support/admin.js";
import { accounts, submitLoginForm } from "./support/sign-in.js";

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
];

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

    await page.goto(screen.path);
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
