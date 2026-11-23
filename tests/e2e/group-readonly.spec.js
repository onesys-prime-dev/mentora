// Cases: the read-only group views (group activities, Task 9.2, screens 4E and 4F). The dean opens a group
// activity on the course's Assessments tab and sees each group's status, the members' finals and the adjustment
// reasons, with a "View only" note and no input or button to change anything; it fits 360 px and axe finds nothing
// serious. The parent of student1 sees "Group: Team 1 (...)" and student1's own grade on the Assessments tab, and
// never a teammate's adjustment reason (every response body is checked).
// Data: IT101-A, taught by instructor1; student1 to student4 enrolled. The setup case makes "Lab 1: Designing a
// Table" a group activity with Team 1 (student1-4), a shared hand-in and member scores, all by SQL (the grading
// flow has its own spec, group-grading.spec.js). The dean account is the dean of IT101's college (dean2, per
// support/sign-in.js); change it if the demo data puts IT101 elsewhere. parent1 follows student1.
// NOT RUN: written without a run. The end-to-end database on :3306 was broken when this was written, so none of
// these cases has been executed; expect selector and account fixes on the first real run.
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { queryValue, runSql } from "./support/database.js";
import { createLabConfig, resetLab } from "./support/group-lab.js";
import { accounts } from "./support/sign-in.js";

test.describe.configure({ mode: "serial" });

const LAB = "Lab 1: Designing a Table";
const TEAMMATE_REASON = "E2E teammate reason never for parents";
const OWN_REASON = "E2E led the demo";

const lab = `(SELECT id FROM assessments WHERE title = '${LAB}' LIMIT 1)`;
const student = (n) => `(SELECT user_id FROM auth_identities WHERE secret = 'student${n}@mentora.test' LIMIT 1)`;

test("TC-GRO-01 Set up: the lab is a graded group activity with Team 1 of four", async () => {
    resetLab();
    createLabConfig({ formation_mode: "'manual'", min_size: 1, max_size: 6, grading_mode: "'collective_adjust'", submission_style: "'shared'" });
    const config = `(SELECT id FROM (SELECT id FROM activity_group_configs WHERE assessment_id = ${lab}) c)`;
    runSql(`INSERT INTO student_groups (config_id, name, status) SELECT ${config}, 'Team 1', 'active' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM student_groups WHERE config_id = ${config} AND name = 'Team 1')`);
    const team = `(SELECT id FROM (SELECT id FROM student_groups WHERE config_id = ${config} AND name = 'Team 1') g)`;

    for (const n of [1, 2, 3, 4]) {
        runSql(`INSERT IGNORE INTO student_group_members (group_id, config_id, student_user_id, joined_at) VALUES (${team}, ${config}, ${student(n)}, NOW())`);
    }

    // Attempt 90: student1 may already have a solo attempt 1 on the lab (the unique key is assessment, student, attempt).
    runSql(`INSERT INTO assessment_submissions (assessment_id, student_id, group_id, submitted_by_user_id, attempt_number, started_at, submitted_at, submission_text, status, is_graded, score)
        SELECT ${lab}, ${student(1)}, ${team}, ${student(1)}, 90, NOW(), NOW(), 'Our table design', 'Graded', 1, 16 FROM DUAL
        WHERE NOT EXISTS (SELECT 1 FROM assessment_submissions WHERE assessment_id = ${lab} AND group_id = ${team})`);
    const shared = `(SELECT id FROM (SELECT id FROM assessment_submissions WHERE assessment_id = ${lab} AND group_id = ${team} LIMIT 1) s)`;

    for (const [n, adjustment, reason] of [[1, 1, OWN_REASON], [2, 0, ""], [3, -4, TEAMMATE_REASON], [4, 0, ""]]) {
        runSql(`INSERT IGNORE INTO group_member_scores (submission_id, group_id, student_user_id, group_points, individual_points, adjustment, adjustment_reason, final_score, final_percentage, excused, pending, created_at)
            VALUES (${shared}, ${team}, ${student(n)}, 16, NULL, ${adjustment}, '${reason}', ${16 + adjustment}, ${(16 + adjustment) * 5}, 0, 0, NOW())`);
    }
});

test("TC-GRO-02 The dean sees the groups and grades of a group activity, view only", async ({ page }) => {
    await signInAs(page, accounts.dean2, "dean");
    await openCourse(page, "dean", "IT101", "A");
    await openTab(page, "Assessments");
    await page.locator("[data-assessment]").filter({ hasText: LAB }).first().click();

    const view = page.locator("[data-dean-groups]");
    await expect(view.locator("[data-gg-group]").filter({ hasText: "Team 1" })).toContainText("Graded");
    await expect(view.locator("[data-gg-lock]")).toContainText("View only.");
    await expect(view.locator("[data-gg-detail]")).toContainText(TEAMMATE_REASON);
    await expect(view.locator("input, textarea, select, button[type=submit], [data-gg-action]")).toHaveCount(0);

    await page.setViewportSize({ width: 360, height: 800 });
    expect(await page.evaluate(() => globalThis.document.documentElement.scrollWidth - globalThis.document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    const results = await new AxeBuilder({ page }).include("[data-dean-groups]").analyze();
    expect(results.violations.filter((v) => ["serious", "critical"].includes(v.impact))).toEqual([]);
});

test("TC-GRO-03 The parent sees the child's group and own grade, never a teammate's", async ({ page }) => {
    const bodies = [];
    page.on("response", async (response) => {
        if (response.url().includes("/parent/")) {
            bodies.push(await response.text().catch(() => ""));
        }
    });

    await signInAs(page, accounts.parent, "parent");
    const child = queryValue(`SELECT ${student(1)}`);
    const offering = queryValue("SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE c.course_code = 'IT101' AND o.section_code = 'A' LIMIT 1");
    await page.goto(`/parent/children/${child}/courses/${offering}/x/assessments`);

    const line = page.locator("[data-parent-group]").first();
    await expect(line).toContainText("Group: Team 1");
    await expect(line).toContainText("Grade 17 / 20");
    await expect(line).toContainText(OWN_REASON);

    for (const body of bodies) {
        expect(body).not.toContain(TEAMMATE_REASON);
    }
});
