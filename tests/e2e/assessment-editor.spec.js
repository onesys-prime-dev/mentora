// Cases: the assessment editor's settings panel (Total Points).
// Data: IT101-A, taught by instructor1.
//   "Quiz 1: Variables and Data Types"  quiz, questions add up to 20
//   "Quiz 2: Database Basics"           draft quiz, questions 2 + 3 + 2 + 3 = 10
//   "Assignment 1: Grade Calculator"    assignment with a rubric, 12 + 10 + 8 = 30
//   "Lab 1: Designing a Table"          assignment with no rubric, 20 typed-in points
import { expect, test } from "@playwright/test";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

/** Opens an assessment's editor from the Assessments tab (a card, or a row in the drafts list). */
async function openEditor(page, title) {
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Assessments");

    const card = page.locator("[data-assessment]").filter({ hasText: title }).locator("[data-edit-assessment-button]");
    const draftRow = page.locator("[data-edit-assessment-button]").filter({ hasText: title });
    await card.or(draftRow).first().click();

    await expect(page.locator("#editor-inspector-panel")).toBeVisible();
}

const totalPoints = (page) => page.locator("[data-inspector-total-points]");

test("A quiz's total points come from its questions and cannot be typed", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await openEditor(page, "Quiz 1: Variables and Data Types");

    await expect(totalPoints(page)).toHaveValue("20");
    await expect(totalPoints(page)).toBeDisabled();
    await expect(page.locator("[data-inspector-total-points-hint]")).toContainText("quiz builder");
});

test("An assignment with a rubric takes its total points from the rubric and cannot be typed", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await openEditor(page, "Assignment 1: Grade Calculator");

    await expect(totalPoints(page)).toHaveValue("30");
    await expect(totalPoints(page)).toBeDisabled();
    await expect(page.locator("[data-inspector-total-points-hint]")).toContainText("rubric");
});

test("An assignment with no rubric lets the instructor type the total points", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await openEditor(page, "Lab 1: Designing a Table");

    await expect(totalPoints(page)).toHaveValue("20");
    await expect(totalPoints(page)).toBeEnabled();
    await expect(page.locator("[data-inspector-total-points-hint]")).toHaveCount(0);

    await totalPoints(page).fill("25");
    await expect(totalPoints(page)).toHaveValue("25");
});

test("Changing a question's points in the quiz builder changes the quiz's total points", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await openEditor(page, "Quiz 2: Database Basics");
    await expect(totalPoints(page)).toHaveValue("10");

    await page.locator("[data-enter-quiz-builder]").click();
    const first = page.locator("[data-question-points-input]").first();
    await expect(first).toHaveValue("2");

    const saved = page.waitForResponse((r) => /\/questions\/\d+/.test(r.url()) && r.request().method() !== "GET");
    await first.fill("5");
    await first.blur();
    expect((await saved).ok()).toBe(true);

    // Back in the editor the total follows the questions: 5 + 3 + 2 + 3.
    await openEditor(page, "Quiz 2: Database Basics");
    await expect(totalPoints(page)).toHaveValue("13");
    await expect(totalPoints(page)).toBeDisabled();
});
