// Cases: Grade Work's group view (group activities, phase 7, screens 2B and 3B's grade card): the instructor grades a
// four-member group once, adjusts one member with a reason and a private note, and saves all four together; a
// teammate's page never carries that member's reason or note (every student API response body is checked); the
// adjusted member sees their own; the view fits 360 px and axe finds nothing serious.
// Data: IT101-A, taught by instructor1; student1 to student4 are enrolled. "Lab 1: Designing a Table" is made a
// group activity in the first case (start from scratch, one group); the SQL then puts the four students in Team 1,
// sets "group score with a per-student adjustment" and hands the work in as student1 (the submit flow has its own
// spec). The last case switches the activity off again.
// NOT RUN: written without a run. The end-to-end database on :3306 was broken when this was written, so none of
// these cases has been executed; expect selector fixes on the first real run.
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { courseAddress, openCourse, openTab, signInAs } from "./support/course.js";
import { queryValue, runSql } from "./support/database.js";
import { resetLab } from "./support/group-lab.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

const LAB = "Lab 1: Designing a Table";
const REASON = "Missed three team meetings";
const NOTE = "Come and see me about the next project";

const lab = `(SELECT id FROM assessments WHERE title = '${LAB}' LIMIT 1)`;
const config = `(SELECT id FROM activity_group_configs WHERE assessment_id = ${lab})`;
const student = (n) => `(SELECT user_id FROM auth_identities WHERE secret = 'student${n}@mentora.test' LIMIT 1)`;

const view = (page) => page.locator("[data-group-grading]");

async function openGroupGrading(page) {
    await signInAs(page, accounts.instructor, "instructor");
    const id = queryValue(`SELECT id FROM assessments WHERE title = '${LAB}' LIMIT 1`);
    await page.goto(`${await courseAddress(page, "instructor", "IT101", "A")}/grades?assessment=${id}&grade=1`);
    await expect(view(page).locator("[data-gg-group]").first()).toBeVisible();
}

test("TC-GG-01 Set up: the lab becomes a group activity with Team 1 of four, handed in by student1", async ({ page }) => {
    resetLab();
    await signInAs(page, accounts.instructor, "instructor");
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Assessments");
    await page.locator("[data-assessment]").filter({ hasText: LAB }).locator("[data-edit-assessment-button]").first().click();

    const section = page.locator("[data-group-activity]");
    const groupSwitch = section.getByRole("switch", { name: "Group activity" });
    await expect(groupSwitch).toBeEnabled();
    await groupSwitch.click({ force: true });
    const attach = page.getByRole("dialog", { name: "Make this a group activity" });
    await attach.getByLabel("Group set").selectOption({ label: "Start from scratch" });
    await attach.getByRole("button", { name: "Make it a group activity" }).click();
    await section.getByRole("button", { name: "Add group" }).click();
    await page.getByRole("dialog", { name: "Add group" }).getByRole("button", { name: "Save" }).click();
    await expect(section.locator("[data-group-card]").filter({ hasText: "Team 1" })).toBeVisible();

    runSql(`UPDATE activity_group_configs SET grading_mode = 'collective_adjust', submission_style = 'shared', max_size = 6 WHERE assessment_id = ${lab}`);

    for (const n of [1, 2, 3, 4]) {
        runSql(`INSERT INTO student_group_members (group_id, config_id, student_user_id, joined_at) SELECT g.id, g.config_id, ${student(n)}, NOW() FROM student_groups g WHERE g.config_id = ${config} AND g.name = 'Team 1'`);
    }

    runSql(`INSERT INTO assessment_submissions (assessment_id, student_id, group_id, submitted_by_user_id, attempt_number, started_at, submitted_at, submission_text, status, is_graded, score)
        SELECT ${lab}, ${student(1)}, g.id, ${student(1)}, 90, NOW(), NOW(), 'Our table design', 'Submitted', 0, 0 FROM student_groups g WHERE g.config_id = ${config} AND g.name = 'Team 1'`);
});

