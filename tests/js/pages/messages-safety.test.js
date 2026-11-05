import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { mountMessages } from "../../../resources/js/pages/messages/messages.js";
import { REASONS, describeFailure } from "../../../resources/js/pages/messages/safety.js";

// The hooks pages/messages/index.php provides, with the block button, the report and settings dialogs.
const SHELL = `
<div id="messages-app" data-view="list" data-api="/messages/api" data-user-id="1" data-open="" data-can-group="0">
  <section data-pane="list">
    <button data-role="new" data-dialog-open="messages-new"></button>
    <button data-role="prefs-open" data-dialog-open="messages-prefs"></button>
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
      <button type="button" data-role="block" hidden></button>
      <p data-role="safety-note" role="status" hidden></p>
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
  <button type="button" data-role="report-open" data-dialog-open="messages-report" hidden></button>
  <div id="messages-report" data-dialog hidden>
    <button data-dialog-close></button>
    <select data-role="report-reason"><option value="">Choose a reason</option></select>
    <textarea data-role="report-note" maxlength="500"></textarea>
    <p data-role="report-state" role="status"></p>
    <button type="button" data-role="report-send" disabled></button>
  </div>
  <div id="messages-prefs" data-dialog hidden>
    <button data-dialog-close></button>
    <input type="checkbox" data-role="prefs-email">
    <div data-role="prefs-quiet" hidden><input type="time" data-role="prefs-start"><input type="time" data-role="prefs-end"></div>
    <p data-role="prefs-note" role="status"></p>
    <button type="button" data-role="prefs-save"></button>
  </div>
</div>
<span data-messages-badge hidden></span>`;

const direct = (over = {}) => ({ id: 5, type: "direct", name: "Ben Ng", initials: "BN", other_user_id: 7, subtitle: null, preview: "Hi", last_message_at: "2026-11-03 09:41:00", unread: 0, is_locked: 0, can_post: true, can_block: true, blocked_by_me: false, ...over });
const msg = (id, over = {}) => ({ id, sender_id: 7, sender_name: "Ben Ng", sender_role: "student", mine: false, body: `m${id}`, removed: false, created_at: "2026-11-03 09:00:00", ...over });
const ok = (data, status = 200) => ({ status, data });

