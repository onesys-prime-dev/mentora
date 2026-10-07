// Cases: docs/test-cases/p1-critical-journeys.md (TC-ANN-01, TC-ANN-02).
// Data: IT102-A is taught by instructor2 and has student1 to student3; student5
// and instructor1 are not part of it.
import { expect, test } from "@playwright/test";
import { courseAddress, courseCard, openCourse, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

test("TC-ANN-01 Instructor posts an announcement and the section sees it", async ({ browser }) => {
    const instructor = await (await browser.newContext()).newPage();
    await signInAs(instructor, accounts.instructor2, "instructor");
    await openCourse(instructor, "instructor", "IT102", "A");
    await openTab(instructor, "Announcements");

    await instructor.getByRole("button", { name: "Post in course" }).click();

    // The Post button stays off until there is a subject, so a post with an
    // empty subject cannot be sent from the page.
    const post = instructor.getByRole("button", { name: "Post", exact: true });
    await expect(post).toBeDisabled();

    await instructor.getByPlaceholder("Add a subject").fill("Quiz reminder");
    await instructor.locator("#announcement-editor .ProseMirror").fill("Quiz: PHP Setup is due tomorrow.");
    await expect(post).toBeEnabled();
    await post.click();

    const card = (page) => page.locator("[data-announcement-card]").filter({ hasText: "Quiz reminder" });
    await expect(card(instructor)).toBeVisible();

    // A student in the section sees it, with its message.
    const student = await (await browser.newContext()).newPage();
    await signInAs(student, accounts.student3, "student");
    await openCourse(student, "student", "IT102", "A");
    await openTab(student, "Announcements");

    await expect(card(student)).toBeVisible();
    await expect(card(student)).toContainText("Quiz: PHP Setup is due tomorrow.");
});

test("TC-ANN-02 People outside the section cannot post or read", async ({ browser }) => {
    // Find IT102-A's address through someone who teaches it.
    const owner = await (await browser.newContext()).newPage();
    await signInAs(owner, accounts.instructor2, "instructor");
    const instructorAddress = await courseAddress(owner, "instructor", "IT102", "A");
    const studentAddress = instructorAddress.replace("/instructor/", "/student/");

    // 1. A student who is not in IT102-A: it is not listed, and opening it directly is refused.
    const outsider = await (await browser.newContext()).newPage();
    await signInAs(outsider, accounts.student, "student");
    await outsider.goto("/student/courses");
    await expect(courseCard(outsider, "IT102", "A")).toHaveCount(0);
    const refused = await outsider.goto(studentAddress);
    expect(refused.status()).toBe(404);

    // 2. A student who is in it has no way to post.
    const member = await (await browser.newContext()).newPage();
    await signInAs(member, accounts.student3, "student");
    await openCourse(member, "student", "IT102", "A");
    await openTab(member, "Announcements");
    await expect(member.locator("[data-announcements-page]")).toBeVisible();
    await expect(member.getByRole("button", { name: "Post in course" })).toHaveCount(0);

    // 3. An instructor who does not teach it can neither list nor open it.
    const other = await (await browser.newContext()).newPage();
    await signInAs(other, accounts.instructor, "instructor");
    await other.goto("/instructor/courses");
    await expect(courseCard(other, "IT102", "A")).toHaveCount(0);
    const refusedInstructor = await other.goto(instructorAddress);
    expect(refusedInstructor.status()).toBe(404);
});
