// Cases: docs/test-cases/p2-institutional-administrator.md (TC-IAA-01 to TC-IAA-06).
// What cuts across the institutional administrator's area rather than one screen: who can
// open it, whether every main screen is reachable from the Apps menu and passes an automated
// accessibility check, whether the keyboard alone is enough, and what a read-only
// institution still allows. The screen-by-screen cases are in institution-structure.spec.js,
// and the invited administrator and the lapse to read-only are TC-SA-05 and TC-SA-06 in
// system-admin.spec.js.
// Data: the demo institutional administrator of institution 1 (DemoUsersSeeder).
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { runSql } from "./support/database.js";
import { accounts, submitLoginForm } from "./support/sign-in.js";

const MAIN_SCREENS = [
    ["/institution/dashboard", null],
    ["/institution/structure", "Structure"],
    ["/institution/terms", "Terms"],
    ["/institution/offerings", "Offerings"],
    ["/institution/people", "People"],
    ["/institution/reports", "Reports"],
    ["/institution/settings", "Institution settings"],
    ["/institution/subscription", "Subscription"],
    ["/institution/integrations", "Integrations"],
    ["/institution/audit", "Audit log"],
];

async function signInAsInstitutionAdmin(page) {
    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);
    await expect(page).toHaveURL(/\/institution\/dashboard$/);
}

test("TC-IAA-01 Only the institution's administrator can open the area, and a signed-out visitor is sent to log in", async ({ page }) => {
    for (const account of [accounts.student, accounts.instructor]) {
        await page.context().clearCookies();
        await submitLoginForm(page, account.email, account.password);
        await expect(page).toHaveURL(/\/(student|instructor)\/dashboard$/);

        for (const [path] of MAIN_SCREENS) {
            await page.goto(path);
            await expect(page, `${account.email} must not reach ${path}`).not.toHaveURL(/\/institution(\/|$)/);
        }
    }

    await page.context().clearCookies();
    await page.goto("/institution/people");
    await expect(page).toHaveURL(/\/login$/);
});

test("TC-IAA-02 A record that is not the institution's answers as if it did not exist", async ({ page }) => {
    await signInAsInstitutionAdmin(page);

    // An id that belongs to no one here: a missing page, not a refusal that confirms it exists elsewhere.
    for (const path of ["/institution/enrolments/offering/999999", "/institution/courses/999999/instructor/modules", "/institution/offerings/999999/copy"]) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(404);
    }
});

test("TC-IAA-03 Every link of the sidebar opens its screen", async ({ page }) => {
    await signInAsInstitutionAdmin(page);

    const tiles = [
        ["Academics", /\/institution\/structure$/],
        ["Terms", /\/institution\/terms$/],
        ["Offerings", /\/institution\/offerings$/],
        ["People", /\/institution\/people$/],
        ["Reports", /\/institution\/reports$/],
        ["Settings", /\/institution\/settings$/],
        ["Subscription", /\/institution\/subscription$/],
        ["Integrations", /\/institution\/integrations$/],
        ["Audit log", /\/institution\/audit$/],
        ["Data export", /\/institution\/export$/],
    ];

    for (const [name, url] of tiles) {
        await page.goto("/institution/dashboard");
        await page.getByRole("navigation", { name: "Institution" }).getByRole("link", { name, exact: true }).click();
        await expect(page, name).toHaveURL(url);
    }
});

test("TC-IAA-04 The main screens pass an automated accessibility check", async ({ page }) => {
    await signInAsInstitutionAdmin(page);

    for (const [path, heading] of MAIN_SCREENS) {
        await page.goto(path);
        await expect(page.getByRole("heading", { level: 1 }).first(), path).toBeVisible();

        if (heading !== null) {
            await expect(page.getByRole("heading", { level: 1 }).first(), path).toContainText(heading);
        }

        const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa"]).analyze();
        const serious = results.violations.filter((v) => v.impact === "serious" || v.impact === "critical");

        expect(
            serious.map((v) => `${v.id}: ${v.help} (${v.nodes.length})`),
            `${path} has serious accessibility problems`,
        ).toEqual([]);
    }
});

test("TC-IAA-05 A dialog can be opened, filled and left with the keyboard alone, and focus comes back", async ({ page }) => {
    await signInAsInstitutionAdmin(page);
    await page.goto("/institution/people");

    const invite = page.getByRole("button", { name: "Invite a person" });
    await invite.focus();
    await page.keyboard.press("Enter");

    const dialog = page.getByRole("dialog", { name: "Invite a person" });
    await expect(dialog).toBeVisible();

    // Focus is inside the dialog, and Tab keeps it there.
    await expect(dialog.locator(":focus")).toHaveCount(1);

    for (let i = 0; i < 12; i++) {
        await page.keyboard.press("Tab");
        await expect(dialog.locator(":focus")).toHaveCount(1);
    }

    await page.keyboard.press("Escape");
    await expect(dialog).toBeHidden();
    await expect(invite).toBeFocused();
});

test("TC-IAA-06 A read-only institution can still be looked at and exported, but nothing offers to change it", async ({ page }) => {
    runSql("UPDATE institutions SET status = 'read_only' WHERE id = 1");

    try {
        await signInAsInstitutionAdmin(page);
        await expect(page.locator("[data-read-only-banner]")).toContainText("Read-only");

        await page.goto("/institution/people");
        await expect(page.getByRole("heading", { name: "People", level: 1 })).toBeVisible();
        await expect(page.getByRole("button", { name: "Invite a person" })).toHaveCount(0);

        await page.goto("/institution/offerings");
        await expect(page.getByRole("button", { name: /^Add an offering/ })).toHaveCount(0);

        // Looking still works: reports, the audit log and the export page.
        for (const path of ["/institution/reports", "/institution/audit", "/institution/export"]) {
            const response = await page.goto(path);
            expect(response?.status(), path).toBe(200);
        }
    } finally {
        runSql("UPDATE institutions SET status = 'active' WHERE id = 1");
    }
});

test("TC-IAA-07 Dark mode is switched with the header button, is kept on the next visit, and the main screens still pass the accessibility check", async ({ page }) => {
    await signInAsInstitutionAdmin(page);

    const html = page.locator("html");
    const toggle = page.getByRole("button", { name: /Dark mode is off/ });

    await expect(html).not.toHaveAttribute("data-theme", "dark");
    await toggle.click();
    await expect(html).toHaveAttribute("data-theme", "dark");
    await expect(page.getByRole("button", { name: /Dark mode is on/ })).toHaveAttribute("aria-pressed", "true");

    // Kept for the next page and for a fresh load, before the page paints.
    await page.goto("/institution/people");
    await expect(html).toHaveAttribute("data-theme", "dark");

    for (const [path] of MAIN_SCREENS) {
        await page.goto(path);
        await expect(html, path).toHaveAttribute("data-theme", "dark");

        const results = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa"]).analyze();
        const serious = results.violations.filter((v) => v.impact === "serious" || v.impact === "critical");

        expect(serious.map((v) => `${v.id}: ${v.help} (${v.nodes.length})`), `${path} in dark mode`).toEqual([]);
    }

    // And back.
    await page.getByRole("button", { name: /Dark mode is on/ }).click();
    await expect(html).not.toHaveAttribute("data-theme", "dark");
});
