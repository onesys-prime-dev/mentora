// Cases: the student's "Group activity" card (group activities, phase 4, screens 3A, 3C, 3E): an assignment that is
// not a group activity has no card; with self-signup open a student sees the open groups and the warning, joins
// one, switches and leaves (asked first); a frozen group shows the frozen notice; the card fits 360 px and axe
// finds nothing serious.
// Data: IT101-A, taught by instructor1; student1 is enrolled. "Lab 1: Designing a Table" is made a group activity
// by the instructor in the first case (start from scratch, two groups); the SQL below then opens self-signup,
// which the editor does not expose until the formation-mode control exists. The last case switches it off again.
// NOT RUN: written without a run. The end-to-end database on :3306 was broken when this was written, so none of
// these cases has been executed; expect selector fixes on the first real run.
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { runSql } from "./support/database.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

const LAB = "Lab 1: Designing a Table";
const QUIZ = "Quiz 1: Variables and Data Types";

const card = (page) => page.locator("[data-student-group]");

async function openAssignment(page, title) {
    await signInAs(page, accounts.student1, "student");
    await openCourse(page, "student", "IT101", "A");
    await openTab(page, "Assessments");
    await page.locator("[data-assessment]").filter({ hasText: title }).first().click();
}

test("TC-GAS-01 An assignment that is not a group activity has no Group activity card", async ({ page }) => {
    await openAssignment(page, QUIZ);
    await expect(card(page)).toHaveCount(0);
});

test("TC-GAS-02 Set up: the instructor makes the lab a group activity with two groups", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Assessments");
    await page.locator("[data-assessment]").filter({ hasText: LAB }).locator("[data-edit-assessment-button]").first().click();

    const section = page.locator("[data-group-activity]");
    await section.getByRole("switch", { name: "Group activity" }).check({ force: true });
    const attach = page.getByRole("dialog", { name: "Make this a group activity" });
    await attach.getByLabel("Group set").selectOption({ label: "Start from scratch" });
    await attach.getByRole("button", { name: "Make it a group activity" }).click();
    await expect(attach).toBeHidden();

    for (const name of ["Team 1", "Team 2"]) {
        await section.getByRole("button", { name: "Add group" }).click();
        const dialog = page.getByRole("dialog", { name: "Add group" });
        await dialog.getByLabel("Group name").fill(name);
        await dialog.getByRole("button", { name: "Save" }).click();
        await expect(section.locator("[data-group-card]").filter({ hasText: name })).toBeVisible();
    }

    runSql("UPDATE activity_group_configs SET formation_mode = 'self_signup', signup_opens_at = NULL, signup_closes_at = NULL WHERE assessment_id = (SELECT id FROM assessments WHERE title = 'Lab 1: Designing a Table' LIMIT 1)");
});

test("TC-GAS-03 Not in a group: the warning and the open groups; Join puts the student in Team 1", async ({ page }) => {
    await openAssignment(page, LAB);

    await expect(card(page).getByText("You can't submit until you join one.")).toBeVisible();
    await expect(card(page).locator("[data-sg-candidate]")).toHaveCount(2);

    await card(page).locator("[data-sg-candidate]").filter({ hasText: "Team 1" }).getByRole("button", { name: "Join" }).click();

    await expect(card(page).getByText("Your group: Team 1")).toBeVisible();
    await expect(page.locator("[data-sg-live]")).toHaveText("You joined Team 1.");
});

test("TC-GAS-04 Switch moves to Team 2, and Leave asks first, stating what happens", async ({ page }) => {
    await openAssignment(page, LAB);

    await card(page).getByRole("button", { name: "Switch group" }).click();
    await card(page).getByRole("button", { name: "Switch here" }).click();
    await expect(card(page).getByText("Your group: Team 2")).toBeVisible();

    await card(page).getByRole("button", { name: "Leave group" }).click();
    const confirm = page.getByRole("dialog", { name: "Leave this group?" });
    await expect(confirm).toContainText("You will leave Team 2.");
    await confirm.getByRole("button", { name: "Leave group" }).click();

    await expect(card(page).getByText("You're not in a group yet.")).toBeVisible();
});

test("TC-GAS-05 A frozen group shows the frozen notice and no Leave", async ({ page }) => {
    await openAssignment(page, LAB);
    await card(page).locator("[data-sg-candidate]").first().getByRole("button", { name: "Join" }).click();
    await expect(card(page).getByRole("button", { name: "Leave group" })).toBeVisible();

    runSql("UPDATE student_groups SET frozen_at = NOW() WHERE id IN (SELECT group_id FROM student_group_members m JOIN users u ON u.id = m.student_user_id JOIN auth_identities i ON i.user_id = u.id WHERE i.secret = 'student1@mentora.test')");
    await page.reload();

    await expect(card(page).getByText("Your group has submitted. Membership is frozen.")).toBeVisible();
    await expect(card(page).getByRole("button", { name: "Leave group" })).toHaveCount(0);

    runSql("UPDATE student_groups SET frozen_at = NULL");
});

test("TC-GAS-06 The card fits a 360 px phone with full-width buttons, and axe finds nothing serious", async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await openAssignment(page, LAB);
    await expect(card(page)).toBeVisible();

    const overflow = await page.evaluate(() => globalThis.document.documentElement.scrollWidth - globalThis.document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    const box = await card(page).getByRole("button").first().boundingBox();
    expect(box.width).toBeGreaterThan(200);

    const results = await new AxeBuilder({ page }).include("[data-student-group]").withTags(["wcag2a", "wcag2aa"]).analyze();
    expect(results.violations.filter((v) => v.impact === "serious" || v.impact === "critical").map((v) => v.id)).toEqual([]);
});
