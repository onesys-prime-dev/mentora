// The profile workflows W1-W13 end to end (docs/test-cases/p4-user-profile.md, TC-PRF-13 to TC-PRF-26).
// W3 and W11 are TC-PRF-10 to TC-PRF-12 in profile-photo.spec.js. Every case puts back what it changed.
import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";
import { clearMail, waitForMailMatch } from "./support/mail.js";
import { openCourse, openTab, signInAs } from "./support/course.js";
import { queryValue, runSql } from "./support/database.js";
import { accounts, submitLoginForm } from "./support/sign-in.js";

const idOf = (account) => Number(queryValue(`SELECT user_id FROM auth_identities WHERE secret = '${account.email}'`));

/** Fails on serious or critical WCAG A/AA problems; `include` narrows the scan to one part of the page. */
async function expectAccessible(page, include) {
    const builder = new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa"]);
    const results = await (include ? builder.include(include) : builder).analyze();
    const bad = results.violations.filter((v) => ["serious", "critical"].includes(v.impact));

    expect(bad.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(" ")).join(" | ")}`)).toEqual([]);
}

/** A second browser context that is signed in as `account` (an administrator-style sign in, any role). */
async function otherPage(browser, account) {
    const context = await browser.newContext();
    const page = await context.newPage();

    await submitLoginForm(page, account.email, account.password);
    await expect(page).toHaveURL(/dashboard$/);

    return { context, page };
}

test("TC-PRF-13 W1 Hover an avatar, open the profile, and reach the same profile by its address", async ({ page }) => {
    await signInAs(page, accounts.student1, "student");
    await openCourse(page, "student", "IT101", "A");
    await openTab(page, "Announcements");

    const avatar = page.locator("[data-announcement-card] [data-person-avatar]").first();
    const name = ((await avatar.getAttribute("aria-label")) ?? "").replace(/^Open (.*)'s profile$/, "$1");

    await avatar.hover();
    const card = page.locator("[data-person-popover]");
    await expect(card).toContainText(name);
    await card.getByRole("link", { name: "See more" }).click();

    const dialog = page.locator("#person-dialog");
    await expect(dialog.locator("[data-person-profile]")).toContainText(name);
    const id = await dialog.locator("[data-person-profile]").getAttribute("data-person-profile");
    await expectAccessible(page, "#person-dialog");

    await page.keyboard.press("Escape");
    await page.goto(`/people/${id}`);
    await expect(page.locator("[data-person-profile]")).toContainText(name);
    await expectAccessible(page);
});

test("TC-PRF-14 W2 An instructor finishes their profile from the checklist", async ({ page }) => {
    const id = idOf(accounts.instructor);

    await signInAs(page, accounts.instructor, "instructor");
    try {
        await page.goto("/profile");
        await page.locator('[data-checklist-item="bio"]').click();
        const dialog = page.getByRole("dialog", { name: "Edit about" });
        await dialog.getByLabel("About").fill("I teach networks.");
        await dialog.getByRole("button", { name: "Save" }).click();
        await expect(dialog).toBeHidden();

        await expect(page.locator("[data-profile-about]")).toContainText("I teach networks.");
        await expect(page.locator('[data-checklist-item="bio"]')).toHaveCount(0);
        await expectAccessible(page);
    } finally {
        runSql(`UPDATE user_profiles SET bio = NULL, checklist_dismissed_at = NULL WHERE user_id = ${id}`);
    }
});

test.describe.serial("W4 A correction request", () => {
    const newEmail = "student5.new@mentora.test";
    const student = accounts.student;

    test.afterAll(() => {
        runSql(`UPDATE auth_identities SET secret = '${student.email}' WHERE secret = '${newEmail}'`);
        runSql(`DELETE FROM pending_email_changes WHERE new_email = '${newEmail}'`);
        runSql(`DELETE FROM profile_correction_requests WHERE user_id = ${idOf(student)}`);
        runSql(`DELETE FROM notifications WHERE user_id = ${idOf(student)} AND type LIKE 'profile.%'`);
    });

    test("TC-PRF-15 A student asks to change their email and an administrator approves it", async ({ page, browser }) => {
        clearMail();
        await signInAs(page, student, "student");
        await page.goto("/profile");
        await page.getByRole("button", { name: "Request a correction" }).click();

        const dialog = page.getByRole("dialog", { name: "Request a correction" });
        await dialog.getByLabel("What needs fixing?").selectOption("email");
        await dialog.getByLabel("Should be").fill(newEmail);
        await dialog.getByLabel("Why (your school sees this)").fill("I have a new address.");
        await dialog.getByRole("button", { name: "Send request" }).click();
        await expect(dialog).toBeHidden();
        await expect(page.locator("[data-correction-waiting]")).toContainText("Waiting for your school");

        const { context, page: admin } = await otherPageAsAdmin(browser);
        await admin.goto("/institution/people/requests");
        await expect(admin.getByRole("table", { name: "Correction requests" })).toContainText(newEmail);
        await expectAccessible(admin);

        await admin.getByRole("button", { name: "Review" }).first().click();
        await admin.getByRole("dialog").getByRole("button", { name: "Approve change" }).click();
        await expect(admin.getByText("No requests waiting")).toBeVisible();
        await context.close();
    });

    test("TC-PRF-16 The link sent to the new address changes the sign-in email", async ({ page }) => {
        const link = await waitForMailMatch(newEmail, /(https?:\/\/[^\s"<>]*\/email-change\/[A-Za-z0-9]+)/);

        await page.goto(link);
        await page.getByRole("button", { name: "Confirm new email" }).click();
        await expect(page.locator("[data-email-changed]")).toContainText(newEmail);

        await submitLoginForm(page, newEmail, student.password);
        await expect(page).toHaveURL(/\/student\/dashboard$/);
    });
});

/** The demo institutional administrator, in a context of its own. */
async function otherPageAsAdmin(browser) {
    const context = await browser.newContext();
    const page = await context.newPage();

    await submitLoginForm(page, accounts.institutionAdmin.email, accounts.institutionAdmin.password);

    return { context, page };
}

test("TC-PRF-17 W5 A student changes their password and the old one stops working", async ({ page, browser }) => {
    const account = accounts.student3;
    const changed = "Changed-Pass-93!";
    const change = async (from, to) => {
        await page.goto("/settings");
        await page.locator("input[name=current_password]").fill(from);
        await page.locator("input[name=password]").fill(to);
        await page.locator("input[name=password_confirm]").fill(to);
        await page.getByRole("button", { name: "Change password" }).click();
    };

    await signInAs(page, account, "student");
    try {
        await change(account.password, changed);
        await expect(page.getByText(/password was changed|Password changed/i).first()).toBeVisible();

        const context = await browser.newContext();
        const fresh = await context.newPage();

        await submitLoginForm(fresh, account.email, account.password);
        await expect(fresh).not.toHaveURL(/dashboard$/);
        await context.close();
    } finally {
        await change(changed, account.password);
        await expect(page.getByText(/password was changed|Password changed/i).first()).toBeVisible();
    }
});

test("TC-PRF-18 W6 A student turns two-step sign-in on and off with their password", async ({ page }) => {
    const account = accounts.student4;
    const toggle = () => page.getByRole("switch", { name: "Email me a sign-in code" });

    await signInAs(page, account, "student");
    await page.goto("/settings");
    await expect(toggle()).toHaveAttribute("aria-checked", "false");

    await toggle().click();
    await expect(toggle()).toHaveAttribute("aria-checked", "true");

    await toggle().click();
    const dialog = page.getByRole("dialog", { name: "Turn off two-step sign-in?" });
    await dialog.locator("input[name=password]").fill(account.password);
    await dialog.getByRole("button", { name: "Turn off" }).click();
    await expect(toggle()).toHaveAttribute("aria-checked", "false");
});

test("TC-PRF-19 W7 Signing out of other devices ends the other browser's session", async ({ page, browser }) => {
    const account = accounts.student2;

    await signInAs(page, account, "student");
    const { context, page: other } = await otherPage(browser, account);

    await page.goto("/settings");
    await page.getByRole("button", { name: "Sign out of all other devices" }).first().click();
    await page.getByRole("dialog").getByRole("button", { name: "Sign out of all other devices" }).click();
    await expect(page.getByRole("dialog")).toBeHidden();

    await other.goto("/settings");
    await expect(other).toHaveURL(/\/login/);
    await context.close();

    await page.goto("/settings");
    await expect(page).toHaveURL(/\/settings$/);
});

test("TC-PRF-20 W8 A parent's per-child email choice is saved", async ({ page }) => {
    await submitLoginForm(page, accounts.parent.email, accounts.parent.password);
    await expect(page).toHaveURL(/dashboard$/);
    await page.goto("/settings");

    const email = () => page.locator('input[name="email[]"]').first();
    const before = await email().isChecked();
    const save = async () => {
        await page.getByRole("button", { name: "Save notifications" }).click();
        await expect(page).toHaveURL(/\/settings/);
    };

    try {
        await email().setChecked(!before, { force: true });
        await save();
        expect(await email().isChecked()).toBe(!before);
        await expectAccessible(page);
    } finally {
        await page.goto("/settings");
        await email().setChecked(before, { force: true });
        await save();
    }
    expect(await email().isChecked()).toBe(before);
});

test("TC-PRF-21 W9 An administrator clears someone's bio and they are told", async ({ page, browser }) => {
    const id = idOf(accounts.instructor2);

    runSql(`UPDATE user_profiles SET bio = 'Something unsuitable' WHERE user_id = ${id}`);
    const { context, page: admin } = await otherPageAsAdmin(browser);

    try {
        await admin.goto(`/people/${id}`);
        await expect(admin.locator("[data-profile-about]")).toContainText("Something unsuitable");

        await admin.locator("[data-admin-clear] summary").click();
        await admin.getByRole("button", { name: "Clear bio" }).click();
        await admin.getByRole("dialog").getByRole("button", { name: "Clear bio" }).click();
        await expect(admin.locator("[data-profile-about]")).toHaveCount(0);
        await expect(queryValue(`SELECT COUNT(*) FROM notifications WHERE user_id = ${id} AND type LIKE 'profile.%'`)).not.toBe("0");
    } finally {
        await context.close();
        runSql(`UPDATE user_profiles SET bio = NULL WHERE user_id = ${id}`);
        runSql(`DELETE FROM notifications WHERE user_id = ${id} AND type LIKE 'profile.%'`);
        runSql(`DELETE FROM mail_outbox WHERE user_id = ${id} AND kind = 'field_cleared'`);
    }
});

test("TC-PRF-22 W10 An administrator sets an instructor's home department and it shows on their profile", async ({ browser }) => {
    const id = idOf(accounts.instructor2);
    const before = queryValue(`SELECT COALESCE(home_department_id, '') FROM user_profiles WHERE user_id = ${id}`);
    const { context, page } = await otherPageAsAdmin(browser);

    try {
        await page.goto(`/institution/people?q=${encodeURIComponent(accounts.instructor2.email)}`);
        await page.locator("[data-person-menu] summary").first().click();
        await page.locator("[data-person-menu] button", { hasText: "Edit details" }).first().click();

        const dialog = page.getByRole("dialog", { name: /^Edit / });
        const select = dialog.getByLabel("Home department");
        const label = await select.locator("option:not([value=''])").first().textContent();

        await select.selectOption({ index: 1 });
        await dialog.getByRole("button", { name: "Save" }).click();
        await expect(dialog).toBeHidden();

        await page.goto(`/people/${id}`);
        await expect(page.locator("[data-profile-band]")).toContainText(label.trim());
    } finally {
        await context.close();
        runSql(`UPDATE user_profiles SET home_department_id = ${before === "" ? "NULL" : before} WHERE user_id = ${id}`);
    }
});

test("TC-PRF-23 W12 A parent sees their child's profile without contact details, and an unlinked student is not found", async ({ page }) => {
    const child = idOf(accounts.student);
    const stranger = idOf(accounts.student2);

    await submitLoginForm(page, accounts.parent.email, accounts.parent.password);
    await expect(page).toHaveURL(/dashboard$/);

    await page.goto("/profile");
    await expect(page.locator("[data-profile-children]")).toBeVisible();

    expect((await page.goto(`/people/${child}`))?.status()).toBe(200);
    await expect(page.locator("[data-person-profile]")).toBeVisible();
    await expect(page.locator("[data-profile-contact]")).toHaveCount(0);
    await expect(page.locator("body")).not.toContainText(accounts.student.email);

    expect((await page.goto(`/people/${stranger}`))?.status()).toBe(404);
});

test("TC-PRF-24 W13 A deactivated person shows their basics and a Deactivated badge", async ({ page }) => {
    const id = idOf(accounts.student1);

    await signInAs(page, accounts.instructor, "instructor");
    runSql(`UPDATE users SET active = 0 WHERE id = ${id}`);
    try {
        await page.goto(`/people/${id}`);
        await expect(page.locator("[data-profile-deactivated]")).toBeVisible();
        await expect(page.locator("[data-profile-contact]")).toHaveCount(0);
    } finally {
        runSql(`UPDATE users SET active = 1 WHERE id = ${id}`);
    }
});

test("TC-PRF-25 The profile, settings, person and modal screens pass the accessibility check", async ({ page }) => {
    await signInAs(page, accounts.instructor, "instructor");
    await page.goto("/profile");
    await expectAccessible(page);
    await page.goto("/settings");
    await expectAccessible(page);
    await page.goto(`/people/${idOf(accounts.student1)}`);
    await expectAccessible(page);
});

// Review sizes; asserts only that nothing scrolls sideways (no committed baselines).
test.describe("TC-PRF-26 No horizontal overflow", () => {
    for (const [width, height] of [[360, 800], [768, 1024], [1440, 900]]) {
        test(`/profile, /settings and the modal at ${width}`, async ({ page }) => {
            const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

            await page.setViewportSize({ width, height });
            await signInAs(page, accounts.student1, "student");

            for (const path of ["/profile", "/settings"]) {
                await page.goto(path);
                expect(await overflow(), path).toBeLessThanOrEqual(0);
            }

            await openCourseAnnouncements(page);
            await page.locator("[data-announcement-card] [data-person-avatar]").first().click();
            await expect(page.locator("#person-dialog [data-person-profile]")).toBeVisible();
            expect(await overflow(), "modal").toBeLessThanOrEqual(0);
        });
    }
});

async function openCourseAnnouncements(page) {
    await openCourse(page, "student", "IT101", "A");
    await openTab(page, "Announcements");
}
