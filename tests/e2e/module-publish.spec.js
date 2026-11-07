// Cases: Publish / Draft and Schedule publish in the Add module, Add learning unit and Add learning item modals (TC-MOD-10 to 12).
// Data: IT101-A, taught by instructor1. The cases add rows with "E2E" titles and TC-MOD-12 removes them (cleanup of this
// case's own data, not something the app offers there).
import { expect, test } from "@playwright/test";
import { runSql } from "./support/database.js";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

const SCHEDULED = "E2E Scheduled module";
const PUBLISHED = "E2E Published module";
const DRAFT_MODULE = "E2E Draft module";

/** A local date and time two days ahead, as a datetime-local field wants it. */
function inTwoDays() {
    const when = new Date(Date.now() + 2 * 24 * 60 * 60 * 1000);
    const pad = (value) => String(value).padStart(2, "0");

    return `${when.getFullYear()}-${pad(when.getMonth() + 1)}-${pad(when.getDate())}T09:30`;
}

async function openModules(page) {
    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Modules");
    await expect(page.locator("[data-create-module-button]")).toBeVisible();
}

test("TC-MOD-10 A module is added as a draft, a scheduled draft, or published", async ({ page }) => {
    await openModules(page);

    const modal = page.locator("#module-editor-modal");

    // Draft by default; the schedule switch is offered, the picker is not.
    await page.locator("[data-create-module-button]").click();
    await expect(modal.getByRole("radio", { name: "Draft", exact: true })).toBeChecked();
    await expect(modal.getByText("Schedule publish")).toBeVisible();
    await expect(modal.locator("[data-schedule-input]")).toBeHidden();

    // Publish hides the schedule; back to Draft shows it again.
    await modal.getByText("Publish", { exact: true }).click();
    await expect(modal.getByText("Schedule publish")).toBeHidden();
    await modal.getByText("Draft", { exact: true }).click();

    // A schedule needs a time.
    await modal.locator("[name='title']").fill(SCHEDULED);
    await modal.getByText("Schedule publish").click();
    await expect(modal.locator("[data-schedule-input]")).toBeVisible();
    await modal.locator("[data-module-editor-submit]").click();
    await expect(modal).toBeVisible();

    await modal.locator("[data-schedule-input]").fill(inTwoDays());
    await modal.locator("[data-module-editor-submit]").click();
    await expect(modal).toBeHidden();
    await expect(page.locator("[data-module-wrapper]").filter({ hasText: SCHEDULED }).locator("[data-publish-chip='scheduled']")).toContainText("Scheduled ·");

    // A draft with nothing scheduled, and a published one.
    await page.locator("[data-create-module-button]").click();
    await modal.locator("[name='title']").fill(DRAFT_MODULE);
    await modal.locator("[data-module-editor-submit]").click();
    await expect(page.locator("[data-module-wrapper]").filter({ hasText: DRAFT_MODULE }).locator("[data-publish-chip='draft']")).toBeVisible();

    await page.locator("[data-create-module-button]").click();
    await modal.locator("[name='title']").fill(PUBLISHED);
    await modal.getByText("Publish", { exact: true }).click();
    await modal.locator("[data-module-editor-submit]").click();
    await expect(modal).toBeHidden();
    await expect(page.locator("[data-module-wrapper]").filter({ hasText: PUBLISHED }).locator("[data-publish-chip]")).toHaveCount(0);
});

test("TC-MOD-11 A unit and an item added as published under a draft parent say they are hidden", async ({ page }) => {
    await openModules(page);
    // The module is empty, so open it without waiting for items (openModule does).
    const card = page.locator("[data-module-wrapper]").filter({ hasText: DRAFT_MODULE });
    await card.locator("[data-module]").first().click();
    await card.getByRole("button", { name: "Go to module" }).click();
    await expect(page.locator("[data-create-learning-unit-button]")).toBeVisible();

    // A unit published under the draft module.
    await page.locator("[data-create-learning-unit-button]").click();
    const unitModal = page.locator("#learning-unit-editor-modal");
    await unitModal.locator("[name='title']").fill("E2E Unit");
    await unitModal.getByText("Publish", { exact: true }).click();
    await expect(unitModal.locator("[data-hidden-badge]")).toHaveText("Hidden — module is a draft");

    // Back to a draft: no badge.
    await unitModal.getByText("Draft", { exact: true }).click();
    await expect(unitModal.locator("[data-hidden-badge]")).toBeHidden();
    await unitModal.getByText("Publish", { exact: true }).click();
    await unitModal.locator("[data-learning-unit-editor-submit]").click();
    await expect(unitModal).toBeHidden();

    // An item in it: published, the same badge; a quiz cannot be published while it has no question.
    await page.locator("[data-create-learning-item-button]").first().click();
    const itemModal = page.locator("#learning-item-create-modal");
    await itemModal.locator("[name='title']").fill("E2E Reading");
    await itemModal.getByText("Publish", { exact: true }).click();
    await expect(itemModal.locator("[data-hidden-badge]")).toHaveText("Hidden — module is a draft");

    await itemModal.getByText("Quiz", { exact: true }).click();
    await expect(itemModal.locator("[data-publish-lock]")).toContainText("A quiz needs a question");
    await expect(itemModal.getByRole("radio", { name: "Draft", exact: true })).toBeChecked();
    await expect(itemModal.getByRole("radio", { name: "Publish", exact: true })).toBeDisabled();

    await itemModal.getByText("Reading", { exact: true }).click();
    await expect(itemModal.getByRole("radio", { name: "Publish", exact: true })).toBeEnabled();
});

test("TC-MOD-12 Clean up the rows these cases added", async ({ page }) => {
    runSql("DELETE FROM learning_items WHERE title LIKE 'E2E %'");
    runSql("DELETE FROM learning_units WHERE title LIKE 'E2E %'");
    runSql("DELETE FROM course_modules WHERE title LIKE 'E2E %'");

    await openModules(page);
    await expect(page.locator("[data-module-wrapper]").filter({ hasText: "E2E" })).toHaveCount(0);
});
