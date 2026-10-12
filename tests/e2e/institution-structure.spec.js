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

test("TC-TRM-01 Adding an overlapping term goes through with a warning, and starting it warns again", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/terms");
    await expect(page.getByRole("heading", { name: "Terms", level: 1 })).toBeVisible();

    await page.getByRole("button", { name: "Add a term" }).click();

    const dialog = page.getByRole("dialog", { name: "Add a term" });
    await dialog.getByLabel("Name").fill("E2E Summer Term");
    await dialog.getByLabel("Academic year").fill("2026-2027");
    await dialog.getByLabel("Starts").fill("2026-10-01");
    await dialog.getByLabel("Ends").fill("2026-11-30");
    await dialog.getByRole("button", { name: "Add term" }).click();

    // It overlaps the demo semester: allowed, but said.
    await expect(page.getByText("The term was added.")).toBeVisible();
    await expect(page.getByText(/overlaps .*That is allowed/)).toBeVisible();
    await expect(page.locator("[data-overlap]").first()).toBeVisible();

    const row = page.getByRole("row", { name: /E2E Summer Term/ });
    await expect(row).toContainText("Upcoming");

    await row.getByRole("button", { name: "Start term" }).click();

    await expect(page.getByText(/Another term is also active/)).toBeVisible();
    await expect(page.getByRole("row", { name: /E2E Summer Term/ })).toContainText("Active");
});

test("TC-TRM-02 A term that ends before it starts is refused beside the date", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/terms");

    await page.getByRole("button", { name: "Add a term" }).click();

    const dialog = page.getByRole("dialog", { name: "Add a term" });
    await dialog.getByLabel("Name").fill("E2E Backwards Term");
    await dialog.getByLabel("Academic year").fill("2027-2028");
    await dialog.getByLabel("Starts").fill("2027-09-01");
    await dialog.getByLabel("Ends").fill("2027-08-01");
    await dialog.getByRole("button", { name: "Add term" }).click();

    await expect(dialog.getByText("The term must end after it starts.")).toBeVisible();
    await expect(dialog.getByLabel("Starts")).toHaveValue("2027-09-01");
});


test("TC-OFF-01 Adding an offering opens a course in a term and lists it with no instructor yet", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/offerings");
    await expect(page.getByRole("heading", { name: "Offerings", level: 1 })).toBeVisible();

    await page.getByRole("button", { name: "Add an offering" }).first().click();

    const dialog = page.getByRole("dialog", { name: "Add an offering" });
    await dialog.getByLabel("Course").selectOption({ index: 1 });
    await dialog.getByLabel("Term").selectOption({ index: 1 });
    await dialog.getByLabel("Section").fill("e2e-a");
    await dialog.getByLabel("Capacity").fill("30");
    await dialog.getByRole("button", { name: "Add offering" }).click();

    await expect(page.getByText("The offering was added.")).toBeVisible();
    await expect(page.getByRole("row", { name: /E2E-A/ })).toContainText("No instructor");
});

test("TC-OFF-02 The same section twice is refused beside the field, keeping what was typed", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/offerings?q=E2E-A");

    const first = page.getByRole("row", { name: /E2E-A/ });
    await expect(first).toBeVisible();

    await page.getByRole("button", { name: "Add an offering" }).first().click();

    const dialog = page.getByRole("dialog", { name: "Add an offering" });
    await dialog.getByLabel("Course").selectOption({ index: 1 });
    await dialog.getByLabel("Term").selectOption({ index: 1 });
    await dialog.getByLabel("Section").fill("E2E-A");
    await dialog.getByRole("button", { name: "Add offering" }).click();

    // Only refuses when the first course and term chosen are the same pair.
    await expect(dialog.getByText(/already has a section/)).toBeVisible();
    await expect(dialog.getByLabel("Section")).toHaveValue("E2E-A");
});

test("TC-OFF-03 An offering with no students can be archived and brought back", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/offerings?q=E2E-A");

    await page.getByRole("row", { name: /E2E-A/ }).getByRole("button", { name: "Archive" }).click();
    await page.getByRole("dialog").getByRole("button", { name: /Archive/ }).click();

    await expect(page.getByText("The offering was archived.")).toBeVisible();

    await page.getByRole("row", { name: /E2E-A/ }).getByRole("button", { name: "Reactivate" }).click();

    await expect(page.getByText("The offering is active again.")).toBeVisible();
    await expect(page.getByRole("row", { name: /E2E-A/ })).toContainText("Active");
});

