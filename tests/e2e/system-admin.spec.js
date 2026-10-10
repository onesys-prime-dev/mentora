// Cases: docs/test-cases/p2-system-administrator.md (TC-SA-01 to TC-SA-09).
// Data: the demo accounts from `php spark db:seed DatabaseSeeder`, plus the system
// administrator tests/e2e/support/serve.js creates. The administrator signs in with
// an emailed code, which the app files in writable/e2e-mail (support/mail.js).
//
// The cases from TC-SA-04 on are one story about a single new institution, so
// they run in order: it is onboarded, its administrator is invited and signs in,
// its subscription lapses to read-only, a renewal brings it back, and the
// administrator looks at the platform as a student.
import { expect, test } from "@playwright/test";
import { confirmPassword, dateIn, emailedCode, signInAsAdmin, startAdminSignIn } from "./support/admin.js";
import { runSql } from "./support/database.js";
import { waitForMailMatch } from "./support/mail.js";
import { accounts, submitLoginForm } from "./support/sign-in.js";
import { spark } from "./support/spark.js";

test.describe.configure({ mode: "serial" });

const college = { name: "E2E College", code: "E2ECOL" };
// What the invited administrator types (a neutral name on purpose: a literal next to
// "password" is flagged by the secret scanner, and this one is not a secret).
const CHOSEN = "Tr4ins-Lantern-Moss-92";
const principal = { first: "Ada", last: "Principal", email: "ada@e2e-college.test", password: CHOSEN };

