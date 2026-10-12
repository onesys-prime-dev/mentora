// Cases: the institutional administrator's academic structure (IA3).
// Data: the demo institutional administrator of institution 1 (DemoUsersSeeder).
import { expect, test } from "@playwright/test";
import { accounts, submitLoginForm } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

async function openStructure(page) {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await expect(page).toHaveURL(/\/institution\/dashboard$/);

    await page.goto("/institution/structure");
    await expect(page.getByRole("heading", { name: "Structure", level: 1 })).toBeVisible();
}

test("TC-STR-01 The administrator lands on their home and reaches the structure from the Apps menu", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await expect(page).toHaveURL(/\/institution\/dashboard$/);

    await page.getByRole("button", { name: "Apps" }).click();
    await page.getByRole("link", { name: "Academics" }).click();

    await expect(page).toHaveURL(/\/institution\/structure$/);
    await expect(page.locator("[data-department]").first()).toBeVisible();
});

test("TC-STR-02 Adding a department in a dialog shows it on the page with a message", async ({ page }) => {
    await openStructure(page);

    await page.getByRole("button", { name: "Add a department" }).click();

    const dialog = page.getByRole("dialog", { name: "Add a department" });
    await expect(dialog).toBeVisible();

    await dialog.getByLabel("Code").fill("e2e-arts");
    await dialog.getByLabel("Name").fill("College of E2E Arts");
    await dialog.getByRole("button", { name: "Add department" }).click();

    await expect(page.getByText("The department was added.")).toBeVisible();
    await expect(page.locator("[data-department]").filter({ hasText: "College of E2E Arts" })).toBeVisible();
    await expect(page.locator("[data-department]").filter({ hasText: "E2E-ARTS" })).toBeVisible();
});

test("TC-STR-03 A refused form shows its reason beside the field and keeps what was typed", async ({ page }) => {
    await openStructure(page);

    await page.getByRole("button", { name: "Add a department" }).click();

    const dialog = page.getByRole("dialog", { name: "Add a department" });
    await dialog.getByLabel("Code").fill("E2E-ARTS");
    await dialog.getByLabel("Name").fill("Another one entirely");
    await dialog.getByRole("button", { name: "Add department" }).click();

    await expect(dialog.getByText("Another department already uses the code E2E-ARTS.")).toBeVisible();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel("Name")).toHaveValue("Another one entirely");

    // Closing it and opening it again starts clean.
    await dialog.getByRole("button", { name: "Cancel" }).click();
    await expect(dialog).toBeHidden();
    await page.getByRole("button", { name: "Add a department" }).click();
    await expect(page.getByRole("dialog", { name: "Add a department" }).getByLabel("Name")).toHaveValue("");
});

test("TC-STR-04 Editing opens the same dialog with the department's values", async ({ page }) => {
    await openStructure(page);

    const card = page.locator("[data-department]").filter({ hasText: "College of E2E Arts" });
    await card.getByRole("button", { name: "Edit" }).first().click();

    const dialog = page.getByRole("dialog", { name: "Edit department" });
    await expect(dialog.getByLabel("Code")).toHaveValue("E2E-ARTS");
    await expect(dialog.getByLabel("Name")).toHaveValue("College of E2E Arts");

    await dialog.getByLabel("Name").fill("College of E2E Arts and Letters");
    await dialog.getByRole("button", { name: "Save" }).click();

    await expect(page.getByText("The department was saved.")).toBeVisible();
    await expect(page.locator("[data-department]").filter({ hasText: "College of E2E Arts and Letters" })).toBeVisible();
});

