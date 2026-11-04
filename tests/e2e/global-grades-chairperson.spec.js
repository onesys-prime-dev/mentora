// Cases: docs/test-cases/p2-global-grades.md (TC-GGR-10 to TC-GGR-12).
// Data: chairperson1 chairs the sub-department of IT101 (IT101-A has students, a Participation item graded by hand
// and work waiting to be graded). The cases leave the data as they found it: the score is put back and the
// follow-up the nudge sends is deleted.
import { expect, test } from "@playwright/test";
import { runSql } from "./support/database.js";
import { submitLoginForm } from "./support/sign-in.js";

const CHAIRPERSON = { email: "chairperson1@mentora.test", password: "Chairperson123!" };

const programRow = (page, label) => page.locator(`[data-program-row][data-label="${label}"]`);
const gridReady = (page) => expect(page.locator("[data-grades-grid] [data-gradebook-roster]")).toBeVisible();

async function chairpersonOnProgram(browser) {
    const page = await (await browser.newContext()).newPage();
    await submitLoginForm(page, CHAIRPERSON.email, CHAIRPERSON.password);
    await expect(page).toHaveURL(/\/chairperson\/dashboard$/);
    await page.goto("/chairperson/grades?tab=program");
    await expect(page.getByRole("heading", { name: "Grades", exact: true })).toBeVisible();
    await page.waitForLoadState("networkidle"); // the page script binds the rows once it has loaded
    return page;
}

/** Opens the IT101-A row and waits for its grid. */
async function openIt101A(page) {
    await programRow(page, "IT101-A").click();
    await expect(programRow(page, "IT101-A")).toHaveAttribute("aria-current", "true");
    await gridReady(page);
}

test("TC-GGR-10 Chairperson opens Program, sees the tracker and table, and opens a section read-only", async ({ browser }) => {
    const page = await chairpersonOnProgram(browser);

    await expect(page.getByText("Your sections and your program's.")).toBeVisible();
    await expect(page.locator("[data-tracker-line]")).toContainText(/Finalized \d+ of \d+ · \d+ ready · \d+ blocked/);
    await expect(page.locator("[data-program-table]")).toBeVisible();
    for (const name of ["Section", "Instructor", "Average", "At risk", "Waiting", "Status"]) {
        await expect(page.locator(`[data-program-table] [data-column-label]`, { hasText: name }).first()).toBeVisible();
    }
    await expect(programRow(page, "IT101-A")).toBeVisible();

    await openIt101A(page);

    // Read-only: a label, no score fields, and the chairperson's way in is the button.
    await expect(page.locator("[data-readonly-label]")).toBeVisible();
    await expect(page.locator("[data-grades-grid] [data-manual-score-input]")).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Edit as chairperson" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Open in course →" })).toBeVisible();
    // Finalizing is the instructor's.
    await expect(page.getByRole("button", { name: /finalize/i })).toHaveCount(0);
});

test("TC-GGR-11 Chairperson edits a manual score as chairperson and the change is kept", async ({ browser }) => {
    const page = await chairpersonOnProgram(browser);
    await openIt101A(page);

    await page.getByRole("button", { name: "Edit as chairperson" }).click();
    const confirm = page.locator("[data-edit-confirm]");
    await expect(confirm).toContainText("You're editing IT101-A as chairperson. Every change is recorded in the audit log and the instructor can see it.");
    await confirm.getByRole("button", { name: "Continue" }).click();

    await expect(page.getByRole("button", { name: "Done" })).toBeVisible();
    const input = page.locator("[data-grades-grid] [data-manual-score-input]").first();
    await expect(input).toBeVisible();
    const row = page.locator("[data-roster-row]").filter({ has: page.locator("[data-manual-score-input]") }).first();
    const original = {
        value: await input.inputValue(),
        studentId: await row.getAttribute("data-student-id"),
        itemId: await input.getAttribute("data-item-id"),
    };
    expect(original.value).toMatch(/^\d/);
    const score = (p) =>
        p.locator(`[data-roster-row][data-student-id="${original.studentId}"] [data-manual-score-input][data-item-id="${original.itemId}"]`);
    const typed = original.value === "1" ? "2" : "1";

    try {
        await score(page).fill(typed);
        await page.getByRole("button", { name: "Done" }).click(); // saves what is waiting, then locks the grid
        await expect(page.getByRole("button", { name: "Edit as chairperson" })).toBeVisible();

        await page.reload();
        await page.waitForLoadState("networkidle");
        await openIt101A(page);
        await page.getByRole("button", { name: "Edit as chairperson" }).click();
        await page.locator("[data-edit-confirm]").getByRole("button", { name: "Continue" }).click();
        await expect(score(page)).toHaveValue(typed);
    } finally {
        // Put the score back (through the page, so the audit trail stays honest).
        if ((await score(page).count()) === 1 && (await score(page).inputValue()) !== original.value) {
            await score(page).fill(original.value);
            await page.getByRole("button", { name: "Done" }).click();
            await expect(page.getByRole("button", { name: "Edit as chairperson" })).toBeVisible();
        }
    }

    await page.reload();
    await page.waitForLoadState("networkidle");
    await openIt101A(page);
    await page.getByRole("button", { name: "Edit as chairperson" }).click();
    await page.locator("[data-edit-confirm]").getByRole("button", { name: "Continue" }).click();
    await expect(score(page)).toHaveValue(original.value);
});

test("TC-GGR-12 Chairperson nudges an instructor and the follow-up exists", async ({ browser }) => {
    const page = await chairpersonOnProgram(browser);
    await openIt101A(page);

    const subject = /IT101-A: \d+ submissions waiting to be graded, oldest \d+ days?/;

    try {
        await page.getByRole("button", { name: "Nudge", exact: true }).click();
        const dialog = page.locator("[data-nudge-dialog]");
        await expect(dialog).toBeVisible();
        await expect(dialog.locator("[data-nudge-title]")).toHaveValue(subject);
        await dialog.getByRole("button", { name: "Send" }).click();

        await expect(page.locator("[data-toast], [role='status']").filter({ hasText: /follow-up|sent|nudge/i }).first()).toBeVisible();
        await expect(dialog).toBeHidden();

        await page.goto("/chairperson/follow-ups");
        await page.getByRole("link", { name: "Sent by you" }).click();
        await expect(page.getByText(subject).first()).toBeVisible();
    } finally {
        runSql("DELETE FROM follow_ups WHERE title LIKE 'IT101-A: % submissions waiting to be graded%'");
    }
});
