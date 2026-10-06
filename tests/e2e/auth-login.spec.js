// Cases: docs/test-cases/p1-critical-journeys.md (TC-AUTH-01).
// Data: the demo accounts from `php spark db:seed DatabaseSeeder`.
import { expect, test } from "@playwright/test";

test("TC-AUTH-01 Student signs in and lands on the student dashboard", async ({ page }) => {
    // Signed out, the home page sends you to the login page.
    await page.goto("/");
    await expect(page).toHaveURL(/\/login$/);

    await page.getByPlaceholder("Email").fill("student5@mentora.test");
    await page.getByPlaceholder("Password").fill("Student123!");
    await page.getByRole("button", { name: "Login", exact: true }).click();

    await expect(page).toHaveURL(/\/student\/dashboard$/);
});
