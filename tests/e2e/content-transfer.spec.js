// Cases: Copy to and Move to another course, for a module (TC-MOD-16 to 18).
// Data: instructor1 teaches IT101-A (with the module "Programming Fundamentals", which students have worked on) and IT101-B
// (nobody enrolled). The cases copy that module into IT101-B, move the copy back into IT101-A, and remove what they made.
import { expect, test } from "@playwright/test";
import { runSql } from "./support/database.js";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

const MODULE = "Programming Fundamentals";

async function openModules(page, section) {
    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", section);
    await openTab(page, "Modules");
    await expect(page.locator("[data-create-module-button]")).toBeVisible();
}

/** Picks a course in the dialog by its label (selectOption cannot match part of a label). */
async function chooseCourse(modal, label) {
    const select = modal.locator("[data-transfer-offering]");
    await select.selectOption(await select.locator("option", { hasText: label }).first().getAttribute("value"));
}

function card(page, title = MODULE) {
    return page.locator("[data-module-wrapper]").filter({ hasText: title });
}

test("TC-MOD-16 A module is copied to another course, choosing what comes along", async ({ page }) => {
    await openModules(page, "A");

    await card(page).first().locator("[data-module-actions]").click();
    await card(page).first().getByRole("button", { name: "Copy to…" }).click();

    const modal = page.getByRole("dialog", { name: /Copy .*Programming Fundamentals/ });
    await expect(modal).toBeVisible();
    await expect(modal).toContainText("Step 1 of 2");

    // Nothing chosen yet: it asks.
    await modal.getByRole("button", { name: "Next" }).click();
    await expect(modal.getByRole("alert")).toContainText(/Choose/);

    await modal.getByRole("radio", { name: "Another course" }).check();
    await chooseCourse(modal, "IT101 · B");
    await modal.getByRole("button", { name: "Next" }).click();

    // Every unit and item is ticked; untick the last item.
    await expect(modal).toContainText("Step 2 of 2");
    const items = modal.locator("[data-transfer-item]");
    await expect(items.first()).toBeChecked();
    const total = await items.count();
    await items.last().uncheck();

    await modal.getByRole("button", { name: "Copy", exact: true }).click();
    await expect(modal).toBeHidden();
    await expect(page.getByText(/Module copied with \d+ learning units? and \d+ items?/)).toBeVisible();
    expect(total).toBeGreaterThan(1);

    // In IT101-B it is there, as a draft.
    await page.goto("/instructor/courses");
    await openCourse(page, "instructor", "IT101", "B");
    await openTab(page, "Modules");
    await expect(card(page).locator("[data-publish-chip='draft']")).toBeVisible();
});

test("TC-MOD-17 The copy is moved back, after a final confirmation, and a module students worked on cannot move", async ({ page }) => {
    await openModules(page, "B");

    await card(page).first().locator("[data-module-actions]").click();
    await card(page).first().getByRole("button", { name: "Move to…" }).click();

    const modal = page.getByRole("dialog", { name: /Move .*Programming Fundamentals/ });
    await expect(modal).toBeVisible();

    // Only another course is offered for a move.
    await expect(modal.getByRole("radio", { name: "This course" })).toBeHidden();
    await chooseCourse(modal, "IT101 · A");
    await modal.getByRole("button", { name: "Next" }).click();

    await expect(modal).toContainText("Step 2 of 2");
    await expect(modal.locator("[data-transfer-confirm-text]")).toContainText("IT101 · A");
    await expect(modal.locator("[data-transfer-confirm-text]")).toContainText(/draft/);

    await modal.getByRole("button", { name: "Move", exact: true }).click();
    await expect(modal).toBeHidden();
    await expect(page.getByText(/module moved/i)).toBeVisible();
    await expect(card(page)).toHaveCount(0);

    // The original in IT101-A has student work, so it will not move.
    await page.goto("/instructor/courses");
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Modules");
    const original = card(page).filter({ has: page.locator("[data-publish-chip='published'], :not([data-publish-chip])") }).first();
    await original.locator("[data-module-actions]").click();
    await original.getByRole("button", { name: "Move to…" }).click();

    const again = page.getByRole("dialog", { name: /Move/ });
    await chooseCourse(again, "IT101 · B");
    await again.getByRole("button", { name: "Next" }).click();
    await again.getByRole("button", { name: "Move", exact: true }).click();
    await expect(again.getByRole("alert")).toContainText(/worked on|cannot be moved|Copy it instead/);
});

test("TC-MOD-18 Clean up the copies these cases made", async ({ page }) => {
    // Only the drafts: the original is published. A copy's quiz and assignment go first (a module's removal alone would
    // leave them behind, with no module, in the course's gradebook).
    runSql(`DELETE a FROM assessments a JOIN course_modules m ON m.id = a.module_id WHERE m.title = '${MODULE}' AND m.status = 'draft'`);
    runSql(`DELETE m FROM course_modules m WHERE m.title = '${MODULE}' AND m.status = 'draft'`);

    await openModules(page, "A");
    await expect(page.locator("[data-module-wrapper]").filter({ hasText: MODULE }).locator("[data-publish-chip='draft']")).toHaveCount(0);
});
