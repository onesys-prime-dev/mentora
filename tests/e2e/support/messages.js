// Helpers for the Messages cases (docs/test-cases/p2-messages.md, tests/e2e/messages.spec.js).
import { expect } from "@playwright/test";
import { runSql } from "./database.js";
import { spark } from "./spark.js";
import { submitLoginForm } from "./sign-in.js";

/** Demo people who are not in sign-in.js's list (names are from DemoUsersSeeder). */
export const people = {
    chairperson: { email: "chairperson1@mentora.test", password: "Chairperson123!", name: "Antonio Fernandez" },
    dean: { email: "dean1@mentora.test", password: "Dean123!", name: "Daniel Reyes" },
    instructor1: { email: "instructor1@mentora.test", password: "Instructor123!", name: "Maria Santos" },
    instructor2: { email: "instructor2@mentora.test", password: "Instructor123!", name: "Joseph Garcia" },
    parent: { email: "parent1@mentora.test", password: "Parent123!", name: "Roberto Cruz" },
    institutionAdmin: { email: "institution.admin@mentora.test", password: "Institution123!", name: "Ines Administrador" },
    juan: { email: "student1@mentora.test", password: "Student123!", name: "Juan Dela Cruz" },
    michael: { email: "student5@mentora.test", password: "Student123!", name: "Michael Villanueva" },
    pedro: { email: "student2@mentora.test", password: "Student123!", name: "Pedro Reyes" },
};

/**
 * Switches Messages on for everyone (the flag ships off) and makes the chats of the seeded offerings. The
 * demo seeder writes offerings straight into the tables, so nothing has created their chats yet; the
 * sync command is what an operator runs before turning the flag on (docs/messages.md).
 */
export function enableMessages() {
    runSql(
        "INSERT INTO feature_flag_overrides (flag_key, institution_id, is_global, enabled, note, created_at, updated_at) " +
            "VALUES ('messages', NULL, 1, 1, 'end-to-end tests', NOW(), NOW()) ON DUPLICATE KEY UPDATE enabled = 1",
    );
    syncChats();
}

/**
 * Leaves the database as the other specs expect it: Messages off again (it adds a tab to the parent's course
 * page and renames the Inbox tile), and nothing left over from these cases (notifications, chats, blocks,
 * reports, settings). The cases share one database, so this is what keeps them from changing later specs.
 */
export function disableMessages() {
    for (const statement of [
        "DELETE FROM notifications WHERE type = 'message'",
        "DELETE FROM message_reports",
        "DELETE FROM conversations",
        "DELETE FROM user_blocks",
        "DELETE FROM message_preferences",
        // The institution's "students messaging each other" switch, if a case left it off.
        "DELETE FROM settings WHERE `key` LIKE '%studentDmEnabled%'",
        "DELETE FROM feature_flag_overrides WHERE flag_key = 'messages'",
    ]) {
        runSql(statement);
    }
}

/** Switches students messaging each other on or off as the institution's administrator does, and waits for the server. */
export async function setStudentMessages(page, on) {
    await page.goto("/institution/settings/messages");
    await page.locator(`input[name=enabled][value='${on ? 1 : 0}']`).check();

    const saved = page.waitForResponse((response) => response.url().includes("/institution/settings/messages") && response.request().method() === "POST");

    await page.getByRole("button", { name: "Save" }).click();
    expect((await saved).ok()).toBe(true);
}

/** What the server's scheduler does every night: create the missing chats and repair who is in them. */
export function syncChats() {
    spark("messages:sync-course-chats");
}

/** Signs a person in, in a browser of their own, and opens Messages with the list loaded. */
export async function openMessagesAs(browser, who, options = {}) {
    const context = await browser.newContext(options);
    const page = await context.newPage();

    await submitLoginForm(page, who.email, who.password);
    await expect(page).not.toHaveURL(/\/login/);
    await goToMessages(page);

    return { context, page };
}

export async function goToMessages(page, path = "/messages") {
    await page.goto(path);
    await expect(page.locator("#messages-app")).toBeVisible();
    await expect(page.locator("[data-role=list] [data-state=loading]")).toBeHidden();
}

/** A row of the conversation list, by a word in its name. */
export function rowFor(page, text) {
    return page.locator("[data-role=rows] button", { hasText: text });
}

/**
 * A row of the list by its conversation id. Course rows carry no section ("IT101 · Introduction to Computing"),
 * and the instructor teaches both IT101-A and IT101-B, so by name their IT101 rows are two: the student, who is only
 * in A, reads the id from their one row and the instructor opens that same chat by id.
 */
export function rowById(page, id) {
    return page.locator(`[data-role=rows] button[data-id="${id}"]`);
}

/** The messages shown in the open thread. */
export function thread(page) {
    return page.locator("[data-role=messages]");
}

/** Types into the composer and sends; waits until the message is in the thread. */
export async function sendMessage(page, text) {
    await page.locator("[data-role=body]").fill(text);
    await page.locator("[data-role=send]").click();
    await expect(thread(page).getByText(text)).toBeVisible();
}

/** "+ New", search by name, pick the person whose result mentions `role`; leaves their thread open. */
export async function startThreadWith(page, search, name, role = null) {
    await page.locator("[data-role=new]").click();
    await page.locator("#messages-picker").fill(search);

    const results = page.locator("[data-role=picker-results] button", { hasText: name });
    const result = role ? results.filter({ hasText: role }).first() : results.first();

    await result.click();
    await expect(page.locator("[data-role=head-name]")).toContainText(name);
}

/** What the picker says after a search that finds nobody. */
export async function searchFindsNobody(page, search) {
    await page.locator("[data-role=new]").click();
    await page.locator("#messages-picker").fill(search);
    await expect(page.locator("[data-role=picker-state]")).toHaveText("No one found.");
    await expect(page.locator("[data-role=picker-results] button")).toHaveCount(0);
    await page.locator("#messages-new [data-dialog-close]").first().click();
}

/** The open context menu (chat or message). */
export function menuOf(page) {
    return page.locator("[data-role=menu]");
}

/** One item of the open menu, by its action id. */
export function menuItem(page, action) {
    return menuOf(page).locator(`[data-action="${action}"]`);
}

/** Right-clicks a conversation row and returns the menu. Never forced: a click that lands on something else should fail. */
export async function chatMenu(page, rowText) {
    await rowFor(page, rowText).first().click({ button: "right" });
    await expect(menuOf(page)).toBeVisible();

    return menuOf(page);
}

/** A bubble of the open thread, by its text. */
export function bubble(page, text) {
    return thread(page).locator("[data-id]", { hasText: text }).first();
}

/** Right-clicks the bubble that holds `text` and returns the menu. */
export async function messageMenu(page, text) {
    await bubble(page, text).click({ button: "right" });
    await expect(menuOf(page)).toBeVisible();

    return menuOf(page);
}

/** The number in the Messages badge of the page's navigation (0 when it is hidden or missing). */
export async function badgeCount(page) {
    const badge = page.locator("[data-messages-badge]");

    if ((await badge.count()) === 0) {
        return 0;
    }

    return Number((await badge.first().textContent()) ?? "0") || 0;
}
