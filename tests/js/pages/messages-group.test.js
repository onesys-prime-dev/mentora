import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { mountMessages } from "../../../resources/js/pages/messages/messages.js";

// The hooks pages/messages/index.php provides, with the new-message picker and the group settings dialog.
const shell = (canGroup) => `
<div id="messages-app" data-view="list" data-api="/messages/api" data-user-id="1" data-open="" data-can-group="${canGroup ? "1" : "0"}">
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
      <button type="button" data-role="group-settings-open" data-dialog-open="messages-group-settings" hidden></button>
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
    <div data-role="picker-name" hidden><input data-role="group-title" maxlength="80"></div>
    <ul data-role="picker-chips" hidden></ul>
    <div data-role="picker-search"><label data-role="picker-label"></label><input data-role="picker-input"><ul data-role="picker-results"></ul></div>
    <p data-role="picker-state"></p>
    <button type="button" data-role="picker-start" hidden></button>
  </div>
  <div id="messages-group-settings" data-dialog hidden>
    <button data-dialog-close></button>
    <div role="tablist" aria-label="Group settings">
      <button type="button" role="tab" id="gs-tab-members" data-tab="members" aria-controls="gs-panel-members" aria-selected="true">Members (<span data-role="gs-count"></span>)</button>
      <button type="button" role="tab" id="gs-tab-details" data-tab="details" aria-controls="gs-panel-details" aria-selected="false" tabindex="-1">Details</button>
    </div>
    <div role="tabpanel" id="gs-panel-members" aria-labelledby="gs-tab-members" data-panel="members">
      <ul data-role="gs-members"></ul>
      <button type="button" data-role="gs-add-toggle" hidden>+ Add people</button>
      <div data-role="gs-add" hidden>
        <input data-role="gs-search"><ul data-role="gs-results"></ul><ul data-role="gs-chips"></ul>
        <button type="button" data-role="gs-add-send" disabled></button>
      </div>
    </div>
    <div role="tabpanel" id="gs-panel-details" aria-labelledby="gs-tab-details" data-panel="details" hidden>
      <div data-role="gs-rename" hidden><input data-role="gs-title" maxlength="80"><button type="button" data-role="gs-save"></button></div>
      <button type="button" data-role="gs-leave" hidden>Leave group</button>
      <button type="button" data-role="gs-close" hidden>Close group</button>
      <p data-role="gs-closed" hidden>This group is closed.</p>
    </div>
    <p data-role="gs-note" role="status"></p>
  </div>
</div>
<span data-messages-badge hidden></span>`;

const person = (id, name, role = "instructor") => ({ id, name, role });
const groupRow = { id: 31, type: "group", name: "Exam planning", initials: "EP", preview: null, unread: 0, can_post: true, can_moderate: true, is_locked: 0, last_message_at: null, closed: false, group_settings: true };
const member = (id, name, over = {}) => ({ id, name, owner: false, me: false, avatar: null, ...over });
const detail = (over = {}) => ({ title: "Exam planning", closed: false, can_manage: true, max: 50, members: [member(1, "Ana Owner", { owner: true, me: true }), member(2, "Ben Member")], ...over });
const ok = (data, status = 200) => ({ status, data });

function makeApi(over = {}) {
    return {
        conversations: vi.fn().mockResolvedValue(ok({ conversations: [], unread: 0 })),
        page: vi.fn().mockResolvedValue(ok({ messages: [], has_more: false })),
        send: vi.fn(),
        markRead: vi.fn().mockResolvedValue(ok({ ok: true })),
        changes: vi.fn().mockResolvedValue(ok({ messages: [], server_time: "2026-01-01 00:00:00", more: false })),
        hide: vi.fn().mockResolvedValue(ok({ ok: true })),
        unread: vi.fn().mockResolvedValue(ok({ unread: 0 })),
        recipients: vi.fn().mockResolvedValue(ok({ recipients: [person(7, "Ivy Alpha"), person(9, "Kim Alpha")], next: null })),
        start: vi.fn().mockResolvedValue(ok({ draft: { recipient_id: 7, subject_student_id: null, first_contact: false, conversation_id: null, name: "Ivy Alpha", avatar: null } })),
        groupCandidates: vi.fn().mockResolvedValue(ok({ candidates: [person(7, "Ivy Alpha"), person(8, "Ian <b>Alpha</b>")], next: null })),
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

const results = () => all("[data-role=picker-results] button");
const chips = () => all("[data-role=picker-chips] li");
const start = () => q("[data-role=picker-start]");
const search = (text) => type("[data-role=picker-input]", text);

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 10, 3, 12, 0, 0));
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = "";
});

