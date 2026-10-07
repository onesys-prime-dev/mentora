// Cases: docs/test-cases/p1-critical-journeys.md (TC-QUIZ-01 to TC-QUIZ-03).
// TC-QUIZ-03 needs TC-MOD-01 to have run first (it unlocks "Quiz: PHP Setup" for student3);
// files run in alphabetical order, one test at a time, so modules.spec.js comes before this file.
import { expect, test } from "@playwright/test";
import { itemRow, openCourse, openItem, openModule, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

/** Runs an action that changes an answer and waits until the server has saved it. */
async function savingAnswer(page, action) {
    const saved = page.waitForResponse((r) => /\/submissions\/\d+\/questions\/\d+\/answer$/.test(r.url()) && r.request().method() === "POST");
    await action();
    expect((await saved).ok()).toBe(true);
}

test("TC-QUIZ-01 Student takes and submits a quiz", async ({ page }) => {
    // The native "Submit your answers?" confirmation must be accepted (Playwright cancels it otherwise).
    page.on("dialog", (dialog) => dialog.accept());

    await signInAs(page, accounts.student, "student");
    await openCourse(page, "student", "IT101", "A");
    await openModule(page, "Programming Fundamentals");

    // 1. The quiz is locked until the two items before it are completed.
    await expect(itemRow(page, "Quiz 1: Variables and Data Types")).toHaveAttribute("data-locked", "1");

    await itemRow(page, "What Is a Variable?").click();
    await page.locator("[data-mark-complete]").click();
    await expect(page.locator("[data-mark-complete]")).toHaveAttribute("data-completed", "1");
    await page.locator("[data-continue]").click();

    await expect(page.getByText("Data Types Cheat Sheet").first()).toBeVisible();
    await page.locator("[data-mark-complete]").click();
    await expect(page.locator("[data-mark-complete]")).toHaveAttribute("data-completed", "1");
    await page.locator("[data-continue]").click();

    // 2. Before the first submission the quiz cannot be marked completed, and the page says so.
    await expect(page.getByText("0 of 2 attempts used")).toBeVisible();
    await expect(page.locator("[data-mark-complete]")).toBeDisabled();
    await expect(page.getByText("Submit the quiz to complete it.")).toBeVisible();
    await page.getByRole("button", { name: "Take Quiz" }).click();
    await expect(page.locator("#quiz-answer-form")).toBeVisible();

    // 3. Answer: four auto-graded questions correctly (3 + 4 + 3 + 3 = 13 points) and the essay.
    const question = (text) => page.locator("[data-quiz-question]").filter({ hasText: text });
    const choice = (text, answer) => question(text).locator("[data-quiz-choice-row]").filter({ hasText: answer });

    await savingAnswer(page, () => choice("Which of these values is a string?", '"42"').click());
    await savingAnswer(page, () => choice("Which two are valid variable names", "total_score").click());
    await savingAnswer(page, () => choice("Which two are valid variable names", "firstName").click());
    await savingAnswer(page, () => question("What data type holds only true or false?").locator("[data-quiz-text-input]").fill("boolean"));
    await savingAnswer(page, () => question("A named value that cannot change").locator("[data-quiz-text-input]").fill("constant"));
    await savingAnswer(page, () =>
        question("In your own words, explain why choosing the right data type matters").locator("[data-quiz-text-input]").fill(
            "The type decides what you can do with a value, so a wrong type gives wrong results."
        )
    );

    // 4. Submit.
    const submitted = page.waitForResponse((r) => /\/submissions\/\d+\/submit$/.test(r.url()) && r.request().method() === "POST");
    await page.locator("[data-submit-quiz]").click();
    expect((await submitted).ok()).toBe(true);

    // The four auto-graded answers are worth 13 of 20; the essay waits for the instructor.
    await expect(page.getByText("13/20", { exact: true })).toBeVisible();
    await expect(page.getByText("The rest is still being graded.")).toBeVisible();
    await expect(page.getByText("Partially Graded").first()).toBeVisible();
    // A second attempt can be started.
    await expect(page.getByRole("button", { name: "Retake Quiz" })).toBeVisible();

    // 5. Back on the quiz item: one of two attempts used, and handing it in completed the item
    // without pressing anything.
    await openModule(page, "Programming Fundamentals");
    await openItem(page, "Quiz 1: Variables and Data Types");
    await expect(page.getByText("1 of 2 attempts used")).toBeVisible();
    await expect(page.getByText("13/20 so far — the rest is being graded")).toBeVisible();
    await expect(page.locator("[data-mark-complete]")).toHaveAttribute("data-completed", "1");
    await expect(page.locator('[data-completion-badge="completed"]')).toBeVisible();
});

/**
 * Asks the server to start the quiz (the request the "Take Quiz" button makes) and returns the
 * page it answers with, as text, plus whether it holds a live answer form.
 */
async function askToStart(page, quizTitle) {
    await openTab(page, "Assessments");
    const card = page.locator("[data-assessment-card]").filter({ hasText: quizTitle });
    const assessmentId = await card.first().getAttribute("data-assessment");
    const { origin, pathname } = new URL(page.url());
    const [, prefix, offeringId] = pathname.match(/^(.*\/student)\/courses\/(\d+)\//);
    const response = await page.request.get(`${origin}${prefix}/api/courses/${offeringId}/assessments/${assessmentId}/take`);
    const { html } = await response.json();

    return {
        hasAnswerForm: html.includes('id="quiz-answer-form"'),
        text: html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim(),
    };
}

test("TC-QUIZ-02 The attempt limit stops another attempt", async ({ page }) => {
    // Data: IT201-A, "Quiz: Keys and Relationships" allows one attempt; student5 has taken it.
    await signInAs(page, accounts.student, "student");
    await openCourse(page, "student", "IT201", "A");
    await openModule(page, "The Relational Model");
    await openItem(page, "Quiz: Keys and Relationships");

    // The quiz item says why there is no button, and offers no way to start.
    await expect(page.getByText("You've already taken this quiz.")).toBeVisible();
    await expect(page.getByText("1 of 1 attempt used")).toBeVisible();
    await expect(page.getByRole("button", { name: /Take Quiz|Retake Quiz|Resume Quiz/ })).toHaveCount(0);

    // The server refuses too: asking it to start gives no answer form.
    const refused = await askToStart(page, "Quiz: Keys and Relationships");
    expect(refused.hasAnswerForm).toBe(false);
    expect(refused.text).toContain("No attempts remaining");

    // And no second attempt was recorded.
    await openModule(page, "The Relational Model");
    await openItem(page, "Quiz: Keys and Relationships");
    await expect(page.getByText("1 of 1 attempt used")).toBeVisible();
});

test("TC-QUIZ-03 A past-due quiz is closed", async ({ page }) => {
    // Data: IT102-A, "Quiz: PHP Setup" was due the day before the seed and does not allow late
    // attempts. It is open to student3 because TC-MOD-01 completed the reading before it.
    await signInAs(page, accounts.student3, "student");
    await openCourse(page, "student", "IT102", "A");
    await openModule(page, "Setting Up PHP");
    const quiz = itemRow(page, "Quiz: PHP Setup");
    await expect(quiz, "run TC-MOD-01 first: it unlocks this quiz").not.toHaveAttribute("data-locked", "1");
    await quiz.click();

    // The quiz item says it is closed, and offers no way to start.
    await expect(page.getByText("The due date has passed and late attempts aren't allowed.")).toBeVisible();
    await expect(page.getByText("0 of 1 attempt used")).toBeVisible();
    await expect(page.getByRole("button", { name: /Take Quiz|Retake Quiz|Resume Quiz/ })).toHaveCount(0);

    // The server refuses too.
    const refused = await askToStart(page, "Quiz: PHP Setup");
    expect(refused.hasAnswerForm).toBe(false);
    expect(refused.text).toContain("The due date has passed");
});
