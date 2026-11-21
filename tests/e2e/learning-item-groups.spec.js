// Cases: groups on an ungraded learning item (group activities, phase 8, screens 2A for items and 3D): the instructor
// turns on groups in the reading's editor with "Any member marks it done for the group" (detach asks first); student1
// sees "Your group" with the roster, the footer button reads "Mark complete for the group", marking it completes it
// for student2 as well, whose card then says who marked it; the card fits 360 px and axe finds nothing serious.
// Data: IT101-A, taught by instructor1; student1 and student2 are enrolled. The reading "Welcome to Introduction to
// Computing" gets one group (Pair 1) made in the editor; the SQL below puts both students in it, since the editor's
// Move picker is covered by the assignment specs. The last case switches groups off again.
// NOT RUN: written without a run. The end-to-end database on :3306 was broken when this was written, so none of
// these cases has been executed; expect selector fixes on the first real run.
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { openCourse, openItem, openModule, signInAs } from "./support/course.js";
import { runSql } from "./support/database.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

const READING = "Welcome to Introduction to Computing";
const ITEM = `(SELECT id FROM learning_items WHERE title = '${READING}' LIMIT 1)`;

const card = (page) => page.locator("[data-student-group]");

async function openReading(page, account, role) {
    await signInAs(page, account, role);
    await openCourse(page, role, "IT101", "A");
    await openModule(page, "Getting Started");
    await openItem(page, READING);
}

test.afterAll(() => {
    runSql(`DELETE FROM learning_completions WHERE learning_item_id = ${ITEM}`);
});

test("TC-LIG-01 The instructor turns on groups for the reading with completion for the group", async ({ page }) => {
    await openReading(page, accounts.instructor, "instructor");
    await page.locator("[data-learning-item-edit-button]").first().click();

    const section = page.locator("[data-group-activity]");
    await expect(section).toBeVisible();
    await expect(section.getByText("Students do this on their own")).toBeVisible();
    await section.getByRole("switch", { name: "Group activity" }).check({ force: true });

    const attach = page.getByRole("dialog", { name: "Make this a group activity" });
    await attach.getByLabel("Group set").selectOption({ label: "Start from scratch" });
    await attach.getByText("Any member marks it done for the group").click();
    await attach.getByRole("button", { name: "Use groups" }).click();
    await expect(attach).toBeHidden();

    await section.getByRole("button", { name: "Add group" }).click();
    const name = page.getByRole("dialog", { name: "Add group" });
    await name.getByLabel("Group name").fill("Pair 1");
    await name.getByRole("button", { name: "Save" }).click();
    await expect(section.locator("[data-group-card]").filter({ hasText: "Pair 1" })).toBeVisible();

    // An item's groups have no leader, deadline or unlock actions.
    await expect(section.getByRole("button", { name: /Set leader|Extend deadline|Unlock/ })).toHaveCount(0);

    runSql(`INSERT INTO student_group_members (group_id, config_id, student_user_id, joined_at)
        SELECT g.id, g.config_id, u.id, NOW() FROM student_groups g JOIN activity_group_configs c ON c.id = g.config_id JOIN users u
        JOIN auth_identities i ON i.user_id = u.id AND i.type = 'email_password'
        WHERE c.learning_item_id = ${ITEM} AND g.name = 'Pair 1' AND i.secret IN ('student1@mentora.test', 'student2@mentora.test')`);
});

test("TC-LIG-02 Student1 sees the group and marks the reading complete for the group", async ({ page }) => {
    await openReading(page, accounts.student1, "student");

    await expect(card(page).getByText("Your group: Pair 1")).toBeVisible();
    await expect(card(page).locator("[data-sg-member]")).toHaveCount(2);
    await expect(card(page).getByText("Any member can mark this done for the group.")).toBeVisible();

    const button = page.locator("[data-mark-complete]");
    await expect(button).toHaveText(/Mark complete for the group/);
    await button.click();
    await expect(button).toHaveText(/Completed/);
    await expect(card(page).getByText(/Marked complete by .+ for the group\./)).toBeVisible();
});

test("TC-LIG-03 Student2's reading is complete too, and the card says who marked it (360 px, axe)", async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 780 });
    await openReading(page, accounts.student2, "student");

    await expect(page.locator("[data-mark-complete]")).toHaveText(/Completed/);
    await expect(card(page).getByText(/Marked complete by .+ for the group\./)).toBeVisible();

    const overflow = await page.evaluate(() => globalThis.document.documentElement.scrollWidth - globalThis.document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    const results = await new AxeBuilder({ page }).include("[data-student-group]").withTags(["wcag2a", "wcag2aa"]).analyze();
    expect(results.violations.filter((v) => ["serious", "critical"].includes(v.impact))).toEqual([]);
});

test("TC-LIG-04 Switching groups off asks first, then the reading has no group card", async ({ page }) => {
    await openReading(page, accounts.instructor, "instructor");
    await page.locator("[data-learning-item-edit-button]").first().click();

    const section = page.locator("[data-group-activity]");
    await section.getByRole("switch", { name: "Group activity" }).uncheck({ force: true });
    const confirm = page.getByRole("dialog", { name: "Stop using groups here?" });
    await expect(confirm).toBeVisible();
    await confirm.getByRole("button", { name: "Remove groups" }).click();
    await expect(section.getByText("Students do this on their own")).toBeVisible();
});