test("TC-GG-02 The instructor grades the group once, adjusts one member with a reason, and saves all four", async ({ page }) => {
    await openGroupGrading(page);

    const team = view(page).locator("[data-gg-group]").filter({ hasText: "Team 1" });
    await expect(team).toContainText("Needs grading");
    await team.click();

    const detail = view(page).locator("[data-gg-detail]");
    await expect(detail.getByText("Our table design")).toBeVisible();
    await detail.getByLabel(/Group score/).fill("16");
    await detail.getByLabel("Add feedback").fill("Clear structure. Test it more.");

    const third = detail.locator(`[data-gg-member="${queryValue(`SELECT ${student(3)}`)}"]`);
    await third.getByLabel(/Adjustment/).fill("-4");
    await expect(third.locator("[data-gg-total]")).toContainText("12");

    // A reason is required first.
    await detail.getByRole("button", { name: "Save grades for 4 members" }).click();
    await expect(third.getByText("Give a reason for this adjustment.")).toBeVisible();

    await third.getByLabel("Reason for the adjustment").fill(REASON);
    await third.getByLabel(/Private note/).fill(NOTE);
    await detail.getByRole("button", { name: "Save grades for 4 members" }).click();

    await expect(page.locator("[data-gg-live]")).toContainText("Grades saved for 4 members");
    await expect(team).toContainText("Graded");
    await expect(detail.getByRole("button", { name: "Reopen grading" })).toBeVisible();
});

test("TC-GG-03 A teammate never receives another member's reason or note; the member sees their own", async ({ page, browser }) => {
    const bodies = [];
    page.on("response", async (response) => {
        if (response.url().includes("/student/")) {
            bodies.push(await response.text().catch(() => ""));
        }
    });

    await signInAs(page, accounts.student1, "student");
    await openCourse(page, "student", "IT101", "A");
    await openTab(page, "Assessments");
    await page.locator("[data-assessment]").filter({ hasText: LAB }).first().click();
    await expect(page.locator("[data-group-result]")).toBeVisible();

    expect(bodies.length).toBeGreaterThan(0);
    expect(bodies.filter((b) => b.includes(REASON) || b.includes(NOTE))).toEqual([]);

    // A browser of their own: clearing cookies races the first student's requests still in flight, whose replies
    // set their session cookie again.
    const context = await browser.newContext();
    const member = await context.newPage();

    try {
        await signInAs(member, accounts.student3, "student");
        await openCourse(member, "student", "IT101", "A");
        await openTab(member, "Assessments");
        await member.locator("[data-assessment]").filter({ hasText: LAB }).first().click();
        await expect(member.locator("[data-group-result]")).toContainText(REASON);
    } finally {
        await context.close();
    }
});

test("TC-GG-04 The group view fits a 360 px phone and axe finds nothing serious", async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await openGroupGrading(page);

    expect(await page.evaluate(() => globalThis.document.documentElement.scrollWidth - globalThis.document.documentElement.clientWidth)).toBeLessThanOrEqual(0);

    const results = await new AxeBuilder({ page }).include("[data-group-grading]").withTags(["wcag2a", "wcag2aa"]).analyze();
    expect(results.violations.filter((v) => v.impact === "serious" || v.impact === "critical").map((v) => v.id)).toEqual([]);
});

test("TC-GG-05 Clean up: the lab stops being a group activity", async () => {
    runSql(`DELETE FROM group_member_scores WHERE submission_id IN (SELECT id FROM assessment_submissions WHERE assessment_id = ${lab} AND group_id IS NOT NULL)`);
    runSql(`DELETE FROM assessment_submissions WHERE assessment_id = ${lab} AND group_id IS NOT NULL`);
    runSql(`DELETE FROM activity_group_configs WHERE assessment_id = ${lab}`);
});