test("TC-STR-05 A department with active sub-departments says why it cannot be switched off", async ({ page }) => {
    await openStructure(page);

    // The first demo department has sub-departments under it.
    const card = page.locator("[data-department]").filter({ has: page.locator("[data-sub-department]") }).first();
    await card.getByRole("button", { name: "Deactivate" }).first().click();

    const confirm = page.getByRole("dialog", { name: /^Deactivate / });
    await expect(confirm).toContainText("must have no active sub-departments");
    await confirm.getByRole("button", { name: "Deactivate" }).click();

    await expect(page.getByRole("alert").filter({ hasText: "cannot be switched off yet" })).toBeVisible();
});

test("TC-STR-06 An empty new department can be switched off and on again", async ({ page }) => {
    await openStructure(page);

    const card = page.locator("[data-department]").filter({ hasText: "College of E2E Arts and Letters" });
    await card.getByRole("button", { name: "Deactivate" }).click();
    await page.getByRole("dialog", { name: /^Deactivate / }).getByRole("button", { name: "Deactivate" }).click();

    await expect(page.getByText("The department was deactivated.")).toBeVisible();
    await expect(card.getByText("Inactive")).toBeVisible();

    await card.getByRole("button", { name: "Reactivate" }).click();

    await expect(page.getByText("The department is active again.")).toBeVisible();
    await expect(card.getByText("Active", { exact: true })).toBeVisible();
});

test("TC-CRS-01 Adding a course files it under its program and shows it in the catalog", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/courses");
    await expect(page.getByRole("heading", { name: "Courses", level: 1 })).toBeVisible();

    await page.getByRole("button", { name: "Add a course" }).click();

    const dialog = page.getByRole("dialog", { name: "Add a course" });
    await dialog.getByLabel("Program").selectOption({ index: 1 });
    await dialog.getByLabel("Code").fill("e2e 101");
    await dialog.getByLabel("Title").fill("Introduction to Browser Testing");
    await dialog.getByLabel("Units").fill("3");
    await dialog.getByRole("button", { name: "Add course" }).click();

    await expect(page.getByText("The course was added.")).toBeVisible();
    await expect(page.getByRole("row", { name: /E2E 101/ })).toContainText("Introduction to Browser Testing");
});

test("TC-CRS-02 A duplicate code shows its reason in the dialog, and search finds the course", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/courses");

    await page.getByRole("button", { name: "Add a course" }).click();

    const dialog = page.getByRole("dialog", { name: "Add a course" });
    await dialog.getByLabel("Program").selectOption({ index: 1 });
    await dialog.getByLabel("Code").fill("E2E 101");
    await dialog.getByLabel("Title").fill("A second course");
    await dialog.getByLabel("Units").fill("3");
    await dialog.getByRole("button", { name: "Add course" }).click();

    await expect(dialog.getByText("already uses the code E2E 101")).toBeVisible();
    await dialog.getByRole("button", { name: "Cancel" }).click();

    await page.getByLabel("Search").fill("Browser Testing");
    await page.getByRole("button", { name: "Apply" }).click();

    await expect(page.getByText("1 course match")).toBeVisible();
    await expect(page.getByRole("row", { name: /E2E 101/ })).toBeVisible();
});

test("TC-CRS-03 A chairperson is assigned to a sub-department and removed again", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/structure");

    // The first sub-department that has a program (the demo data has a chairperson who is not yet assigned there).
    const sub = page.locator("[data-sub-department]").first();
    await sub.getByRole("button", { name: "Assign chairperson" }).click();

    const dialog = page.getByRole("dialog", { name: /^Assign a chairperson to / });
    await dialog.getByLabel("Chairperson").selectOption({ index: 1 });
    await dialog.getByRole("button", { name: "Assign" }).click();

    await expect(page.getByText("The chairperson was assigned.")).toBeVisible();
    await expect(page.locator("[data-chair]").first()).toBeVisible();

    await page.locator("[data-chair]").first().getByRole("button", { name: /^Remove / }).click();
    await page.getByRole("dialog", { name: /as chairperson\?$/ }).getByRole("button", { name: "Remove" }).click();

    await expect(page.getByText("The chairperson was removed from the sub-department.")).toBeVisible();
});
