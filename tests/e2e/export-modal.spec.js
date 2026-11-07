// Cases: the quiz builder's Export modal (TC-QUIZ-31).
// Data: IT101-A, taught by instructor1: "Quiz 1: Variables and Data Types", a quiz with questions (read only: nothing is saved).
import { expect, test } from "@playwright/test";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

/** Opens the quiz's builder from the Assessments tab. */
async function openBuilder(page) {
    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Assessments");

    const title = "Quiz 1: Variables and Data Types";
    const card = page.locator("[data-assessment]").filter({ hasText: title }).locator("[data-edit-assessment-button]");
    const draftRow = page.locator("[data-edit-assessment-button]").filter({ hasText: title });
    await card.or(draftRow).first().click();
    await page.locator("[data-enter-quiz-builder]").click();
    await expect(page.locator("[data-open-export-questions]")).toBeVisible();
}

test("TC-QUIZ-31 The export icon opens a modal with two choices, each with only the formats we can write", async ({ page }) => {
    await openBuilder(page);

    // It is a modal now, not a dropdown panel.
    await expect(page.locator("[data-export-options-panel]")).toHaveCount(0);
    await page.locator("[data-open-export-questions]").click();

    const dialog = page.getByRole("dialog", { name: "Export" });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel(/Editable file/)).toBeVisible();
    await expect(dialog.getByLabel(/Printable test/)).toBeChecked();

    // Printable test: PDF, Word, Rich Text; and under them Versions, Paper and the three checkboxes.
    const printable = dialog.locator('[data-export-formats="printable"]');
    await expect(printable.getByRole("radio")).toHaveCount(3);
    await expect(printable.getByLabel("PDF (.pdf)")).toBeChecked();
    await expect(printable.getByLabel("Versions")).toBeVisible();
    await expect(printable.getByLabel("Paper")).toBeVisible();
    await expect(printable.getByLabel("Shuffle the order of questions")).toBeVisible();
    await expect(printable.getByLabel("Shuffle the choices")).toBeVisible();
    await expect(printable.getByLabel("Answer key only")).toBeVisible();

    const formatBox = await printable.getByLabel("Rich Text (.rtf)").boundingBox();
    const optionsBox = await printable.getByLabel("Versions").boundingBox();
    expect(optionsBox.y).toBeGreaterThan(formatBox.y);

    // Editable file: its own formats, and no print options.
    await dialog.getByLabel(/Editable file/).check();
    const editable = dialog.locator('[data-export-formats="editable"]');
    await expect(editable.getByRole("radio")).toHaveCount(6);
    await expect(editable.getByLabel("Word document (.docx)")).toBeChecked();
    await expect(dialog.locator("[data-print-options]")).toBeHidden();
    await expect(printable).toBeHidden();

    // Nothing the server cannot write.
    await expect(dialog).not.toContainText(/QTI|Blackboard|D2L|Sakai|\.tst|\.html/);

    // Escape closes it.
    await page.keyboard.press("Escape");
    await expect(dialog).toBeHidden();
});

test("TC-QUIZ-31 Exporting downloads the chosen file, and the modal opens on the last choice next time", async ({ page }) => {
    await openBuilder(page);
    await page.locator("[data-open-export-questions]").click();

    const dialog = page.getByRole("dialog", { name: "Export" });

    // An editable Word file.
    await dialog.getByLabel(/Editable file/).check();
    const [word] = await Promise.all([page.waitForEvent("download"), dialog.getByRole("button", { name: "Export" }).click()]);
    expect(word.suggestedFilename()).toMatch(/\.docx$/);
    await expect(dialog).toBeHidden();

    // A printable PDF with two versions on Legal paper.
    await page.locator("[data-open-export-questions]").click();
    await dialog.getByLabel(/Printable test/).check();
    await dialog.getByLabel("Versions").selectOption("2");
    await dialog.getByLabel("Paper").selectOption("Legal");
    await dialog.getByLabel("Answer key only").check();

    const [pdf] = await Promise.all([page.waitForEvent("download"), dialog.getByRole("button", { name: "Export" }).click()]);
    expect(pdf.suggestedFilename()).toMatch(/\.pdf$/);

    // Reloading the builder: the modal is back on that last choice.
    await page.reload();
    await page.locator("[data-open-export-questions]").click();
    await expect(dialog.getByLabel(/Printable test/)).toBeChecked();
    await expect(dialog.getByLabel("Versions")).toHaveValue("2");
    await expect(dialog.getByLabel("Paper")).toHaveValue("Legal");
    await expect(dialog.getByLabel("Answer key only")).toBeChecked();
});