// One "+ New" for both: a pick becomes a chip (as Messenger does); one person is a direct draft, two or more a group.
describe("the new-message picker", () => {
    it("keeps a pick as a removable chip and clears the search for the next name", async () => {
        const api = makeApi();

        await mount(api);
        // Open, as the dialog is when a person picks (nothing inside a hidden dialog takes focus).
        q("#messages-new").hidden = false;
        await search("alpha");
        expect(api.recipients).toHaveBeenCalledWith("alpha");

        results()[0].click();

        expect(api.start).not.toHaveBeenCalled();
        expect(chips().map((c) => c.textContent)).toEqual(["Ivy Alpha ×"]);
        expect(q("[data-role=picker-input]").value).toBe("");
        expect(document.activeElement).toBe(q("[data-role=picker-input]"));
        expect(start().hidden).toBe(false);
        expect(start().textContent).toBe("Message");
        expect(q("[data-role=picker-name]").hidden).toBe(true);
        expect(q("[data-role=picker-label]").textContent).toBe("Add another person to make a group");

        chips()[0].querySelector("button").click();

        expect(chips()).toHaveLength(0);
        expect(start().hidden).toBe(true);
        expect(document.activeElement).toBe(q("[data-role=picker-input]"));
    });

    it("opens the direct draft for one person", async () => {
        const api = makeApi();
        const closed = vi.fn();

        await mount(api);
        q("#messages-new [data-dialog-close]").addEventListener("click", closed);
        await search("alpha");
        results()[0].click();
        start().click();
        await flush();

        expect(api.start).toHaveBeenCalledWith(7, undefined);
        expect(closed).toHaveBeenCalled();
        expect(q("[data-role=head-name]").textContent).toBe("Ivy Alpha");
        expect(chips()).toHaveLength(0);
    });

    it("after the first pick, finds only people who can be in a group, leaving out who is picked", async () => {
        const api = makeApi();

        await mount(api);
        await search("alpha");
        results()[0].click();
        await search("alpha");

        expect(api.groupCandidates).toHaveBeenCalledWith("alpha");
        expect(results().map((b) => b.textContent)).toEqual(["Ian <b>Alpha</b>Instructor"]);
        // Names are text, never markup.
        expect(all("[data-role=picker-results] b")).toHaveLength(0);
    });

    it("makes a group of two or more, named after its members when the name is left blank", async () => {
        const api = makeApi();
        const closed = vi.fn();

        await mount(api);
        q("#messages-new [data-dialog-close]").addEventListener("click", closed);
        await search("alpha");
        results()[0].click();
        await search("alpha");
        results()[0].click();

        expect(chips()).toHaveLength(2);
        expect(q("[data-role=picker-name]").hidden).toBe(false);
        expect(start().textContent).toBe("Create group");

        start().click();
        await flush();

        expect(api.createGroup).toHaveBeenCalledWith("", [7, 8]);
        expect(closed).toHaveBeenCalled();
        expect(q("[data-role=rows] button").textContent).toContain("Exam planning");
        expect(q("[data-role=head-name]").textContent).toBe("Exam planning");
    });

    it("sends the group name when one is given, capped at 80", async () => {
        const api = makeApi();

        await mount(api);
        expect(q("[data-role=group-title]").maxLength).toBe(80);
        await search("alpha");
        results()[0].click();
        await search("alpha");
        results()[0].click();
        q("[data-role=group-title]").value = "  Exam planning ";
        start().click();
        await flush();

        expect(api.createGroup).toHaveBeenCalledWith("Exam planning", [7, 8]);
    });

    it("lets no one else be added beside a person picked about a child", async () => {
        await mount(makeApi({ recipients: vi.fn().mockResolvedValue(ok({ recipients: [{ ...person(4, "Ann Lee", "parent"), subject_student_id: 9, subject_name: "Stu Dent" }] })) }));
        await search("ann");
        results()[0].click();

        expect(chips().map((c) => c.textContent)).toEqual(["Ann Lee · about Stu Dent ×"]);
        expect(q("[data-role=picker-search]").hidden).toBe(true);
        expect(start().textContent).toBe("Message");
    });

    it("creates once while a request is in flight", async () => {
        let release;
        const api = makeApi({ createGroup: vi.fn(() => new Promise((resolve) => { release = () => resolve(ok({ conversation: { id: 31, type: "group", name: "Exam planning", initials: "EP", unread: 0, can_post: true, is_locked: 0 } }, 201)); })) });

        await mount(api);
        await search("alpha");
        results()[0].click();
        await search("alpha");
        results()[0].click();
        start().click();
        start().click();
        await flush();

        expect(api.createGroup).toHaveBeenCalledTimes(1);
        expect(start().disabled).toBe(true);

        release();
        await flush();
    });

    it("says so and keeps the picks when the server refuses the group", async () => {
        const api = makeApi({ createGroup: vi.fn().mockResolvedValue(ok({ error: "not_found" }, 404)) });

        await mount(api);
        await search("alpha");
        results()[0].click();
        await search("alpha");
        results()[0].click();
        start().click();
        await flush();

        expect(q("[data-role=picker-state]").textContent).toBe("Couldn't create the group");
        expect(chips()).toHaveLength(2);
        expect(start().disabled).toBe(false);
    });

    it("starts afresh each time + New is pressed", async () => {
        await mount(makeApi());
        await search("alpha");
        results()[0].click();
        q("[data-role=new]").click();

        expect(chips()).toHaveLength(0);
        expect(start().hidden).toBe(true);
    });

    it("opens the draft at once for someone who cannot start groups", async () => {
        const api = makeApi();

        await mount(api, false);
        await search("alpha");
        results()[0].click();
        await flush();

        expect(api.start).toHaveBeenCalledWith(7, undefined);
        expect(chips()).toHaveLength(0);
    });

    it("pages the group candidates with the server's cursor", async () => {
        const api = makeApi({ groupCandidates: vi.fn()
            .mockResolvedValueOnce(ok({ candidates: [person(8, "Ian Alpha")], next: 8 }))
            .mockResolvedValueOnce(ok({ candidates: [person(10, "Lee Alpha")], next: null })) });

        await mount(api);
        await search("alpha");
        results()[0].click();
        await search("alpha");
        q("[data-action=picker-more]").click();
        await flush();

        expect(api.groupCandidates).toHaveBeenLastCalledWith("alpha", 8);
        expect(results().map((b) => b.textContent)).toEqual(["Ian AlphaInstructor", "Lee AlphaInstructor"]);
    });
});

