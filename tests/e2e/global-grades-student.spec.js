// Cases: docs/test-cases/p2-global-grades.md (TC-GGR-04 to TC-GGR-06).
// Data: student1 is in IT101-A (a published scheme, graded so far: 90.00%, In progress) and IT102-A (a published scheme
// with nothing graded yet). Student 1 has a graded Class Participation score in IT101-A that instructor1
// can change. The cases leave the data as they found it: the score is put back and the extra term TC-GGR-05
// adds is removed.
import { expect, test } from "@playwright/test";
import { signInAs } from "./support/course.js";
import { runSql } from "./support/database.js";
import { accounts } from "./support/sign-in.js";

const studentId = 1;

async function studentOnGrades(browser) {
    const page = await (await browser.newContext()).newPage();
    await signInAs(page, accounts.student1, "student");
    await page.goto("/student/grades");
    await expect(page.getByRole("heading", { name: "Grades", exact: true })).toBeVisible();
    return page;
}

// The demo scheme has no grading periods, so the "Show categories" toggle (which only a scheme with periods has)
// needs two: Midterm (Quizzes, Assignments) and Final (Participation). Each period's categories are put to 100.
// Only the weights the page reads change; nothing is recalculated, and removeGradingPeriods puts it all back.
const schemeOfIt101A = "(SELECT id FROM grading_schemes WHERE course_offering_id = 1)";

function addGradingPeriods() {
    const run = (sql) => expect(runSql(sql)).toContain("ok");
    run(`UPDATE grading_schemes SET uses_periods = 1 WHERE course_offering_id = 1`);
    run(`INSERT INTO grading_periods (grading_scheme_id, name, weight_percentage, sort_order, status, created_at, updated_at) VALUES (${schemeOfIt101A}, 'Midterm', 50, 1, 'Active', NOW(), NOW()), (${schemeOfIt101A}, 'Final', 50, 2, 'Active', NOW(), NOW())`);
    run(`UPDATE grade_categories SET period_id = (SELECT id FROM grading_periods WHERE grading_scheme_id = ${schemeOfIt101A} AND name = 'Midterm'), weight_percentage = 50 WHERE grading_scheme_id = ${schemeOfIt101A} AND name IN ('Quizzes', 'Assignments')`);
    run(`UPDATE grade_categories SET period_id = (SELECT id FROM grading_periods WHERE grading_scheme_id = ${schemeOfIt101A} AND name = 'Final'), weight_percentage = 100 WHERE grading_scheme_id = ${schemeOfIt101A} AND name = 'Participation'`);
}

function removeGradingPeriods() {
    runSql(`UPDATE grading_schemes SET uses_periods = 0 WHERE course_offering_id = 1`);
    runSql(`UPDATE grade_categories SET period_id = NULL, weight_percentage = CASE name WHEN 'Participation' THEN 20 ELSE 40 END WHERE grading_scheme_id = ${schemeOfIt101A}`);
    runSql(`DELETE FROM grading_periods WHERE grading_scheme_id = ${schemeOfIt101A}`);
}

const row = (page, code) => page.locator("[data-grades-row]").filter({ hasText: code });

