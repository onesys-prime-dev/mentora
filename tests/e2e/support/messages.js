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
        "DELETE FROM message_restrictions",
        "UPDATE institution_users SET is_minor = 0 WHERE is_minor = 1",
        // The institution's "students messaging each other" scope and default quiet hours, if a case left them set.
        "DELETE FROM settings WHERE `key` LIKE '%studentDmEnabled%' OR `key` LIKE '%studentMessagingScope%' OR `key` LIKE '%defaultQuietStart%' OR `key` LIKE '%defaultQuietEnd%'",
        "DELETE FROM feature_flag_overrides WHERE flag_key = 'messages'",
    ]) {
        runSql(statement);
    }
}

/**
 * Sets who students may message as the institution's administrator does, and waits for the server: true = whole
 * institution, false = off, or a scope value ("off", "course", "institution").
 */
export async function setStudentMessages(page, on) {
    const scope = typeof on === "string" ? on : on ? "institution" : "off";

    await page.goto("/institution/settings/messages");
    // The radio is sr-only under its label (a segmented switch), so the label is what takes the click.
    await page.locator(`label:has(input[name=scope][value='${scope}'])`).check();

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

    // The bubble shows at once under a local id; its menu only opens once the server has given it a real one.
    await expect(thread(page).locator("[data-id]", { hasText: text }).last()).toHaveAttribute("data-id", /^\d+$/);
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

/**
 * "+ New", then each [search, name] picked in turn (each becomes a chip), the group name if any, and Create group;
 * leaves the new group open. Two or more picks make a group; one would be a direct message.
 */
export async function startGroupWith(page, picks, title = "") {
    await page.locator("[data-role=new]").click();

    for (const [search, name] of picks) {
        await page.locator("#messages-picker").fill(search);
        await page.locator("[data-role=picker-results] button", { hasText: name }).first().click();
        await expect(page.locator("[data-role=picker-chips] li", { hasText: name })).toBeVisible();
    }

    if (title !== "") {
        await page.locator("[data-role=group-title]").fill(title);
    }

    await page.locator("[data-role=picker-start]").click();
}

/** What the picker says after a search that finds nobody. */
export async function searchFindsNobody(page, search) {
    await page.locator("[data-role=new]").click();
    await page.locator("#messages-picker").fill(search);
    await expect(page.locator("[data-role=picker-state]")).toHaveText("No one found.");
    await expect(page.locator("[data-role=picker-results] button")).toHaveCount(0);
    await page.locator("#messages-new [data-dialog-close]").first().click();
}

/** "+ New", search, and expect a result for `name` (optionally mentioning `text`); closes the picker again. */
export async function searchFinds(page, search, name, text = null) {
    await page.locator("[data-role=new]").click();
    await page.locator("#messages-picker").fill(search);

    const results = page.locator("[data-role=picker-results] button", { hasText: name });
    await expect((text ? results.filter({ hasText: text }) : results).first()).toBeVisible();
    await page.locator("#messages-new [data-dialog-close]").first().click();
}

/** Marks a demo student as a minor (true) or not (false), as the People screen's action does. */
export function setMinor(who, minor) {
    runSql(
        "UPDATE institution_users iu JOIN auth_identities ai ON ai.user_id = iu.user_id " +
            `SET iu.is_minor = ${minor ? 1 : 0} WHERE ai.secret = '${who.email}'`,
    );
}

/** An administrator's restriction on a demo person for a day ("no_new" or "no_send"); restrict(who, null) lifts it. */
export function restrict(who, kind) {
    const user = `(SELECT user_id FROM auth_identities WHERE secret = '${who.email}' LIMIT 1)`;

    if (kind === null) {
        runSql(`DELETE FROM message_restrictions WHERE user_id = ${user}`);

        return;
    }

    runSql(
        "INSERT INTO message_restrictions (institution_id, user_id, kind, source, starts_at, ends_at, created_at) " +
            `SELECT iu.institution_id, iu.user_id, '${kind}', 'admin', NOW(), NOW() + INTERVAL 1 DAY, NOW() FROM institution_users iu WHERE iu.user_id = ${user} LIMIT 1`,
    );
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
