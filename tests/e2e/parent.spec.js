// The parent portal end to end (docs/test-cases/p2-parent.md, TC-PRT-01 to TC-PRT-09): a parent
// follows the students linked to them, read-only. Demo data: parent1 follows student1 (Juan, in
// IT101-A and IT102-A) and student5 (Michael, in IT101-A and IT201-A); parent2 follows nobody.
import { expect, test } from "@playwright/test";
import { signInAs } from "./support/course.js";
import { accounts, submitLoginForm } from "./support/sign-in.js";

/** The ids of the children in the dashboard's switcher, by name. */
async function childIds(page) {
    const options = await page.locator("[data-child-switcher] option").evaluateAll((all) =>
        all.map((o) => ({ name: o.textContent.trim(), id: Number(o.value.match(/children\/(\d+)/)[1]) })),
    );

    return Object.fromEntries(options.map((o) => [o.name.split(" ")[0], o.id]));
}

test("TC-PRT-01 A parent lands on the dashboard and can switch between their children", async ({ page }) => {
    await signInAs(page, accounts.parent, "parent");

    await expect(page.getByRole("heading", { name: "Courses this term" })).toBeVisible();
    const switcher = page.locator("[data-child-switcher]");
    await expect(switcher.locator("option")).toHaveCount(2);
    await expect(page.getByText("Introduction to Computing").first()).toBeVisible();

    const ids = await childIds(page);
    await switcher.selectOption({ label: await switcher.locator("option").last().innerText() });
    await expect(page).toHaveURL(new RegExp(`/parent/children/(${ids.Juan}|${ids.Michael})$`));
});

test("TC-PRT-02 A child's course opens read-only with exactly four tabs", async ({ page }) => {
    await signInAs(page, accounts.parent, "parent");
    await page.locator("[data-parent-course] a").first().click();

    await expect(page).toHaveURL(/\/parent\/children\/\d+\/courses\/\d+\//);
    const tabs = page.getByRole("tab");
    await expect(tabs).toHaveText(["Overview", "Announcements", "Assessments", "Grades"]);
    await expect(page.getByText("View only").first()).toBeVisible();

    await page.getByRole("tab", { name: "Grades", exact: true }).click();
    await expect(page.getByRole("tab", { name: "Grades", exact: true })).toHaveAttribute("aria-selected", "true");
    // The student's own tabs that a parent must not get are not on the page.
    await expect(page.getByRole("tab", { name: "Modules" })).toHaveCount(0);
    await expect(page.getByRole("tab", { name: "Discussions" })).toHaveCount(0);
});

test("TC-PRT-03 A course the child is not in answers 404, whoever the child is", async ({ page }) => {
    await signInAs(page, accounts.parent, "parent");
    const ids = await childIds(page);

    // Juan is in Computer Programming 1 (IT102-A); Michael is not.
    await page.goto(`/parent/children/${ids.Juan}`);
    const href = await page.getByRole("link", { name: "Computer Programming 1" }).first().getAttribute("href");
    const offering = href.match(/courses\/(\d+)\//)[1];

    const response = await page.goto(`/parent/children/${ids.Michael}/courses/${offering}/computer-programming-1/overview`);
    expect(response.status()).toBe(404);
});

test("TC-PRT-04 A student who is not the parent's child answers 404 by direct address", async ({ page }) => {
    await signInAs(page, accounts.parent, "parent");
    const linked = Object.values(await childIds(page));

    for (let id = 1; id <= 40; id++) {
        if (linked.includes(id)) {
            continue;
        }

        const response = await page.goto(`/parent/children/${id}`);
        expect(response.status(), `child ${id}`).toBe(404);
    }
});

test("TC-PRT-05 The calendar is read-only", async ({ page }) => {
    await signInAs(page, accounts.parent, "parent");
    await page.goto("/parent/calendar");

    const calendar = page.locator("#calendar-app");
    await expect(calendar).toHaveAttribute("data-role", "parent");
    await expect(page.locator("[data-calendar-label]")).not.toBeEmpty();
    await expect(page.getByRole("button", { name: /Add (Assessment|Announcement|Reminder)/ })).toHaveCount(0);
    await expect(page.getByText("Reminder", { exact: true })).toHaveCount(0);
});

test("TC-PRT-06 A parent chooses which child they are emailed about, and it is kept", async ({ page }) => {
    await signInAs(page, accounts.parent, "parent");
    await page.goto("/parent/profile");

    const michael = page.getByLabel(/Michael Villanueva/);
    await michael.uncheck();
    await page.getByRole("button", { name: "Save choices" }).click();

    await page.goto("/parent/profile");
    await expect(michael).not.toBeChecked();

    // Put it back so the next run starts the same (the e2e database is reseeded anyway).
    await michael.check();
    await page.getByRole("button", { name: "Save choices" }).click();
});

test("TC-PRT-07 A parent with no linked student gets an explanation, not an error", async ({ page }) => {
    await signInAs(page, accounts.parentUnlinked, "parent");

    await expect(page.getByText("You are not linked to a student yet")).toBeVisible();
});

test("TC-PRT-08 Other roles cannot open the parent area", async ({ page }) => {
    await submitLoginForm(page, accounts.student.email, accounts.student.password);
    await expect(page).toHaveURL(/\/student\/dashboard$/);

    await page.goto("/parent/dashboard");
    await expect(page).not.toHaveURL(/\/parent\//);
});

test("TC-PRT-09 A student sees which parents can see their progress", async ({ page }) => {
    await signInAs(page, accounts.student, "student");
    await page.goto("/student/profile");

    const parents = page.locator("[data-student-parents]");
    await expect(parents).toContainText("Roberto");
    await expect(parents).toContainText("cannot change anything");
});

test("TC-PRT-10 The institution's administrator sees who follows which students", async ({ page }) => {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await page.goto("/institution/people/parents");

    await expect(page.getByText("Roberto").first()).toBeVisible();
    const linked = page.locator("[data-linked-student]");
    await expect(linked).toHaveCount(2);
    await expect(linked.filter({ hasText: "Juan" })).toHaveCount(1);
    await expect(linked.filter({ hasText: "Michael" })).toHaveCount(1);
    await expect(page.locator("[data-no-students]")).toHaveCount(1); // the second parent
});