test("TC-ENR-01 Students are enrolled by email from the roster, and listed with their place", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/offerings?q=E2E-A");

    await page.getByRole("row", { name: /E2E-A/ }).getByRole("link", { name: /Roster of/ }).click();
    await expect(page.getByRole("heading", { level: 1 })).toContainText("roster");
    await expect(page.getByText("No students enrolled yet")).toBeVisible();

    await page.getByRole("button", { name: "Add students" }).first().click();

    const dialog = page.getByRole("dialog", { name: /Add students/ });
    await dialog.getByLabel("Student numbers or email addresses").fill(`${accounts.student.email}\nnobody@mentora.test`);
    await dialog.getByRole("button", { name: "Enrol students" }).click();

    await expect(page.getByText(/1 student was enrolled/)).toBeVisible();
    await expect(page.getByText(/nobody@mentora.test is not a student number or email/)).toBeVisible();
    await expect(page.getByRole("row", { name: new RegExp(accounts.student.email) })).toContainText("Enrolled");
});

test("TC-ENR-02 A student can be dropped and the row stays with its new status", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/offerings?q=E2E-A");
    await page.getByRole("row", { name: /E2E-A/ }).getByRole("link", { name: /Roster of/ }).click();

    await page.getByRole("row", { name: new RegExp(accounts.student.email) }).getByRole("button", { name: "Change status" }).click();

    const dialog = page.getByRole("dialog", { name: /Change the place of/ });
    await dialog.getByLabel("Status").selectOption("dropped");
    await dialog.getByRole("button", { name: "Save" }).click();

    await expect(page.getByText("The enrolment was changed to dropped.")).toBeVisible();
    await expect(page.getByRole("row", { name: new RegExp(accounts.student.email) })).toContainText("Dropped");
});

test("TC-ENR-03 Going over capacity is refused until the box is ticked", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/offerings?q=E2E-A");

    // Shrink the section to nothing free: capacity 1 with one place taken needs a second student.
    await page.getByRole("row", { name: /E2E-A/ }).getByRole("button", { name: "Edit" }).click();
    const edit = page.locator("#offering-edit");
    await edit.getByLabel("Capacity").fill("1");
    await edit.getByRole("button", { name: "Save" }).click();
    await expect(page.getByText("The offering was saved.")).toBeVisible();

    await page.getByRole("row", { name: /E2E-A/ }).getByRole("link", { name: /Roster of/ }).click();

    // Re-enrol the dropped student (fills the one place), then try a second one.
    await page.getByRole("button", { name: "Add students" }).first().click();
    let dialog = page.getByRole("dialog", { name: /Add students/ });
    await dialog.getByLabel("Student numbers or email addresses").fill(accounts.student.email);
    await dialog.getByRole("button", { name: "Enrol students" }).click();
    await expect(page.getByText(/earlier place was reinstated/)).toBeVisible();

    await page.getByRole("button", { name: "Add students" }).first().click();
    dialog = page.getByRole("dialog", { name: /Add students/ });
    await dialog.getByLabel("Student numbers or email addresses").fill(accounts.student1.email);
    await dialog.getByRole("button", { name: "Enrol students" }).click();

    await expect(dialog.getByText(/over capacity by 1/)).toBeVisible();
    await expect(dialog.getByLabel("Student numbers or email addresses")).toHaveValue(accounts.student1.email);

    await dialog.getByLabel(/Enrol even if the section goes over capacity/).check();
    await dialog.getByRole("button", { name: "Enrol students" }).click();

    await expect(page.getByText(/1 student was enrolled/)).toBeVisible();
    await expect(page.locator("[data-over-capacity]")).toContainText("Over capacity by 1");
});

