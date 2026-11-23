// Cases: proposals and the leader (group activities, phase 5, screens 3A, 3B, 3E, 4B): student1 proposes a group and
// invites student2; student2 accepts; the instructor approves it in the proposals queue; both vote student1 as leader;
// the leader renames the group; the card fits 360 px.
// Data: IT101-A, taught by instructor1; student1 and student2 are enrolled. "Lab 1: Designing a Table" must already be
// a group activity (group-activity-student.spec.js TC-GAS-02 makes it one); the SQL below switches it to the
// student-proposed mode with an open window, sizes 2-3, and clears its groups.
// NOT RUN: written without a run. The end-to-end database on :3306 was broken when this was written, so none of
// these cases has been executed; expect selector fixes on the first real run.
import { expect, test } from "@playwright/test";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { runSql } from "./support/database.js";
import { createLabConfig, resetLab } from "./support/group-lab.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

const LAB = "Lab 1: Designing a Table";

const card = (page) => page.locator("[data-student-group]");

async function openAssignment(page, account) {
    await signInAs(page, account, "student");
    await openCourse(page, "student", "IT101", "A");
    await openTab(page, "Assessments");
    await page.locator("[data-assessment]").filter({ hasText: LAB }).first().click();
}

test("TC-GAL-01 Set up: the lab uses student proposals with an open window", async () => {
    resetLab();
    createLabConfig({ formation_mode: "'proposed'", min_size: 2, max_size: 3, nonleader_can_rename: 0 });
});

test("TC-GAL-02 student1 proposes Night Owls and invites student2; the card waits for approval", async ({ page }) => {
    await openAssignment(page, accounts.student1);

    await card(page).getByRole("button", { name: "Propose a new group" }).click();
    const dialog = page.getByRole("dialog", { name: "Propose a new group" });
    await expect(dialog).toContainText("Groups have 2 to 3 members, you included.");
    await dialog.getByLabel("Group name").fill("Night Owls");
    const student2 = String(runSql("SELECT user_id FROM auth_identities WHERE secret = 'student2@mentora.test' LIMIT 1")).match(/d+/)[0];
    await dialog.locator(`input[name=invitee_ids][value="${student2}"]`).check();
    await dialog.getByRole("button", { name: "Send invitations" }).click();

    await expect(card(page).getByText("Waiting for your instructor to approve this group.")).toBeVisible();
});

test("TC-GAL-03 student2 accepts the invitation", async ({ page }) => {
    await openAssignment(page, accounts.student2);

    const invite = card(page).locator("[data-sg-invitation]").filter({ hasText: "Night Owls" });
    await invite.getByRole("button", { name: "Accept" }).click();

    await expect(card(page).getByText("Your group: Night Owls")).toBeVisible();
});

test("TC-GAL-04 The instructor approves Night Owls in the proposals queue", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Assessments");
    await page.locator("[data-assessment]").filter({ hasText: LAB }).locator("[data-edit-assessment-button]").first().click();

    const row = page.locator("[data-ga-proposal]").filter({ hasText: "Night Owls" });
    await expect(row).toContainText("minimum met");
    await row.getByRole("button", { name: "Approve Night Owls" }).click();

    await expect(page.locator("[data-group-card]").filter({ hasText: "Night Owls" })).toBeVisible();
});

test("TC-GAL-05 Both members vote student1 as leader; then only the leader may rename", async ({ page }) => {
    await openAssignment(page, accounts.student1);
    await card(page).locator("[data-sg-nominee]").selectOption({ index: 0 });
    await card(page).getByRole("button", { name: "Nominate" }).click();
    await expect(card(page).locator("[data-sg-leader]")).toContainText("1 of 2 agreed");

    await openAssignment(page, accounts.student2);
    await card(page).getByRole("button", { name: "Agree" }).click();
    await expect(card(page).locator("[data-sg-leader]")).toContainText("Leader:");
    await expect(card(page).getByRole("button", { name: "Rename group" })).toHaveCount(0);

    await openAssignment(page, accounts.student1);
    await card(page).getByRole("button", { name: "Rename group" }).click();
    const dialog = page.getByRole("dialog", { name: "Rename group" });
    await dialog.getByLabel("Group name").fill("Early Birds");
    await dialog.getByRole("button", { name: "Save" }).click();
    await expect(card(page).getByText("Your group: Early Birds")).toBeVisible();
});

test("TC-GAL-06 The leader row fits a 360 px phone", async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await openAssignment(page, accounts.student1);
    await expect(card(page).locator("[data-sg-leader]")).toBeVisible();

    const overflow = await page.evaluate(() => globalThis.document.documentElement.scrollWidth - globalThis.document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
});
