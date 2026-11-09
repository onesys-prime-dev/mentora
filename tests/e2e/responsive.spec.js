// The responsive sweep: every screen in SCREENS at every window size (support/responsive.js), checked for
// sideways page scroll, touch targets (phone and tablet sizes), axe findings and, on Linux, a screenshot
// baseline. A normal run checks the smoke sizes in light; RESPONSIVE_FULL=1 (the nightly Responsive
// workflow) checks the whole matrix in light and dark, plus 200% zoom. The WebKit project runs the 375px
// phone only, with the iPhone's own touch.
//
// Later phases append to SCREENS. `as` is an account key from support/sign-in.js (null: signed out);
// `scope` limits the touch-target and axe checks to part of the page (the overflow check is always the
// whole page); `dialogs` are the ids of dialogs inside the scope to open and check at the touch sizes.
import { expect, test } from "@playwright/test";
import { expectAccessible, expectNoPageOverflow, expectNoPanelOverflow, expectTouchTargets, setTheme, sizes, snap, zoom200 } from "./support/responsive.js";
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

                    await expectNoPageOverflow(page);
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
                        await openDialog(page.locator(`${screen.scope ?? "body"} [data-dialog-open="${id}"]`), dialog);

                        await expectTouchTargets(page, `#${id}`);
                        await expectNoPageOverflow(page);
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

if (FULL) {
    for (const screen of SCREENS) {
        test(`${screen.name} @zoom200`, async ({ page }, testInfo) => {
            test.skip(testInfo.project.name === "webkit", "WebKit runs the 375px phone only");

            await open(page, screen, "light");
            await zoom200(page);

            await expectNoPageOverflow(page);
        });
    }
}
