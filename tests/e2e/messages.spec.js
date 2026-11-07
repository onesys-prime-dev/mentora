// Messages end to end (docs/test-cases/p2-messages.md, TC-MSG-01 to TC-MSG-14): course chats, direct
// messages, parents, staff groups, the administrator, blocks and reports, quiet hours, a phone screen.
//
// Demo data (DemoUsersSeeder and friends): Michael Villanueva (student5) and Juan Dela Cruz (student1) are
// both in IT101-A; instructor1 (Maria Santos) teaches it; parent1 (Roberto Cruz) follows Juan and Michael;
// chairperson1 (Antonio Fernandez) chairs the IT department; dean1 (Daniel Reyes) oversees the General
// college, which has no courses; the institution's administrator is Ines Administrador.
//
// The cases run in order on one database and each person signs in in a browser of their own. Messages ships
// off, so the first thing is to switch it on and make the chats of the seeded offerings. runSql / spark are
// used for that and for two states a person cannot create on the screen (a student dropped from a course, and
// time passing); everything else is done through the screens.
import { expect, test } from "@playwright/test";
import { startAdminSignIn, emailedCode } from "./support/admin.js";
import { runSql } from "./support/database.js";
import { submitLoginForm } from "./support/sign-in.js";
import {
    badgeCount,
    bubble,
    chatMenu,
    disableMessages,
    enableMessages,
    goToMessages,
    menuItem,
    menuOf,
    messageMenu,
    openMessagesAs,
    people,
    rowById,
    rowFor,
    searchFindsNobody,
    sendMessage,
    setStudentMessages,
    startThreadWith,
    syncChats,
    thread,
} from "./support/messages.js";

test.describe.configure({ mode: "serial" });

test.beforeAll(() => {
    enableMessages();
});

// The other specs share this database and expect Messages off and no chats, notifications or settings left.
test.afterAll(() => {
    disableMessages();
});

