import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { mountMessages } from "../../../resources/js/pages/messages/messages.js";

// The hooks pages/messages/index.php provides, and nothing else.
const SHELL = `
<div id="messages-app" data-view="list" data-api="/messages/api" data-user-id="1" data-open="">
  <section data-pane="list">
    <button data-role="new" data-dialog-open="messages-new"></button>
    <input data-role="search">
    <button data-chip="all" aria-pressed="true"></button><button data-chip="course" aria-pressed="false"></button>
    <button data-chip="direct" aria-pressed="false"></button><button data-chip="group" aria-pressed="false"></button>
    <button data-role="unread-toggle" aria-pressed="false"></button>
    <div data-role="list">
      <div data-state="loading"></div><p data-state="empty" hidden></p><p data-state="no-match" hidden></p><p data-state="empty-archived" hidden>No archived chats</p><p data-role="list-note" role="status" hidden></p>
      <div data-state="error" hidden><button data-role="retry-list"></button></div>
      <button data-role="archived-open" hidden><span data-role="archived-count"></span></button><button data-role="archived-back" hidden></button>
      <div data-role="rows"></div>
    </div>
  </section>
  <section data-pane="thread">
    <div data-state="none"></div>
    <div data-role="thread" hidden>
      <button data-role="back"></button>
      <span data-role="head-avatar"></span><h2 data-role="head-name"></h2><p data-role="head-sub"></p>
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

const conv = (over = {}) => ({ id: 1, type: "direct", name: "Prof. Reyes", initials: "PR", subtitle: null, preview: "Thanks", last_message_at: "2026-11-03 09:41:00", unread: 0, is_locked: 0, can_post: true, ...over });
const msg = (id, over = {}) => ({ id, sender_id: 2, sender_name: "Prof. Reyes", sender_role: "instructor", mine: false, body: `m${id}`, removed: false, created_at: "2026-11-03 09:00:00", ...over });
const ok = (data, status = 200) => ({ status, data });

function makeApi(over = {}) {
    return {
        conversations: vi.fn().mockResolvedValue(ok({ conversations: [conv()], unread: 0 })),
        start: vi.fn(),
        page: vi.fn().mockResolvedValue(ok({ messages: [msg(10), msg(11)], has_more: false })),
        send: vi.fn().mockResolvedValue(ok({ message: msg(12, { mine: true, body: "hello" }) }, 201)),
        markRead: vi.fn().mockResolvedValue(ok({ ok: true })),
        hide: vi.fn().mockResolvedValue(ok({ ok: true })),
        unread: vi.fn().mockResolvedValue(ok({ unread: 0 })),
        recipients: vi.fn().mockResolvedValue(ok({ recipients: [] })),
        ...over,
    };
}

const q = (sel) => document.querySelector(sel);
const all = (sel) => [...document.querySelectorAll(sel)];
const texts = () => all("[data-role=messages] [data-id]").map((n) => n.textContent);
const flush = () => vi.advanceTimersByTimeAsync(0);

async function mount(api, { open = "" } = {}) {
    document.body.innerHTML = SHELL;
    q("#messages-app").dataset.open = open;

    const page = mountMessages(q("#messages-app"), { api });

    await page.ready;
    await flush();

    return page;
}

async function openFirst() {
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

describe("the conversation list", () => {

    it("shows the rows the server sent, with the preview as text", async () => {

        await mount(makeApi({ conversations: vi.fn().mockResolvedValue(ok({ conversations: [conv({ id: 1, type: "course", name: "CS 201", preview: "Quiz moved" }), conv({ id: 2, name: "Mark Cruz", preview: "<img src=x onerror=alert(1)>" })], unread: 0 })) }));

        const rows = all("[data-role=rows] button");

        expect(rows.map((r) => r.dataset.id)).toEqual(["1", "2"]);
        expect(rows[1].textContent).toContain("<img src=x onerror=alert(1)>");
        expect(q("[data-role=rows] img")).toBeNull();
        expect(q("[data-state=loading]").hidden).toBe(true);

    });

    it("says there are no conversations yet", async () => {

        await mount(makeApi({ conversations: vi.fn().mockResolvedValue(ok({ conversations: [], unread: 0 })) }));

        expect(q("[data-state=empty]").hidden).toBe(false);

    });

    it("shows an error with a retry when the list cannot load", async () => {

        const conversations = vi.fn().mockResolvedValueOnce(ok(null, 0)).mockResolvedValue(ok({ conversations: [conv()], unread: 0 }));

        await mount(makeApi({ conversations }));
        expect(q("[data-state=error]").hidden).toBe(false);

        q("[data-role=retry-list]").click();
        await flush();

        expect(q("[data-state=error]").hidden).toBe(true);
        expect(all("[data-role=rows] button")).toHaveLength(1);

    });

    it("shows the pin limit in the list note with no chat open", async () => {

        const api = makeApi({
            conversations: vi.fn().mockResolvedValue(ok({ conversations: [conv({ can_pin_chat: true })], unread: 0, archived_count: 0 })),
            chatAction: vi.fn().mockResolvedValue(ok({ error: "limit", max: 5 }, 409)),
        });

        await mount(api);
        q("[data-role=rows] button").dispatchEvent(new MouseEvent("contextmenu", { bubbles: true, cancelable: true, clientX: 5, clientY: 5 }));
        document.querySelector("[data-action=pin]").click();
        await flush();

        expect(api.chatAction).toHaveBeenCalledWith(1, "pin");
        expect(q("[data-role=list-note]").hidden).toBe(false);
        expect(q("[data-role=list-note]").textContent).toBe("You can pin up to 5 chats.");

    });

    it("says No archived chats for an empty Archived view", async () => {

        const api = makeApi({ conversations: vi.fn().mockResolvedValue(ok({ conversations: [], unread: 0, archived_count: 1 })) });

        await mount(api);
        q("[data-role=archived-open]").click();
        await flush();

        expect(q("[data-state=empty-archived]").hidden).toBe(false);
        expect(q("[data-state=no-match]").hidden).toBe(true);

    });

    it("does not arm the block confirm when the chat failed to open", async () => {

        const api = makeApi({
            conversations: vi.fn().mockResolvedValue(ok({ conversations: [conv({ can_block: true })], unread: 0, archived_count: 0 })),
            page: vi.fn().mockResolvedValue(ok(null, 404)),
        });

        await mount(api);
        q("[data-role=rows] button").dispatchEvent(new MouseEvent("contextmenu", { bubbles: true, cancelable: true, clientX: 5, clientY: 5 }));
        document.querySelector("[data-action=block]").click();
        await flush();
        await flush();

        expect(api.block).toBeUndefined();
        expect(document.body.textContent).not.toContain("Click again to block");

    });

    it("opens the Archived view from the count row and comes back", async () => {

        const api = makeApi({ conversations: vi.fn().mockResolvedValue(ok({ conversations: [conv()], unread: 0, archived_count: 2 })) });

        await mount(api);
        expect(q("[data-role=archived-open]").hidden).toBe(false);
        expect(q("[data-role=archived-count]").textContent).toBe("2");

        q("[data-role=archived-open]").click();
        await flush();
        expect(api.conversations).toHaveBeenLastCalledWith({ filter: "all", unread: false, archived: true });
        expect(q("[data-role=archived-back]").hidden).toBe(false);

        q("[data-role=archived-back]").click();
        await flush();
        expect(api.conversations).toHaveBeenLastCalledWith({ filter: "all", unread: false, archived: false });

    });

    it("asks the server for the chosen kind and for unread only", async () => {

        const api = makeApi();

        await mount(api);
        q("[data-chip=direct]").click();
        await flush();
        expect(api.conversations).toHaveBeenLastCalledWith({ filter: "direct", unread: false, archived: false });
        expect(q("[data-chip=direct]").getAttribute("aria-pressed")).toBe("true");
        expect(q("[data-chip=all]").getAttribute("aria-pressed")).toBe("false");

        q("[data-role=unread-toggle]").click();
        await flush();
        expect(api.conversations).toHaveBeenLastCalledWith({ filter: "direct", unread: true, archived: false });

    });

    it("narrows the loaded rows by what is typed in the search box", async () => {

        await mount(makeApi({ conversations: vi.fn().mockResolvedValue(ok({ conversations: [conv({ id: 1, name: "Prof. Reyes" }), conv({ id: 2, name: "Mark Cruz", subtitle: "about Stu" })], unread: 0 })) }));

        const search = q("[data-role=search]");

        search.value = "cruz";
        search.dispatchEvent(new Event("input"));
        expect(all("[data-role=rows] button").map((r) => r.dataset.id)).toEqual(["2"]);

        search.value = "nobody";
        search.dispatchEvent(new Event("input"));
        expect(all("[data-role=rows] button")).toHaveLength(0);
        expect(q("[data-state=no-match]").hidden).toBe(false);

    });

    it("refreshes the list every 15 seconds", async () => {

        const api = makeApi();

        await mount(api);
        const before = api.conversations.mock.calls.length;

        await vi.advanceTimersByTimeAsync(15000);

        expect(api.conversations.mock.calls.length).toBe(before + 1);

    });

    it("keeps the unread count on the Messages tile", async () => {

        await mount(makeApi({ conversations: vi.fn().mockResolvedValue(ok({ conversations: [conv()], unread: 4 })) }));

        expect(q("[data-messages-badge]").textContent).toBe("4");
        expect(q("[data-messages-badge]").hidden).toBe(false);

    });

});

describe("opening a conversation", () => {

    it("shows the messages oldest first, marks the newest read and starts polling after it", async () => {

        const api = makeApi();

        await mount(api);
        await openFirst();

        expect(texts().join(" ")).toContain("m10");
        expect(texts()[0]).toContain("m10");
        expect(texts()[1]).toContain("m11");
        expect(api.markRead).toHaveBeenCalledWith(1, 11);
        expect(q("#messages-app").dataset.view).toBe("thread");
        expect(q("[data-role=head-name]").textContent).toBe("Prof. Reyes");

        api.page.mockResolvedValueOnce(ok({ messages: [msg(12)], has_more: false }));
        await vi.advanceTimersByTimeAsync(5000);

        expect(api.page).toHaveBeenLastCalledWith(1, { after: 11 });
        expect(texts()[2]).toContain("m12");

    });

    it("separates the days", async () => {

        const api = makeApi({ page: vi.fn().mockResolvedValue(ok({ messages: [msg(10, { created_at: "2026-11-02 09:00:00" }), msg(11)], has_more: false })) });

        await mount(api);
        await openFirst();

        const text = q("[data-role=messages]").textContent;

        expect(text).toContain("Yesterday");
        expect(text).toContain("Today");

    });

    it("does not render an answer for a conversation the person has since left", async () => {

        let releaseFirst;
        const page = vi.fn()
            .mockImplementationOnce(() => new Promise((resolve) => { releaseFirst = resolve; }))
            .mockResolvedValue(ok({ messages: [msg(50, { body: "from B" })], has_more: false }));
        const api = makeApi({ page, conversations: vi.fn().mockResolvedValue(ok({ conversations: [conv({ id: 1 }), conv({ id: 2, name: "B" })], unread: 0 })) });

        await mount(api);
        all("[data-role=rows] button")[0].click();
        await flush();
        all("[data-role=rows] button")[1].click();
        await flush();

        releaseFirst(ok({ messages: [msg(10, { body: "from A" })], has_more: false }));
        await flush();

        const shown = q("[data-role=messages]").textContent;

        expect(shown).toContain("from B");
        expect(shown).not.toContain("from A");

    });

    it("shows a tombstone for a removed message", async () => {

        await mount(makeApi({ page: vi.fn().mockResolvedValue(ok({ messages: [msg(10, { removed: true, body: null })], has_more: false })) }));
        await openFirst();

        expect(q("[data-role=messages]").textContent).toContain("Message removed");

    });

    it("loads older messages above, and hides the control when there are no more", async () => {

        const page = vi.fn()
            .mockResolvedValueOnce(ok({ messages: [msg(20), msg(21)], has_more: true }))
            .mockResolvedValueOnce(ok({ messages: [msg(18), msg(19)], has_more: false }))
            .mockResolvedValue(ok({ messages: [], has_more: false }));

        await mount(makeApi({ page }));
        await openFirst();
        expect(q("[data-role=older]").hidden).toBe(false);

        q("[data-role=older]").click();
        await flush();

        expect(page).toHaveBeenCalledWith(1, { before: 20 });
        expect(texts().map((t) => t.slice(-3))).toEqual(["m18", "m19", "m20", "m21"]);
        expect(q("[data-role=older]").hidden).toBe(true);

    });

    it("says the conversation is no longer available on a 404, and stops polling", async () => {

        const api = makeApi();

        await mount(api);
        await openFirst();

        api.page.mockResolvedValue(ok({ error: "not_found" }, 404));
        await vi.advanceTimersByTimeAsync(5000);
        const calls = api.page.mock.calls.length;

        expect(q("[data-state=gone]").hidden).toBe(false);

        await vi.advanceTimersByTimeAsync(60000);

        expect(api.page.mock.calls.length).toBe(calls);

    });

    it("opens the thread named in the address, or says it is gone", async () => {

        const api = makeApi({ page: vi.fn().mockResolvedValue(ok({ error: "not_found" }, 404)) });

        await mount(api, { open: "77" });

        expect(api.page).toHaveBeenCalledWith(77, {});
        expect(q("[data-state=gone]").hidden).toBe(false);

    });

    it("goes back to the list on a phone", async () => {

        await mount(makeApi());
        await openFirst();

        q("[data-role=back]").click();

        expect(q("#messages-app").dataset.view).toBe("list");

    });

    it("polls slowly while the tab is hidden", async () => {

        const api = makeApi();

        await mount(api);
        await openFirst();
        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
        api.page.mockClear();

        await vi.advanceTimersByTimeAsync(5000);
        await vi.advanceTimersByTimeAsync(20000);
        expect(api.page.mock.calls.filter((c) => c[1].after !== undefined).length).toBeLessThanOrEqual(1);

        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });

    });

});

describe("sending", () => {

    const type = (text) => {
        const body = q("[data-role=body]");

        body.value = text;
        body.dispatchEvent(new Event("input"));
    };

    it("enables Send only for a message that can be sent", async () => {

        await mount(makeApi());
        await openFirst();

        type("   ");
        expect(q("[data-role=send]").disabled).toBe(true);
        type("hi");
        expect(q("[data-role=send]").disabled).toBe(false);
        type("a".repeat(4001));
        expect(q("[data-role=send]").disabled).toBe(true);

    });

    it("shows the message at once and confirms it from the server's answer", async () => {

        const api = makeApi();

        await mount(api);
        await openFirst();
        type("hello");
        q("[data-role=composer]").dispatchEvent(new Event("submit", { cancelable: true }));

        expect(q("[data-role=messages]").textContent).toContain("Sending…");
        expect(q("[data-role=body]").value).toBe("");

        await flush();

        expect(api.send).toHaveBeenCalledTimes(1);
        expect(api.send.mock.calls[0].slice(0, 2)).toEqual([1, "hello"]);
        expect(q("[data-role=messages]").textContent).not.toContain("Sending…");
        expect(texts().at(-1)).toContain("hello");

    });

    it("sends once however many times it is submitted", async () => {

        const api = makeApi();

        await mount(api);
        await openFirst();
        type("hello");
        q("[data-role=composer]").dispatchEvent(new Event("submit", { cancelable: true }));
        q("[data-role=composer]").dispatchEvent(new Event("submit", { cancelable: true }));
        await flush();

        expect(api.send).toHaveBeenCalledTimes(1);

    });

    it("sends on Enter and writes a new line on Shift+Enter", async () => {

        const api = makeApi();

        await mount(api);
        await openFirst();
        type("hello");

        q("[data-role=body]").dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", shiftKey: true, bubbles: true, cancelable: true }));
        await flush();
        expect(api.send).not.toHaveBeenCalled();

        q("[data-role=body]").dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true }));
        await flush();
        expect(api.send).toHaveBeenCalledTimes(1);

    });

    it("keeps a failed message with a Retry that sends it again with the same id", async () => {

        const send = vi.fn().mockResolvedValueOnce(ok(null, 0)).mockResolvedValue(ok({ message: msg(12, { mine: true, body: "hello" }) }, 201));
        const api = makeApi({ send });

        await mount(api);
        await openFirst();
        type("hello");
        q("[data-role=composer]").dispatchEvent(new Event("submit", { cancelable: true }));
        await flush();

        expect(q("[data-role=messages]").textContent).toContain("Not sent");

        q("[data-action=retry]").click();
        await flush();

        expect(send).toHaveBeenCalledTimes(2);
        expect(send.mock.calls[1][2]).toBe(send.mock.calls[0][2]);
        expect(q("[data-role=messages]").textContent).not.toContain("Not sent");

    });

    it("replaces the composer with the reason when the person cannot post", async () => {

        await mount(makeApi({ conversations: vi.fn().mockResolvedValue(ok({ conversations: [conv({ can_post: false, is_locked: 1 })], unread: 0 })) }));
        await openFirst();

        expect(q("[data-role=composer]").hidden).toBe(true);
        expect(q("[data-role=cannot-post]").hidden).toBe(false);
        expect(q("[data-role=cannot-post]").textContent).toBe("Only the instructor can post here");

    });

    it("says why otherwise when posting is closed", async () => {

        await mount(makeApi({ conversations: vi.fn().mockResolvedValue(ok({ conversations: [conv({ can_post: false })], unread: 0 })) }));
        await openFirst();

        expect(q("[data-role=cannot-post]").textContent).toBe("You can't post in this conversation");

    });

});

describe("review fixes", () => {

    const type = (text) => {
        const body = q("[data-role=body]");

        body.value = text;
        body.dispatchEvent(new Event("input"));
    };
    const submit = () => q("[data-role=composer]").dispatchEvent(new Event("submit", { cancelable: true }));

    it("does not skip a message from someone else that has a lower id than one I just sent", async () => {

        const api = makeApi({ send: vi.fn().mockResolvedValue(ok({ message: msg(15, { mine: true, body: "hello" }) }, 201)) });

        await mount(api);
        await openFirst();
        type("hello");
        submit();
        await flush();

        api.page.mockResolvedValueOnce(ok({ messages: [msg(12), msg(13)], has_more: false }));
        await vi.advanceTimersByTimeAsync(5000);

        expect(api.page).toHaveBeenLastCalledWith(1, { after: 11 });
        expect(texts().map((t) => t.slice(-3))).toEqual(["m10", "m11", "m12", "m13", "llo"]);
        expect(api.markRead).toHaveBeenLastCalledWith(1, 13);

        await vi.advanceTimersByTimeAsync(5000);

        expect(api.page).toHaveBeenLastCalledWith(1, { after: 13 });

    });

    it("keeps polling when loading older messages fails", async () => {

        const page = vi.fn()
            .mockResolvedValueOnce(ok({ messages: [msg(20), msg(21)], has_more: true }))
            .mockResolvedValueOnce(ok(null, 0))
            .mockResolvedValue(ok({ messages: [], has_more: false }));

        await mount(makeApi({ page }));
        await openFirst();
        q("[data-role=older]").click();
        await flush();
        await vi.advanceTimersByTimeAsync(5000);

        expect(page).toHaveBeenLastCalledWith(1, { after: 21 });

    });

    it("loads one older page however many times the control is clicked", async () => {

        let release;
        const page = vi.fn()
            .mockResolvedValueOnce(ok({ messages: [msg(20), msg(21)], has_more: true }))
            .mockImplementationOnce(() => new Promise((resolve) => { release = resolve; }))
            .mockResolvedValue(ok({ messages: [], has_more: false }));

        await mount(makeApi({ page }));
        await openFirst();
        q("[data-role=older]").click();
        q("[data-role=older]").click();
        release(ok({ messages: [msg(18), msg(19)], has_more: false }));
        await flush();

        expect(page.mock.calls.filter((c) => c[1].before !== undefined)).toHaveLength(1);
        expect(texts().map((t) => t.slice(-3))).toEqual(["m18", "m19", "m20", "m21"]);

    });

    it("retries an older failed message with its own id even after another was sent", async () => {

        const send = vi.fn()
            .mockResolvedValueOnce(ok(null, 0))
            .mockResolvedValueOnce(ok({ message: msg(30, { mine: true, body: "B" }) }, 201))
            .mockResolvedValueOnce(ok({ message: msg(31, { mine: true, body: "A" }) }, 201));

        await mount(makeApi({ send }));
        await openFirst();
        type("A");
        submit();
        await flush();
        type("B");
        submit();
        await flush();
        q("[data-action=retry]").click();
        await flush();

        expect(send.mock.calls[2][2]).toBe(send.mock.calls[0][2]);
        expect(send.mock.calls[1][2]).not.toBe(send.mock.calls[0][2]);

    });

    it("polls the open thread at once when the tab comes back", async () => {

        const api = makeApi();

        await mount(api);
        await openFirst();
        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
        await vi.advanceTimersByTimeAsync(5000);
        api.page.mockClear();

        Object.defineProperty(document, "visibilityState", { value: "visible", configurable: true });
        document.dispatchEvent(new Event("visibilitychange"));
        await flush();

        expect(api.page).toHaveBeenCalledWith(1, { after: 11 });

    });

});

describe("deleting for me", () => {

    it("hides the message on the server and removes it from the screen", async () => {

        const api = makeApi();

        await mount(api);
        await openFirst();
        all("[data-action=hide]")[0].click();
        await flush();

        expect(api.hide).toHaveBeenCalledWith(1, 10);
        expect(texts().join(" ")).not.toContain("m10");
        expect(texts().join(" ")).toContain("m11");

    });

});

describe("starting a conversation", () => {

    it("lists what the server finds and opens the conversation that is started", async () => {

        const person = { id: 4, name: "Ann Lee", role: "instructor", subject_student_id: 9, subject_name: "Stu Dent" };
        const api = makeApi({
            recipients: vi.fn().mockResolvedValue(ok({ recipients: [person] })),
            start: vi.fn().mockResolvedValue(ok({ conversation: conv({ id: 7, name: "Ann Lee" }) })),
        });

        await mount(api);

        const input = q("[data-role=picker-input]");

        input.value = "ann";
        input.dispatchEvent(new Event("input"));
        await vi.advanceTimersByTimeAsync(300);

        const choice = q("[data-role=picker-results] button");

        expect(choice.textContent).toContain("Ann Lee");
        expect(choice.textContent).toContain("Stu Dent");
        expect(api.recipients).toHaveBeenCalledWith("ann");

        choice.click();
        await flush();

        expect(api.start).toHaveBeenCalledWith(4, 9);
        expect(api.page).toHaveBeenCalledWith(7, {});

    });

    it("says so when that person cannot be messaged", async () => {

        const api = makeApi({
            recipients: vi.fn().mockResolvedValue(ok({ recipients: [{ id: 4, name: "Ann Lee", role: "instructor", subject_student_id: null, subject_name: null }] })),
            start: vi.fn().mockResolvedValue(ok({ error: "not_found" }, 404)),
        });

        await mount(api);

        const input = q("[data-role=picker-input]");

        input.value = "ann";
        input.dispatchEvent(new Event("input"));
        await vi.advanceTimersByTimeAsync(300);
        q("[data-role=picker-results] button").click();
        await flush();

        expect(q("[data-role=picker-state]").textContent).toBe("That person can't be messaged.");

    });

    it("renders hostile names from the search as text", async () => {

        const api = makeApi({ recipients: vi.fn().mockResolvedValue(ok({ recipients: [{ id: 4, name: "<img src=x onerror=alert(1)>", role: "instructor", subject_student_id: null, subject_name: null }] })) });

        await mount(api);

        const input = q("[data-role=picker-input]");

        input.value = "img";
        input.dispatchEvent(new Event("input"));
        await vi.advanceTimersByTimeAsync(300);

        expect(q("[data-role=picker-results] img")).toBeNull();
        expect(q("[data-role=picker-results]").textContent).toContain("<img src=x onerror=alert(1)>");

    });

});
