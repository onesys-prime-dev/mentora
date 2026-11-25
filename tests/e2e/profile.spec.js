// The My profile page (docs/test-cases/p4-user-profile.md, TC-PRF-01 to TC-PRF-05), plus review screenshots.
// TC-PRF-02 changes instructor1's preferred name and pronouns and puts them back.
import { expect, test } from "@playwright/test";
import { signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

const accountMenu = (page) => page.getByRole("button", { name: "Account menu" });

test("TC-PRF-01 An instructor opens My Profile from the account menu", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await accountMenu(page).click();
    await page.getByRole("link", { name: "My Profile" }).click();

    await expect(page).toHaveURL(/\/profile$/);
    const band = page.locator("[data-profile-band]");
    await expect(band.getByRole("list", { name: "Roles" })).toContainText("Instructor");
    await expect(page.locator("[data-profile-about]")).toBeVisible();
    await expect(page.locator("[data-profile-office]")).toBeVisible();
});

test("TC-PRF-02 An instructor edits their preferred name and pronouns and sees them in the header band", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await page.goto("/profile");

    const save = async (name, pronouns) => {
        await page.getByRole("button", { name: "Edit name & pronouns" }).click();
        const dialog = page.getByRole("dialog", { name: "Edit name & pronouns" });
        await dialog.getByLabel("Preferred first name").fill(name);
        await dialog.getByLabel("Pronouns", { exact: true }).selectOption(pronouns);
        await dialog.getByRole("button", { name: "Save" }).click();
        await expect(dialog).toBeHidden();
    };

    try {
        await save("Prof", "they/them");
        await expect(page.locator("[data-profile-band] [data-profile-name]")).toContainText("Prof");
        await expect(page.locator("[data-profile-band] [data-profile-name]")).toContainText("they/them");
    } finally {
        await save("", "");
        await expect(page.locator("[data-profile-band] [data-profile-name]")).not.toContainText("they/them");
    }
});

test("TC-PRF-03 A student's profile shows Studies and Official details with the lock", async ({ page }) => {
    await signInAs(page, accounts.student, "student");
    await page.goto("/profile");

    await expect(page.locator("[data-profile-studies]")).toBeVisible();
    const official = page.locator("[data-profile-official]");
    await expect(official).toBeVisible();
    await expect(official).toContainText("Managed by your school");
    // Phase 6 added "Request a correction"; official details themselves have no edit pencil.
    await expect(official.getByRole("button", { name: /^Edit / })).toHaveCount(0);
    await expect(official.getByRole("button", { name: "Request a correction" })).toBeVisible();
});

test("TC-PRF-04 \"As a student sees it\" hides Official details and the edit pencils", async ({ page }) => {
    await signInAs(page, accounts.student, "student");
    await page.goto("/profile");
    await expect(page.getByRole("button", { name: "Edit name & pronouns" })).toBeVisible();

    await page.getByRole("link", { name: "As a student sees it" }).click();
    await expect(page).toHaveURL(/\/profile\?preview=student$/);
    await expect(page.locator("[data-profile-official]")).toHaveCount(0);
    await expect(page.getByRole("button", { name: /^Edit / })).toHaveCount(0);

    await page.getByRole("link", { name: "Me", exact: true }).click();
    await expect(page.locator("[data-profile-official]")).toBeVisible();
});

test("TC-PRF-05 Help & Support in the account menu is disabled", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await accountMenu(page).click();

    const help = page.locator('nav[aria-label="Account"] [aria-disabled="true"]', { hasText: "Help & Support" });
    await expect(help).toBeVisible();
    await expect(help).toContainText("Coming soon");
    await expect(page.getByRole("link", { name: "Help & Support" })).toHaveCount(0);
});

// Review screenshots for a human to look at; not baselines (test-results/ is gitignored).
test.describe("profile screenshots", () => {
    const sizes = [[360, 800], [768, 1024], [1440, 900]];

    for (const [role, account] of [["instructor", accounts.instructor], ["student", accounts.student]]) {
        for (const [width, height] of sizes) {
            test(`${role} ${width}`, async ({ page }) => {
                await page.setViewportSize({ width, height });
                await signInAs(page, account, role);
                await page.goto("/profile");
                await expect(page.locator("[data-profile-band]")).toBeVisible();
                const overflow = await page.evaluate(() => globalThis.document.documentElement.scrollWidth - globalThis.innerWidth);
                expect(overflow).toBeLessThanOrEqual(0);
                await page.screenshot({ path: `test-results/profile-screens/${role}-${width}.png`, fullPage: true });
            });
        }
    }
});
