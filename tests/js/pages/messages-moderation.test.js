import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { mountMessages } from "../../../resources/js/pages/messages/messages.js";

// The hooks pages/messages/index.php provides for the thread, with the lock button and its note.
const SHELL = `
<div id="messages-app" data-view="list" data-api="/messages/api" data-user-id="1" data-open="">
  <section data-pane="list">
    <button data-role="new" data-dialog-open="messages-new"></button>
    <input data-role="search">
    <button data-chip="all" aria-pressed="true"></button><button data-chip="course" aria-pressed="false"></button>
    <button data-chip="direct" aria-pressed="false"></button><button data-chip="group" aria-pressed="false"></button>
    <button data-role="unread-toggle" aria-pressed="false"></button>
    <div data-role="list">
      <div data-state="loading"></div><p data-state="empty" hidden></p><p data-state="no-match" hidden></p>
      <div data-state="error" hidden><button data-role="retry-list"></button></div>
      <div data-role="rows"></div>
    </div>
  </section>
  <section data-pane="thread">
    <div data-state="none"></div>
    <div data-role="thread" hidden>
      <button data-role="back"></button>
      <span data-role="head-avatar"></span><h2 data-role="head-name"></h2><p data-role="head-sub"></p>
      <button type="button" data-role="lock" hidden aria-pressed="false"></button>
      <p data-role="lock-note" role="status" hidden></p>
      <div data-role="scroller">
        <button data-role="older" hidden></button>
        <div data-role="messages" role="log" aria-live="polite"></div>
        <p data-state="thread-empty" hidden></p><p data-state="thread-loading" hidden></p>
        <div data-state="thread-error" hidden><button data-role="retry-thread"></button></div>
        <p data-state="gone" hidden>This conversation is no longer available.</p>
      </div>
      <form data-role="composer"><textarea data-role="body"></textarea><button type="submit" data-role="send" disabled></button></form>
      <p data-role="cannot-post" hidden></p>
    </div>
  </section>
  <div id="messages-new" data-dialog hidden>
    <button data-dialog-close></button>
    <input data-role="picker-input"><ul data-role="picker-results"></ul><p data-role="picker-state"></p>
  </div>
</div>
<span data-messages-badge hidden></span>`;

const course = (over = {}) => ({ id: 5, type: "course", name: "IT101 · Intro", initials: "IT101", subtitle: null, preview: "Hi", last_message_at: "2026-11-03 09:41:00", unread: 0, is_locked: 0, can_post: true, can_moderate: false, ...over });
const msg = (id, over = {}) => ({ id, sender_id: 2, sender_name: "Ana Cruz", sender_role: "student", mine: false, body: `m${id}`, removed: false, created_at: "2026-11-03 09:00:00", ...over });
const ok = (data, status = 200) => ({ status, data });

function makeApi(conversation, over = {}) {
    return {
        conversations: vi.fn().mockResolvedValue(ok({ conversations: [conversation], unread: 0 })),
        start: vi.fn(),
        page: vi.fn().mockResolvedValue(ok({ messages: [msg(10), msg(11), msg(12, { removed: true, body: null })], has_more: false })),
        send: vi.fn(),
        markRead: vi.fn().mockResolvedValue(ok({ ok: true })),
        changes: vi.fn().mockResolvedValue(ok({ messages: [], server_time: "2026-01-01 00:00:00", more: false })),
        hide: vi.fn().mockResolvedValue(ok({ ok: true })),
        unread: vi.fn().mockResolvedValue(ok({ unread: 0 })),
        recipients: vi.fn().mockResolvedValue(ok({ recipients: [] })),
        lock: vi.fn().mockResolvedValue(ok({ conversation: { id: 5, is_locked: true } })),
        unlock: vi.fn().mockResolvedValue(ok({ conversation: { id: 5, is_locked: false } })),
        remove: vi.fn().mockResolvedValue(ok({ ok: true })),
        ...over,
    };
}

const q = (sel) => document.querySelector(sel);
const all = (sel) => [...document.querySelectorAll(sel)];
const flush = () => vi.advanceTimersByTimeAsync(0);

async function mountOpen(api) {
    document.body.innerHTML = SHELL;

    const page = mountMessages(q("#messages-app"), { api });

    await page.ready;
    await flush();
    q("[data-role=rows] button").click();
    await flush();
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 10, 3, 12, 0, 0));
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = "";
});

