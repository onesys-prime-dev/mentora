// Cases: the course Groups tab (group activities, phase 1): an instructor creates a group set, adds a group and
// places students in it with the "Move…" picker and by drag; the tab fits a 360 px phone and axe finds nothing serious.
// Data: IT101-A, taught by instructor1, with its demo students. The set is made here; nothing else uses group sets.
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

const SET = "E2E project teams";

/** What axe finds that a person would really be stopped by (the WCAG A and AA rules, serious or critical). */
async function seriousProblems(page) {
    const results = await new AxeBuilder({ page }).include("#course-content").withTags(["wcag2a", "wcag2aa"]).analyze();

    return results.violations
        .filter((v) => v.impact === "serious" || v.impact === "critical")
        .map((v) => `${v.id}: ${v.help} (${v.nodes.length} place${v.nodes.length === 1 ? "" : "s"}, first: ${v.nodes[0].target.join(" ")})`);
}

/** How far the page is wider than the window: anything above 0 means a sideways scrollbar for the whole page. */
const overflowOf = (page) => page.evaluate(() => globalThis.document.documentElement.scrollWidth - globalThis.document.documentElement.clientWidth);

async function openGroups(page) {
    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Groups");
    await expect(page.getByRole("heading", { name: "Group sets" })).toBeVisible();
}

test("TC-GRP-01 An instructor creates a set, adds a group and places students with the picker and by drag", async ({ page }) => {
    await openGroups(page);

    await page.getByRole("button", { name: "New group set" }).first().click();
    const setDialog = page.getByRole("dialog", { name: "New group set" });
    await setDialog.getByLabel("Name").fill(SET);
    await setDialog.getByLabel("Smallest group").fill("1");
    await setDialog.getByLabel("Largest group").fill("4");
    await setDialog.getByRole("button", { name: "Create set" }).click();
    await expect(setDialog).toBeHidden();

    const detail = page.locator("[data-group-set-detail]");
    await expect(detail.getByRole("heading", { name: SET })).toBeVisible();
    await expect(detail.getByText("No groups yet")).toBeVisible();

    await detail.getByRole("button", { name: "Add group" }).click();
    const nameDialog = page.getByRole("dialog", { name: "Add group" });
    await expect(nameDialog.getByLabel("Group name")).toHaveValue("Team 1");
    await nameDialog.getByRole("button", { name: "Add group" }).click();
    await expect(nameDialog).toBeHidden();

    const team = detail.locator("[data-group-card]").filter({ hasText: "Team 1" });
    await expect(team.getByText("0 / 4 · below minimum")).toBeVisible();

    // The picker: the keyboard and phone path.
    const pool = detail.locator("[data-drop-zone='']");
    const first = pool.locator("[data-member]").first();
    const name = (await first.locator("[data-member-name]").textContent()).trim();
    await first.getByRole("button", { name: /^Move/ }).click();
    const moveDialog = page.getByRole("dialog", { name: `Move ${name}` });
    await moveDialog.getByLabel("Move to").selectOption({ label: "Team 1 (0 / 4)" });
    await moveDialog.getByRole("button", { name: "Move" }).click();
    await expect(moveDialog).toBeHidden();

    await expect(team.getByText(name)).toBeVisible();
    await expect(team.getByText("1 / 4")).toBeVisible();
    await expect(page.locator("[data-groups-live]")).toHaveText(`${name} moved to Team 1`);

    // Drag: an extra for a mouse.
    const second = pool.locator("[data-member]").first();
    const other = (await second.locator("[data-member-name]").textContent()).trim();
    await second.dragTo(team);
    await expect(team.getByText(other)).toBeVisible();
    await expect(team.getByText("2 / 4")).toBeVisible();

    // Kept: the server has it.
    await page.reload();
    await expect(page.locator("[data-group-card]").filter({ hasText: "Team 1" }).getByText(name)).toBeVisible();
});

test("TC-GRP-02 The Groups tab fits a 360 px phone and axe finds nothing serious", async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await openGroups(page);
    await expect(page.locator("[data-group-card]").first()).toBeVisible();

    expect(await overflowOf(page)).toBeLessThanOrEqual(0);
    expect(await seriousProblems(page)).toEqual([]);

    await page.setViewportSize({ width: 1440, height: 900 });
    expect(await seriousProblems(page)).toEqual([]);
});

test("TC-GRP-03 Generate groups previews without saving, then saves after confirming the replacement", async ({ page }) => {
    await openGroups(page);

    const detail = page.locator("[data-group-set-detail]");
    const cards = detail.locator("[data-group-card]");
    const before = await cards.count();

    await detail.getByRole("button", { name: "Generate groups" }).click();
    const dialog = page.getByRole("dialog", { name: "Generate groups" });
    await expect(dialog.getByText(/^Pool: \d+ students?$/)).toBeVisible();

    await dialog.getByLabel("Split by").selectOption("count");
    await dialog.getByLabel("How many").fill("2");
    await dialog.getByRole("button", { name: "Preview" }).click();

    await expect(dialog.getByText("Step 2 of 2 · Preview. Nothing has been saved yet.")).toBeVisible();
    await expect(dialog.locator("[data-preview-group]")).toHaveCount(2);
    expect(await cards.count()).toBe(before); // nothing saved yet

    await dialog.getByRole("button", { name: "Shuffle again" }).click();
    await expect(dialog.locator("[data-preview-group]")).toHaveCount(2);

    await dialog.getByRole("button", { name: "Save groups" }).click();

    // The set already has groups from TC-GRP-01: replacing needs the confirmation.
    const confirm = page.getByRole("dialog", { name: "Replace the existing groups?" });
    await expect(confirm).toBeVisible();
    await confirm.getByRole("button", { name: "Replace groups" }).click();

    await expect(cards).toHaveCount(2);
    await expect(dialog).toBeHidden();
});