describe("Group settings", () => {
    async function openGroup(over = {}, api = makeApi({ conversations: vi.fn().mockResolvedValue(ok({ conversations: [groupRow], unread: 0 })), groupMembers: vi.fn().mockResolvedValue(ok(detail(over))) })) {
        await mount(api);
        q("[data-role=rows] button").click();
        await flush();
        q("[data-role=group-settings-open]").click();
        await flush();

        return api;
    }

    const tab = (name) => q(`[role=tab][data-tab=${name}]`);
    const visible = (sel) => { const el = q(sel); return el !== null && !el.hidden; };
    const withRows = (over) => makeApi({ conversations: vi.fn().mockResolvedValue(ok({ conversations: [groupRow], unread: 0 })), groupMembers: vi.fn().mockResolvedValue(ok(detail())), ...over });

    it("opens with Members and Details tabs", async () => {
        await openGroup();

        expect(q("[data-role=group-settings-open]").hidden).toBe(false);
        expect(all("[role=tab]").map((t) => t.dataset.tab)).toEqual(["members", "details"]);
        expect(tab("members").getAttribute("aria-selected")).toBe("true");
        expect(q("[data-panel=members]").hidden).toBe(false);
        expect(q("[data-panel=details]").hidden).toBe(true);
        expect(q("[data-role=gs-count]").textContent).toBe("2");

        tab("details").click();
        expect(tab("details").getAttribute("aria-selected")).toBe("true");
        expect(tab("details").tabIndex).toBe(0);
        expect(tab("members").tabIndex).toBe(-1);
        expect(q("[data-panel=members]").hidden).toBe(true);
        expect(q("[data-panel=details]").hidden).toBe(false);

        // Open, as the dialog is when a person uses the keys (nothing inside a hidden dialog takes focus).
        q("#messages-group-settings").hidden = false;
        tab("details").dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowLeft", bubbles: true }));
        expect(tab("members").getAttribute("aria-selected")).toBe("true");
        expect(document.activeElement).toBe(tab("members"));
    });

    it("owner sees Remove and Add; member sees Leave only", async () => {
        await openGroup();

        const rows = all("[data-role=gs-members] li");

        expect(rows.map((r) => r.textContent)).toEqual([expect.stringContaining("Ana Owner"), expect.stringContaining("Ben Member")]);
        expect(rows[0].textContent).toContain("Owner");
        expect(rows[0].querySelector("[data-action=remove]")).toBeNull();
        expect(rows[1].querySelector("[data-action=remove]")).not.toBeNull();
        expect(visible("[data-role=gs-add-toggle]")).toBe(true);
        expect(visible("[data-role=gs-rename]")).toBe(true);
        expect(visible("[data-role=gs-close]")).toBe(true);
        expect(visible("[data-role=gs-leave]")).toBe(true);

        await openGroup({ can_manage: false, members: [member(1, "Ana Owner", { owner: true }), member(2, "Ben Member", { me: true })] });

        expect(all("[data-action=remove]")).toHaveLength(0);
        expect(visible("[data-role=gs-add-toggle]")).toBe(false);
        expect(visible("[data-role=gs-rename]")).toBe(false);
        expect(visible("[data-role=gs-close]")).toBe(false);
        expect(visible("[data-role=gs-leave]")).toBe(true);
    });

    it("shows a closed group with nothing to change and no Leave", async () => {
        await openGroup({ closed: true });

        expect(all("[data-action=remove]")).toHaveLength(0);
        expect(visible("[data-role=gs-add-toggle]")).toBe(false);
        expect(visible("[data-role=gs-leave]")).toBe(false);
        expect(visible("[data-role=gs-closed]")).toBe(true);
    });

    it("removes a member and refreshes the list", async () => {
        const api = withRows({
            groupMembers: vi.fn().mockResolvedValueOnce(ok(detail())).mockResolvedValue(ok(detail({ members: [member(1, "Ana Owner", { owner: true, me: true })] }))),
            removeGroupMember: vi.fn().mockResolvedValue(ok({ ok: true })),
        });

        await openGroup({}, api);
        q("[data-action=remove]").click();
        await flush();

        expect(api.removeGroupMember).toHaveBeenCalledWith(31, 2);
        expect(all("[data-role=gs-members] li")).toHaveLength(1);
    });

    it("keeps focus in the member list after Remove, on the next Remove or else the tab", async () => {
        const three = detail({ members: [member(1, "Ana Owner", { owner: true, me: true }), member(2, "Ben Member"), member(3, "Cy Member")] });
        const api = withRows({
            groupMembers: vi.fn().mockResolvedValueOnce(ok(three)).mockResolvedValueOnce(ok(detail({ members: [three.members[0], three.members[2]] }))).mockResolvedValue(ok(detail({ members: [three.members[0]] }))),
            removeGroupMember: vi.fn().mockResolvedValue(ok({ ok: true })),
        });

        await openGroup({}, api);
        q("#messages-group-settings").hidden = false;
        q("[data-action=remove][data-user-id='2']").click();
        await flush();
        expect(document.activeElement).toBe(q("[data-action=remove][data-user-id='3']"));

        q("[data-action=remove][data-user-id='3']").click();
        await flush();
        expect(document.activeElement).toBe(tab("members"));
    });

    it("adds people found by name, leaving out those already in the group", async () => {
        const api = withRows({
            groupCandidates: vi.fn().mockResolvedValue(ok({ candidates: [person(2, "Ben Member"), person(9, "Cy New")] })),
            addGroupMembers: vi.fn().mockResolvedValue(ok({ added: [9], refused: [] })),
        });

        await openGroup({}, api);
        q("[data-role=gs-add-toggle]").click();
        await type("[data-role=gs-search]", "new");

        const choices = all("[data-role=gs-results] button");

        expect(choices.map((b) => b.textContent)).toEqual(["Cy New"]);

        choices[0].click();
        q("[data-role=gs-add-send]").click();
        await flush();

        expect(api.addGroupMembers).toHaveBeenCalledWith(31, [9]);
    });

    it("leaves only on the second press, then returns to the list", async () => {
        const api = withRows({ leaveGroup: vi.fn().mockResolvedValue(ok({ ok: true })) });

        await openGroup({}, api);
        tab("details").click();
        q("[data-role=gs-leave]").click();
        expect(api.leaveGroup).not.toHaveBeenCalled();

        q("[data-role=gs-leave]").click();
        await flush();

        expect(api.leaveGroup).toHaveBeenCalledWith(31);
        expect(q("[data-role=thread]").hidden).toBe(true);
    });

    it("renames and says when it could not", async () => {
        const api = withRows({ renameGroup: vi.fn().mockResolvedValueOnce(ok({ ok: true })).mockResolvedValueOnce(ok({ error: "not_found" }, 404)) });

        await openGroup({}, api);
        tab("details").click();
        q("[data-role=gs-title]").value = "Board";
        q("[data-role=gs-save]").click();
        await flush();
        expect(api.renameGroup).toHaveBeenCalledWith(31, "Board");

        q("[data-role=gs-title]").value = "Board again";
        q("[data-role=gs-save]").click();
        await flush();
        expect(q("[data-role=gs-note]").textContent).toBe("That didn't work. Try again.");
    });

    it("closes the group only on the second press", async () => {
        const api = withRows({ closeGroup: vi.fn().mockResolvedValue(ok({ ok: true })) });

        await openGroup({}, api);
        tab("details").click();
        q("[data-role=gs-close]").click();
        expect(api.closeGroup).not.toHaveBeenCalled();

        q("[data-role=gs-close]").click();
        await flush();
        expect(api.closeGroup).toHaveBeenCalledWith(31);
    });

    it("hides the button outside a group and says when the group cannot load", async () => {
        await mount(makeApi({ conversations: vi.fn().mockResolvedValue(ok({ conversations: [{ ...groupRow, type: "direct", group_settings: false }], unread: 0 })) }));
        q("[data-role=rows] button").click();
        await flush();
        expect(q("[data-role=group-settings-open]").hidden).toBe(true);

        await openGroup({}, withRows({ groupMembers: vi.fn().mockResolvedValue(ok({ error: "not_found" }, 404)) }));
        expect(q("[data-role=gs-note]").textContent).toBe("The group could not load.");
    });
});