/** Opens Introduction to Computing (IT101) for the parent and goes to the "From the instructor" tab. */
async function openInstructorTab(page) {
    await page.goto("/parent/dashboard");
    await page.getByRole("link", { name: "Introduction to Computing" }).first().click();
    await expect(page).toHaveURL(/\/parent\/children\/\d+\/courses\/\d+\//);
    await page.getByRole("tab", { name: "From the instructor", exact: true }).click();
    await expect(page.locator("[data-parent-instructor-feed]")).toBeVisible();
}

test("TC-MSG-01 A student sees the pinned course chat, posts in it, and the instructor sees the post", async ({ browser }) => {
    const student = await openMessagesAs(browser, people.michael);
    const instructor = await openMessagesAs(browser, people.instructor1);

    try {
        const chat = rowFor(student.page, "IT101");
        await expect(chat).toBeVisible();
        // Course chats are pinned at the top of the list.
        await expect(student.page.locator("[data-role=rows] button").first()).toContainText(/IT101|IT201/);

        const id = await chat.getAttribute("data-id");

        await chat.click();
        await expect(student.page.locator("[data-role=head-sub]")).toHaveText("Course chat");
        await sendMessage(student.page, "Is the first quiz open yet?");

        await instructor.page.reload();
        await rowById(instructor.page, id).click();
        await expect(thread(instructor.page).getByText("Is the first quiz open yet?")).toBeVisible();
    } finally {
        await student.context.close();
        await instructor.context.close();
    }
});

test("TC-MSG-02 The instructor locks a course chat; the student can read it but not post", async ({ browser }) => {
    const instructor = await openMessagesAs(browser, people.instructor1);
    const student = await openMessagesAs(browser, people.michael);

    try {
        // The same chat for both: by id, because the instructor has two IT101 rows (sections A and B).
        const id = await rowFor(student.page, "IT101").getAttribute("data-id");

        await rowById(instructor.page, id).click();
        await instructor.page.locator("[data-role=lock]").click();
        await expect(instructor.page.locator("[data-role=lock]")).toHaveText("Unlock chat");
        // The instructor can still post.
        await sendMessage(instructor.page, "Announcement: quiz one opens Friday.");

        await student.page.reload();
        await rowFor(student.page, "IT101").click();
        await expect(thread(student.page).getByText("Announcement: quiz one opens Friday.")).toBeVisible();
        await expect(student.page.locator("[data-role=cannot-post]")).toHaveText("Only the instructor can post here");
        await expect(student.page.locator("[data-role=composer]")).toBeHidden();

        await instructor.page.locator("[data-role=lock]").click();
        await expect(instructor.page.locator("[data-role=lock]")).toHaveText("Lock chat");
    } finally {
        await student.context.close();
        await instructor.context.close();
    }
});

test("TC-MSG-03 A student starts a DM with a classmate; switching student messages off stops new ones and freezes old ones", async ({ browser }) => {
    const student = await openMessagesAs(browser, people.michael);
    const admin = await openMessagesAs(browser, people.institutionAdmin);

    try {
        await startThreadWith(student.page, "Juan", people.juan.name, "Student");
        await sendMessage(student.page, "Want to study for the quiz together?");

        await setStudentMessages(admin.page, false);

        try {
            await goToMessages(student.page);
            // A new student thread is no longer offered...
            await searchFindsNobody(student.page, "Juan");
            // ...and the old one is readable but nobody can add to it.
            await rowFor(student.page, people.juan.name).click();
            await expect(thread(student.page).getByText("Want to study for the quiz together?")).toBeVisible();
            await expect(student.page.locator("[data-role=cannot-post]")).toBeVisible();
            await expect(student.page.locator("[data-role=composer]")).toBeHidden();
        } finally {
            await setStudentMessages(admin.page, true);
        }
    } finally {
        await student.context.close();
        await admin.context.close();
    }
});

test("TC-MSG-04 A student cannot find or reach a chairperson, dean or parent", async ({ browser }) => {
    const student = await openMessagesAs(browser, people.michael);

    try {
        await searchFindsNobody(student.page, "Fernandez");
        await searchFindsNobody(student.page, "Daniel");
        await searchFindsNobody(student.page, "Roberto");
    } finally {
        await student.context.close();
    }
});

test("TC-MSG-05 A student added to a course joins its chat; dropped, they lose it at once", async ({ browser }) => {
    // Enrolling and dropping through the screens is TC-ENR-*; here the enrolment is changed directly and the
    // nightly sync run, which is what keeps chats right when a hook is missed (the hooks have PHP tests).
    const enrol =
        "INSERT INTO enrollments (offering_id, student_user_id, status, enrolled_at, updated_at) " +
        "SELECT o.id, u.id, 'enrolled', NOW(), NOW() FROM course_offerings o JOIN courses c ON c.id = o.course_id " +
        "JOIN auth_identities ai ON ai.secret = 'student2@mentora.test' JOIN users u ON u.id = ai.user_id " +
        "WHERE c.course_code = 'IT101' AND o.section_code = 'A' ON DUPLICATE KEY UPDATE status = 'enrolled'";
    const drop =
        "UPDATE enrollments e JOIN auth_identities ai ON ai.user_id = e.student_user_id AND ai.secret = 'student2@mentora.test' " +
        "JOIN course_offerings o ON o.id = e.offering_id JOIN courses c ON c.id = o.course_id " +
        "SET e.status = 'dropped' WHERE c.course_code = 'IT101' AND o.section_code = 'A'";

    // "1 row" = the enrolment is new, "0 rows" = Pedro already was enrolled; anything else means the demo data has
    // him in some other state, which this case will not touch.
    const outcome = runSql(enrol);
    const created = outcome.includes("(1 row");
    test.skip(!created && !outcome.includes("(0 row"), "Pedro has another enrolment status in IT101-A in the demo data");
    syncChats();

    const student = await openMessagesAs(browser, people.pedro);

    try {
        const chat = rowFor(student.page, "IT101");
        await expect(chat).toBeVisible();
        const id = await chat.getAttribute("data-id");

        runSql(drop);
        syncChats();

        await goToMessages(student.page);
        await expect(rowFor(student.page, "IT101")).toHaveCount(0);

        await goToMessages(student.page, `/messages/${id}`);
        await expect(student.page.getByText("This conversation is no longer available.")).toBeVisible();
    } finally {
        await student.context.close();

        // Put the demo data back as it was: removed if the case made it, enrolled again if he already was.
        runSql(
            created
                ? "DELETE e FROM enrollments e JOIN auth_identities ai ON ai.user_id = e.student_user_id AND ai.secret = 'student2@mentora.test' " +
                      "JOIN course_offerings o ON o.id = e.offering_id JOIN courses c ON c.id = o.course_id WHERE c.course_code = 'IT101' AND o.section_code = 'A'"
                : "UPDATE enrollments e JOIN auth_identities ai ON ai.user_id = e.student_user_id AND ai.secret = 'student2@mentora.test' " +
                      "JOIN course_offerings o ON o.id = e.offering_id JOIN courses c ON c.id = o.course_id " +
                      "SET e.status = 'enrolled' WHERE c.course_code = 'IT101' AND o.section_code = 'A'",
        );
        syncChats();
    }
});

test("TC-MSG-06 A parent messages the child's instructor; the thread is about the child and the student never sees it", async ({ browser }) => {
    const parent = await browser.newContext();
    const page = await parent.newPage();
    await submitLoginForm(page, people.parent.email, people.parent.password);
    await expect(page).not.toHaveURL(/\/login/);

    try {
        await openInstructorTab(page);
        const instructorName = (await page.locator("[data-parent-message]").first().innerText()).replace(/^Message\s+/, "").trim();
        await page.locator("[data-parent-message]").first().click();
        await expect(page).toHaveURL(/\/messages\/\d+$/);

        await expect(page.locator("[data-role=head-name]")).toHaveText(instructorName);
        await expect(page.locator("[data-role=head-sub]")).toContainText("About ");
        await sendMessage(page, "How is my child doing in this course?");

        // The instructor sees it, labelled with the child.
        const account = instructorName === people.instructor2.name ? people.instructor2 : people.instructor1;
        const teacher = await openMessagesAs(browser, account);
        await expect(rowFor(teacher.page, people.parent.name)).toBeVisible();
        await rowFor(teacher.page, people.parent.name).click();
        await expect(thread(teacher.page).getByText("How is my child doing in this course?")).toBeVisible();
        await teacher.context.close();

        // Neither child sees it anywhere.
        for (const child of [people.juan, people.michael]) {
            const student = await openMessagesAs(browser, child);
            await expect(rowFor(student.page, people.parent.name)).toHaveCount(0);
            await student.context.close();
        }
    } finally {
        await parent.close();
    }
});

test("TC-MSG-07 A parent escalates to the child's chairperson, who replies but cannot start with a parent", async ({ browser }) => {
    const parent = await openMessagesAs(browser, people.parent);
    const chair = await openMessagesAs(browser, people.chairperson);

    try {
        await startThreadWith(parent.page, "Fernandez", people.chairperson.name);
        await sendMessage(parent.page, "I would like to talk about my child's progress.");

        await chair.page.reload();
        await rowFor(chair.page, people.parent.name).click();
        await expect(thread(chair.page).getByText("I would like to talk about my child's progress.")).toBeVisible();
        await sendMessage(chair.page, "Of course. I'm looking into it.");

        // A chairperson never starts a thread with a parent: the parent is not even offered.
        await searchFindsNobody(chair.page, "Roberto");
    } finally {
        await parent.context.close();
        await chair.context.close();
    }
});

test("TC-MSG-08 A parent sees the read-only feed of the instructor's posts and none of the students' words", async ({ browser }) => {
    const instructor = await openMessagesAs(browser, people.instructor1);
    const student = await openMessagesAs(browser, people.michael);
    const parent = await browser.newContext();
    const page = await parent.newPage();

    try {
        const id = await rowFor(student.page, "IT101").getAttribute("data-id");

        await rowById(instructor.page, id).click();
        await sendMessage(instructor.page, "Reminder: bring your laptops on Monday.");
        await student.page.reload();
        await rowFor(student.page, "IT101").click();
        await sendMessage(student.page, "I will bring mine and a charger.");

        await submitLoginForm(page, people.parent.email, people.parent.password);
        await expect(page).not.toHaveURL(/\/login/);
        await openInstructorTab(page);

        const feed = page.locator("[data-parent-instructor-feed]");
        await expect(feed.getByText("Reminder: bring your laptops on Monday.")).toBeVisible();
        await expect(feed.getByText("I will bring mine and a charger.")).toHaveCount(0);
        // Read-only: nothing to type into.
        await expect(feed.locator("textarea, input, form")).toHaveCount(0);
    } finally {
        await parent.close();
        await student.context.close();
        await instructor.context.close();
    }
});

test("TC-MSG-09 A chairperson starts a staff group of instructors; a dean cannot add a stranger", async ({ browser }) => {
    const chair = await openMessagesAs(browser, people.chairperson);
    const teacher = await openMessagesAs(browser, people.instructor2);
    const dean = await openMessagesAs(browser, people.dean);

    try {
        await chair.page.locator("[data-role=new-group]").click();
        await chair.page.locator("[data-role=group-title]").fill("IT college planning");
        await chair.page.locator("[data-role=group-search]").fill("Garcia");
        await chair.page.locator("[data-role=group-results] button", { hasText: people.instructor2.name }).click();
        await chair.page.locator("[data-role=group-create]").click();
        await expect(chair.page.locator("[data-role=head-name]")).toHaveText("IT college planning");
        await sendMessage(chair.page, "Please send me your exam dates.");

        await teacher.page.reload();
        await expect(rowFor(teacher.page, "IT college planning")).toBeVisible();
        await rowFor(teacher.page, "IT college planning").click();
        await expect(thread(teacher.page).getByText("Please send me your exam dates.")).toBeVisible();

        // The dean (General college, no courses) has nobody in scope: a student cannot be added.
        await dean.page.locator("[data-role=new-group]").click();
        await dean.page.locator("[data-role=group-search]").fill("Villanueva");
        await expect(dean.page.locator("[data-role=group-note]")).toHaveText("No one found.");
        await expect(dean.page.locator("[data-role=group-results] button")).toHaveCount(0);
    } finally {
        await chair.context.close();
        await teacher.context.close();
        await dean.context.close();
    }
});

test("TC-MSG-10 The institution's administrator messages a student and gets a reply", async ({ browser }) => {
    const admin = await openMessagesAs(browser, people.institutionAdmin);
    const student = await openMessagesAs(browser, people.michael);

    try {
        await startThreadWith(admin.page, "Michael", people.michael.name);
        await sendMessage(admin.page, "Welcome note from the registrar's office.");

        await student.page.reload();
        await rowFor(student.page, people.institutionAdmin.name).click();
        await expect(thread(student.page).getByText("Welcome note from the registrar's office.")).toBeVisible();
        await sendMessage(student.page, "Thank you, noted.");

        // Nobody else can start a thread with the administrator.
        await searchFindsNobody(student.page, "Administrador");

        await admin.page.reload();
        await rowFor(admin.page, people.michael.name).click();
        await expect(thread(admin.page).getByText("Thank you, noted.")).toBeVisible();
    } finally {
        await admin.context.close();
        await student.context.close();
    }
});

test("TC-MSG-11 A student blocks a classmate and reports a message; the administrator reviews the snapshot", async ({ browser }) => {
    const juan = await openMessagesAs(browser, people.juan);
    const michael = await openMessagesAs(browser, people.michael);
    const admin = await openMessagesAs(browser, people.institutionAdmin);

    try {
        // Juan already has a thread with Michael from TC-MSG-03 only if student messages were on; start or reopen it.
        await startThreadWith(juan.page, "Michael", people.michael.name, "Student");
        await sendMessage(juan.page, "This is the message to report.");

        await michael.page.reload();
        await rowFor(michael.page, people.juan.name).click();
        await expect(thread(michael.page).getByText("This is the message to report.")).toBeVisible();

        // Report it (the icons appear while the pointer is over the message).
        await thread(michael.page).getByText("This is the message to report.").hover();
        await michael.page.locator("[data-action=report]").last().click();
        await michael.page.locator("[data-role=report-reason]").selectOption("spam");
        await michael.page.locator("[data-role=report-send]").click();
        await expect(michael.page.locator("[data-role=safety-note]")).toHaveText("Thanks, we'll look into it.");

        // Block him (a second click confirms).
        await michael.page.locator("[data-role=block]").click();
        await michael.page.locator("[data-role=block]").click();
        await expect(michael.page.locator("[data-role=safety-note]")).toContainText("You blocked");
        await expect(michael.page.locator("[data-role=block]")).toHaveText(/^Unblock/);

        // Juan can still read but can no longer post.
        await juan.page.reload();
        await rowFor(juan.page, people.michael.name).click();
        await expect(thread(juan.page).getByText("This is the message to report.")).toBeVisible();
        await expect(juan.page.locator("[data-role=composer]")).toBeHidden();
        await expect(juan.page.locator("[data-role=cannot-post]")).toBeVisible();

        // The administrator reads the snapshot and decides.
        await admin.page.goto("/messages/reports");
        await admin.page.getByRole("link", { name: /Spam/ }).first().click();
        await expect(admin.page.getByText("This is the message to report.")).toBeVisible();
        await admin.page.getByRole("button", { name: "Mark as actioned" }).click();
        await expect(admin.page).toHaveURL(/\/messages\/reports$/);
        await expect(admin.page.getByRole("link", { name: /Spam/ })).toHaveCount(0);
    } finally {
        await juan.context.close();
        await michael.context.close();
        await admin.context.close();
    }
});

test("TC-MSG-12 Quiet hours hold a notification, and the unread count still shows the message", async ({ browser }) => {
    const teacher = await openMessagesAs(browser, people.instructor1);
    const student = await openMessagesAs(browser, people.michael);

    try {
        // Earlier cases left message notifications behind; start this one without any.
        runSql("DELETE FROM notifications WHERE type = 'message'");

        // The whole day, but a minute.
        await teacher.page.locator("[data-role=prefs-open]").click();
        await teacher.page.locator("[data-role=prefs-start]").fill("00:00");
        await teacher.page.locator("[data-role=prefs-end]").fill("23:59");
        await teacher.page.locator("[data-role=prefs-save]").click();
        await expect(teacher.page.locator("[data-role=prefs-note]")).toHaveText("Saved.");
        await teacher.page.locator("#messages-prefs [data-dialog-close]").first().click();

        await startThreadWith(student.page, "Maria", people.instructor1.name, "Instructor");
        await sendMessage(student.page, "May I submit my assignment a day late?");

        // The message is there and counted...
        await teacher.page.reload();
        await expect(rowFor(teacher.page, people.michael.name).locator("[data-unread]")).toBeVisible();

        // ...but no notification was made while the hours last.
        await teacher.page.locator("#notifications-button").click();
        await expect(teacher.page.locator("#notifications-panel")).toBeVisible();
        await expect(teacher.page.locator("#notifications-panel")).not.toContainText("Michael Villanueva sent you a message.");

        // Control: with no quiet hours, a reply notifies the student.
        await teacher.page.keyboard.press("Escape");
        await expect(teacher.page.locator("#notifications-panel")).toBeHidden();
        await teacher.page.locator("[data-role=prefs-open]").click();
        await teacher.page.locator("[data-role=prefs-start]").fill("");
        await teacher.page.locator("[data-role=prefs-end]").fill("");
        await teacher.page.locator("[data-role=prefs-save]").click();
        await expect(teacher.page.locator("[data-role=prefs-note]")).toHaveText("Saved.");
        await teacher.page.locator("#messages-prefs [data-dialog-close]").first().click();
        await rowFor(teacher.page, people.michael.name).click();
        await sendMessage(teacher.page, "Yes, until Friday.");

        await student.page.reload();
        await student.page.locator("#notifications-button").click();
        await expect(student.page.locator("#notifications-panel")).toContainText("Maria Santos sent you a message.");
    } finally {
        await teacher.context.close();
        await student.context.close();
    }
});

test("TC-MSG-13 On a phone the list and the thread are one screen at a time", async ({ browser }) => {
    const student = await openMessagesAs(browser, people.michael, { viewport: { width: 390, height: 844 } });

    try {
        const app = student.page.locator("#messages-app");
        await expect(app).toHaveAttribute("data-view", "list");
        await expect(student.page.locator("[data-pane=list]")).toBeVisible();
        await expect(student.page.locator("[data-pane=thread]")).toBeHidden();

        await rowFor(student.page, "IT101").click();
        await expect(app).toHaveAttribute("data-view", "thread");
        await expect(student.page.locator("[data-pane=thread]")).toBeVisible();
        await expect(student.page.locator("[data-pane=list]")).toBeHidden();

        await student.page.locator("[data-role=back]").click();
        await expect(app).toHaveAttribute("data-view", "list");
        await expect(student.page.locator("[data-pane=list]")).toBeVisible();
    } finally {
        await student.context.close();
    }
});

test("TC-MSG-14 The system administrator has no Messages entry and the address is refused", async ({ page }) => {
    await startAdminSignIn(page);
    await page.getByLabel("6-digit code").fill(await emailedCode());
    await page.getByRole("button", { name: "Confirm" }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard$/);

    await expect(page.getByRole("link", { name: "Messages", exact: true })).toHaveCount(0);

    for (const path of ["/messages", "/messages/api/conversations", "/messages/reports"]) {
        const response = await page.goto(path);
        expect(response.status(), path).toBe(404);
    }
});

// ---- chat and message actions (TC-MSG-15 to 26) ------------------------------------------------------------------
// Michael (student5) and Juan (student1) share the direct thread from TC-MSG-03 and 11; every case writes its own
// unique texts. TC-MSG-11 leaves Michael blocking Juan, so the first case lifts the block.

const juanName = people.juan.name;
const michaelName = people.michael.name;

/** Both students signed in with their direct thread open. */
async function openDirectPair(browser) {
    const michael = await openMessagesAs(browser, people.michael);
    const juan = await openMessagesAs(browser, people.juan);

    await rowFor(michael.page, juanName).first().click();
    await rowFor(juan.page, michaelName).first().click();
    await expect(michael.page.locator("[data-role=composer]")).toBeVisible();
    await expect(juan.page.locator("[data-role=composer]")).toBeVisible();

    return {
        michael,
        juan,
        close: async () => {
            await michael.context.close();
            await juan.context.close();
        },
    };
}

test("TC-MSG-15 A student pins a chat to the top and unpins it", async ({ browser }) => {
    runSql("DELETE FROM user_blocks");

    const michael = await openMessagesAs(browser, people.michael);

    try {
        const rows = michael.page.locator("[data-role=rows] > button");

        await expect(rows.first()).not.toContainText(juanName);

        await (await chatMenu(michael.page, juanName)).locator("[data-action=pin]").click();
        await expect(menuOf(michael.page)).toBeHidden();
        await expect(rows.first()).toContainText(juanName);

        await chatMenu(michael.page, juanName);
        await expect(menuItem(michael.page, "pin")).toHaveCount(0);
        await menuItem(michael.page, "unpin").click();
        await expect(rows.first()).not.toContainText(juanName);
    } finally {
        await michael.context.close();
    }
});

test("TC-MSG-16 Muting a chat keeps its unread dot but stops notifications and the badge", async ({ browser }) => {
    const pair = await openDirectPair(browser);
    const { michael, juan } = pair;

    try {
        await goToMessages(michael.page);
        await (await chatMenu(michael.page, juanName)).locator("[data-action=mute]").click();
        await expect(menuOf(michael.page)).toBeHidden();

        const before = await badgeCount(michael.page);

        await sendMessage(juan.page, "Muted chat message one");

        await michael.page.reload();
        await expect(rowFor(michael.page, juanName).locator("[data-unread]")).toBeVisible({ timeout: 15000 });
        expect(await badgeCount(michael.page)).toBe(before);

        await michael.page.locator("#notifications-button").click();
        await expect(michael.page.locator("#notifications-panel")).toBeVisible();
        await expect(michael.page.locator("#notifications-panel")).not.toContainText(`${juanName} sent you a message.`);
        await michael.page.keyboard.press("Escape");
        await expect(michael.page.locator("#notifications-panel")).toBeHidden();

        await (await chatMenu(michael.page, juanName)).locator("[data-action=unmute]").click();
        await expect(menuOf(michael.page)).toBeHidden();
        await michael.page.reload();
        await expect(rowFor(michael.page, juanName).locator("[data-unread]")).toBeVisible();
        expect(await badgeCount(michael.page)).toBeGreaterThan(before);
    } finally {
        await pair.close();
    }
});

test("TC-MSG-17 Archiving moves a chat to Archived chats, and a new message brings it back", async ({ browser }) => {
    const pair = await openDirectPair(browser);
    const { michael, juan } = pair;

    try {
        await goToMessages(michael.page);
        await (await chatMenu(michael.page, juanName)).locator("[data-action=archive]").click();
        await expect(rowFor(michael.page, juanName)).toHaveCount(0);

        const archived = michael.page.locator("[data-role=archived-open]");

        await expect(archived).toContainText("Archived chats (1)");
        await archived.click();
        await expect(rowFor(michael.page, juanName)).toBeVisible();
        await michael.page.locator("[data-role=archived-back]").click();
        await expect(rowFor(michael.page, juanName)).toHaveCount(0);

        await sendMessage(juan.page, "Back from the archive");
        await michael.page.reload();
        await expect(rowFor(michael.page, juanName)).toBeVisible();
        await expect(michael.page.locator("[data-role=archived-open]")).toBeHidden();
    } finally {
        await pair.close();
    }
});

test("TC-MSG-18 Deleting a chat for me hides it only from me, and a new message brings back only the new part", async ({ browser }) => {
    const pair = await openDirectPair(browser);
    const { michael, juan } = pair;

    try {
        await sendMessage(juan.page, "Said before the delete");
        await goToMessages(michael.page);

        // A course chat cannot be deleted.
        await chatMenu(michael.page, "IT101");
        await expect(menuItem(michael.page, "delete")).toHaveCount(0);
        await michael.page.keyboard.press("Escape");
        await expect(menuOf(michael.page)).toBeHidden();

        await (await chatMenu(michael.page, juanName)).locator("[data-action=delete]").click();
        await expect(rowFor(michael.page, juanName)).toHaveCount(0);

        await juan.page.reload();
        await expect(rowFor(juan.page, michaelName)).toBeVisible();

        await rowFor(juan.page, michaelName).click();
        await sendMessage(juan.page, "Said after the delete");

        await michael.page.reload();
        await rowFor(michael.page, juanName).click();
        await expect(thread(michael.page).getByText("Said after the delete")).toBeVisible();
        await expect(thread(michael.page).getByText("Said before the delete")).toHaveCount(0);
    } finally {
        await pair.close();
    }
});

test("TC-MSG-19 A reaction shows on the other screen without a reload, and pressing it again removes it", async ({ browser }) => {
    const pair = await openDirectPair(browser);
    const { michael, juan } = pair;

    try {
        await sendMessage(juan.page, "Please react to this");
        await michael.page.reload();
        await rowFor(michael.page, juanName).click();
        await expect(thread(michael.page).getByText("Please react to this")).toBeVisible();

        await messageMenu(michael.page, "Please react to this");
        await menuOf(michael.page).locator('[data-emoji="👍"]').first().click();
        await expect(menuOf(michael.page)).toBeHidden();

        await expect(bubble(michael.page, "Please react to this").locator('[data-emoji="👍"]')).toHaveCount(1);
        await expect(bubble(juan.page, "Please react to this").locator('[data-emoji="👍"]')).toHaveCount(1, { timeout: 15000 });

        await bubble(michael.page, "Please react to this").locator('[data-emoji="👍"]').click();
        await expect(bubble(michael.page, "Please react to this").locator('[data-emoji="👍"]')).toHaveCount(0);
        await expect(bubble(juan.page, "Please react to this").locator('[data-emoji="👍"]')).toHaveCount(0, { timeout: 15000 });
    } finally {
        await pair.close();
    }
});

test("TC-MSG-20 Replying quotes the original, and the quote leads back to it", async ({ browser }) => {
    const pair = await openDirectPair(browser);
    const { michael, juan } = pair;

    try {
        await sendMessage(juan.page, "Original for the reply");
        await michael.page.reload();
        await rowFor(michael.page, juanName).click();

        await messageMenu(michael.page, "Original for the reply");
        await menuItem(michael.page, "reply").click();
        await expect(michael.page.locator("[data-role=reply-strip]")).toContainText("Original for the reply");

        await sendMessage(michael.page, "This is my answer");
        await expect(michael.page.locator("[data-role=reply-strip]")).toBeHidden();

        const quote = bubble(michael.page, "This is my answer").locator("[data-reply-to]");

        await expect(quote).toContainText("Original for the reply");
        await quote.click();
        await expect(thread(michael.page).locator("[data-id]", { hasText: "Original for the reply" }).first()).toHaveClass(/ring-2/);
    } finally {
        await pair.close();
    }
});

test("TC-MSG-21 Pinning a message shows the pinned bar to both; a student cannot pin in a course chat", async ({ browser }) => {
    const pair = await openDirectPair(browser);
    const { michael, juan } = pair;
    const instructor = await openMessagesAs(browser, people.instructor1);

    try {
        await sendMessage(juan.page, "Remember this one");
        await michael.page.reload();
        await rowFor(michael.page, juanName).click();

        await messageMenu(michael.page, "Remember this one");
        await menuItem(michael.page, "pin").click();
        await expect(michael.page.locator("[data-role=pinbar]")).toContainText("Remember this one");
        await expect(juan.page.locator("[data-role=pinbar]")).toContainText("Remember this one", { timeout: 15000 });

        // Put it back so the shared thread is as found.
        await messageMenu(michael.page, "Remember this one");
        await menuItem(michael.page, "unpin").click();
        await expect(michael.page.locator("[data-role=pinbar]")).toBeHidden();

        // A course chat: the student's menu has no Pin message, the instructor's does.
        const chat = rowFor(michael.page, "IT101");
        const id = await chat.getAttribute("data-id");

        await chat.click();
        await sendMessage(michael.page, "A course chat line to pin");
        await messageMenu(michael.page, "A course chat line to pin");
        await expect(menuItem(michael.page, "pin")).toHaveCount(0);
        await michael.page.keyboard.press("Escape");

        await rowById(instructor.page, id).click();
        await expect(thread(instructor.page).getByText("A course chat line to pin")).toBeVisible({ timeout: 15000 });
        await messageMenu(instructor.page, "A course chat line to pin");
        await expect(menuItem(instructor.page, "pin")).toBeVisible();
        await instructor.page.keyboard.press("Escape");
    } finally {
        await pair.close();
        await instructor.context.close();
    }
});

test("TC-MSG-22 Forwarding sends a copy marked Forwarded; a parent thread has no Forward", async ({ browser }) => {
    const pair = await openDirectPair(browser);
    const { michael, juan } = pair;
    const parent = await openMessagesAs(browser, people.parent);

    try {
        await sendMessage(juan.page, "Share this with the class");
        await michael.page.reload();
        await rowFor(michael.page, juanName).click();

        await messageMenu(michael.page, "Share this with the class");
        await menuItem(michael.page, "forward").click();
        await michael.page.locator("#messages-forward label", { hasText: "IT101" }).first().locator("input").check();
        await michael.page.locator("[data-role=forward-send]").click();
        await expect(michael.page.locator("[data-role=toast]")).toHaveText("Forwarded");

        await rowFor(michael.page, "IT101").click();
        await expect(bubble(michael.page, "Share this with the class")).toContainText("Forwarded");

        // The parent's thread with the instructor (TC-MSG-06) cannot be forwarded out of.
        await rowFor(parent.page, people.instructor1.name).first().click();
        await thread(parent.page).locator("[data-id]").first().click({ button: "right" });
        await expect(menuOf(parent.page)).toBeVisible();
        await expect(menuItem(parent.page, "forward")).toHaveCount(0);
        await parent.page.keyboard.press("Escape");
    } finally {
        await pair.close();
        await parent.context.close();
    }
});

test("TC-MSG-23 Bump resends my own message as a reminder, once an hour, and not other people's", async ({ browser }) => {
    const pair = await openDirectPair(browser);
    const { michael, juan } = pair;

    try {
        await sendMessage(michael.page, "Please remember the deadline");
        await sendMessage(juan.page, "Someone else's line");
        await michael.page.reload();
        await rowFor(michael.page, juanName).click();

        await messageMenu(michael.page, "Someone else's line");
        await expect(menuItem(michael.page, "bump")).toHaveCount(0);
        await michael.page.keyboard.press("Escape");

        await messageMenu(michael.page, "Please remember the deadline");
        await menuItem(michael.page, "bump").click();
        await expect(thread(michael.page).getByText("Please remember the deadline")).toHaveCount(2);
        await expect(thread(michael.page).locator("[data-id]", { hasText: "Please remember the deadline" }).last()).toContainText("Bump");

        await messageMenu(michael.page, "Please remember the deadline");
        await menuItem(michael.page, "bump").click();
        await expect(michael.page.locator("[data-role=toast]")).not.toHaveText("");
        await expect(thread(michael.page).getByText("Please remember the deadline")).toHaveCount(2);
    } finally {
        await pair.close();
    }
});

test("TC-MSG-24 Delete for everyone leaves a tombstone, erases the text, and is limited to my own messages for 15 minutes", async ({ browser }) => {
    const pair = await openDirectPair(browser);
    const { michael, juan } = pair;

    try {
        const id = await rowFor(michael.page, juanName).first().getAttribute("data-id");

        await sendMessage(juan.page, "Juan's line stays");
        await sendMessage(michael.page, "Unsend this line");
        await michael.page.reload();
        await rowFor(michael.page, juanName).click();

        await messageMenu(michael.page, "Juan's line stays");
        await expect(menuItem(michael.page, "delete-everyone")).toHaveCount(0);
        await michael.page.keyboard.press("Escape");

        await messageMenu(michael.page, "Unsend this line");
        michael.page.once("dialog", (dialog) => dialog.accept());
        await menuItem(michael.page, "delete-everyone").click();

        await expect(thread(michael.page)).toContainText("You deleted a message");
        await expect(thread(juan.page)).toContainText(`${michaelName} deleted a message`, { timeout: 15000 });
        await expect(thread(juan.page).getByText("Unsend this line")).toHaveCount(0);

        const json = await juan.page.request.get(`/messages/api/conversations/${id}/messages`);

        expect(await json.text()).not.toContain("Unsend this line");

        // After 15 minutes the option is gone.
        await sendMessage(michael.page, "Too old to unsend");

        const messageId = await bubble(michael.page, "Too old to unsend").getAttribute("data-id");

        runSql(`UPDATE messages SET created_at = created_at - INTERVAL 20 MINUTE WHERE id = ${Number(messageId)}`);
        await michael.page.reload();
        await rowFor(michael.page, juanName).click();
        await messageMenu(michael.page, "Too old to unsend");
        await expect(menuItem(michael.page, "delete-everyone")).toHaveCount(0);
        await michael.page.keyboard.press("Escape");
    } finally {
        await pair.close();
    }
});

test("TC-MSG-25 On a phone a long press opens the menu; on a desktop the keyboard does, and the composer keeps the browser's menu", async ({ browser }) => {
    const phone = await openMessagesAs(browser, people.michael, { viewport: { width: 390, height: 844 } });
    const desktop = await openMessagesAs(browser, people.michael);

    try {
        await rowFor(phone.page, juanName).first().click();
        await expect(phone.page.locator("[data-role=composer]")).toBeVisible();

        const last = thread(phone.page).locator("[data-id]").last();

        await last.dispatchEvent("touchstart");
        await phone.page.waitForTimeout(700);
        await last.dispatchEvent("touchend");
        await expect(menuOf(phone.page)).toBeVisible();
        await phone.page.keyboard.press("Escape");
        await expect(menuOf(phone.page)).toBeHidden();

        await rowFor(desktop.page, juanName).first().click();

        const target = thread(desktop.page).locator("[data-id]").last();

        await target.focus();
        await desktop.page.keyboard.press("Shift+F10");
        await expect(menuOf(desktop.page)).toBeVisible();
        await desktop.page.keyboard.press("Escape");
        await expect(menuOf(desktop.page)).toBeHidden();
        await expect(target).toBeFocused();

        await target.focus();
        await desktop.page.keyboard.press("Shift+F10");
        await expect(menuOf(desktop.page)).toBeVisible();
        await desktop.page.keyboard.press("ArrowDown");
        await desktop.page.keyboard.press("Enter");
        await expect(menuOf(desktop.page)).toBeHidden();

        // The composer keeps the browser's own menu.
        await desktop.page.locator("[data-role=body]").click({ button: "right" });
        await expect(menuOf(desktop.page)).toHaveCount(0);
    } finally {
        await phone.context.close();
        await desktop.context.close();
    }
});

test("TC-MSG-26 The institution's administrator turns off Forwarding, Reactions and Delete for everyone; the student's menu follows", async ({ browser }) => {
    const student = await openMessagesAs(browser, people.michael);
    const admin = await openMessagesAs(browser, people.institutionAdmin);
    const names = ["forwarding", "reactions", "delete_everyone"];

    try {
        const chat = rowFor(student.page, "IT101");
        const id = await chat.getAttribute("data-id");

        await chat.click();
        await sendMessage(student.page, "A line to test the controls");

        // A student's Settings has only My alerts, and the Messages Settings has no Chat controls for anyone.
        await student.page.locator("[data-role=prefs-open]").click();
        await expect(student.page.locator("[data-role=settings-tabs]")).toBeHidden();
        await expect(student.page.locator("[data-control]")).toHaveCount(0);
        await student.page.locator("#messages-prefs [data-dialog-close]").first().click();

        const setSwitches = async (on) => {
            await admin.page.goto("/institution/settings/messages");
            await admin.page.locator("[data-role=controls-picker]").selectOption(id);

            for (const name of names) {
                const toggle = admin.page.locator(`[data-control="${name}"]`);

                if ((await toggle.getAttribute("aria-checked")) !== String(on)) {
                    await toggle.click();
                    await expect(toggle).toHaveAttribute("aria-checked", String(on));
                }
            }

            // The switches are a draft until Save.
            await admin.page.getByRole("button", { name: "Save", exact: true }).click();
            await expect(admin.page.locator("[data-toast]").first()).toBeVisible();
        };

        await setSwitches(false);

        try {
            await student.page.reload();
            await rowById(student.page, id).click();
            await messageMenu(student.page, "A line to test the controls");
            await expect(menuItem(student.page, "forward")).toHaveCount(0);
            await expect(menuOf(student.page).locator("[data-emoji]")).toHaveCount(0);
            await expect(menuItem(student.page, "delete-everyone")).toHaveCount(0);
            await expect(menuItem(student.page, "delete-me")).toBeVisible();
            await student.page.keyboard.press("Escape");

            // A stale call is refused by the server too (the page's own fetch carries the CSRF header).
            const messageId = await bubble(student.page, "A line to test the controls").getAttribute("data-id");
            const status = await student.page.evaluate(async ([chatId, mid]) => {
                const response = await fetch(`/messages/api/conversations/${chatId}/messages/${mid}/react`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", Accept: "application/json" },
                    body: JSON.stringify({ emoji: "👍" }),
                });

                return response.status;
            }, [id, messageId]);

            expect(status).toBe(403);
        } finally {
            await setSwitches(true);
        }
    } finally {
        await student.context.close();
        await admin.context.close();
    }
});
