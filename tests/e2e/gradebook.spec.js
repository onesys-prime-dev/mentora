// Cases: docs/test-cases/p2-gradebook.md (TC-GBK-01 to TC-GBK-05).
// Data: IT101-A, taught by instructor1; the grading scheme is published. student1 (Dela Cruz, Juan)
// has a final grade of 90% so far; Lab 1 is handed in and not graded yet, so the grades cannot be
// finalized (that is what TC-GBK-04 relies on).
import { expect, test } from "@playwright/test";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

/** An instructor on IT101-A's Grades tab. */
async function instructorOnGrades(browser) {
    const page = await (await browser.newContext()).newPage();
    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Grades");
    await expect(page.locator("[data-gradebook-toolbar]")).toBeVisible();
    return page;
}

async function openGradebook(page) {
    await page.locator("[data-open-gradebook-roster]").click();
    await expect(page.locator("[data-gradebook-roster]")).toBeVisible();
}

const visibleRows = (page) => page.locator("[data-roster-row]:not(.hidden)");

test("TC-GBK-01 Instructor searches, filters and sorts the gradebook", async ({ browser }) => {
    const page = await instructorOnGrades(browser);
    await openGradebook(page);

    // The class at a glance, and every student listed.
    await expect(page.locator("[data-roster-stats]")).toContainText(/class average/i);
    const everyone = await page.locator("[data-roster-row]").count();
    expect(everyone).toBeGreaterThan(1);
    await expect(page.locator("[data-roster-count]")).toHaveText(`${everyone} students`);

    // Search by name narrows the list, and says so.
    await page.locator("[data-roster-search]").fill("Dela Cruz");
    await expect(visibleRows(page)).toHaveCount(1);
    await expect(page.locator("[data-roster-count]")).toHaveText(`Showing 1 of ${everyone}`);

    // A search with no match says that, instead of an empty table.
    await page.locator("[data-roster-search]").fill("zzzz-nobody");
    await expect(visibleRows(page)).toHaveCount(0);
    await expect(page.locator("[data-roster-no-match]")).toBeVisible();

    await page.locator("[data-roster-search]").fill("");
    await expect(visibleRows(page)).toHaveCount(everyone);

    // "Needs a look" shows only students who are at risk or failed.
    await page.locator("[data-roster-filter]").selectOption("attention");
    for (const row of await visibleRows(page).all()) {
        expect(["At risk", "Failed"]).toContain(await row.getAttribute("data-tag"));
    }
    await page.locator("[data-roster-filter]").selectOption("all");

    // Highest grade first; a student with no grade yet comes last.
    await page.locator("[data-roster-sort]").selectOption("grade-desc");
    const grades = [];
    for (const row of await visibleRows(page).all()) {
        const grade = await row.getAttribute("data-grade");
        grades.push(grade === "" ? null : Number(grade));
    }
    const graded = grades.filter((g) => g !== null);
    expect(graded).toEqual([...graded].sort((a, b) => b - a));
    expect(grades.slice(graded.length).every((g) => g === null)).toBe(true);
});

test("TC-GBK-02 Instructor marks a grade Incomplete and takes it off", async ({ browser }) => {
    const page = await instructorOnGrades(browser);
    await openGradebook(page);

    const row = () => page.locator("[data-roster-row]").filter({ hasText: "Dela Cruz, Juan" });

    await row().locator("[data-open-incomplete]").click();
    await expect(page.locator("#grade-incomplete-modal")).toBeVisible();

    // A reason is required.
    await page.locator("#grade-incomplete-form [data-incomplete-reason]").fill("");
    await page.locator("[data-incomplete-submit]").click();
    await expect(page.locator("[data-incomplete-error]")).toContainText(/reason/i);

    await page.locator("#grade-incomplete-form [data-incomplete-reason]").fill("Medical leave: final project to follow");
    await page.locator("#grade-incomplete-form [data-incomplete-until]").fill("2026-12-20");
    await page.locator("[data-incomplete-submit]").click();

    // The grid loads again: the grade is untouched and the standing is INC, with the finish-by date.
    await expect(row()).toHaveAttribute("data-tag", "INC");
    await expect(row()).toContainText("Finish by Dec 20, 2026");
    await expect(row()).toContainText("90%");

    // The student sees it too.
    const student = await (await browser.newContext()).newPage();
    await signInAs(student, accounts.student1, "student");
    await openCourse(student, "student", "IT101", "A");
    await openTab(student, "Grades");
    await expect(student.getByRole("tabpanel")).toContainText("Incomplete (INC)");
    await expect(student.getByRole("tabpanel")).toContainText("Medical leave");

    // Take it off again.
    await row().locator("[data-open-incomplete]").click();
    await page.locator("[data-incomplete-remove]").click();
    await expect(row()).not.toHaveAttribute("data-tag", "INC");
});

