// Cases: Dependencies for modules and learning units, and what a student sees (TC-MOD-13 to 15).
// Data: IT101-A, taught by instructor1, student5 enrolled. The cases add "E2E ..." modules (with one unit and one reading
// each) straight into the database, set the dependencies in the browser, and TC-MOD-15 removes them.
import { expect, test } from "@playwright/test";
import { runSql } from "./support/database.js";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

test("TC-MOD-13 An instructor makes a module wait for the one before it", async ({ page }) => {
    const offering = "(SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE c.course_code = 'IT101' AND o.section_code = 'A' LIMIT 1)";

    runSql(`INSERT INTO course_modules (offering_id, title, order_index, status) VALUES (${offering}, 'E2E First', 90, 'published'), (${offering}, 'E2E Second', 91, 'published')`);

    for (const title of ["E2E First", "E2E Second"]) {
        runSql(`INSERT INTO learning_units (module_id, title, order_index, status) SELECT id, 'E2E Unit of ${title}', 1, 'published' FROM course_modules WHERE title = '${title}'`);
        runSql(`INSERT INTO learning_items (learning_unit_id, title, item_type, order_index, status) SELECT u.id, 'E2E Reading of ${title}', 'reading', 1, 'published' FROM learning_units u WHERE u.title = 'E2E Unit of ${title}'`);
    }

    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Modules");

    const second = page.locator("[data-module-wrapper]").filter({ hasText: "E2E Second" });
    await second.locator("[data-module-actions]").first().click();
    await second.getByRole("button", { name: "Dependencies" }).click();

    const modal = page.getByRole("dialog", { name: "Dependencies" });
    await expect(modal).toBeVisible();
    await expect(modal).toContainText("“E2E Second”");

    // The options load after the dialog opens: Save is enabled once they are in.
    await expect(modal.getByRole("button", { name: "Save" })).toBeEnabled();
    await modal.getByText("Allow dependencies").click();
    await expect(modal.getByRole("radio", { name: /Previous module/ })).toBeChecked();
    await expect(modal.getByText("Unlocks after “")).toBeVisible();
    await modal.getByRole("button", { name: "Save" }).click();
    await expect(modal).toBeHidden();

    await expect(page.locator("[data-module-wrapper]").filter({ hasText: "E2E Second" }).locator("[data-dependency-chip='waits']")).toContainText("After");
});

test("TC-MOD-14 A student sees the module locked, and its items locked with it", async ({ page }) => {
    await signInAs(page, accounts.student, "student");
    await openCourse(page, "student", "IT101", "A");
    await openTab(page, "Modules");

    const second = page.locator("[data-module-wrapper]").filter({ hasText: "E2E Second" });
    await expect(second.locator("[data-dependency-chip='locked']")).toContainText("Locked · after");

    // Open the module: its unit is locked, and so is the item inside it.
    await second.locator("[data-module]").first().click();
    await second.getByRole("button", { name: "Go to module" }).click();
    await expect(page.locator("[data-learning-item-wrapper]").first()).toHaveAttribute("data-locked", "1");
});

test("TC-MOD-15 Clean up the rows these cases added", async ({ page }) => {
    runSql("DELETE i FROM learning_items i JOIN learning_units u ON u.id = i.learning_unit_id WHERE u.title LIKE 'E2E %'");
    runSql("DELETE FROM learning_units WHERE title LIKE 'E2E %'");
    runSql("DELETE FROM course_modules WHERE title LIKE 'E2E %'");

    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Modules");
    await expect(page.locator("[data-module-wrapper]").filter({ hasText: "E2E" })).toHaveCount(0);
});