describe("a group's thread", () => {
    it("shows membership lines among the messages, by time", async () => {
        const api = makeApi({
            conversations: vi.fn().mockResolvedValue(ok({ conversations: [groupRow], unread: 0 })),
            page: vi.fn().mockResolvedValue(ok({
                messages: [{ id: 5, body: "hi", sender_id: 2, sender_name: "Ben", mine: false, created_at: "2026-11-03 11:00:00", reactions: [] }],
                events: [
                    { id: 1, event: "group_created", actor_id: 5, target_id: null, actor_name: "Ana", target_name: null, created_at: "2026-11-03 10:00:00" },
                    { id: 2, event: "member_added", actor_id: 5, target_id: 2, actor_name: "Ana", target_name: "Ben", created_at: "2026-11-03 11:30:00" },
                ],
                has_more: false,
            })),
        });

        await mount(api);
        q("[data-role=rows] button").click();
        await flush();

        expect(all("[data-role=messages] [data-role=system-line]").map((l) => l.textContent)).toEqual(["Ana created the group", "Ana added Ben"]);

        const text = q("[data-role=messages]").textContent;

        expect(text.indexOf("Ana created the group")).toBeLessThan(text.indexOf("hi"));
        expect(text.indexOf("hi")).toBeLessThan(text.indexOf("Ana added Ben"));
    });
});

