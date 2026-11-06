// Cases: a quiz's password and randomized questions, set in the assessment settings and met by a student (TC-QSEC-01 to 03).
// Data: IT101-A, taught by instructor1; "Quiz 2: Database Basics" is a draft quiz that opens in the future and waits for the item
// before it (the module's "previous" lock). These cases open it for the student, publish it with a password, and put the lock, the
// opening date and the draft status back at the end.
import { expect, test } from "@playwright/test";
import { runSql } from "./support/database.js";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

const QUIZ = "Quiz 2: Database Basics";
const PASSWORD = "Red-Kite-77";

/** Opens an assessment's editor from the Assessments tab (a card, or a row in the drafts list). */
async function openEditor(page, title) {
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Assessments");

    const card = page.locator("[data-assessment]").filter({ hasText: title }).locator("[data-edit-assessment-button]");
    const draftRow = page.locator("[data-edit-assessment-button]").filter({ hasText: title });
    await card.or(draftRow).first().click();

    await expect(page.locator("#editor-inspector-panel")).toBeVisible();
}

test("TC-QSEC-01 The settings of a quiz offer randomize, a password and additional security; an assignment offers none of them", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await openEditor(page, QUIZ);

    const panel = page.locator("#editor-inspector-panel");
    await expect(panel.getByText("Randomize questions")).toBeVisible();
    await expect(panel.getByText("Require a password")).toBeVisible();
    await expect(panel.locator("[data-additional-security]")).toBeVisible();
    await expect(panel.locator("[data-additional-security]")).not.toHaveAttribute("open", "");
    await expect(panel.locator("[data-password-state]")).toBeHidden();

    // Additional security expands, and says what a web page cannot do.
    await panel.locator("[data-additional-security] summary").click();
    await expect(panel.getByText(/cannot prevent it/)).toBeVisible();
    await expect(panel.getByText(/cannot see which tab or site/)).toBeVisible();

    // An assignment has none of it.
    await openEditor(page, "Assignment 1: Grade Calculator");
    const assignmentPanel = page.locator("#editor-inspector-panel");
    await expect(assignmentPanel.getByText("Randomize questions")).toHaveCount(0);
    await expect(assignmentPanel.getByText("Require a password")).toHaveCount(0);
    await expect(assignmentPanel.locator("[data-additional-security]")).toHaveCount(0);
});

test("TC-QSEC-02 A password is created in a modal, shown as ******, kept after saving, and never in the page", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await openEditor(page, QUIZ);

    const panel = page.locator("#editor-inspector-panel");

    // Switched on with no password yet: a Create password button.
    await panel.locator("[data-password-toggle]").check({ force: true });
    await expect(panel.getByRole("button", { name: "Create password" })).toBeVisible();
    await panel.getByRole("button", { name: "Create password" }).click();

    // The modal opens with a generated password in a plain text field.
    const modal = page.getByRole("dialog", { name: "Quiz password" });
    const field = modal.getByLabel("Password");
    await expect(modal).toBeVisible();
    await expect(field).toHaveAttribute("type", "text");
    await expect(field).toHaveValue(/^[A-HJ-NP-Za-km-z2-9]{10}$/);

    // Cancel changes nothing.
    await modal.getByRole("button", { name: "Cancel" }).click();
    await expect(modal).toBeHidden();
    await expect(panel.getByRole("button", { name: "Create password" })).toBeVisible();

    // The teacher erases it and types their own; Save.
    await panel.getByRole("button", { name: "Create password" }).click();
    await field.fill(PASSWORD);
    await modal.getByRole("button", { name: "Save" }).click();
    await expect(modal).toBeHidden();
    await expect(page.getByText("Password saved.")).toBeVisible();
    await expect(panel.getByText("******")).toBeVisible();
    await expect(panel.getByRole("button", { name: "Edit password" })).toBeVisible();

    // The edit icon opens the same modal.
    await panel.getByRole("button", { name: "Edit password" }).click();
    await expect(modal).toBeVisible();
    await modal.getByRole("button", { name: "Cancel" }).click();

    // Randomize the questions and detect leaving the page, then publish.
    // Open it unless it already is (a click on its summary would close it, and a hidden switch cannot be changed).
    const security = panel.locator("[data-additional-security]");
    if (!(await security.evaluate((el) => el.open))) {
        await security.locator("summary").click();
    }
    await expect(security).toHaveAttribute("open", "");
    await panel.locator('[data-inspector-field="randomize_questions"]').check({ force: true });
    await panel.locator('[data-inspector-field="detect_tab_switch"]').check({ force: true });
    await page.locator("[data-publish]").click();

    // Reopened: everything is as it was left, and the password is nowhere in the page.
    await openEditor(page, QUIZ);
    const again = page.locator("#editor-inspector-panel");
    await expect(again.locator("[data-password-toggle]")).toBeChecked();
    await expect(again.getByText("******")).toBeVisible();
    await expect(again.locator('[data-inspector-field="randomize_questions"]')).toBeChecked();
    await expect(again.locator('[data-inspector-field="detect_tab_switch"]')).toBeChecked();
    await expect(again.locator("[data-additional-security]")).toHaveAttribute("open", "");

    const html = await page.content();
    expect(html).not.toContain(PASSWORD);
    expect(html).not.toContain("$2y$");
});

