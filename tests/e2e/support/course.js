import { expect } from "@playwright/test";
import { submitLoginForm } from "./sign-in.js";

/** Signs in and waits for the role's dashboard. `role` is "student" or "instructor". */
export async function signInAs(page, account, role) {
    await submitLoginForm(page, account.email, account.password);
    await expect(page).toHaveURL(new RegExp(`/${role}/dashboard$`));
}

/** The card for a course section on the Courses page, such as ("IT102", "A"). */
export function courseCard(page, code, section) {
    return page.locator("a", { hasText: `${code} · ${section}` });
}

/** Opens a course from the Courses page, as a person would. */
export async function openCourse(page, role, code, section) {
    await page.goto(`/${role}/courses`);
    await courseCard(page, code, section).first().click();
    await expect(page).toHaveURL(new RegExp(`/${role}/courses/\\d+/`));
}

/** Opens one of the course page's tabs (Announcements, Modules, ...). */
export async function openTab(page, name) {
    const tab = page.getByRole("tab", { name, exact: true });
    // A click that lands before the page script has attached does nothing, so click again until it takes
    // (a slow machine, such as a CI runner, hits this).
    await expect(async () => {
        await tab.click();
        await expect(tab).toHaveAttribute("aria-selected", "true", { timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
}

/** The address of a course section, read from its card (for "open it directly" steps). */
export async function courseAddress(page, role, code, section) {
    await page.goto(`/${role}/courses`);
    return courseCard(page, code, section).first().getAttribute("href");
}

/** Opens a module from the Modules tab (the course must already be open). */
export async function openModule(page, title) {
    await openTab(page, "Modules");
    const card = page.locator("[data-module-wrapper]").filter({ hasText: title });
    await card.locator("[data-module]").first().click(); // expands the card
    await card.getByRole("button", { name: "Go to module" }).click();
}

/** A learning item's row in an open module, found by its title. */
export function itemRow(page, title) {
    // Exact title only: a locked row's hint ("Complete "X" first") names another item.
    return page.locator("[data-learning-item-wrapper]").filter({ has: page.getByText(title, { exact: true }) });
}

/**
 * Opens a learning item from an open module, expanding its unit first if it is collapsed
 * (the app only opens the unit a student should resume).
 */
export async function openItem(page, title) {
    const row = itemRow(page, title);
    const unit = page.locator("[data-learning-unit-wrapper]").filter({ has: row });
    const panel = unit.locator("[data-learning-unit-content]").first();

    if ((await panel.getAttribute("class"))?.includes("max-h-0")) {
        await unit.locator("[data-learning-unit]").first().click();
    }

    await row.click();
}
