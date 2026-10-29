// Cases: docs/test-cases/p1-critical-journeys.md (TC-AUTH-01 to TC-AUTH-04).
// Data: the demo accounts from `php spark db:seed DatabaseSeeder`.
import { expect, test } from "@playwright/test";
import { accounts, submitLoginForm } from "./support/sign-in.js";

test("TC-AUTH-01 Student signs in and lands on the student dashboard", async ({ page }) => {
    // Signed out, the home page sends you to the login page.
    await page.goto("/");
    await expect(page).toHaveURL(/\/login$/);

    await submitLoginForm(page, accounts.student.email, accounts.student.password);

    await expect(page).toHaveURL(/\/student\/dashboard$/);
    await expect(page.locator("[data-profile-role]")).toHaveText("Student");
});

test("TC-AUTH-02 Instructor signs in and lands on the instructor dashboard", async ({ page }) => {
    await submitLoginForm(page, accounts.instructor.email, accounts.instructor.password);

    // The instructor dashboard, not the student one.
    await expect(page).toHaveURL(/\/instructor\/dashboard$/);
    await expect(page.locator("[data-profile-role]")).toHaveText("Instructor");
});

test("TC-AUTH-03 A wrong password is refused and reveals nothing about the account", async ({ page }) => {
    const banner = page.getByRole("alert").filter({ hasText: "Unable to log you in." });

    // A real email with the wrong password.
    await submitLoginForm(page, accounts.student.email, "wrong-password");
    await expect(page).toHaveURL(/\/login$/);
    await expect(banner).toBeVisible();
    const forRealEmail = (await banner.textContent()).trim();
    expect(forRealEmail).toBe("Unable to log you in. Please check your credentials.");

    // Still not signed in: a protected page sends you back to login.
    await page.goto("/student/dashboard");
    await expect(page).toHaveURL(/\/login$/);

    // An email with no account gets exactly the same message, so the page never
    // reveals which emails are registered.
    await submitLoginForm(page, "nobody@mentora.test", "wrong-password");
    await expect(page).toHaveURL(/\/login$/);
    await expect(banner).toBeVisible();
    expect((await banner.textContent()).trim()).toBe(forRealEmail);

    // The right password still works straight after the failed attempts.
    await submitLoginForm(page, accounts.student.email, accounts.student.password);
    await expect(page).toHaveURL(/\/student\/dashboard$/);
});

test("TC-AUTH-04 Signing out ends the session", async ({ page }) => {
    await submitLoginForm(page, accounts.student.email, accounts.student.password);
    await expect(page).toHaveURL(/\/student\/dashboard$/);

    await page.getByRole("button", { name: "Account menu" }).click();
    await page.getByRole("button", { name: "Log out" }).click();
    await expect(page).toHaveURL(/\/login$/);

    // A protected page now sends you back to login.
    await page.goto("/student/courses");
    await expect(page).toHaveURL(/\/login$/);
});