test("TC-GBK-03 Instructor previews, applies and removes a curve", async ({ browser }) => {
    const page = await instructorOnGrades(browser);

    const openCurve = async () => {
        await page.locator("[data-open-curve]").click();
        await expect(page.locator("#grade-curve-modal")).toBeVisible();
    };

    await openCurve();
    await page.locator("[data-curve-target]").selectOption("final");
    await page.locator("[data-curve-method]").selectOption("add_points");
    await page.locator('[data-curve-field="points"]').fill("2");
    await page.locator("[data-curve-preview]").click();

    // The preview says what would change; nothing is saved yet.
    const result = page.locator("[data-curve-result]");
    await expect(result).toBeVisible();
    await expect(result).toContainText(/go up/);
    await expect(result).toContainText(/class average/i);
    await expect(page.locator("[data-curve-apply]")).toBeVisible();

    // Changing the form makes the preview stale: it cannot be applied as it was.
    await page.locator('[data-curve-field="points"]').fill("3");
    await expect(page.locator("[data-curve-apply]")).toBeHidden();
    await page.locator('[data-curve-field="points"]').fill("2");
    await page.locator("[data-curve-preview]").click();

    await page.locator("[data-curve-apply]").click();
    await expect(page.locator("[data-gradebook-toolbar]")).toBeVisible();

    // student1's 90% is now 92%, and says it was adjusted.
    const student = await (await browser.newContext()).newPage();
    await signInAs(student, accounts.student1, "student");
    await openCourse(student, "student", "IT101", "A");
    await openTab(student, "Grades");
    await expect(student.getByRole("tabpanel")).toContainText("92%");
    await expect(student.getByRole("tabpanel")).toContainText(/adjusted by your instructor/i);

    // Remove it: everything is back as it was.
    await openCurve();
    await expect(page.locator("[data-curve-live]")).toContainText("Add 2 percentage points");
    await page.locator("[data-curve-undo]").click();
    await expect(page.locator("[data-gradebook-toolbar]")).toBeVisible();

    await student.reload();
    await openTab(student, "Grades");
    await expect(student.getByRole("tabpanel")).toContainText("90%");
    await expect(student.getByRole("tabpanel")).not.toContainText(/adjusted by your instructor/i);
});

test("TC-GBK-04 Grades cannot be finalized while work waits to be graded", async ({ browser }) => {
    const page = await instructorOnGrades(browser);

    await page.locator("[data-open-finalize]").click();
    const dialog = page.locator("#grade-finalize-modal");
    await expect(dialog).toBeVisible();

    // The checklist says what stands in the way, and there is no Finalize button.
    await expect(dialog).toContainText(/before you can finalize/i);
    await expect(dialog).toContainText(/waiting to be graded/i);
    await expect(page.locator("[data-finalize-confirm]")).toBeHidden();

    await page.locator("[data-close-grade-finalize-modal]").first().click();
    await expect(dialog).toBeHidden();

    // Nothing was locked: a score can still be changed and the page does not say the grades are final.
    await expect(page.locator("[data-finalized-banner]")).toHaveCount(0);
});

test("TC-GBK-05 Instructor downloads the class gradebook and the class summary", async ({ browser }) => {
    const page = await instructorOnGrades(browser);

    await page.locator("[data-toggle-report-menu]").click();

    // The class gradebook as a CSV file.
    const download = page.waitForEvent("download");
    await page.locator('[data-report-path="class"][data-report-format="csv"]').click();
    const file = await download;
    expect(file.suggestedFilename()).toMatch(/^gradebook-IT101-A-\d{8}\.csv$/);
    const path = await file.path();
    const csv = (await import("node:fs")).readFileSync(path, "utf8");
    expect(csv).toContain("Student number");
    expect(csv).toContain("Dela Cruz");

    // The class summary as a print view in its own tab.
    await page.locator("[data-toggle-report-menu]").click();
    const popup = page.waitForEvent("popup");
    await page.locator('[data-report-path="summary"][data-report-mode="print"]').click();
    const view = await popup;
    await expect(view.getByRole("heading", { name: "Class summary" })).toBeVisible();
    await expect(view.getByText("Dela Cruz")).toBeVisible();
    await expect(view.getByRole("button", { name: /print or save as pdf/i })).toBeVisible();
});
