// Cases: the ⋮ menus of modules, learning units and learning items are never clipped or hidden by the card they sit in (TC-MOD-19).
// Data: instructor1 on IT101-A; "Programming Fundamentals" has several items.
import { expect, test } from "@playwright/test";
import { openCourse, openModule, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

/** The open menu's box, and whether what is drawn at its middle is the menu itself (nothing covers it or cuts it off). */
async function openMenuState(page) {
    const panel = page.locator("[data-action-menu-panel]:not(.hidden)");
    await expect(panel).toHaveCount(1);
    await expect(panel).toBeVisible();

    const box = await panel.boundingBox();
    const viewport = page.viewportSize();
    const onTop = await panel.evaluate((el) => {
        const r = el.getBoundingClientRect();
        const at = el.ownerDocument.elementFromPoint(r.left + r.width / 2, r.top + Math.min(r.height / 2, 20));

        return el.contains(at);
    });

    return { box, viewport, onTop, placement: await panel.getAttribute("data-placement") };
}

test("TC-MOD-19 A menu opens where there is room: under its button, or over it near the bottom, and is never cut off", async ({ page }) => {
    // A tall window first: the first rows have room under them.
    await page.setViewportSize({ width: 1280, height: 1000 });

    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", "A");
    await openModule(page, "Programming Fundamentals");

    const triggers = page.locator("[data-learning-item-actions]");
    const count = await triggers.count();
    expect(count).toBeGreaterThan(1);

    // The first item's menu, near the top of the page: under the button.
    await page.evaluate(() => globalThis.scrollTo(0, 0));
    await triggers.first().click();
    let state = await openMenuState(page);
    expect(state.placement).toBe("below");
    expect(state.box.y).toBeGreaterThanOrEqual(0);
    expect(state.box.y + state.box.height).toBeLessThanOrEqual(state.viewport.height);
    expect(state.onTop).toBe(true);
    await page.keyboard.press("Escape");
    await expect(page.locator("[data-action-menu-panel]:not(.hidden)")).toHaveCount(0);

    // A short window: the last rows are close to the bottom edge.
    await page.setViewportSize({ width: 1280, height: 560 });

    // The last item's menu with the button at the bottom edge of the window: over the button, wholly on screen.
    const last = triggers.last();
    await last.evaluate((el) => el.scrollIntoView({ block: "end" }));
    await last.click();
    state = await openMenuState(page);
    const lastBox = await last.boundingBox();
    expect(state.placement).toBe("above");
    expect(state.box.y + state.box.height).toBeLessThanOrEqual(lastBox.y);
    expect(state.box.y).toBeGreaterThanOrEqual(0);
    expect(state.box.y + state.box.height).toBeLessThanOrEqual(state.viewport.height);
    expect(state.onTop).toBe(true);

    // It goes where it was when it closes, and a unit's menu works the same way.
    await page.keyboard.press("Escape");
    const unitMenu = page.locator("[data-learning-unit-actions]").first();
    await unitMenu.scrollIntoViewIfNeeded();
    await unitMenu.click();
    state = await openMenuState(page);
    expect(state.box.y).toBeGreaterThanOrEqual(0);
    expect(state.box.y + state.box.height).toBeLessThanOrEqual(state.viewport.height);
    expect(state.onTop).toBe(true);
});