describe("a group's poll", () => {
    it("asks the changes feed only for events newer than the last one it holds", async () => {
        const api = makeApi({
            conversations: vi.fn().mockResolvedValue(ok({ conversations: [groupRow], unread: 0 })),
            page: vi.fn().mockResolvedValue(ok({
                messages: [],
                events: [{ id: 4, event: "group_created", actor_id: 5, target_id: null, actor_name: "Ana", target_name: null, created_at: "2026-11-03 10:00:00" }],
                has_more: false,
            })),
            changes: vi.fn().mockResolvedValue(ok({ messages: [], events: [], server_time: "2026-11-03 12:00:00", more: false })),
        });

        await mount(api);
        q("[data-role=rows] button").click();
        await flush();
        await vi.advanceTimersByTimeAsync(20000);

        expect(api.changes).toHaveBeenCalled();
        expect(api.changes.mock.calls.at(-1)[2]).toBe(4);
    });

    it("words an automatic close without a person", async () => {
        const { groupEventText } = await import("../../../resources/js/pages/messages/render.js");

        expect(groupEventText({ event: "closed", actor_id: null, actor_name: null }, 1)).toBe("The group closed");
        expect(groupEventText({ event: "closed", actor_id: 5, actor_name: "Ana" }, 1)).toBe("Ana closed the group");
    });
});

describe("the member limit", () => {
    it("is 50 including the person starting the group", async () => {
        const { MAX_MEMBERS } = await import("../../../resources/js/pages/messages/group.js");

        expect(MAX_MEMBERS).toBe(50);
    });
});
