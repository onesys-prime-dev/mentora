// Cases: docs/test-cases/p1-critical-journeys.md (TC-GRD-01 to TC-GRD-03).
// Data: IT101-A, taught by instructor1.
//   TC-GRD-01: "Assignment 1: Grade Calculator" (30 points, rubric 12 + 10 + 8); student3 handed it
//              in late and it is not graded yet.
//   TC-GRD-02: "Quiz 1: Variables and Data Types" (20 points); student2's attempt is Partially
//              Graded: 9 of the 13 auto-graded points, and the 7-point essay waits.
import { expect, test } from "@playwright/test";
import { openCourse, openItem, openModule, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

/** Opens an item's "View Submissions" (the Grade Work screen) and selects one student. */
async function openGradeWork(page, itemTitle, studentName) {
    await openCourse(page, "instructor", "IT101", "A");
    await openModule(page, "Programming Fundamentals");
    await openItem(page, itemTitle);
    await page.getByRole("button", { name: "View Submissions" }).click();
    await page.locator(`[data-grade-work-student][data-student-name="${studentName}"]`).click();
}

const studentRow = (page, name) => page.locator(`[data-grade-work-student][data-student-name="${name}"]`);

test("TC-GRD-01 Instructor grades an assignment with a rubric", async ({ browser }) => {
    const instructor = await (await browser.newContext()).newPage();
    await signInAs(instructor, accounts.instructor, "instructor");
    await openGradeWork(instructor, "Assignment 1: Grade Calculator", "Maria Santos");
    await expect(studentRow(instructor, "Maria Santos")).toContainText("Submitted");

    // Open it for grading and switch to the rubric: three criteria.
    await instructor.locator("[data-expand-grade-work-actions]").click();
    await instructor.locator("[data-with-rubric-grade-work-toggle]").click();
    const points = instructor.locator("[data-grade-work-rubric-criteria-points]");
    await expect(points).toHaveCount(3);

    // A score above a criterion's maximum is held at the maximum (Correctness is worth 12).
    await points.nth(0).fill("13");
    await points.nth(0).blur();
    await expect(points.nth(0)).toHaveValue("12");

    // 10 + 8 + 6 = 24 of 30, with a comment.
    await points.nth(0).fill("10");
    await points.nth(1).fill("8");
    await points.nth(2).fill("6");
    await points.nth(2).blur();
    await expect(instructor.locator("[data-grade-work-score-preview]").first()).toHaveText("24/30");
    await instructor.locator("[data-grade-work-feedback]").fill("Good logic. Add more test cases next time.");

    const graded = instructor.waitForResponse((r) => /\/submissions\/\d+\/grade$/.test(r.url()) && r.request().method() === "PATCH");
    await instructor.locator("[data-grade-work-submit-grade]").click();
    expect((await graded).ok()).toBe(true);
    await expect(studentRow(instructor, "Maria Santos")).toContainText("Graded");
    await expect(studentRow(instructor, "Maria Santos")).toContainText("24/30");

    // The student sees the grade and the comment.
    const student = await (await browser.newContext()).newPage();
    await signInAs(student, accounts.student3, "student");
    await openCourse(student, "student", "IT101", "A");
    await openModule(student, "Programming Fundamentals");
    await openItem(student, "Assignment 1: Grade Calculator");
    await expect(student.getByText("Graded", { exact: true }).first()).toBeVisible();
    await expect(student.getByText("24/30", { exact: true }).first()).toBeVisible();
    await expect(student.getByText("Good logic. Add more test cases next time.")).toBeVisible();
});

test("TC-GRD-02 Instructor grades a quiz's essay", async ({ browser }) => {
    const instructor = await (await browser.newContext()).newPage();
    await signInAs(instructor, accounts.instructor, "instructor");
    await openGradeWork(instructor, "Quiz 1: Variables and Data Types", "Pedro Reyes");
    await expect(studentRow(instructor, "Pedro Reyes")).toContainText("Partially Graded");

    // Open it for grading: the essay's score box becomes editable straight away.
    await instructor.locator("[data-expand-grade-work-actions]").click();
    const essay = instructor.locator("[data-grade-work-question]").filter({ hasText: "In your own words" });
    const essayPoints = essay.locator("[data-question-points-input]");
    await expect(essayPoints).toBeEnabled();
    const total = instructor.locator("[data-grade-work-quiz-total]").first();
    await expect(total).toHaveText("9"); // 9 of the 13 auto-graded points so far

    // 8 is more than the essay is worth: refused, and the total does not move.
    const refusal = instructor.waitForEvent("dialog");
    await essayPoints.fill("8");
    await essayPoints.blur();
    const dialog = await refusal;
    expect(dialog.message()).toBe("Points must be between 0 and 7.");
    await dialog.accept();
    await expect(total).toHaveText("9");

    // 5 is accepted and the total follows: 9 + 5 = 14.
    await essayPoints.fill("5");
    await essayPoints.blur();
    await expect(total).toHaveText("14");

    const graded = instructor.waitForResponse((r) => /\/submissions\/\d+\/grade$/.test(r.url()) && r.request().method() === "PATCH");
    await instructor.locator("[data-grade-work-submit-grade]").click();
    expect((await graded).ok()).toBe(true);
    await expect(studentRow(instructor, "Pedro Reyes")).toContainText("Graded");
    await expect(studentRow(instructor, "Pedro Reyes")).toContainText("14/20");

    // The student now sees the full score.
    const student = await (await browser.newContext()).newPage();
    await signInAs(student, accounts.student2, "student");
    await openCourse(student, "student", "IT101", "A");
    await openModule(student, "Programming Fundamentals");
    await openItem(student, "Quiz 1: Variables and Data Types");
    await expect(student.getByText("Best score: 14/20")).toBeVisible();
});

test("TC-GRD-03 Student sees their grades", async ({ browser }) => {
    // Data: IT101-A, student1: all work graded except Lab 1 (handed in, waiting); the grading
    // scheme is published; "Quiz 2: Database Basics" is a draft.
    const student = await (await browser.newContext()).newPage();
    await signInAs(student, accounts.student1, "student");
    await openCourse(student, "student", "IT101", "A");
    await openTab(student, "Grades");
    const grades = student.getByRole("tabpanel");

    // The overall grade and its breakdown by category.
    await expect(grades).toContainText(/overall grade/i);
    await expect(grades).toContainText("90.00%");
    await expect(grades).toContainText("63/70");
    await expect(grades).toContainText(/on track:\s+passed/i);
    await expect(grades).toContainText("Quizzes");
    await expect(grades).toContainText("40%");
    await expect(grades).toContainText("27 of 30 pts");

    // Each assessment with its own score, and Lab 1 still waiting.
    await expect(grades).toContainText(/Course Pre-Test[\s\S]*?8\/10/);
    await expect(grades).toContainText(/Quiz 1: Variables and Data Types[\s\S]*?19\/20/);
    await expect(grades).toContainText(/Assignment 1: Grade Calculator[\s\S]*?27\/30/);
    await expect(grades).toContainText(/Lab 1: Designing a Table[\s\S]*?Waiting for grade/);

    // The draft quiz is not shown.
    await expect(grades).not.toContainText("Quiz 2: Database Basics");

    // The instructor's gradebook shows the same final grade for the same student.
    const shown = Number.parseFloat((await grades.innerText()).match(/(\d+(?:\.\d+)?)%/)[1]);
    const instructor = await (await browser.newContext()).newPage();
    await signInAs(instructor, accounts.instructor, "instructor");
    await openCourse(instructor, "instructor", "IT101", "A");
    await openTab(instructor, "Grades");
    const { origin, pathname } = new URL(instructor.url());
    const [, prefix, offeringId] = pathname.match(/^(.*\/instructor)\/courses\/(\d+)\//);
    const { html } = await (await instructor.request.get(`${origin}${prefix}/api/courses/${offeringId}/grades/roster`)).json();
    const row = html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").match(/Dela Cruz, Juan 2026-02000 (.*?) (Passed|Failed|Incomplete)/);
    expect(row, "student1 is in the instructor's gradebook").not.toBeNull();
    const final = Number.parseFloat(row[1].trim().split(" ").at(-1));
    expect(final).toBe(shown);
    expect(final).toBe(90);
});