test("TC-GGR-04 Student opens Grades from the Apps menu, sees the term and expands a course", async ({ browser }) => {
    addGradingPeriods();
    try {
        const page = await (await browser.newContext()).newPage();
        await signInAs(page, accounts.student1, "student");

        await page.getByRole("button", { name: /apps/i }).first().click();
        await page.getByRole("link", { name: /^Grades$/ }).first().click();
        await expect(page).toHaveURL(/\/student\/grades$/);

        await expect(page.locator("[data-term-line]")).toContainText("1st Semester");
        await expect(page.locator("[data-term-line]")).toContainText("2 courses");
        await expect(page.locator("[data-term-line]")).toContainText("6 units");

        // The summary cards: nothing is finalized, so the GWA reads a dash.
        for (const label of ["GWA", "Units", "Passed", "Failed", "INC"]) {
            await expect(page.locator("[data-grades-summary]")).toContainText(label, { ignoreCase: true });
        }
        await expect(page.locator('[data-summary="gwa"]')).toContainText("—");

        // IT101-A shows its grade; IT102-A has nothing graded yet, so it shows a dash.
        const it101 = row(page, "IT101-A");
        await expect(it101.locator("[data-grade-status]")).toHaveText(/In progress/);
        await expect(it101.locator("[data-grade-percentage]")).toHaveText("90.00%");
        await expect(row(page, "IT102-A").locator("[data-grade-status]")).toHaveText(/In progress/);
        await expect(row(page, "IT102-A").locator("[data-grade-main]")).toHaveText("—");

        // Expand: the breakdown loads in place.
        const toggle = it101.locator("[data-disclosure]");
        await toggle.click();
        await expect(toggle).toHaveAttribute("aria-expanded", "true");
        const slot = it101.locator("[data-breakdown-slot]");
        await expect(slot.locator("[data-grades-breakdown]")).toBeVisible();
        await expect(slot).toContainText(/Passing is \d/);

        // Categories are behind a toggle.
        const categories = slot.locator("[data-categories]");
        const categoriesToggle = slot.locator("[data-categories-toggle]");
        await expect(categories).toBeHidden();
        await categoriesToggle.click();
        await expect(categoriesToggle).toHaveText("Hide categories");
        await expect(categories).toBeVisible();
        await categoriesToggle.click();
        await expect(categoriesToggle).toHaveText("Show categories");
        await expect(categories).toBeHidden();

        // Collapse and expand again: it is shown again.
        await toggle.click();
        await expect(slot).toBeHidden();
        await toggle.click();
        await expect(slot.locator("[data-grades-breakdown]")).toBeVisible();

        // Open course grades leads to the course's Grades tab.
        await slot.getByRole("link", { name: /Open course grades/ }).click();
        await expect(page).toHaveURL(/\/student\/courses\/\d+\//);

    } finally {
        removeGradingPeriods();
    }
});

test("TC-GGR-05 Student changes term with the picker", async ({ browser }) => {
    // The demo has one term for student1, so a finished one with one course is added (and removed after).
    const ok = (result) => expect(result).toContain("ok");
    ok(runSql(
        "INSERT INTO academic_terms (institution_id, name, academic_year, start_date, end_date, status, created_at, updated_at) "
        + "VALUES (1, 'Summer', '2025-2026', '2026-03-01', '2026-06-01', 'completed', NOW(), NOW())",
    ));

    try {
        ok(runSql(
            "INSERT INTO course_offerings (course_id, term_id, section_code, capacity, status, created_at, updated_at) "
            + "SELECT 4, id, 'A', 40, 'completed', NOW(), NOW() FROM academic_terms WHERE name = 'Summer' AND academic_year = '2025-2026'",
        ));
        ok(runSql(
            "INSERT INTO enrollments (offering_id, student_user_id, status, enrolled_at, updated_at) "
            + `SELECT o.id, ${studentId}, 'completed', NOW(), NOW() FROM course_offerings o JOIN academic_terms t ON t.id = o.term_id `
            + "WHERE t.name = 'Summer' AND t.academic_year = '2025-2026'",
        ));

        const page = await studentOnGrades(browser);
        await expect(page.locator("[data-term-line]")).toContainText("1st Semester");

        await page.locator("#grades-term").selectOption({ label: "Summer (2025-2026)" });
        await expect(page).toHaveURL(/\/student\/grades\?term=\d+$/);

        // A finished term: "ended", and only its own course.
        await expect(page.locator("[data-term-line]")).toContainText("Summer");
        await expect(page.locator("[data-term-line]")).toContainText("ended Jun 1");
        await expect(page.locator("[data-grades-row]")).toHaveCount(1);
        await expect(row(page, "CS101-A")).toBeVisible();
        // It has no published scheme, so it says so and cannot be opened.
        await expect(row(page, "CS101-A").locator("[data-grade-status]")).toHaveText(/Not published yet/);
        await expect(row(page, "CS101-A").locator("[data-disclosure]")).toHaveCount(0);
        await expect(row(page, "IT101-A")).toHaveCount(0);
    } finally {
        expect(runSql("DELETE FROM academic_terms WHERE name = 'Summer' AND academic_year = '2025-2026'")).toContain("ok (1 row(s))");
    }
});

test("TC-GGR-06 A grade changed by the instructor shows Changed once", async ({ browser }) => {
    // The student looks first, so the page knows what was seen.
    const student = await studentOnGrades(browser);
    await expect(row(student, "IT101-A")).toBeVisible();
    await expect(student.locator("[data-changed]")).toHaveCount(0);

    // The instructor changes a graded manual score of student 1 in IT101-A.
    const instructor = await (await browser.newContext()).newPage();
    await signInAs(instructor, accounts.instructor, "instructor");
    await instructor.goto("/instructor/grades");
    await expect(instructor.locator("[data-grades-grid] [data-gradebook-roster]")).toBeVisible();

    const field = instructor.locator(`[data-roster-row][data-student-id="${studentId}"] [data-manual-score-input]`).first();
    const original = await field.inputValue();
    expect(original).toMatch(/^\d/);
    const typed = original === "1" ? "2" : "1";

    const save = async (value) => {
        await field.fill(value);
        await field.blur();
        await expect(instructor.locator("[data-roster-save-message]")).toHaveText(/saved/i);
    };

    try {
        await save(typed);

        // The student reloads: the badge is on the row. Reloading again, it is gone.
        await student.reload();
        await expect(row(student, "IT101-A").locator("[data-changed]")).toHaveText("Changed");
        await student.reload();
        await expect(student.locator("[data-changed]")).toHaveCount(0);
    } finally {
        // Put the score back, then let the student see the restored grade so the page is quiet again.
        await save(original);
        await student.reload();
        await student.reload();
        await expect(student.locator("[data-changed]")).toHaveCount(0);
    }
});