test("TC-PPL-01 A person is invited from a dialog and waits on the Invitations tab", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/people");
    await expect(page.getByRole("heading", { name: "People", level: 1 })).toBeVisible();

    await page.getByRole("button", { name: "Invite a person" }).click();

    const dialog = page.getByRole("dialog", { name: "Invite a person" });
    await dialog.getByLabel("First name").fill("Pat");
    await dialog.getByLabel("Last name").fill("Invitee");
    await dialog.getByLabel("Email").fill("pat.invitee@mentora.test");
    await dialog.getByLabel("Role").selectOption("instructor");
    await dialog.getByRole("button", { name: "Send invitation" }).click();

    await expect(page.getByText(/An invitation was emailed to pat.invitee@mentora.test/)).toBeVisible();

    await page.getByRole("link", { name: /Invitations/ }).click();
    await expect(page.getByRole("row", { name: /Pat Invitee/ })).toContainText("Waiting");
});

test("TC-PPL-02 An address that already has an account is refused in the dialog and what was typed stays", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/people");

    await page.getByRole("button", { name: "Invite a person" }).click();

    const dialog = page.getByRole("dialog", { name: "Invite a person" });
    await dialog.getByLabel("First name").fill("Quinn");
    await dialog.getByLabel("Last name").fill("Typo");
    await dialog.getByLabel("Email").fill(accounts.student1.email);
    await dialog.getByLabel("Role").selectOption("student");
    await dialog.getByRole("button", { name: "Send invitation" }).click();

    await expect(dialog.getByText(/already exists/)).toBeVisible();
    await expect(dialog.getByLabel("First name")).toHaveValue("Quinn");
});

test("TC-PPL-03 Deactivating asks for the password in a dialog, then switches the account off", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto(`/institution/people?q=${encodeURIComponent(accounts.student4.email)}`);

    const row = page.getByRole("row", { name: new RegExp(accounts.student4.email) });
    await row.getByText("Actions").click();
    await row.getByRole("button", { name: "Deactivate" }).click();
    await page.getByRole("dialog", { name: /^Deactivate / }).getByRole("button", { name: "Deactivate" }).click();

    const password = page.getByRole("dialog", { name: "Confirm your password" });
    await password.getByLabel("Password").fill(accounts.institutionAdmin.password);
    await password.getByRole("button", { name: "Confirm" }).click();

    await expect(page.getByText(/The account was deactivated/)).toBeVisible();
    await expect(page.getByRole("row", { name: new RegExp(accounts.student4.email) })).toContainText("Deactivated");
});

test("TC-PPL-04 The last administrator has no Deactivate action", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto(`/institution/people?q=${encodeURIComponent(accounts.institutionAdmin.email)}`);

    const row = page.getByRole("row", { name: new RegExp(accounts.institutionAdmin.email) });
    await expect(row).toContainText("(you)");
    await row.getByText("Actions").click();
    await expect(row.getByRole("button", { name: "Deactivate" })).toHaveCount(0);
});

test("TC-PAR-01 A parent is linked to a student from the Parents tab and unlinked again", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/people");
    await page.getByRole("link", { name: "Parents" }).click();
    await expect(page.getByRole("heading", { name: "Parents", level: 1 })).toBeVisible();

    const row = page.getByRole("row", { name: /parent1@mentora.test/ });
    await expect(row).toContainText("No student linked");

    await row.getByRole("button", { name: "Link students" }).click();

    const dialog = page.getByRole("dialog", { name: /Link students to/ });
    await dialog.getByLabel("Student numbers or email addresses").fill(`${accounts.student1.email}\nnobody@mentora.test`);
    await dialog.getByRole("button", { name: "Link students" }).click();

    await expect(page.getByText(/1 student was linked/)).toBeVisible();
    await expect(page.getByText(/nobody@mentora.test is not a student number or email/)).toBeVisible();
    await expect(page.getByRole("row", { name: /parent1@mentora.test/ }).locator("[data-linked-student]")).toHaveCount(1);

    await page.getByRole("row", { name: /parent1@mentora.test/ }).getByRole("button", { name: /^Unlink / }).click();
    await page.getByRole("dialog", { name: /^Unlink / }).getByRole("button", { name: "Unlink" }).click();

    await expect(page.getByText("The link was removed.")).toBeVisible();
    await expect(page.getByRole("row", { name: /parent1@mentora.test/ })).toContainText("No student linked");
});
