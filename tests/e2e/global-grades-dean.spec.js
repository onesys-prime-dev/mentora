// Cases: docs/test-cases/p2-global-grades.md (TC-GGR-13 to TC-GGR-15).
// Data: dean2 is dean of CBM, which holds IT101-A and -B (instructor1), IT102-A and IT201-A (instructor2).
// instructor1 teaches IT101-A, which has work waiting to be graded. The cases only read, so the data stays as found.
// The Grades badge is cached for 60 seconds per user; TC-GGR-15 asserts only that it is drawn for a fresh sign-in
// (the first page view computes it), never a changed count.
import { expect, test } from "@playwright/test";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { submitLoginForm } from "./support/sign-in.js";

const DEAN = { email: "dean2@mentora.test", password: "Dean123!" };
const INSTRUCTOR = { email: "instructor1@mentora.test", password: "Instructor123!" };

const programRow = (page, label) => page.locator(`[data-program-row][data-label="${label}"]`);

async function deanSignedIn(browser) {
    const page = await (await browser.newContext()).newPage();
    await submitLoginForm(page, DEAN.email, DEAN.password);
    await expect(page).toHaveURL(/\/dean\/dashboard$/);
    return page;
}

async function openDeanGrades(page) {
    await page.locator("#app-menu-button").click();
    await page.locator("#app-menu-panel").locator("[data-app-tile='grades']").click();
    await expect(page).toHaveURL(/\/dean\/grades/);
    await expect(page.getByRole("heading", { name: "Grades", exact: true })).toBeVisible();
    await page.waitForLoadState("networkidle"); // the page script binds the rows once it has loaded
}

test("TC-GGR-13 Dean opens Grades from the Apps menu, picks a program, and opens a section read-only", async ({ browser }) => {
    const page = await deanSignedIn(browser);
    await openDeanGrades(page);

    await expect(page.getByText("Every section in your college.")).toBeVisible();
    await expect(page.locator("[data-strip-line]")).toContainText(/Finalized \d+ of \d+ · Signed off \d+ · Override requests waiting: \d+/);
    await expect(page.locator("[data-program-table]")).toBeVisible();
    await expect(page.locator("[data-program-table] [data-column-label]", { hasText: "Signed off" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Download college" })).toBeVisible();

    const picker = page.locator("[data-program-select]");
    await expect(picker.locator("option").first()).toHaveText("All programs");
    expect(await picker.locator("option").count()).toBeGreaterThan(1);
    await picker.selectOption({ label: "Bachelor of Science in Information Technology" });
    await expect(page).toHaveURL(/program=\d+/);
    await page.waitForLoadState("networkidle");
    await expect(page.locator("[data-strip-line]")).toContainText(/Finalized \d+ of \d+/);

    await picker.selectOption({ index: 0 });
    await page.waitForLoadState("networkidle");
    await programRow(page, "IT101-A").click();
    await expect(programRow(page, "IT101-A")).toHaveAttribute("aria-current", "true");
    await expect(page.locator("[data-grades-grid] [data-gradebook-roster]")).toBeVisible();
    await expect(page.locator("[data-readonly-label]")).toBeVisible();
    await expect(page.locator("[data-grades-grid] [data-manual-score-input]")).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Edit as chairperson" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: /finalize/i })).toHaveCount(0);
});

test("TC-GGR-14 The strip's Sign-off link opens Sign-off", async ({ browser }) => {
    const page = await deanSignedIn(browser);
    await openDeanGrades(page);

    await page.locator("a[data-strip-signed-off]").click();
    await expect(page).toHaveURL(/\/dean\/signoff/);
});

test("TC-GGR-15 Instructor sees the waiting badge on Grades and 'All my sections' lands on that section", async ({ browser }) => {
    const page = await (await browser.newContext()).newPage();
    await signInAs(page, INSTRUCTOR, "instructor");

    await page.locator("#app-menu-button").click();
    const tile = page.locator("#app-menu-panel [data-app-tile='grades']");
    await expect(tile.locator("[data-app-badge]")).toBeVisible();
    await expect(tile.locator("[data-app-badge]")).toHaveAttribute("aria-label", /waiting to grade/);

    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Grades");
    await page.getByRole("link", { name: "All my sections →" }).click();
    await expect(page).toHaveURL(/\/instructor\/grades\?.*section=\d+/);
    await page.waitForLoadState("networkidle");
    await expect(page.locator("[data-grades-grid] [data-gradebook-roster]")).toBeVisible();
});
