// Cases: docs/test-cases/p2-dean.md (TC-DEAN-01 to TC-DEAN-11).
// The dean's whole area, end to end: signing in and the dashboard, the role switch of a dean who also
// teaches, a course opened read-only, the override chain and the dean's decision, term-end sign-off,
// follow-ups, department announcements, nothing outside the department, the keyboard, the accessibility
// and width checks on every screen, and the states a dean can be in (no department, a read-only
// institution).
//
// Data (DemoUsersSeeder, the academic seeders):
//   dean1@mentora.test   dean of the General department, which has no courses: the "outside" and the
//                        empty-state dean.
//   dean2@mentora.test   dean of the College of Business and Management (CBM) who also teaches (no
//                        courses of their own). CBM holds IT101-A and -B (instructor1), IT102-A and
//                        IT201-A (instructor2), all in the IT sub-department that chairperson1 chairs.
// Sections the cases use: IT201-A for the override chain (three graded students), IT101-B for sign-off
// (no students, so its grades can be finalized in two clicks), IT101-A as the blocked one.
//
// Everything a person can do is done through the screens. runSql is used only for the two states the
// app has no screen for yet (a dean who has lost the department, an institution made read-only), and
// both are put back.
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { openTab } from "./support/course.js";
import { runSql } from "./support/database.js";
import { accounts, submitLoginForm } from "./support/sign-in.js";

const DEAN = { email: "dean1@mentora.test", password: "Dean123!" };
const DEAN_WHO_TEACHES = { email: "dean2@mentora.test", password: "Dean123!" };
const CHAIRPERSON = { email: "chairperson1@mentora.test", password: "Chairperson123!" };

/** A fresh browser, signed in, on the dashboard of `home` (the first address segment of the role). */
async function signedIn(browser, account, home = "dean") {
    const page = await (await browser.newContext()).newPage();
    await submitLoginForm(page, account.email, account.password);
    await expect(page).toHaveURL(new RegExp(`/${home}/dashboard$`));

    return page;
}

/** Attaches a full-page screenshot to the report, named for the state it shows. */
async function shot(page, testInfo, name) {
    await testInfo.attach(name, { body: await page.screenshot({ fullPage: true }), contentType: "image/png" });
}

/**
 * Clicks a control until its dialog is open. A click that lands before the page's script has attached does
 * nothing (a slow machine, such as a CI runner, hits this), so it is repeated until it takes.
 */
