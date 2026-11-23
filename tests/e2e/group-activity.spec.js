// Cases: the assignment editor's "Group activity" card (group activities, phase 3, screen 2A): switching it on
// attaches groups, a setting persists, a group can be added, switching it off asks first; a quiz has no such card;
// the card fits a 360 px phone and axe finds nothing serious.
// Data: IT101-A, taught by instructor1. "Lab 1: Designing a Table" (assignment) and "Quiz 1: Variables and Data
// Types" (quiz). The last case switches the card off again, so the lab is left as it was.
// Not covered here: unlocking a frozen group and adding a late enrollee. Nothing in the app freezes a group until
// submissions arrive (phase 6); the Vitest and feature tests cover both dialogs and their endpoints.
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { queryValue } from "./support/database.js";
import { resetLab } from "./support/group-lab.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

const LAB = "Lab 1: Designing a Table";

async function seriousProblems(page) {
    const results = await new AxeBuilder({ page }).include("[data-group-activity]").withTags(["wcag2a", "wcag2aa"]).analyze();

    return results.violations
        .filter((v) => v.impact === "serious" || v.impact === "critical")
        .map((v) => `${v.id}: ${v.help} (${v.nodes.length} place${v.nodes.length === 1 ? "" : "s"}, first: ${v.nodes[0].target.join(" ")})`);
}

const overflowOf = (page) => page.evaluate(() => globalThis.document.documentElement.scrollWidth - globalThis.document.documentElement.clientWidth);

/** Opens an assessment's editor from the Assessments tab (a card, or a row in the drafts list). */
async function openEditor(page, title) {
    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Assessments");

    const card = page.locator("[data-assessment]").filter({ hasText: title }).locator("[data-edit-assessment-button]");
    const draftRow = page.locator("[data-edit-assessment-button]").filter({ hasText: title });
    await card.or(draftRow).first().click();

    await expect(page.locator("#editor-inspector-panel")).toBeVisible();
}

const section = (page) => page.locator("[data-group-activity]");

test("TC-GA-01 Switching Group activity on attaches groups, and a setting persists", async ({ page }) => {
    resetLab();
    await openEditor(page, LAB);

    await expect(section(page).getByText("Students work on this alone")).toBeVisible();
    const groupSwitch = section(page).getByRole("switch", { name: "Group activity" });
    await expect(groupSwitch).toBeEnabled();
    await groupSwitch.click({ force: true });

    const attach = page.getByRole("dialog", { name: "Make this a group activity" });
    await attach.getByLabel("Group set").selectOption({ label: "Start from scratch" });
    await attach.getByRole("button", { name: "Make it a group activity" }).click();
    await expect(attach).toBeHidden();

    await expect(section(page).getByLabel("Submission style")).toBeVisible();
    const saved = page.waitForResponse((r) => r.url().includes("/settings") && r.request().method() === "PUT");
    await section(page).getByLabel("Grading").selectOption("individual");
    const response = await saved;
    expect(response.ok(), await response.text()).toBe(true);
    expect(queryValue("SELECT grading_mode FROM activity_group_configs ORDER BY id DESC LIMIT 1"), "grading_mode in the database").toContain("individual");
    await expect(page.locator("[data-groups-live]").first()).toHaveText("Group activity settings saved");

    await section(page).getByRole("button", { name: "Add group" }).click();
    const name = page.getByRole("dialog", { name: "Add group" });
    await name.getByRole("button", { name: "Save" }).click();
    await expect(section(page).locator("[data-group-card]").filter({ hasText: "Team 1" })).toBeVisible();

    await page.reload();
    await expect(section(page).getByLabel("Grading")).toHaveValue("individual");
    await expect(section(page).locator("[data-group-card]").filter({ hasText: "Team 1" })).toBeVisible();
});

test("TC-GA-02 The card fits a 360 px phone and axe finds nothing serious", async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await openEditor(page, LAB);
    await expect(section(page).locator("[data-group-card]").first()).toBeVisible();

    expect(await overflowOf(page)).toBeLessThanOrEqual(0);
    expect(await seriousProblems(page)).toEqual([]);
});

test("TC-GA-03 A quiz's editor has no Group activity card", async ({ page }) => {
    await openEditor(page, "Quiz 1: Variables and Data Types");

    await expect(section(page)).toHaveCount(0);
});

test("TC-GA-04 Switching it off asks first, then removes the activity's groups", async ({ page }) => {
    await openEditor(page, LAB);

    const groupSwitch = section(page).getByRole("switch", { name: "Group activity" });

    await expect(groupSwitch).toBeEnabled();

    await groupSwitch.click({ force: true });
    const confirm = page.getByRole("dialog", { name: "Stop using groups here?" });
    await expect(confirm.getByText("This removes the activity's groups.")).toBeVisible();
    await confirm.getByRole("button", { name: "Remove groups" }).click();

    await expect(section(page).getByText("Students work on this alone")).toBeVisible();
    await expect(section(page).locator("[data-group-card]")).toHaveCount(0);
});