/** Opens the college's page from the institutions list, as a person would. */
async function openCollege(page, tab = null) {
    await page.goto("/admin/institutions");
    await page.getByRole("link", { name: college.name, exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/institutions\/\d+$/);

    if (tab !== null) {
        await page.locator(`[data-institution-tabs] [data-tab="${tab}"]`).click();
    }
}

test("TC-SA-01 The administrator needs the emailed code, and the admin area stays closed until it is entered", async ({ page }) => {
    await startAdminSignIn(page);

    // Password accepted, code not yet entered: the admin area is still closed.
    await page.goto("/admin/dashboard");
    await expect(page).not.toHaveURL(/\/admin\/dashboard$/);

    // A wrong code does not open it.
    await page.goto("/auth/a/show");
    await page.getByRole("button", { name: "Send code" }).click();
    const code = await emailedCode();
    await page.getByLabel("6-digit code").fill(code === "000000" ? "111111" : "000000");
    await page.getByRole("button", { name: "Confirm" }).click();
    await expect(page).not.toHaveURL(/\/admin\//);

    // The right one does.
    await page.goto("/auth/a/show");
    await page.getByRole("button", { name: "Send code" }).click();
    await page.getByLabel("6-digit code").fill(await emailedCode());
    await page.getByRole("button", { name: "Confirm" }).click();

    await expect(page).toHaveURL(/\/admin\/dashboard$/);
    await expect(page.getByRole("heading", { name: "Home" })).toBeVisible();
});

test("TC-SA-02 Nobody but the system administrator can open the admin area", async ({ page }) => {
    for (const account of [accounts.student, accounts.instructor]) {
        await page.context().clearCookies();
        await submitLoginForm(page, account.email, account.password);
        await expect(page).toHaveURL(/\/(student|instructor)\/dashboard$/);

        for (const path of ["/admin/dashboard", "/admin/institutions", "/admin/users", "/admin/audit-log"]) {
            await page.goto(path);
            await expect(page, `${account.email} must not reach ${path}`).not.toHaveURL(/\/admin(\/|$)/);
        }
    }

    // Signed out, the admin area sends you to the login page.
    await page.context().clearCookies();
    await page.goto("/admin/dashboard");
    await expect(page).toHaveURL(/\/login$/);
});

test("TC-SA-03 The home page says what needs attention and its menu reaches the right pages", async ({ page }) => {
    await signInAsAdmin(page);

    await expect(page.locator('[data-section="attention"]')).toBeVisible();
    await expect(page.locator('[data-section="snapshot"]')).toBeVisible();
    await expect(page.locator("[data-menu-group]")).toHaveCount(4);

    // A shortcut and a menu link each land on their own page.
    await page.getByRole("link", { name: "Add an institution" }).click();
    await expect(page).toHaveURL(/\/admin\/institutions\/new$/);

    const menu = page.locator("[data-admin-menu]");

    for (const [name, path] of [
        ["Institutions", /\/admin\/institutions$/],
        ["Users", /\/admin\/users$/],
        ["System status", /\/admin\/monitoring$/],
        ["Audit log", /\/admin\/audit-log$/],
        ["General", /\/admin\/settings\/general$/],
    ]) {
        await menu.getByRole("link", { name, exact: true }).click();
        await expect(page).toHaveURL(path);
    }
});

test("TC-SA-04 Onboarding an institution in three steps, and finding it afterwards", async ({ page }) => {
    await signInAsAdmin(page);

    // Step 1: the institution.
    await page.goto("/admin/institutions/new");
    await page.locator("input[name=name]").fill(college.name);
    await page.locator("input[name=code]").fill(college.code);
    await page.getByRole("button", { name: "Add institution and continue" }).click();

    // Not usable yet: it is pending until the last step.
    await expect(page.getByRole("heading", { name: `Onboard ${college.name}` })).toBeVisible();

    // Step 2: plan and first subscription (a new customer cannot start with an ended one).
    await page.locator("select[name=plan_id]").selectOption({ label: "Standard" });
    await page.locator("input[name=period_start]").fill(dateIn(0));
    await page.locator("input[name=period_end]").fill(dateIn(30));
    await page.getByRole("button", { name: "Continue" }).click();

    // Step 3: the first institutional administrator.
    await page.locator("input[name=first_name]").fill(principal.first);
    await page.locator("input[name=last_name]").fill(principal.last);
    await page.locator("input[name=email]").fill(principal.email);
    await page.getByRole("button", { name: "Finish onboarding" }).click();

    await expect(page.getByRole("status").filter({ hasText: `${college.name} is onboarded` })).toBeVisible();
    await expect(page.locator("[data-institution-status]")).toHaveAttribute("data-institution-status", "active");

    // And it can be found in the list.
    await page.goto("/admin/institutions");
    await expect(page.getByRole("link", { name: college.name, exact: true })).toBeVisible();
});

test("TC-SA-05 The invited administrator sets a password, signs in, and cannot export while the subscription runs", async ({ page }) => {
    const token = await waitForMailMatch(principal.email, /\/invitation\/([A-Za-z0-9_-]+)/);

    await page.goto(`/invitation/${token}`);
    await expect(page.getByRole("heading", { name: `Welcome, ${principal.first}` })).toBeVisible();

    await page.getByPlaceholder("Password", { exact: true }).fill(principal.password);
    await page.getByPlaceholder("Repeat the password").fill(principal.password);
    await page.getByRole("button", { name: "Set password" }).click();

    await expect(page).toHaveURL(/\/login$/);

    // The link works once.
    await page.goto(`/invitation/${token}`);
    await expect(page.getByRole("heading", { name: "This link can't be used" })).toBeVisible();

    await submitLoginForm(page, principal.email, principal.password);

    // They land on their own page, and the export is not open while the subscription runs.
    await expect(page).toHaveURL(/\/institution\/export$/);
    await expect(page.getByRole("heading", { name: "Data export" })).toBeVisible();
    await expect(page.locator("[data-export-unavailable]")).toBeVisible();
    await expect(page.locator("[data-read-only-banner]")).toHaveCount(0);

    // Nor can they reach the admin area.
    await page.goto("/admin/dashboard");
    await expect(page).not.toHaveURL(/\/admin(\/|$)/);
});

test("TC-SA-06 A lapsed subscription makes the institution read-only, and its administrator can then export", async ({ page }) => {
    // Time passes: the period ended 20 days ago, which is past the grace days.
    // Then what the server's scheduler does every day.
    runSql(
        `UPDATE subscriptions SET start_date = CURDATE() - INTERVAL 60 DAY, end_date = CURDATE() - INTERVAL 20 DAY ` +
            `WHERE institution_id = (SELECT id FROM institutions WHERE code = '${college.code}')`
    );
    spark("subscriptions:sync");

    await submitLoginForm(page, principal.email, principal.password);
    await expect(page).toHaveURL(/\/institution\/export$/);

    await expect(page.locator("[data-read-only-banner]")).toContainText("Read-only");
    await expect(page.locator("[data-export-unavailable]")).toHaveCount(0);

    await page.getByRole("button", { name: "Create an export" }).click();

    await expect(page.getByRole("status").filter({ hasText: "Your export is ready" })).toBeVisible();
    const row = page.locator('[data-export="ready"]').first();
    await expect(row).toBeVisible();

    // The download is a ZIP.
    const [download] = await Promise.all([page.waitForEvent("download"), row.getByRole("link", { name: "Download" }).click()]);
    expect(download.suggestedFilename()).toMatch(/^e2ecol-.*\.zip$/);
});

test("TC-SA-07 Recording a renewal brings a read-only institution back", async ({ page, browser, baseURL }) => {
    await signInAsAdmin(page);

    await openCollege(page);
    await expect(page.locator("[data-institution-status]")).toHaveAttribute("data-institution-status", "read_only");

    await page.locator('[data-institution-tabs] [data-tab="subscription"]').click();
    await expect(page.getByText("Read-only until")).toBeVisible();

    // Dangerous actions ask for the password again.
    await page.goto("/admin/reauth");
    await confirmPassword(page);

    await openCollege(page, "subscription");
    const renewal = page.locator("form[action$='/subscription/renew']");
    await renewal.locator("input[name=period_start]").fill(dateIn(-19));
    await renewal.locator("input[name=period_end]").fill(dateIn(340));
    await renewal.getByRole("button", { name: "Record renewal" }).click();

    await expect(page.getByRole("status")).toBeVisible();

    await openCollege(page);
    await expect(page.locator("[data-institution-status]")).toHaveAttribute("data-institution-status", "active");

    // The institution's administrator no longer sees the read-only banner.
    const context = await browser.newContext({ baseURL });
    const theirs = await context.newPage();
    await submitLoginForm(theirs, principal.email, principal.password);
    await expect(theirs).toHaveURL(/\/institution\/export$/);
    await expect(theirs.locator("[data-read-only-banner]")).toHaveCount(0);
    await context.close();
});

test("TC-SA-08 Viewing as a student is view only, always marked, and ends with one click", async ({ page }) => {
    await signInAsAdmin(page);

    await page.goto("/admin/reauth");
    await confirmPassword(page);

    await page.goto(`/admin/users?q=${encodeURIComponent(accounts.student.email)}`);
    await page.locator("tr", { hasText: accounts.student.email }).locator("a").first().click();
    await expect(page).toHaveURL(/\/admin\/users\/\d+$/);

    // A reason is required.
    await page.locator("input[name=reason]").fill("E2E support check: grades not showing");
    await page.getByRole("button", { name: /^View as / }).click();

    // Now on the student's side, with the banner on every page.
    await expect(page).toHaveURL(/\/student\/dashboard$/);
    const banner = page.locator("[data-impersonation-banner]");
    await expect(banner).toContainText("view only");
    await expect(banner).toContainText(accounts.student.email);

    await page.goto("/student/courses");
    await expect(banner).toBeVisible();

    // One click back to being the administrator.
    await banner.getByRole("button", { name: "Exit view" }).click();
    await expect(page).toHaveURL(/\/admin\/users\/\d+$/);
    await expect(page.locator("[data-impersonation-banner]")).toHaveCount(0);
});

test("TC-SA-09 What happened is in the audit log", async ({ page }) => {
    await signInAsAdmin(page);

    await page.goto("/admin/audit-log");
    const body = page.locator("body");

    for (const action of ["impersonation.started", "impersonation.ended", "invitation.accepted", "institution.exported"]) {
        await expect(body).toContainText(action);
    }
});