function makeApi(conversation = direct(), over = {}) {
    return {
        conversations: vi.fn().mockResolvedValue(ok({ conversations: [conversation], unread: 0 })),
        start: vi.fn(),
        page: vi.fn().mockResolvedValue(ok({ messages: [msg(10), msg(11, { mine: true, sender_id: 1 }), msg(12, { removed: true, body: null })], has_more: false })),
        send: vi.fn().mockResolvedValue(ok({ message: msg(13, { mine: true, body: "hello" }) }, 201)),
        markRead: vi.fn().mockResolvedValue(ok({ ok: true })),
        changes: vi.fn().mockResolvedValue(ok({ messages: [], server_time: "2026-01-01 00:00:00", more: false })),
        hide: vi.fn().mockResolvedValue(ok({ ok: true })),
        unread: vi.fn().mockResolvedValue(ok({ unread: 0 })),
        recipients: vi.fn().mockResolvedValue(ok({ recipients: [] })),
        block: vi.fn().mockResolvedValue(ok({ ok: true })),
        unblock: vi.fn().mockResolvedValue(ok({ ok: true })),
        report: vi.fn().mockResolvedValue(ok({ ok: true })),
        preferences: vi.fn().mockResolvedValue(ok({ preferences: { quiet_start: "22:00", quiet_end: "07:00", email_alerts: false }, quiet_hours_allowed: true })),
        savePreferences: vi.fn().mockResolvedValue(ok({ preferences: {}, quiet_hours_allowed: true })),
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

describe("describeFailure", () => {
    it("uses the server's sentence for a rate limit, a plain one offline, and the fallback otherwise", () => {
        expect(describeFailure({ status: 429, data: { message: "Slow down." } }, "x")).toBe("Slow down.");
        expect(describeFailure({ status: 429, data: null }, "x")).toBe("You're doing that too fast. Wait a moment and try again.");
        expect(describeFailure({ status: 0, data: null }, "x")).toBe("Couldn't reach the server. Check your connection and try again.");
        expect(describeFailure({ status: 404, data: null }, "Nope")).toBe("Nope");
    });

    it("offers the four reasons", () => {
        expect(Object.keys(REASONS)).toEqual(["harassment", "spam", "inappropriate", "other"]);
    });
});

describe("blocking", () => {
    it("is offered only when the server says the person can be blocked", async () => {
        await mountOpen(makeApi(direct({ can_block: false })));
        expect(q("[data-role=block]").hidden).toBe(true);

        await mountOpen(makeApi(direct()));
        expect(q("[data-role=block]").hidden).toBe(false);
        expect(q("[data-role=block]").textContent).toBe("Block Ben Ng");
    });

    it("asks for a second click, blocks, and then offers to unblock", async () => {
        const api = makeApi();

        await mountOpen(api);
        q("[data-role=block]").click();
        await flush();

        expect(api.block).not.toHaveBeenCalled();
        expect(q("[data-role=block]").textContent).toBe("Click again to block Ben Ng");

        q("[data-role=block]").click();
        await flush();

        expect(api.block).toHaveBeenCalledWith(7);
        expect(q("[data-role=block]").textContent).toBe("Unblock Ben Ng");
        expect(q("[data-role=safety-note]").textContent).toBe("You blocked Ben Ng. They can't start or reply to messages with you.");

        q("[data-role=block]").click();
        await flush();

        expect(api.unblock).toHaveBeenCalledWith(7);
        expect(q("[data-role=block]").textContent).toBe("Block Ben Ng");
    });

    it("says so and keeps the button when the server refuses", async () => {
        await mountOpen(makeApi(direct(), { block: vi.fn().mockResolvedValue(ok(null, 404)) }));

        q("[data-role=block]").click();
        q("[data-role=block]").click();
        await flush();

        expect(q("[data-role=safety-note]").textContent).toBe("That person can't be blocked.");
        expect(q("[data-role=block]").textContent).toBe("Block Ben Ng");
    });
});

describe("reporting a message", () => {
    it("is offered on other people's live messages only", async () => {
        await mountOpen(makeApi());

        expect(all("[data-action=report]").map((b) => b.dataset.messageId)).toEqual(["10"]);
    });

    it("needs a reason, then sends it with the trimmed note and thanks the person", async () => {
        const api = makeApi();
        const closed = vi.fn();

        await mountOpen(api);
        q("#messages-report [data-dialog-close]").addEventListener("click", closed);
        q("[data-action=report]").click();

        expect(q("[data-role=report-send]").disabled).toBe(true);
        expect(all("[data-role=report-reason] option").map((o) => o.value)).toEqual(["", "harassment", "spam", "inappropriate", "other"]);

        q("[data-role=report-reason]").value = "spam";
        q("[data-role=report-reason]").dispatchEvent(new Event("change", { bubbles: true }));
        q("[data-role=report-note]").value = "  unwanted  ";
        expect(q("[data-role=report-send]").disabled).toBe(false);

        q("[data-role=report-send]").click();
        q("[data-role=report-send]").click();
        await flush();

        expect(api.report).toHaveBeenCalledTimes(1);
        expect(api.report).toHaveBeenCalledWith(5, 10, "spam", "unwanted");
        expect(closed).toHaveBeenCalled();
        expect(q("[data-role=safety-note]").textContent).toBe("Thanks, we'll look into it.");
    });

    it("shows the server's sentence for a rate limit and keeps the dialog open", async () => {
        const api = makeApi(direct(), { report: vi.fn().mockResolvedValue(ok({ error: "rate_limited", message: "Too many reports today." }, 429)) });
        const closed = vi.fn();

        await mountOpen(api);
        q("#messages-report [data-dialog-close]").addEventListener("click", closed);
        q("[data-action=report]").click();
        q("[data-role=report-reason]").value = "other";
        q("[data-role=report-reason]").dispatchEvent(new Event("change", { bubbles: true }));
        q("[data-role=report-send]").click();
        await flush();

        expect(q("[data-role=report-state]").textContent).toBe("Too many reports today.");
        expect(closed).not.toHaveBeenCalled();
        expect(q("[data-role=report-send]").disabled).toBe(false);
    });

    it("says the report could not be sent when the server refuses it", async () => {
        await mountOpen(makeApi(direct(), { report: vi.fn().mockResolvedValue(ok(null, 404)) }));

        q("[data-action=report]").click();
        q("[data-role=report-reason]").value = "spam";
        q("[data-role=report-reason]").dispatchEvent(new Event("change", { bubbles: true }));
        q("[data-role=report-send]").click();
        await flush();

        expect(q("[data-role=report-state]").textContent).toBe("Couldn't send the report. Try again.");
    });
});

describe("a send over the limit", () => {
    it("keeps the draft as a failed message and shows the server's sentence beside Retry", async () => {
        const api = makeApi(direct(), { send: vi.fn().mockResolvedValue(ok({ error: "rate_limited", message: "You're sending messages too fast." }, 429)) });

        await mountOpen(api);
        q("[data-role=body]").value = "hello again";
        q("[data-role=body]").dispatchEvent(new Event("input", { bubbles: true }));
        q("[data-role=composer]").dispatchEvent(new Event("submit", { cancelable: true }));
        await flush();

        const note = all("[data-role=messages] div").map((d) => d.textContent).find((t) => t.includes("too fast"));

        expect(note).toContain("You're sending messages too fast.");
        expect(note).toContain("Retry");
    });
});

describe("notification settings", () => {
    it("loads the person's values and offers quiet hours only when allowed", async () => {
        await mountOpen(makeApi());
        q("[data-role=prefs-open]").click();
        await flush();

        expect(q("[data-role=prefs-email]").checked).toBe(false);
        expect(q("[data-role=prefs-quiet]").hidden).toBe(false);
        expect(q("[data-role=prefs-start]").value).toBe("22:00");
        expect(q("[data-role=prefs-end]").value).toBe("07:00");

        await mountOpen(makeApi(direct(), { preferences: vi.fn().mockResolvedValue(ok({ preferences: { quiet_start: null, quiet_end: null, email_alerts: true }, quiet_hours_allowed: false })) }));
        q("[data-role=prefs-open]").click();
        await flush();

        expect(q("[data-role=prefs-quiet]").hidden).toBe(true);
    });

    it("saves the email switch and the hours, and says so", async () => {
        const api = makeApi();

        await mountOpen(api);
        q("[data-role=prefs-open]").click();
        await flush();
        q("[data-role=prefs-email]").checked = true;
        q("[data-role=prefs-start]").value = "21:30";
        q("[data-role=prefs-save]").click();
        await flush();

        expect(api.savePreferences).toHaveBeenCalledWith({ email_alerts: true, quiet_start: "21:30", quiet_end: "07:00" });
        expect(q("[data-role=prefs-note]").textContent).toBe("Saved.");
    });

    it("sends no hours when they are not offered, and asks to check the times on a refusal", async () => {
        const api = makeApi(direct(), {
            preferences: vi.fn().mockResolvedValue(ok({ preferences: { quiet_start: null, quiet_end: null, email_alerts: true }, quiet_hours_allowed: false })),
            savePreferences: vi.fn().mockResolvedValue(ok({ error: "invalid" }, 422)),
        });

        await mountOpen(api);
        q("[data-role=prefs-open]").click();
        await flush();
        q("[data-role=prefs-save]").click();
        await flush();

        expect(api.savePreferences).toHaveBeenCalledWith({ email_alerts: true, quiet_start: null, quiet_end: null });
        expect(q("[data-role=prefs-note]").textContent).toBe("Check the times.");
    });
});