async function openDialog(trigger, dialog) {
    await expect(async () => {
        if (!(await dialog.isVisible())) {
            await trigger.click({ timeout: 2_000 });
        }

        await expect(dialog).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
}

const profileMenuButton = (page) => page.locator("#profile-menu-button");
const profileRole = (page) => page.locator("[data-profile-role]");
const roleSwitchButton = (page, role) => page.locator(`[data-role-switch-button][data-role="${role}"]`);

async function openProfileMenu(page) {
    await profileMenuButton(page).click();
    await expect(page.locator("#profile-menu-panel")).toBeVisible();
}

/** The address of a course section, read from its card on the dean's Courses page. */
async function deanCourseAddress(page, code, section) {
    await page.goto("/dean/courses");

    return page.locator("a", { hasText: `${code} · ${section}` }).first().getAttribute("href");
}

test("TC-DEAN-01 A dean signs in and lands on the department dashboard", async ({ browser }, testInfo) => {
    const page = await signedIn(browser, DEAN_WHO_TEACHES);

    // The department in numbers, and the one place that says what needs attention.
    await expect(page.getByRole("heading", { name: /^Dean dashboard, / })).toHaveCount(1);
    await expect(page.locator('[data-widget="snapshot"]')).toContainText("Your department");
    await expect(page.locator('[data-widget="needs-attention"]')).toContainText("Needs Attention");

    // Every tool a dean has is one click from here.
    for (const tool of ["Courses", "Approvals", "Sign-off", "Follow-ups", "Announcements", "Teaching load", "Chairperson review", "Monitor Department"]) {
        await expect(page.getByRole("link", { name: tool, exact: true }).first(), `the ${tool} tile`).toBeVisible();
    }

    await openProfileMenu(page);
    await expect(profileRole(page)).toHaveText("Dean");

    await shot(page, testInfo, "dean-dashboard");
});

test("TC-DEAN-02 A dean who also teaches switches roles and the app follows", async ({ browser }) => {
    // A dean with no other role has nothing to switch to.
    const only = await signedIn(browser, DEAN);
    await openProfileMenu(only);
    await expect(roleSwitchButton(only, "instructor")).toHaveCount(0);

    // First sign-in of a dean who also teaches opens as the dean, the highest role held.
    const page = await signedIn(browser, DEAN_WHO_TEACHES);
    await openProfileMenu(page);
    await expect(profileRole(page)).toHaveText("Dean");
    await roleSwitchButton(page, "instructor").click();

    await expect(page).toHaveURL(/\/instructor\/dashboard$/);
    await openProfileMenu(page);
    await expect(profileRole(page)).toHaveText("Instructor");

    // The switch is not just a redirect: the dean's pages are no longer reachable, nor do their tiles show.
    await page.goto("/dean/approvals");
    await expect(page).toHaveURL(/\/instructor\/dashboard$/);
    await expect(page.getByRole("link", { name: "Approvals", exact: true })).toHaveCount(0);

    // ...until switching back.
    await openProfileMenu(page);
    await roleSwitchButton(page, "dean").click();
    await expect(page).toHaveURL(/\/dean\/dashboard$/);
    await page.goto("/dean/approvals");
    await expect(page.getByRole("heading", { name: "Grade overrides", level: 1 })).toBeVisible();
});

test("TC-DEAN-03 A course opened by its dean is view only", async ({ browser }, testInfo) => {
    const page = await signedIn(browser, DEAN_WHO_TEACHES);

    await page.goto("/dean/courses");
    await page.locator("a", { hasText: "IT201 · A" }).first().click();
    await expect(page).toHaveURL(/\/dean\/courses\/\d+\//);

    // Said before anyone tries anything.
    await expect(page.locator('[data-lock="closed"]')).toContainText("View only. You are looking at IT201 A");

    const tab = async (name) => {
        await page.getByRole("tab", { name, exact: true }).click();
        await expect(page.getByRole("tab", { name, exact: true })).toHaveAttribute("aria-selected", "true");
    };
    const panel = page.getByRole("tabpanel");

    // Every tab can be read, and none of them offers what the dean cannot do.
    await tab("Modules");
    await expect(panel.getByRole("heading", { name: "Course Modules" })).toBeVisible();
    await expect(panel.getByRole("button", { name: "Add Module" })).toHaveCount(0);

    await tab("Discussions");
    await expect(panel.getByRole("heading", { name: "Discussions", level: 1 }).or(panel.getByText("Discussions").first())).toBeVisible();
    await expect(panel.getByRole("button", { name: "New discussion" })).toHaveCount(0);

    await tab("Assessments");
    await expect(panel.getByText("TOTAL ASSESSMENTS")).toBeVisible();
    await expect(panel.getByRole("button", { name: "Add Assessment" })).toHaveCount(0);

    await tab("Announcements");
    await expect(panel.locator("textarea, [contenteditable=true]")).toHaveCount(0);

    // The gradebook is there to read, with none of the instructor's controls.
    await tab("Grades");
    await expect(page.locator("[data-gradebook-toolbar]")).toBeVisible();

    for (const control of ["[data-open-finalize]", "[data-open-reopen]", "[data-open-curve]", "[data-open-grading-scheme]", "[data-toggle-grade-sheet-menu]"]) {
        await expect(page.locator(control), control).toHaveCount(0);
    }

    // Reports are for reading, so they stay.
    await expect(page.locator("[data-toggle-report-menu]")).toBeVisible();

    await page.locator("[data-open-gradebook-roster]").click();
    await expect(page.locator("[data-gradebook-roster]")).toBeVisible();
    expect(await page.locator("[data-roster-row]").count(), "the students and their grades are listed").toBeGreaterThan(1);
    await expect(page.locator("[data-grade-readout]").first()).toBeVisible();
    await expect(page.locator("[data-open-override]")).toHaveCount(0);
    await expect(page.locator("[data-open-incomplete]")).toHaveCount(0);

    await shot(page, testInfo, "dean-course-view-only");

    // The instructor's own addresses are not the dean's: they lead back to the dean's own dashboard.
    await page.goto("/instructor/courses");
    await expect(page).toHaveURL(/\/dean\/dashboard$/);
});

/** An instructor of IT201-A, on its gradebook. */
async function instructorOnIt201Gradebook(browser) {
    const page = await signedIn(browser, accounts.instructor2, "instructor");
    await page.goto("/instructor/courses");
    await page.locator("a", { hasText: "IT201 · A" }).first().click();
    await openTab(page, "Grades");
    await expect(page.locator("[data-gradebook-toolbar]")).toBeVisible();
    await page.locator("[data-open-gradebook-roster]").click();
    await expect(page.locator("[data-gradebook-roster]")).toBeVisible();

    return page;
}

const rosterRow = (page, name) => page.locator("[data-roster-row]").filter({ hasText: name });

/** The row of a table on a dean, chairperson or instructor list, found by what it says. */
const listRow = (page, text) => page.getByRole("row").filter({ hasText: text });

/** Asks for an override of one student's final grade, through the gradebook dialog. */
async function requestOverride(page, name, percentage, reason) {
    const dialog = page.locator("#grade-override-modal");

    await openDialog(rosterRow(page, name).locator("[data-open-override]"), dialog);
    await expect(dialog.locator("[data-override-student-name]")).toContainText(name);
    await dialog.locator("[data-override-percentage]").fill(String(percentage));
    await dialog.locator("textarea[data-override-reason]").fill(reason);
    await dialog.locator("[data-override-submit]").click();
    await expect(dialog).toBeHidden();
    await expect(rosterRow(page, name).locator("[data-open-override]")).toHaveAttribute("data-request-status", /.+/);
}

test("TC-DEAN-04 An override is a request: the chairperson endorses it, the dean decides it, and the grade changes only then", async ({ browser }, testInfo) => {
    test.slow(); // a journey through several people's screens: three times the usual limit
    // 1. The instructor asks to change two final grades. Nothing changes yet.
    const instructor = await instructorOnIt201Gradebook(browser);
    await requestOverride(instructor, "Reyes, Pedro", 75, "Late project excused: medical certificate on file.");
    await requestOverride(instructor, "Villanueva, Michael", 99, "Counted the wrong rubric row.");
    await expect(rosterRow(instructor, "Reyes, Pedro")).toContainText("50%");
    await expect(rosterRow(instructor, "Reyes, Pedro").locator("[data-open-override]")).toHaveAttribute("data-request-status", "awaiting_chair");

    // 2. It is the chairperson's to endorse first: the dean has nothing to decide yet.
    const dean = await signedIn(browser, DEAN_WHO_TEACHES);
    await dean.goto("/dean/approvals");
    await expect(dean.getByText("Nothing waiting for you")).toBeVisible();
    await shot(dean, testInfo, "approvals-empty-before-endorsement");

    // 3. The chairperson of the sub-department endorses both.
    const chair = await signedIn(browser, CHAIRPERSON, "chairperson");
    await chair.goto("/chairperson/override-requests");
    await expect(listRow(chair, "Pedro Reyes")).toBeVisible();

    for (const name of ["Pedro Reyes", "Michael Villanueva"]) {
        await listRow(chair, name).getByRole("button", { name: "Endorse", exact: true }).click();
        await chair.locator("[data-dialog]:not([hidden])").getByRole("button", { name: "Endorse", exact: true }).click();
        await expect(listRow(chair, name).getByRole("button", { name: "Endorse", exact: true })).toHaveCount(0);
    }

    // 4. Now both wait for the dean, each with what the decision is judged by.
    await dean.goto("/dean/approvals");
    await expect(dean.locator("[data-pending-count]")).toHaveText("(2)");
    await shot(dean, testInfo, "approvals-waiting");

    await listRow(dean, "Pedro Reyes").getByRole("button", { name: "Review" }).click();
    const review = dean.locator("[data-dialog]:not([hidden])");
    await expect(review).toContainText("Pedro Reyes");
    await expect(review).toContainText("Late project excused");
    await expect(review).toContainText("Endorsed by");

    // The decision says what it will do before it is taken.
    await expect(review).toContainText("Approve 75% for Pedro Reyes in IT201 A? This replaces the calculated 50%.");
    await shot(dean, testInfo, "approval-dialog");
    await review.getByRole("button", { name: "Approve 75%" }).click();
    await expect(dean.locator("[data-pending-count]")).toHaveText("(1)");

    // 5. Rejecting needs a reason, and says who reads it.
    await listRow(dean, "Michael Villanueva").getByRole("button", { name: "Review" }).click();
    const second = dean.locator("[data-dialog]:not([hidden])");
    await expect(second).toContainText("will read this");
    await second.getByRole("button", { name: "Reject", exact: true }).click();
    await expect(second.locator("[data-form-error]:not([hidden]), [data-field-error]:not([hidden])").first()).toBeVisible();
    await second.locator("textarea[name=reason]").fill("The rubric was scored as published; no error found.");
    await second.getByRole("button", { name: "Reject", exact: true }).click();
    await expect(dean.getByText("Nothing waiting for you")).toBeVisible();

    // 6. Both stay on record.
    await dean.getByRole("link", { name: /^Decided/ }).click();
    await expect(listRow(dean, "Pedro Reyes")).toContainText("Approved");
    await expect(listRow(dean, "Michael Villanueva")).toContainText("Rejected");
    await shot(dean, testInfo, "approvals-decided");

    // 7. Only the approved one changed a grade; a student sees the grade in force, never a request.
    const approved = await signedIn(browser, accounts.student2, "student");
    await approved.goto("/student/courses");
    await approved.locator("a", { hasText: "IT201 · A" }).first().click();
    await approved.getByRole("tab", { name: "Grades", exact: true }).click();
    await expect(approved.getByRole("tabpanel")).toContainText("75%");
    await expect(approved.getByRole("tabpanel")).not.toContainText(/request/i);

    const rejected = await signedIn(browser, accounts.student, "student");
    await rejected.goto("/student/courses");
    await rejected.locator("a", { hasText: "IT201 · A" }).first().click();
    await rejected.getByRole("tab", { name: "Grades", exact: true }).click();
    await expect(rejected.getByRole("tabpanel")).toContainText("94%");
    await expect(rejected.getByRole("tabpanel")).not.toContainText("99%");
});

test("TC-DEAN-05 The dean signs off a section's final grades, and only the dean can reopen the sign-off", async ({ browser }, testInfo) => {
    test.slow(); // a journey through several people's screens: three times the usual limit
    // The instructor finalizes the grades of IT101-B (nobody is enrolled, so nothing stands in the way).
    const instructor = await signedIn(browser, accounts.instructor, "instructor");
    await instructor.goto("/instructor/courses");
    await instructor.locator("a", { hasText: "IT101 · B" }).first().click();
    await openTab(instructor, "Grades");
    await expect(instructor.locator("[data-gradebook-toolbar]")).toBeVisible();
    await openDialog(instructor.locator("[data-open-finalize]"), instructor.locator("#grade-finalize-modal"));
    await instructor.locator("[data-finalize-confirm]").click();
    await expect(instructor.locator("[data-finalized-banner]")).toBeVisible();

    // The dean's board: what is ready, and what is blocked and why.
    const dean = await signedIn(browser, DEAN_WHO_TEACHES);
    await dean.goto("/dean/signoff");
    const section = (label) => dean.locator("li").filter({ hasText: label });
    await expect(section("IT101 B")).toContainText("Ready");
    await expect(section("IT101 A")).toContainText("Blocked");
    await expect(section("IT101 A")).toContainText("has not finalized the grades yet");
    await shot(dean, testInfo, "signoff-board");

    // Signing off says what it does, then asks for the password again.
    await section("IT101 B").getByRole("button", { name: "Sign off", exact: true }).click();
    const confirm = dean.locator("[data-dialog]:not([hidden])");
    await expect(confirm).toContainText("only you can");
    await confirm.getByRole("button", { name: "Sign off", exact: true }).click();

    const password = dean.locator("#reauth-dialog");
    await expect(password).toBeVisible();
    await password.locator("input[name=password]").fill(DEAN_WHO_TEACHES.password);
    await password.getByRole("button", { name: "Confirm" }).click();

    await expect(section("IT101 B")).toContainText("Signed off");
    await shot(dean, testInfo, "signoff-done");

    // The instructor now sees the grades as signed off, with no way to reopen them.
    await instructor.reload();
    await openTab(instructor, "Grades");
    await expect(instructor.locator("[data-signed-off-tag]")).toBeVisible();
    await expect(instructor.locator("[data-open-reopen]")).toHaveCount(0);

    // Reopening is the dean's, and needs a reason.
    await section("IT101 B").getByRole("button", { name: /^Reopen/ }).click();
    const reopen = dean.locator("[data-dialog]:not([hidden])");
    await reopen.getByRole("button", { name: "Reopen", exact: true }).click();
    // The reason is a required field: the browser stops the empty form and the dialog stays open.
    await expect(reopen.locator("textarea[name=reason]:invalid")).toHaveCount(1);
    await expect(reopen).toBeVisible();
    await reopen.locator("textarea[name=reason]").fill("A late correction came in from the registrar.");
    await reopen.getByRole("button", { name: "Reopen", exact: true }).click();

    await expect(section("IT101 B")).toContainText("Ready");

    // The instructor can reopen their own finalization again, now that it is not signed off.
    await instructor.reload();
    await openTab(instructor, "Grades");
    await expect(instructor.locator("[data-signed-off-tag]")).toHaveCount(0);
    await expect(instructor.locator("[data-open-reopen]")).toBeVisible();
});

test("TC-DEAN-06 A dean sends a follow-up and the instructor answers it and closes it", async ({ browser }, testInfo) => {
    test.slow(); // a journey through several people's screens: three times the usual limit
    const dean = await signedIn(browser, DEAN_WHO_TEACHES);
    await dean.goto("/dean/follow-ups");
    await expect(dean.getByText("No open follow-ups")).toBeVisible();
    await shot(dean, testInfo, "follow-ups-empty");

    // Start from a course; the form opens for it, with only the people who can be asked about it.
    await dean.getByRole("button", { name: "New follow-up" }).click();
    const course = dean.locator("#follow-up-pick select");
    await course.selectOption(await course.locator("option", { hasText: "IT201 A" }).getAttribute("value"));
    await dean.locator("#follow-up-pick").getByRole("button", { name: "Continue" }).click();

    const form = dean.locator("#follow-up-new");
    await expect(form).toBeVisible();
    await expect(form.locator("select[name=recipient_id]")).toContainText("(chairperson)");
    await expect(form.locator("select[name=recipient_id]")).toContainText("(instructor)");

    await form.locator("select[name=recipient_id]").selectOption({ label: "Joseph Garcia (instructor)" });
    await form.locator("select[name=reason]").selectOption("overdue_grading");
    await form.locator("input[name=title]").fill("IT201-A: one submission is still ungraded");
    await form.locator("textarea[name=message]").fill("Please grade the waiting submission this week so the grades can be finalized.");
    await shot(dean, testInfo, "follow-up-form");
    await form.getByRole("button", { name: "Send follow-up" }).click();

    await expect(listRow(dean, "IT201-A: one submission is still ungraded")).toBeVisible();

    // The instructor finds it under Requests, answers, and closes it.
    const instructor = await signedIn(browser, accounts.instructor2, "instructor");
    await instructor.goto("/instructor/follow-ups");
    await expect(instructor.getByRole("heading", { name: "Requests", level: 1 })).toBeVisible();
    await listRow(instructor, "IT201-A: one submission is still ungraded").getByRole("button", { name: "Open" }).click();

    const thread = instructor.locator("[data-dialog]:not([hidden])");
    await expect(thread).toContainText("Please grade the waiting submission");
    await thread.locator("textarea[name=body]").fill("Grading it tomorrow morning.");
    await thread.getByRole("button", { name: "Send reply" }).click();
    await expect(listRow(instructor, "IT201-A: one submission is still ungraded")).toBeVisible();

    await listRow(instructor, "IT201-A: one submission is still ungraded").getByRole("button", { name: "Open" }).click();
    await expect(instructor.locator("[data-dialog]:not([hidden])")).toContainText("Grading it tomorrow morning.");
    await instructor.locator("[data-dialog]:not([hidden])").getByRole("button", { name: "Mark as done" }).click();
    await expect(instructor.getByText("Nothing is waiting for you")).toBeVisible();

    // The dean sees it done, with the reply.
    await dean.goto("/dean/follow-ups?tab=done");
    await expect(listRow(dean, "IT201-A: one submission is still ungraded")).toContainText("Done");
    await listRow(dean, "IT201-A: one submission is still ungraded").getByRole("button", { name: "Open" }).click();
    await expect(dean.locator("[data-dialog]:not([hidden])")).toContainText("Grading it tomorrow morning.");
});

test("TC-DEAN-07 A dean posts a department announcement that its people read, and ends it", async ({ browser }, testInfo) => {
    const dean = await signedIn(browser, DEAN_WHO_TEACHES);
    await dean.goto("/dean/announcements");
    await expect(dean.getByText("No announcements showing")).toBeVisible();
    await shot(dean, testInfo, "announcements-empty");

    await dean.getByRole("button", { name: "New announcement" }).click();
    const form = dean.locator("#announcement-new");
    await form.locator("select[name=scope]").selectOption({ index: 0 });
    await form.locator("select[name=audience]").selectOption("everyone");
    await form.locator("input[name=title]").fill("Enrolment week schedule");
    await form.locator("textarea[name=message]").fill("The College is open for enrolment questions from Monday to Friday.");
    await form.getByRole("button", { name: "Post announcement" }).click();

    const row = listRow(dean, "Enrolment week schedule");
    await expect(row).toBeVisible();
    await expect(row).toContainText("Everyone");
    await shot(dean, testInfo, "announcement-posted");

    // A student of the department reads it, in the Announcements app, under its own level.
    const student = await signedIn(browser, accounts.student1, "student");
    await student.goto("/student/announcements");
    const level = student.locator('[data-level="department"]');
    await expect(level).toContainText("Enrolment week schedule");
    await expect(level).toContainText("enrolment questions");

    // The other department's dean has no sign of it.
    const outsider = await signedIn(browser, DEAN);
    await outsider.goto("/dean/announcements");
    await expect(outsider.getByText("Enrolment week schedule")).toHaveCount(0);

    // Ending it takes it away at once, and keeps it in the dean's Ended list.
    await row.getByRole("button", { name: "End" }).click();
    await dean.locator("[data-dialog]:not([hidden])").getByRole("button", { name: "End announcement" }).click();
    await expect(dean.getByText("No announcements showing")).toBeVisible();

    await student.reload();
    await expect(student.locator('[data-level="department"]')).not.toContainText("Enrolment week schedule");
    await expect(student.locator('[data-level="department"]')).toContainText("Nothing from your department right now");

    await dean.getByRole("link", { name: /^Ended/ }).click();
    await expect(listRow(dean, "Enrolment week schedule")).toBeVisible();
});

test("TC-DEAN-08 Nothing outside the dean's department can be reached", async ({ browser }, testInfo) => {
    // The addresses of things inside CBM, found the way a dean of CBM would find them.
    const inside = await signedIn(browser, DEAN_WHO_TEACHES);
    const course = await deanCourseAddress(inside, "IT201", "A");
    await inside.goto("/dean/teaching-load");
    const instructor = await inside.locator('a[href*="/dean/teaching-load/"]').first().getAttribute("href");
    await inside.goto("/dean/monitor-department?compare=program");
    const program = await inside.locator("[data-program-link]").first().getAttribute("href");

    expect(course, "a CBM course address").toMatch(/\/dean\/courses\/\d+\//);
    expect(instructor, "a CBM instructor address").toMatch(/\/dean\/teaching-load\/\d+/);
    expect(program, "a CBM program address").toMatch(/\/dean\/monitor-department\/programs\/\d+/);

    // The dean of the General department asks for each of them: not found, the same page as for
    // an address that was never there, so nothing says that it exists elsewhere.
    const outside = await signedIn(browser, DEAN);

    for (const address of [course, instructor, program]) {
        const response = await outside.goto(address);
        expect(response?.status(), address).toBe(404);
        await expect(outside.getByRole("heading", { name: /couldn.t find that page/i })).toBeVisible();
    }

    await shot(outside, testInfo, "address-not-found");

    // A made-up one looks exactly the same.
    const missing = await outside.goto("/dean/courses/999999/nothing");
    expect(missing?.status()).toBe(404);
    await expect(outside.getByRole("heading", { name: /couldn.t find that page/i })).toBeVisible();

    // And another role's area is not theirs at all.
    await outside.goto("/instructor/dashboard");
    await expect(outside).toHaveURL(/\/dean\/dashboard$/);
});

// Every screen of the dean's area, by address and by the heading it opens with.
const DEAN_SCREENS = [
    ["/dean/dashboard", /Dean dashboard/],
    ["/dean/courses", /Courses/],
    ["/dean/approvals", /Grade overrides/],
    ["/dean/signoff", /sign-off/i],
    ["/dean/follow-ups", /Follow-ups/],
    ["/dean/announcements", /Announcements/],
    ["/dean/chairperson-review", /Chairperson review/],
    ["/dean/teaching-load", /Teaching load/],
    ["/dean/monitor-department", /Monitor Department/],
];

const WIDTHS = [360, 768, 1440];

const slug = (path) => path.replace(/^\/dean\//, "").replace(/\W+/g, "-");

/** What axe finds that a person would really be stopped by (the WCAG A and AA rules, serious or critical). */
async function seriousProblems(page) {
    const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa"]).analyze();

    return results.violations
        .filter((v) => v.impact === "serious" || v.impact === "critical")
        .map((v) => `${v.id}: ${v.help} (${v.nodes.length} place${v.nodes.length === 1 ? "" : "s"}, first: ${v.nodes[0].target.join(" ")})`);
}

/** How far the page is wider than the window: anything above 0 means a sideways scrollbar for the whole page. */
const overflowOf = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

test("TC-DEAN-09 The dean's screens work with the keyboard alone", async ({ browser }) => {
    const page = await signedIn(browser, DEAN_WHO_TEACHES);

    // From the top of the dashboard, Tab reaches the Approvals tile, and Enter opens it.
    let reached = false;

    for (let press = 0; press < 40 && !reached; press++) {
        await page.keyboard.press("Tab");
        reached = await page.evaluate(() => document.activeElement?.tagName === "A" && document.activeElement.textContent.trim() === "Approvals");
    }

    expect(reached, "Tab reaches the Approvals tile").toBe(true);

    // It shows where the focus is.
    const marked = await page.evaluate(() => {
        const style = getComputedStyle(document.activeElement);

        return style.outlineStyle !== "none" || style.boxShadow !== "none";
    });
    expect(marked, "the focused tile is visibly marked").toBe(true);

    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/\/dean\/approvals$/);

    // A dialog opens from the keyboard, takes the focus inside, closes with Escape and gives the focus back.
    await page.goto("/dean/announcements");
    const opener = page.getByRole("button", { name: "New announcement" });
    await opener.focus();
    await page.keyboard.press("Enter");

    const dialog = page.locator("#announcement-new");
    await expect(dialog).toBeVisible();
    expect(await dialog.evaluate((el) => el.contains(document.activeElement)), "the focus is inside the dialog").toBe(true);

    // Tab stays inside it however many times it is pressed.
    for (let press = 0; press < 12; press++) {
        await page.keyboard.press("Tab");
    }
    expect(await dialog.evaluate((el) => el.contains(document.activeElement)), "Tab does not leave the dialog").toBe(true);

    await page.keyboard.press("Escape");
    await expect(dialog).toBeHidden();
    await expect(opener).toBeFocused();
});

test("TC-DEAN-10 Every screen of the dean fits 360, 768 and 1440 pixels and passes the accessibility check", async ({ browser }, testInfo) => {
    test.slow(); // nine screens, three widths each

    const page = await signedIn(browser, DEAN_WHO_TEACHES);

    for (const [path, heading] of DEAN_SCREENS) {
        for (const width of WIDTHS) {
            // Each width is a device of its own, so the page is loaded at that width, as a phone would load it.
            await page.setViewportSize({ width, height: 900 });
            const response = await page.goto(path);
            expect(response?.status(), path).toBe(200);
            await expect(page.locator("h1").first(), path).toContainText(heading);

            expect(await overflowOf(page), `${path} must not scroll sideways at ${width}px`).toBeLessThanOrEqual(0);
            await shot(page, testInfo, `${slug(path)}-${width}`);

            // At the narrowest and at the widest: a different layout can fail differently.
            if (width !== 768) {
                expect(await seriousProblems(page), `${path} at ${width}px`).toEqual([]);
            }
        }
    }
});

test("TC-DEAN-11 The states of a dean with nothing to show, no department, or a read-only institution", async ({ browser }, testInfo) => {
    test.slow();

    // 1. A department with no courses: every list says what it is for and what will appear in it.
    const empty = await signedIn(browser, DEAN);

    for (const [path, text] of [
        ["/dean/approvals", "Nothing waiting for you"],
        ["/dean/signoff", "No sections to sign off"],
        ["/dean/follow-ups", "No open follow-ups"],
        ["/dean/announcements", "No announcements showing"],
        ["/dean/chairperson-review", "Nothing to review"],
        ["/dean/courses", "No courses"],
        ["/dean/teaching-load", "When your department has courses running in a term"],
        ["/dean/monitor-department", "Nothing to monitor yet"],
    ]) {
        await empty.goto(path);
        await expect(empty.getByText(text).first(), path).toBeVisible();
        expect(await seriousProblems(empty), `${path}, empty`).toEqual([]);
        await shot(empty, testInfo, `empty-${slug(path)}`);
    }

    // The dashboard of a dean with nothing waiting says so, and still passes the check.
    await empty.goto("/dean/dashboard");
    await expect(empty.locator("[data-attention-empty]")).toBeVisible();
    expect(await seriousProblems(empty), "empty dashboard").toEqual([]);
    await shot(empty, testInfo, "empty-dashboard");

    // 2. A dean who has lost the department gets an explanation, not an error and not any data.
    const deanOne = "ai.secret = 'dean1@mentora.test'";
    const endDeanship = "UPDATE dean_assignments da JOIN auth_identities ai ON ai.user_id = da.dean_user_id AND ai.type = 'email_password' SET da.status = 'ended', da.active_marker = NULL, da.ended_at = NOW() WHERE " + deanOne;
    const restoreDeanship = "UPDATE dean_assignments da JOIN auth_identities ai ON ai.user_id = da.dean_user_id AND ai.type = 'email_password' SET da.status = 'active', da.active_marker = 1, da.ended_at = NULL WHERE " + deanOne;

    runSql(endDeanship);

    try {
        const lost = await (await browser.newContext()).newPage();
        await submitLoginForm(lost, DEAN.email, DEAN.password);

        for (const [path] of DEAN_SCREENS) {
            const response = await lost.goto(path);
            expect(response?.status(), path).toBe(200);
            await expect(lost.getByText("You are not assigned to a department yet"), path).toBeVisible();
        }

        expect(await seriousProblems(lost), "no department").toEqual([]);
        await shot(lost, testInfo, "no-department");
    } finally {
        runSql(restoreDeanship);
    }

    // 3. A read-only institution: everything can be looked at, and the page says so before anyone tries to change anything.
    runSql("UPDATE institutions SET status = 'read_only' WHERE id = 1");

    try {
        const dean = await signedIn(browser, DEAN_WHO_TEACHES);
        await expect(dean.locator("[data-read-only-banner]")).toContainText("Read-only");

        for (const [path, heading] of DEAN_SCREENS) {
            const response = await dean.goto(path);
            expect(response?.status(), path).toBe(200);
            await expect(dean.locator("h1").first(), path).toContainText(heading);
        }

        await shot(dean, testInfo, "read-only-institution");

        // Changing something is refused, and nothing is saved.
        await dean.goto("/dean/announcements");
        await dean.getByRole("button", { name: "New announcement" }).click();
        const form = dean.locator("#announcement-new");
        await form.locator("input[name=title]").fill("Posted while read-only");
        await form.locator("textarea[name=message]").fill("This must not be saved.");
        await form.getByRole("button", { name: "Post announcement" }).click();
        await expect(form.locator("[data-form-error]:not([hidden])")).toBeVisible();
        await shot(dean, testInfo, "read-only-refusal");

        await dean.goto("/dean/announcements");
        await expect(dean.getByText("Posted while read-only")).toHaveCount(0);
    } finally {
        runSql("UPDATE institutions SET status = 'active' WHERE id = 1");
    }
});
