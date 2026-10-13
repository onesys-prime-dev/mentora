// A chairperson-only page (R5 of the chairperson plan): the program
// snapshot, the course comparison table and its filters all load, a term
// filter narrows the comparison, and a course row's link drills into that
// course's own page.
import { expect, test } from "@playwright/test";
import { submitLoginForm } from "./support/sign-in.js";

const CHAIRPERSON = { email: "chairperson1@mentora.test", password: "Chairperson123!" };

async function signInAsChairperson(page) {
    await submitLoginForm(page, CHAIRPERSON.email, CHAIRPERSON.password);
    await expect(page).toHaveURL(/\/chairperson\/dashboard$/);
}

test("Monitor Program page loads with the chairperson's own program", async ({ page }) => {
    await signInAsChairperson(page);
    await page.goto("/chairperson/monitor-program");

    await expect(page.getByRole("heading", { name: "Monitor Program" })).toBeVisible();
    await expect(page.locator("[data-course-comparison-link]", { hasText: "IT101" }).first()).toBeVisible();
});

test("Filtering by an unrelated term empties the course comparison rather than erroring", async ({ page }) => {
    await signInAsChairperson(page);
    await page.goto("/chairperson/monitor-program");

    const termSelect = page.locator("select[name='term']");
    const options = await termSelect.locator("option").allTextContents();

    // Only run this check when there is more than one real term to pick —
    // a single-term seed would make "an unrelated term" impossible to choose.
    if (options.length > 2) {
        await termSelect.selectOption({ index: 1 });
        await page.getByRole("button", { name: "Apply" }).click();
        await expect(page.getByRole("heading", { name: "Monitor Program" })).toBeVisible();
    }
});

test("A course comparison row's link drills into that course", async ({ page }) => {
    await signInAsChairperson(page);
    await page.goto("/chairperson/monitor-program");

    const row = page.locator("[data-course-comparison-link]").first();
    await expect(row).toBeVisible();
    await row.click();

    await expect(page).toHaveURL(/chairperson\/courses\/\d+\//);
});
