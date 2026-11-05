// Cases: "Specific students" in the assessment settings (every assessment), and what a student who is not chosen sees (TC-QSEC-05 to 07).
// Data: IT101-A, taught by instructor1; "Assignment 1: Grade Calculator" is published and given to everyone. These cases give it to
// one student (student1) and not to student5, then put it back for everyone.
import { expect, test } from "@playwright/test";
import { runSql } from "./support/database.js";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

const ASSIGNMENT = "Assignment 1: Grade Calculator";

async function openEditor(page, title) {
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Assessments");

    const card = page.locator("[data-assessment]").filter({ hasText: title }).locator("[data-edit-assessment-button]");
    const draftRow = page.locator("[data-edit-assessment-button]").filter({ hasText: title });
    await card.or(draftRow).first().click();

    await expect(page.locator("#editor-inspector-panel")).toBeVisible();
}

test("TC-QSEC-05 Specific students: the switch shows how many are chosen, the list picks them, and a save with nobody chosen is refused", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await openEditor(page, ASSIGNMENT);

    const panel = page.locator("#editor-inspector-panel");
    await expect(panel.locator("[data-specific-state]")).toBeHidden();

    await panel.locator("[data-specific-toggle]").check({ force: true });
    await expect(panel.getByText("0 students selected")).toBeVisible();

    // On with nobody chosen: refused, with the reason.
    await page.locator("[data-publish]").click();
    const refusal = page.getByText("Choose at least one student");
    await expect(refusal).toBeVisible();
    await page.getByRole("button", { name: "Dismiss" }).last().click({ force: true });
    await expect(refusal).toBeHidden();

    // The list: everyone of the section, Select all, then only one student.
    await panel.getByRole("button", { name: "Choose students" }).click();
    const modal = page.getByRole("dialog", { name: "Choose students" });
    await expect(modal).toBeVisible();
    await expect(modal.locator("[data-assignee-id]").first()).toBeVisible();

    await modal.getByLabel("Select all").check();
    const total = await modal.locator("[data-assignee-id]").count();
    await expect(modal.locator("[data-assignee-id]:checked")).toHaveCount(total);

    await modal.getByLabel("Select all").uncheck();
    await expect(modal.locator("[data-assignee-id]:checked")).toHaveCount(0);

    await modal.locator("[data-assignees-list] li").filter({ hasText: "student1@mentora.test" }).getByRole("checkbox").check();
    await modal.getByRole("button", { name: "Save" }).click();
    await expect(modal).toBeHidden();
    await expect(panel.getByText("1 student selected")).toBeVisible();

    await page.locator("[data-publish]").click();

    // Reopened: still on, still one.
    await openEditor(page, ASSIGNMENT);
    await expect(page.locator("[data-specific-toggle]")).toBeChecked();
    await expect(page.locator("#editor-inspector-panel").getByText("1 student selected")).toBeVisible();
});

test("TC-QSEC-06 A student who is not chosen does not see the assignment; the chosen one does", async ({ page }) => {
    await signInAs(page, accounts.student, "student");
    await openCourse(page, "student", "IT101", "A");
    await openTab(page, "Assessments");
    await expect(page.locator("[data-assessment]").first()).toBeVisible();
    await expect(page.locator("[data-assessment]").filter({ hasText: ASSIGNMENT })).toHaveCount(0);

    await page.context().clearCookies();
    await signInAs(page, accounts.student1, "student");
    await openCourse(page, "student", "IT101", "A");
    await openTab(page, "Assessments");
    // The card (the feedback it was given also names it, once it is graded).
    await expect(page.locator("[data-assessment]").filter({ hasText: ASSIGNMENT }).filter({ hasText: "30 Points" })).toHaveCount(1);
});

test("TC-QSEC-07 Put the assignment back for everyone", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await openEditor(page, ASSIGNMENT);

    await page.locator("[data-specific-toggle]").uncheck({ force: true });
    await page.locator("[data-publish]").click();

    // The chosen list goes too (cleanup of this case's own data; the app keeps it so switching back on remembers who).
    runSql(`DELETE aa FROM assessment_assignees aa JOIN assessments a ON a.id = aa.assessment_id WHERE a.title = '${ASSIGNMENT}'`);

    await openEditor(page, ASSIGNMENT);
    await expect(page.locator("[data-specific-toggle]")).not.toBeChecked();
});
