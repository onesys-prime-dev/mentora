import { beforeEach, describe, expect, it, vi } from "vitest";
import { createSettings } from "../../../resources/js/pages/messages/settings.js";

const markup = `
<div id="root">
  <div data-role="settings-tabs" hidden>
    <button data-role="settings-tab-alerts" class="border-brand"></button>
    <button data-role="settings-tab-controls" class="border-transparent"></button>
  </div>
  <div data-role="settings-panel-controls" hidden>
    <p data-role="controls-title"></p><p data-role="controls-empty" hidden></p>
    <div data-role="controls-list"></div><p data-role="controls-note"></p>
  </div>
  <div data-role="settings-panel-alerts">
    <span data-role="blocked-count"></span><ul data-role="blocked-list"></ul><p data-role="blocked-empty"></p>
  </div>
</div>`;

const ok = (data) => ({ status: 200, data });

function build({ conversation = null, managesAny = false, setControl = vi.fn().mockResolvedValue(ok({})), blocked = [] } = {}) {
    document.body.innerHTML = markup;

    const root = document.getElementById("root");
    const api = { setControl, blocks: vi.fn().mockResolvedValue(ok({ blocked })), unblock: vi.fn().mockResolvedValue(ok({ ok: true })) };
    const onControlsChanged = vi.fn();
    const settings = createSettings({ root, api, getConversation: () => conversation, managesAny: () => managesAny, onControlsChanged });

    settings.sync();

    return { root, api, onControlsChanged, settings, q: (s) => root.querySelector(s) };
}

const course = (extra = {}) => ({ id: 7, name: "IT101", type: "course", can_manage_controls: true, controls: { lock: false, reactions: true, replies: true, forwarding: true, bump: true, delete_everyone: true }, ...extra });

describe("Settings", () => {
    beforeEach(() => { document.body.innerHTML = ""; });

    it("shows no tab bar to someone who manages nothing", () => {
        const { q } = build({ conversation: { id: 1, type: "direct", can_manage_controls: false } });

        expect(q("[data-role=settings-tabs]").hidden).toBe(true);
        expect(q("[data-role=settings-panel-alerts]").hidden).toBe(false);
    });

    it("shows both tabs to a manager", () => {
        const { q } = build({ conversation: course() });

        expect(q("[data-role=settings-tabs]").hidden).toBe(false);
    });

    it("tells a manager with no chat open to open one", () => {
        const { q } = build({ conversation: null, managesAny: true });

        q("[data-role=settings-tab-controls]").click();

        expect(q("[data-role=settings-tabs]").hidden).toBe(false);
        expect(q("[data-role=controls-empty]").textContent).toBe("Open a chat to change its controls.");
    });

    it("shows the lock switch only for course chats", () => {
        const a = build({ conversation: course() });

        expect(a.q("[data-control=lock]")).not.toBeNull();

        const b = build({ conversation: course({ type: "group" }) });

        expect(b.q("[data-control=lock]")).toBeNull();
        expect(b.q("[data-control=reactions]")).not.toBeNull();
    });

    it("sends the switch and the new value, and keeps the new state on success", async () => {
        const conversation = course();
        const setControl = vi.fn().mockResolvedValue(ok({ controls: { ...conversation.controls, forwarding: false } }));
        const { q, onControlsChanged } = build({ conversation, setControl });
        const toggle = q("[data-control=forwarding]");

        expect(toggle.getAttribute("aria-checked")).toBe("true");
        toggle.click();
        await vi.waitFor(() => expect(onControlsChanged).toHaveBeenCalled());

        expect(setControl).toHaveBeenCalledWith(7, "forwarding", false);
        expect(q("[data-control=forwarding]").getAttribute("aria-checked")).toBe("false");
    });

    it("reverts the switch and says so when the server refuses", async () => {
        const setControl = vi.fn().mockResolvedValue({ status: 403, data: { error: "not_allowed" } });
        const { q } = build({ conversation: course(), setControl });
        const toggle = q("[data-control=reactions]");

        toggle.click();
        await vi.waitFor(() => expect(q("[data-role=controls-note]").textContent).toBe("Couldn't change that. Try again."));

        expect(toggle.getAttribute("aria-checked")).toBe("true");
    });

    it("lists blocked people with an Unblock button, or says no one is blocked", async () => {
        const none = build({ conversation: null });

        await vi.waitFor(() => expect(none.q("[data-role=blocked-empty]").hidden).toBe(false));

        const some = build({ conversation: null, blocked: [{ id: 5, name: "Ann Lee" }] });

        await vi.waitFor(() => expect(some.q("[data-role=blocked-count]").textContent).toBe("1"));
        some.q("[data-unblock='5']").click();
        await vi.waitFor(() => expect(some.api.unblock).toHaveBeenCalledWith(5));
    });
});
