// Cases: docs/test-cases/p2-global-grades.md (TC-GGR-07 to TC-GGR-09).
// Data: parent1 follows student1 (Juan) and student5 (Michael); both are in IT101-A, which has a published scheme.
// The cases leave the data as they found it: TC-GGR-09 switches the parent portal off as the institution's
// administrator and puts the switch back (On removes the institution's own setting, as it was).
import { expect, test } from "@playwright/test";
import { signInAs } from "./support/course.js";
import { accounts, submitLoginForm } from "./support/sign-in.js";

const row = (page, code) => page.locator("[data-grades-row]").filter({ hasText: code });

async function parentOnGrades(browser) {
    const page = await (await browser.newContext()).newPage();
    await signInAs(page, accounts.parent, "parent");
    return page;
}

test("TC-GGR-07 Parent opens Grades from the Apps menu, sees the child's courses and expands one", async ({ browser }) => {
    const page = await parentOnGrades(browser);

    await page.getByRole("button", { name: /apps/i }).first().click();
    await page.getByRole("link", { name: /^Grades$/ }).first().click();
    await expect(page).toHaveURL(/\/parent\/children\/\d+\/grades$/);

    await expect(page.getByText("Your child's grades in every course.")).toBeVisible();
    await expect(page.locator("[data-term-line]")).toContainText("1st Semester");
    await expect(page.locator("[data-grades-summary]")).toBeVisible();

    const it101 = row(page, "IT101-A");
    await expect(it101).toBeVisible();

    // Expand: the breakdown loads in place, with the course's periods or categories and no items.
    const toggle = it101.locator("[data-disclosure]");
    await page.waitForLoadState("networkidle"); // the page script binds the rows once it has loaded
    await toggle.click();
    await expect(toggle).toHaveAttribute("aria-expanded", "true");
    const slot = it101.locator("[data-breakdown-slot]");
    await expect(slot.locator("[data-grades-breakdown-root]")).toBeVisible();
    await expect(slot).toContainText(/Passing is \d/);
    await expect(slot.locator("table")).not.toContainText(/quiz 1|assignment 1/i);

    // Open course grades leads to the parent's view of the course.
    await slot.getByRole("link", { name: /Open course grades/ }).click();
    await expect(page).toHaveURL(/\/parent\/children\/\d+\/courses\/\d+\//);
    await expect(page.getByText("View only").first()).toBeVisible();
});

test("TC-GGR-08 Parent with two children switches child", async ({ browser }) => {
    const page = await parentOnGrades(browser);
    await page.goto("/parent/grades");
    await expect(page).toHaveURL(/\/parent\/children\/\d+\/grades$/);

    const first = page.url();
    const switcher = page.locator("[data-child-switcher]");
    await expect(page.getByLabel("Showing")).toBeVisible();
    await expect(switcher.locator("option")).toHaveCount(2);

    await switcher.selectOption({ index: (await switcher.evaluate((s) => s.selectedIndex)) === 0 ? 1 : 0 });
    await expect(page).toHaveURL(/\/parent\/children\/\d+\/grades$/);
    expect(page.url()).not.toBe(first);

    // The other child's page is the same page: summary, term line and the shared IT101-A row.
    await expect(page.locator("[data-term-line]")).toBeVisible();
    await expect(row(page, "IT101-A")).toBeVisible();
});

test("TC-GGR-09 With the parent portal off the parent sees the portal-off page", async ({ browser }) => {
    const admin = await (await browser.newContext()).newPage();
    await submitLoginForm(admin, accounts.institutionAdmin.email, accounts.institutionAdmin.password);

    const setPortal = async (on) => {
        await admin.goto("/institution/settings/parents");
        await admin.locator(`input[name="enabled"][value="${on ? 1 : 0}"]`).check();
        await Promise.all([
            admin.waitForResponse((r) => r.url().includes("/institution/settings/parents") && r.request().method() === "POST"),
            admin.getByRole("button", { name: "Save parent portal" }).click(),
        ]);
    };

    const page = await parentOnGrades(browser);

    await setPortal(false);
    try {
        await page.goto("/parent/grades");
        await expect(page.getByText("The parent portal is switched off")).toBeVisible();
        await expect(page.locator("[data-grades-row]")).toHaveCount(0);
    } finally {
        await setPortal(true);
    }

    await page.goto("/parent/grades");
    await expect(page).toHaveURL(/\/parent\/children\/\d+\/grades$/);
    await expect(page.locator("[data-grades-row]").first()).toBeVisible();
});
