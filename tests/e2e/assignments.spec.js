// Cases: docs/test-cases/p1-critical-journeys.md (TC-ASG-01, TC-ASG-02).
// TC-ASG-01: IT101-A, unit "Introduction to Databases": reading "What Is a Database?", then
// assignment "Lab 1: Designing a Table" (due the day after the seed; text, link and file allowed;
// one submission). student5 has done neither.
// TC-ASG-02: IT201-A, assignment "ER Diagram Draft" (due the day before the seed; late work
// allowed). student4 has done the items before it but has not handed it in.
import { expect, test } from "@playwright/test";
import { openCourse, openItem, openModule, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

const form = (page) => page.locator("[data-assignment-submission-form]");
const submit = (page) => page.locator("[data-assignment-submit]");
const formError = (page) => page.locator("[data-assignment-submission-error]");

/** The form offers one way of submitting at a time: File Upload, Text Entry or Link. */
const chooseTab = (page, label) => form(page).locator("label").filter({ hasText: label }).click();

test("TC-ASG-01 Student submits an assignment", async ({ page }) => {
    await signInAs(page, accounts.student, "student");
    await openCourse(page, "student", "IT101", "A");
    await openModule(page, "Working with Data");

    // 1. Complete the reading so the lab unlocks.
    await openItem(page, "What Is a Database?");
    await page.locator("[data-mark-complete]").click();
    await expect(page.locator("[data-mark-complete]")).toHaveAttribute("data-completed", "1");
    await page.locator("[data-continue]").click();

    // The lab: nothing handed in yet, so it cannot be marked completed, and says why.
    await expect(form(page)).toBeVisible();
    await expect(page.locator("[data-mark-complete]")).toBeDisabled();
    await expect(page.getByText("Submit your work to complete it.")).toBeVisible();

    // 2. Submitting with nothing entered is refused.
    await submit(page).click();
    await expect(formError(page)).toHaveText("Add your work before submitting.");

    // 3. A link that is not a web address is refused. (The page checks this itself; the server
    // checks it again, which the PHP tests cover: a browser test cannot tell the two apart.)
    await chooseTab(page, "Link");
    await page.getByPlaceholder("https://...").fill("ftp://example.com/table");
    await submit(page).click();
    await expect(formError(page)).toHaveText("Enter a full link starting with http:// or https://.");

    // 4. Text is accepted.
    await page.getByPlaceholder("https://...").fill("");
    await chooseTab(page, "Text Entry");
    await page.getByPlaceholder("Type your response here...").fill("Students(id PK, first_name, last_name, section)");
    await submit(page).click();
    await expect(page.getByText("Submitted", { exact: true }).first()).toBeVisible();
    await expect(page.getByText("Attempt 1 of 1")).toBeVisible();

    // 5. It cannot be handed in again.
    await expect(form(page)).toHaveCount(0);
    await expect(page.getByText("You've already submitted this assignment.")).toBeVisible();

    // And handing it in completed the item, with nothing to press.
    await openCourse(page, "student", "IT101", "A");
    await openModule(page, "Working with Data");
    await openItem(page, "Lab 1: Designing a Table");
    await expect(page.locator("[data-mark-complete]")).toHaveAttribute("data-completed", "1");
});

test("TC-ASG-02 A late submission is accepted and marked late", async ({ page }) => {
    await signInAs(page, accounts.student4, "student");
    await openCourse(page, "student", "IT201", "A");
    await openModule(page, "The Relational Model");
    await openItem(page, "ER Diagram Draft");

    // Past its due date but open to late work.
    await expect(form(page)).toBeVisible();
    await chooseTab(page, "Text Entry");
    await page.getByPlaceholder("Type your response here...").fill("Book(isbn PK), Member(id PK), Loan(book FK, member FK)");
    await submit(page).click();

    await expect(page.getByText("Submitted", { exact: true }).first()).toBeVisible();
    await expect(page.getByText("Late", { exact: true }).first()).toBeVisible();
});