test("TC-QSEC-03 A student is asked for the password before the quiz starts, and a wrong one starts nothing", async ({ page }) => {
    // The module's own lock (finish Lab 1 first) and the quiz opening in the future are not what this case is about.
    runSql(`UPDATE learning_items SET prerequisite_mode = 'none' WHERE title = '${QUIZ}'`);
    runSql(`UPDATE assessments SET available_from = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE title = '${QUIZ}'`);

    await signInAs(page, accounts.student, "student");
    await openCourse(page, "student", "IT101", "A");
    await openTab(page, "Assessments");

    await page.locator("[data-assessment]").filter({ hasText: QUIZ }).first().click();
    await page.getByRole("button", { name: "Take Quiz" }).click();

    // Asked, not started.
    await expect(page.getByText("This quiz needs a password")).toBeVisible();
    await expect(page.locator("#quiz-answer-form")).toHaveCount(0);

    // A wrong password.
    await page.locator("[data-quiz-password-input]").fill("not-the-password");
    await page.getByRole("button", { name: "Start the quiz" }).click();
    await expect(page.getByText("That password is not right.")).toBeVisible();
    await expect(page.locator("#quiz-answer-form")).toHaveCount(0);

    // The right one (spaces around it do not matter) starts the attempt.
    await page.locator("[data-quiz-password-input]").fill(`  ${PASSWORD} `);
    await page.getByRole("button", { name: "Start the quiz" }).click();
    await expect(page.locator("#quiz-answer-form")).toBeVisible();
    await expect(page.getByText("This quiz needs a password")).toHaveCount(0);
});

test("TC-QSEC-04 Put the quiz back as it was", async ({ page }) => {
    // The student's attempt goes, and the module's lock comes back (cleanup of this case's own data, not something the app offers).
    runSql(`DELETE s FROM assessment_submissions s JOIN assessments a ON a.id = s.assessment_id WHERE a.title = '${QUIZ}'`);
    runSql(`UPDATE learning_items SET prerequisite_mode = 'previous' WHERE title = '${QUIZ}'`);
    runSql(`UPDATE assessments SET available_from = DATE_ADD(NOW(), INTERVAL 5 DAY) WHERE title = '${QUIZ}'`);

    await signInAs(page, accounts.instructor, "instructor");
    await openEditor(page, QUIZ);

    const panel = page.locator("#editor-inspector-panel");
    await panel.locator("[data-password-toggle]").uncheck({ force: true });
    await panel.locator('[data-inspector-field="randomize_questions"]').uncheck({ force: true });
    await panel.locator('[data-inspector-field="detect_tab_switch"]').uncheck({ force: true });
    await page.locator("[data-save-draft]").click();

    await openEditor(page, QUIZ);
    await expect(page.locator("[data-password-toggle]")).not.toBeChecked();
    await expect(page.locator('[data-inspector-field="randomize_questions"]')).not.toBeChecked();
});
