// Author avatars open the profile card and the profile (docs/test-cases/p4-user-profile.md, TC-PRF-06 to TC-PRF-09).
// Data: IT101-A has instructor1's demo announcements (CalendarDemoSeeder) and student1 in it.
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

/** Opens IT101-A's Announcements as student1 and returns the first author avatar and the author's name. */
async function authorAvatar(page) {
    await signInAs(page, accounts.student1, "student");
    await openCourse(page, "student", "IT101", "A");
    await openTab(page, "Announcements");

    const avatar = page.locator("[data-announcement-card] [data-person-avatar]").first();
    await expect(avatar).toBeVisible();
    const name = (await avatar.getAttribute("aria-label")).replace(/^Open (.*)'s profile$/, "$1");

    return { avatar, name };
}

const popover = (page) => page.locator("[data-person-popover]");
const dialog = (page) => page.locator("#person-dialog");

test("TC-PRF-06 Hovering an author's avatar shows their profile card", async ({ page }) => {
    const { avatar, name } = await authorAvatar(page);

    await avatar.hover();
    await expect(popover(page)).toBeVisible();
    await expect(popover(page)).toContainText(name);
    await expect(popover(page)).not.toHaveAttribute("aria-busy", "true");

    const results = await new AxeBuilder({ page }).include("[data-person-popover]").include("[data-announcement-card]").withTags(["wcag2a", "wcag2aa"]).analyze();
    expect(results.violations).toEqual([]);
});

test("TC-PRF-07 Clicking an author's avatar opens their profile", async ({ page }) => {
    const { avatar, name } = await authorAvatar(page);

    await avatar.click();
    await expect(dialog(page)).toBeVisible();
    await expect(dialog(page).locator("[data-person-profile]")).toContainText(name);

    const results = await new AxeBuilder({ page }).include("#person-dialog").withTags(["wcag2a", "wcag2aa"]).analyze();
    expect(results.violations).toEqual([]);
});

test("TC-PRF-08 The avatar works from the keyboard", async ({ page }) => {
    const { avatar } = await authorAvatar(page);

    // Tab through the page until the avatar has focus.
    await expect(async () => {
        await page.keyboard.press("Tab");
        await expect(avatar).toBeFocused({ timeout: 100 });
    }).toPass({ timeout: 20_000, intervals: [0] });

    await expect(popover(page)).toBeVisible();

    await page.keyboard.press("Enter");
    await expect(dialog(page)).toBeVisible();
    await expect(dialog(page).locator("[data-person-profile]")).toBeVisible();

    await page.keyboard.press("Escape");
    await expect(dialog(page)).toBeHidden();
    await expect(avatar).toBeFocused();
});

test("TC-PRF-09 On a phone a tap opens the profile", async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 360, height: 800 }, hasTouch: true, isMobile: true });
    const page = await context.newPage();
    const { avatar, name } = await authorAvatar(page);

    await avatar.tap();
    await expect(dialog(page)).toBeVisible();
    await expect(dialog(page).locator("[data-person-profile]")).toContainText(name);
    await expect(popover(page)).toHaveCount(0);

    await context.close();
});
