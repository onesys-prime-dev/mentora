// Chairperson role end to end (branch test/e2e-chairperson, the chairperson
// plan's closing branch): sign-in lands on the chairperson dashboard, a
// dual-role user can switch roles and the whole app follows, a role that
// isn't held is denied rather than erroring, and ordinary instructor flows
// are unaffected by any of this.
import { expect, test } from "@playwright/test";
import { submitLoginForm } from "./support/sign-in.js";

const CHAIRPERSON = { email: "chairperson1@mentora.test", password: "Chairperson123!" };
const DUAL_ROLE = { email: "chairperson2@mentora.test", password: "Chairperson123!" };
const INSTRUCTOR = { email: "instructor1@mentora.test", password: "Instructor123!" };

const profileMenuButton = (page) => page.locator("#profile-menu-button");
const profileRole = (page) => page.locator("[data-profile-role]");
const roleSwitchButton = (page, role) => page.locator(`[data-role-switch-button][data-role="${role}"]`);

/** Opens the account menu, where the role switcher and role label live. */
async function openProfileMenu(page) {
    await profileMenuButton(page).click();
    await expect(page.locator("#profile-menu-panel")).toBeVisible();
}

test("A chairperson signs in and lands on the chairperson dashboard", async ({ page }) => {
    await submitLoginForm(page, CHAIRPERSON.email, CHAIRPERSON.password);

    await expect(page).toHaveURL(/\/chairperson\/dashboard$/);

    await openProfileMenu(page);
    await expect(profileRole(page)).toHaveText("Chairperson");
});

test("A dual-role chairperson can switch to instructor and back, and the app follows", async ({ page }) => {
    await submitLoginForm(page, DUAL_ROLE.email, DUAL_ROLE.password);

    // First login of a dual-role user defaults to chairperson.
    await expect(page).toHaveURL(/\/chairperson\/dashboard$/);

    await openProfileMenu(page);
    await roleSwitchButton(page, "instructor").click();

    await expect(page).toHaveURL(/\/instructor\/dashboard$/);
    await openProfileMenu(page);
    await expect(profileRole(page)).toHaveText("Instructor");
    await expect(roleSwitchButton(page, "instructor")).toBeDisabled();

    // The switch isn't just a redirect: a chairperson-only page is no longer
    // reachable — RoleFilter sends it to "/", which then resolves to
    // whichever dashboard the active role (now instructor) actually owns.
    await page.goto("/chairperson/monitor-program");
    await expect(page).toHaveURL(/\/instructor\/dashboard$/);

    // ...until switching back.
    await page.goto("/instructor/dashboard");
    await openProfileMenu(page);
    await roleSwitchButton(page, "chairperson").click();

    await expect(page).toHaveURL(/\/chairperson\/dashboard$/);
    await page.goto("/chairperson/monitor-program");
    await expect(page.getByRole("heading", { name: "Monitor Program" })).toBeVisible();
});

test("An instructor is denied direct access to a chairperson-only page", async ({ page }) => {
    await submitLoginForm(page, INSTRUCTOR.email, INSTRUCTOR.password);
    await expect(page).toHaveURL(/\/instructor\/dashboard$/);

    await page.goto("/chairperson/monitor-program");

    // RoleFilter sends an unauthorized role to "/" rather than erroring,
    // which then resolves to the role they actually hold.
    await expect(page).toHaveURL(/\/instructor\/dashboard$/);
});

test("A signed-out visitor hitting a chairperson URL directly is sent to log in", async ({ page }) => {
    await page.goto("/chairperson/dashboard");

    await expect(page).toHaveURL(/\/login$/);
});

test("Instructor regression: an ordinary instructor's own dashboard and courses are unaffected", async ({ page }) => {
    await submitLoginForm(page, INSTRUCTOR.email, INSTRUCTOR.password);

    await expect(page).toHaveURL(/\/instructor\/dashboard$/);
    await openProfileMenu(page);
    await expect(profileRole(page)).toHaveText("Instructor");

    await page.goto("/instructor/courses");
    await expect(page.getByRole("heading", { name: "Courses" })).toBeVisible();
});
