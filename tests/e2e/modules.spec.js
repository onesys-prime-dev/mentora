// Cases: docs/test-cases/p1-critical-journeys.md (TC-MOD-01, TC-MOD-02).
// Data: IT102-A, module "Setting Up PHP", unit "Your Development Environment":
// PDF "XAMPP Installation Guide", reading "Your First PHP Script", quiz "Quiz: PHP Setup".
// In the seed student3 has completed the PDF and nothing else there.
// TC-MOD-01 completes the reading, which unlocks "Quiz: PHP Setup" for TC-QUIZ-03: it must run first
// (files run in alphabetical order, one test at a time).
import { expect, test } from "@playwright/test";
import { itemRow, openCourse, openModule, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

test("TC-MOD-01 Items unlock in order", async ({ page }) => {
    await signInAs(page, accounts.student3, "student");
    await openCourse(page, "student", "IT102", "A");
    await openModule(page, "Setting Up PHP");

    const script = itemRow(page, "Your First PHP Script");
    const quiz = itemRow(page, "Quiz: PHP Setup");

    // 1. The PDF is done, so the reading is open; the quiz waits for the reading and says so.
    await expect(script).toBeVisible();
    await expect(script).not.toHaveAttribute("data-locked", "1");
    await expect(quiz).toHaveAttribute("data-locked", "1");
    await expect(quiz).toContainText('Complete "Your First PHP Script" first');

    // 2. A locked item cannot be opened from the list ...
    await quiz.click();
    await expect(page.locator("[data-mark-complete]")).toHaveCount(0);

    // ... and opened by its address (the course page with ?item=, as the Overview links do)
    // it says what to do first, with a way to open that item, not the quiz itself.
    const lockedItemId = await quiz.getAttribute("data-learning-item");
    const { origin, pathname } = new URL(page.url());
    await page.goto(`${origin}${pathname}?item=${lockedItemId}`);
    await expect(page.getByText("Complete “Your First PHP Script” to unlock “Quiz: PHP Setup”.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Open “Your First PHP Script”" })).toBeVisible();
    await expect(page.locator("[data-mark-complete]")).toHaveCount(0);

    // The server enforces it, not just the page: the item's own request is refused with the reason.
    const [, prefix, offeringId] = pathname.match(/^(.*\/student)\/courses\/(\d+)\//);
    const refused = await page.request.get(`${origin}${prefix}/api/courses/${offeringId}/modules/items/${lockedItemId}`);
    expect(refused.status()).toBe(403);
    const body = await refused.json();
    expect(body.locked).toBe(true);
    expect(body.message).toBe("Complete “Your First PHP Script” to unlock “Quiz: PHP Setup”.");

    // 3. Complete the reading.
    await openCourse(page, "student", "IT102", "A");
    await openModule(page, "Setting Up PHP");
    await script.click();
    const complete = page.locator("[data-mark-complete]");
    await expect(complete).toBeEnabled();
    await complete.click();
    await expect(complete).toHaveAttribute("data-completed", "1");

    // 4. The quiz is now open: Continue takes you to it, not to the lock.
    await page.locator("[data-continue]").click();
    await expect(page.getByText("Quiz: PHP Setup").first()).toBeVisible();
    await expect(page.getByText("This item is locked")).toHaveCount(0);
});

test("TC-MOD-02 Draft work is hidden from students", async ({ browser }) => {
    // Data: IT101-A, module "Working with Data", unit "Introduction to Databases":
    // reading "What Is a Database?" (published) and the draft quiz "Quiz 2: Database Basics".
    const draft = "Quiz 2: Database Basics";

    // 1. The instructor sees the draft, marked as one, in the module and in Assessments.
    const instructor = await (await browser.newContext()).newPage();
    await signInAs(instructor, accounts.instructor, "instructor");
    await openCourse(instructor, "instructor", "IT101", "A");
    await openModule(instructor, "Working with Data");
    await expect(itemRow(instructor, draft)).toContainText("Draft");

    await openTab(instructor, "Assessments");
    const instructorCard = instructor.locator("[data-assessment-card]").filter({ hasText: draft });
    await expect(instructorCard).toBeVisible();
    await expect(instructorCard).toContainText("Draft");
    const assessmentId = await instructorCard.locator("[data-assessment]").first().getAttribute("data-assessment");

    // 2. A student sees the published work around it, but not the draft.
    const student = await (await browser.newContext()).newPage();
    await signInAs(student, accounts.student, "student");
    await openCourse(student, "student", "IT101", "A");
    await openModule(student, "Working with Data");
    await expect(itemRow(student, "What Is a Database?")).toBeVisible();
    await expect(itemRow(student, draft)).toHaveCount(0);

    await openTab(student, "Assessments");
    await expect(student.locator("[data-assessment-card]").filter({ hasText: "Quiz 1: Variables and Data Types" })).toBeVisible();
    await expect(student.locator("[data-assessment-card]").filter({ hasText: draft })).toHaveCount(0);

    // 3. Opening the draft by its address is refused (not found), not just hidden from the list.
    const { origin, pathname } = new URL(student.url());
    const [, prefix, offeringId] = pathname.match(/^(.*\/student)\/courses\/(\d+)\//);
    const refused = await student.request.get(`${origin}${prefix}/api/courses/${offeringId}/assessments/${assessmentId}/view`);
    expect(refused.status()).toBe(404);
});
