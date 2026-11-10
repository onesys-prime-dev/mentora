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
        dialogs: ["kit-fullscreen", "kit-sheet", "kit-menu", "kit-resp-confirm"],
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