describe("a removed message", () => {
    it("is a muted tombstone with no menu, and its text is never in the page", async () => {
        await mountOpen(makeApi(course()));

        const row = q("[data-id='12']");

        expect(row.textContent).toContain("Message removed");
        expect(row.querySelector("small")).not.toBeNull();
        expect(q("[data-id='12']").closest(".group\\/msg").querySelector("[data-action]")).toBeNull();
    });
});

describe("removing a message as a moderator", () => {
    it("offers Remove message only when the conversation says the viewer can moderate", async () => {
        await mountOpen(makeApi(course({ can_moderate: false })));
        expect(all("[data-action=remove]")).toHaveLength(0);

        await mountOpen(makeApi(course({ can_moderate: true })));
        // The removed row has none; the two live ones do.
        expect(all("[data-action=remove]")).toHaveLength(2);
    });

    it("asks for a second click, then removes and shows a tombstone", async () => {
        const api = makeApi(course({ can_moderate: true }));

        await mountOpen(api);

        const button = q("[data-action=remove][data-message-id='10']");

        button.click();
        await flush();
        expect(api.remove).not.toHaveBeenCalled();
        expect(q("[data-action=remove][data-message-id='10']").textContent).toBe("Click again to remove");

        q("[data-action=remove][data-message-id='10']").click();
        await flush();

        expect(api.remove).toHaveBeenCalledWith(5, 10);
        expect(q("[data-id='10']").textContent).toContain("Message removed");
    });

    it("leaves the message alone when the server refuses", async () => {
        const api = makeApi(course({ can_moderate: true }), { remove: vi.fn().mockResolvedValue(ok(null, 404)) });

        await mountOpen(api);
        q("[data-action=remove][data-message-id='10']").click();
        q("[data-action=remove][data-message-id='10']").click();
        await flush();

        expect(q("[data-id='10']").textContent).toContain("m10");
    });
});

describe("locking a course chat", () => {
    it("shows the lock button only to a moderator of a course chat", async () => {
        await mountOpen(makeApi(course({ can_moderate: false })));
        expect(q("[data-role=lock]").hidden).toBe(true);

        await mountOpen(makeApi(course({ can_moderate: true })));
        expect(q("[data-role=lock]").hidden).toBe(false);
        expect(q("[data-role=lock]").textContent).toBe("Lock chat");
    });

    it("locks, flips the label, and tells the instructor students can only read", async () => {
        const api = makeApi(course({ can_moderate: true }));

        await mountOpen(api);
        q("[data-role=lock]").click();
        await flush();

        expect(api.lock).toHaveBeenCalledWith(5);
        expect(q("[data-role=lock]").textContent).toBe("Unlock chat");
        expect(q("[data-role=lock]").getAttribute("aria-pressed")).toBe("true");
        expect(q("[data-role=lock-note]").textContent).toBe("Locked: students can read only");
        expect(q("[data-role=composer]").hidden).toBe(false);

        q("[data-role=lock]").click();
        await flush();

        expect(api.unlock).toHaveBeenCalledWith(5);
        expect(q("[data-role=lock]").textContent).toBe("Lock chat");
        expect(q("[data-role=lock-note]").hidden).toBe(true);
    });

    it("keeps the label and says so when the lock fails", async () => {
        const api = makeApi(course({ can_moderate: true }), { lock: vi.fn().mockResolvedValue(ok(null, 0)) });

        await mountOpen(api);
        q("[data-role=lock]").click();
        await flush();

        expect(q("[data-role=lock]").textContent).toBe("Lock chat");
        expect(q("[data-role=lock-note]").textContent).toBe("Couldn't change that. Try again.");
    });
});

describe("a chat the viewer cannot post in", () => {
    it("tells a student a locked chat is the instructor's to post in", async () => {
        await mountOpen(makeApi(course({ is_locked: 1, can_post: false })));

        expect(q("[data-role=composer]").hidden).toBe(true);
        expect(q("[data-role=cannot-post]").textContent).toBe("Only the instructor can post here");
    });

    it("says a closed course's chat is read-only, in words for someone who closed nothing", async () => {
        await mountOpen(makeApi(course({ is_locked: 0, can_post: false, closed: true })));

        expect(q("[data-role=composer]").hidden).toBe(true);
        expect(q("[data-role=cannot-post]").textContent).toBe("This course has ended, so this chat is read-only.");
    });
});
