// Cases: docs/test-cases/p2-global-grades.md (TC-GGR-01 to TC-GGR-03).
// Data: instructor1 teaches IT101-A (5 students, work waiting to be graded, a Participation item graded by
// hand) and IT101-B (nobody enrolled, so it is ready to finalize). The cases leave the data as they found
// it: the score is put back and the finalized section is reopened.
import { expect, test } from "@playwright/test";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { runSql } from "./support/database.js";
import { accounts } from "./support/sign-in.js";

/** An instructor on the Grades page. */
async function instructorOnGrades(browser) {
    const page = await (await browser.newContext()).newPage();
    await signInAs(page, accounts.instructor, "instructor");
    await page.goto("/instructor/grades");
    await expect(page.getByRole("heading", { name: "Grades", exact: true })).toBeVisible();
    return page;
}

const card = (page, label) => page.locator(`[data-section-card][data-label="${label}"]`);
const gridReady = (page) => expect(page.locator("[data-grades-grid] [data-gradebook-roster]")).toBeVisible();

test("TC-GGR-01 Instructor opens Grades from the Apps menu, switches sections and keeps a typed score", async ({ browser }) => {
    const page = await (await browser.newContext()).newPage();
    await signInAs(page, accounts.instructor, "instructor");

    // The Grades tile in the Apps menu leads to the page.
    await page.getByRole("button", { name: /apps/i }).first().click();
    await page.locator("[data-app-tile='grades']").first().click();
    await expect(page).toHaveURL(/\/instructor\/grades$/);

    // The busiest section (most work waiting) is selected and its grid is shown.
    await expect(card(page, "IT101-A")).toHaveAttribute("aria-current", "true");
    await gridReady(page);

    // A graded Participation score: change it, and switch sections before the page has had time to save it.
    const first = page.locator("[data-roster-row]").filter({ has: page.locator("[data-manual-score-input]") }).first();
    const field = first.locator("[data-manual-score-input]").first();
    const original = {
        value: await field.inputValue(),
        studentId: await first.getAttribute("data-student-id"),
        itemId: await field.getAttribute("data-item-id"),
    };
    expect(original.value).toMatch(/^\d/);

    const score = page.locator(
        `[data-roster-row][data-student-id="${original.studentId}"] [data-manual-score-input][data-item-id="${original.itemId}"]`,
    );
    const typed = original.value === "1" ? "2" : "1";

    await score.fill(typed);
    await card(page, "IT101-B").click();

    // The other section is shown at once, and the card follows the choice.
    await expect(card(page, "IT101-B")).toHaveAttribute("aria-current", "true");
    await expect(page.locator("[data-section-actions] [data-action-label]")).toHaveText("IT101-B");

    // Reload: the score was saved, not lost.
    await page.goto("/instructor/grades");
    await gridReady(page);
    await expect(score).toHaveValue(typed);

    // Put it back.
    await score.fill(original.value);
    await score.blur();
    await expect(page.locator("[data-roster-save-message]")).toHaveText(/saved/i);
    await page.reload();
    await gridReady(page);
    await expect(score).toHaveValue(original.value);
});

test("TC-GGR-02 Grade work opened from To grade leads back to the same queue", async ({ browser }) => {
    const page = await instructorOnGrades(browser);

    await page.locator("[data-grades-tab='to-grade']").click();
    await expect(page.locator("[data-to-grade-row]").first()).toBeVisible();

    // Filter to quizzes; the queue narrows.
    await page.locator("#grades-type").selectOption({ label: "Quiz" });
    await expect(page).toHaveURL(/type=quiz/);
    const rows = page.locator("[data-to-grade-row]");
    await expect(rows).toHaveCount(1);

    // Grade →, then pick another student in the same piece of work: the way back is kept.
    await rows.first().locator("[data-grade-link]").click();
    await expect(page).toHaveURL(/grade=1/);
    await expect(page).toHaveURL(/return=/);
    const students = page.locator("[data-grade-work-student]");
    await expect(students.first()).toBeVisible();

    if ((await students.count()) > 1) {
        await students.nth(1).click();
        await expect(page).toHaveURL(/student=/);
        await expect(page).toHaveURL(/return=/);
    }

    const back = page.locator("[data-back-to-assessments]");
    await expect(back).toHaveText("← Back to To grade");
    await back.click();

    // On the queue, with the same filter.
    await expect(page).toHaveURL(/\/instructor\/grades\?.*tab=to-grade/);
    await expect(page).toHaveURL(/type=quiz/);
    await expect(page.locator("#grades-type option:checked")).toHaveText("Quiz");
    await expect(page.locator("[data-to-grade-row]")).toHaveCount(1);
});

test("TC-GGR-03 Finalize ready sections finalizes the ready one and names the skipped one", async ({ browser }) => {
    const page = await instructorOnGrades(browser);
    const offeringA = Number(await card(page, "IT101-A").getAttribute("data-section-card"));
    const dialog = page.locator("#finalize-ready-dialog");

    // IT101-B is ready. Make IT101-A ready too (its students leave, so no work waits). Once the page is open,
    // IT101-A is closed (completed), so it is still offered by the page but is refused and named when the
    // instructor confirms. (The server reads the cards again at that point, so a section whose weights break
    // after loading simply stops being ready and is left out; a closed section is the case that is named.)
    expect(runSql(`UPDATE enrollments SET status = 'dropped' WHERE offering_id = ${offeringA} AND status = 'enrolled'`)).toContain("ok (5 row(s))");

    try {
        await page.goto("/instructor/grades");
        await expect(page.locator("[data-ready-count]")).toHaveText("2");
        expect(runSql(`UPDATE course_offerings SET status = 'completed' WHERE id = ${offeringA} AND status = 'active'`)).toContain("ok (1 row(s))");
        await page.locator("[data-finalize-ready]").click();
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText("IT101-A");
        await expect(dialog).toContainText("IT101-B");

        await dialog.getByRole("button", { name: "Finalize", exact: true }).click();

        // One finalized, the other named with its reason.
        const result = dialog.locator("[data-dialog-text]");
        await expect(result).toContainText("Finalized 1 section.");
        await expect(result).toContainText("Skipped 1: IT101-A");
        await expect(result).toContainText("This section is closed");
    } finally {
        // Put IT101-A back as it was.
        expect(runSql(`UPDATE course_offerings SET status = 'active' WHERE id = ${offeringA} AND status = 'completed'`)).toContain("ok (1 row(s))");
        expect(runSql(`UPDATE enrollments SET status = 'enrolled' WHERE offering_id = ${offeringA} AND status = 'dropped'`)).toContain("ok (5 row(s))");
    }

    await dialog.getByRole("button", { name: "Done" }).click();
    await expect(card(page, "IT101-B").locator("[data-card-state]")).toHaveText("Finalized");
    await expect(page.locator("[data-ready-count]")).toHaveCount(0);

    // Leave the data as found: reopen IT101-B with a reason.
    await openCourse(page, "instructor", "IT101", "B");
    await openTab(page, "Grades");
    await page.locator("[data-open-reopen]").click();
    await page.locator("[data-finalize-reason]").fill("End-to-end test: putting the section back as it was.");
    await page.locator("[data-finalize-confirm]").click();
    await expect(page.locator("[data-open-finalize]")).toBeVisible();
});
