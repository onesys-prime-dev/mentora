import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { mountMessages } from "../../../resources/js/pages/messages/messages.js";

// The hooks pages/messages/index.php provides, with the new-group button and dialog.
const shell = (canGroup) => `
<div id="messages-app" data-view="list" data-api="/messages/api" data-user-id="1" data-open="" data-can-group="${canGroup ? "1" : "0"}">
  <section data-pane="list">
    <button data-role="new" data-dialog-open="messages-new"></button>
    <button data-role="new-group" data-dialog-open="messages-group" hidden></button>
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
  <div id="messages-group" data-dialog hidden>
    <button data-dialog-close></button>
    <input data-role="group-title" maxlength="80">
    <input data-role="group-search">
    <ul data-role="group-results"></ul>
    <ul data-role="group-chips"></ul>
    <p data-role="group-note" role="status"></p>
    <button type="button" data-role="group-create" disabled></button>
  </div>
</div>
<span data-messages-badge hidden></span>`;

const person = (id, name, role = "instructor") => ({ id, name, role });
const ok = (data, status = 200) => ({ status, data });

function makeApi(over = {}) {
    return {
        conversations: vi.fn().mockResolvedValue(ok({ conversations: [], unread: 0 })),
        start: vi.fn(),
        page: vi.fn().mockResolvedValue(ok({ messages: [], has_more: false })),
        send: vi.fn(),
        markRead: vi.fn().mockResolvedValue(ok({ ok: true })),
        hide: vi.fn().mockResolvedValue(ok({ ok: true })),
        unread: vi.fn().mockResolvedValue(ok({ unread: 0 })),
        recipients: vi.fn().mockResolvedValue(ok({ recipients: [] })),
        groupCandidates: vi.fn().mockResolvedValue(ok({ candidates: [person(7, "Ivy Alpha"), person(8, "Ian <b>Alpha</b>")] })),
        createGroup: vi.fn().mockResolvedValue(ok({ conversation: { id: 31, type: "group", name: "Exam planning", initials: "EP", preview: null, unread: 0, can_post: true, can_moderate: true, is_locked: 0, last_message_at: null } }, 201)),
        ...over,
    };
}

const q = (sel) => document.querySelector(sel);
const all = (sel) => [...document.querySelectorAll(sel)];
const flush = () => vi.advanceTimersByTimeAsync(0);

async function mount(api, canGroup = true) {
    document.body.innerHTML = shell(canGroup);

    const page = mountMessages(q("#messages-app"), { api });

    await page.ready;
    await flush();
}

async function type(selector, text) {
    const input = q(selector);

    input.value = text;
    input.dispatchEvent(new Event("input", { bubbles: true }));
    await vi.advanceTimersByTimeAsync(300);
}

const results = () => all("[data-role=group-results] button");
const chips = () => all("[data-role=group-chips] li");
const create = () => q("[data-role=group-create]");

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 10, 3, 12, 0, 0));
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = "";
});

describe("the new group button", () => {
    it("is shown only to someone the page says can start groups", async () => {
        await mount(makeApi(), true);
        expect(q("[data-role=new-group]").hidden).toBe(false);

        await mount(makeApi(), false);
        expect(q("[data-role=new-group]").hidden).toBe(true);
    });
});

describe("choosing the members", () => {
    it("sends nothing for fewer than two letters", async () => {
        const api = makeApi();

        await mount(api);
        await type("[data-role=group-search]", "i");

        expect(api.groupCandidates).not.toHaveBeenCalled();
        expect(results()).toHaveLength(0);
    });

    it("lists the names as text, never as markup", async () => {
        await mount(makeApi());
        await type("[data-role=group-search]", "alpha");

        expect(results().map((b) => b.textContent)).toEqual(expect.arrayContaining(["Ivy Alpha", "Ian <b>Alpha</b>"]));
        expect(all("[data-role=group-results] b")).toHaveLength(0);
    });

    it("selects a person as a removable chip, and again removes them", async () => {
        await mount(makeApi());
        await type("[data-role=group-search]", "alpha");

        results()[0].click();
        expect(chips().map((c) => c.textContent)).toEqual(["Ivy Alpha ×"]);
        expect(results()[0].getAttribute("aria-pressed")).toBe("true");

        results()[0].click();
        expect(chips()).toHaveLength(0);

        results()[0].click();
        chips()[0].querySelector("button").click();
        expect(chips()).toHaveLength(0);
    });

    it("ignores an answer for a search that has since been replaced", async () => {
        let release;
        const api = makeApi({ groupCandidates: vi.fn()
            .mockImplementationOnce(() => new Promise((resolve) => { release = () => resolve(ok({ candidates: [person(1, "Old Result")] })); }))
            .mockResolvedValueOnce(ok({ candidates: [person(2, "New Result")] })) });

        await mount(api);
        await type("[data-role=group-search]", "ol");
        await type("[data-role=group-search]", "new");
        release();
        await flush();

        expect(results().map((b) => b.textContent)).toEqual(["New Result"]);
    });
});

describe("creating the group", () => {
    async function ready(api) {
        await mount(api);
        q("[data-role=group-title]").value = "Exam planning";
        q("[data-role=group-title]").dispatchEvent(new Event("input", { bubbles: true }));
        await type("[data-role=group-search]", "alpha");
        results()[0].click();
    }

    it("needs a title and at least one member, and the title is capped at 80", async () => {
        await mount(makeApi());
        expect(create().disabled).toBe(true);
        expect(q("[data-role=group-title]").maxLength).toBe(80);

        await type("[data-role=group-search]", "alpha");
        results()[0].click();
        expect(create().disabled).toBe(true);

        q("[data-role=group-title]").value = "   ";
        q("[data-role=group-title]").dispatchEvent(new Event("input", { bubbles: true }));
        expect(create().disabled).toBe(true);

        q("[data-role=group-title]").value = "Exam planning";
        q("[data-role=group-title]").dispatchEvent(new Event("input", { bubbles: true }));
        expect(create().disabled).toBe(false);
    });

    it("creates the group, closes the dialog and opens it at the top of the list", async () => {
        const api = makeApi();
        const closed = vi.fn();

        await ready(api);
        q("#messages-group [data-dialog-close]").addEventListener("click", closed);
        create().click();
        await flush();

        expect(api.createGroup).toHaveBeenCalledWith("Exam planning", [7]);
        expect(closed).toHaveBeenCalled();
        expect(q("[data-role=rows] button").textContent).toContain("Exam planning");
        expect(q("[data-role=head-name]").textContent).toBe("Exam planning");
    });

    it("sends once while a request is in flight", async () => {
        let release;
        const api = makeApi({ createGroup: vi.fn(() => new Promise((resolve) => { release = () => resolve(ok({ conversation: { id: 31, type: "group", name: "Exam planning", initials: "EP", unread: 0, can_post: true, is_locked: 0 } }, 201)); })) });

        await ready(api);
        create().click();
        create().click();
        await flush();

        expect(api.createGroup).toHaveBeenCalledTimes(1);
        expect(create().disabled).toBe(true);

        release();
        await flush();
    });

    it("says so and keeps the form when the server refuses", async () => {
        const api = makeApi({ createGroup: vi.fn().mockResolvedValue(ok({ error: "not_found" }, 404)) });

        await ready(api);
        create().click();
        await flush();

        expect(q("[data-role=group-note]").textContent).toBe("Couldn't create the group");
        expect(q("[data-role=group-title]").value).toBe("Exam planning");
        expect(chips()).toHaveLength(1);
        expect(create().disabled).toBe(false);
    });
});
