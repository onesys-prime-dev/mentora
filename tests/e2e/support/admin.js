// Signing in as the system administrator: password, then the 6-digit code the
// app emails (read from the mail sink).
import { expect } from "@playwright/test";
import { clearMail, waitForMailMatch } from "./mail.js";
import { adminAccount, submitLoginForm } from "./sign-in.js";

/** Password step, then the "send me a code" step. Leaves the page on "Enter your code". */
export async function startAdminSignIn(page) {
    clearMail();
    await submitLoginForm(page, adminAccount.email, adminAccount.password);

    await expect(page.getByRole("heading", { name: "One more step" })).toBeVisible();
    await page.getByRole("button", { name: "Send code" }).click();
    await expect(page.getByRole("heading", { name: "Enter your code" })).toBeVisible();
}

/** The code the app emailed the administrator. */
export function emailedCode() {
    return waitForMailMatch(adminAccount.email, /<h1>\s*(\d{6})\s*<\/h1>/);
}

/** The whole sign-in, ending on the admin home. */
export async function signInAsAdmin(page) {
    await startAdminSignIn(page);
    await page.getByLabel("6-digit code").fill(await emailedCode());
    await page.getByRole("button", { name: "Confirm" }).click();

    await expect(page).toHaveURL(/\/admin\/dashboard$/);
}

/** Answers the "confirm your password" page that guards dangerous actions. */
export async function confirmPassword(page) {
    await expect(page.getByRole("heading", { name: "Confirm your password" })).toBeVisible();
    await page.locator("input[name=password]").fill(adminAccount.password);
    await page.getByRole("button", { name: "Confirm" }).click();
}

/** YYYY-MM-DD for today plus `days` (negative for the past). */
export function dateIn(days) {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}
