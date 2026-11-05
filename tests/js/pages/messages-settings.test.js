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

function build({ chats = [], setControl = vi.fn().mockResolvedValue(ok({})), blocked = [] } = {}) {
    document.body.innerHTML = markup;

    const root = document.getElementById("root");
    const api = {
        setControl,
        chatControls: vi.fn().mockResolvedValue(ok({ chats })),
        blocks: vi.fn().mockResolvedValue(ok({ blocked })),
        unblock: vi.fn().mockResolvedValue(ok({ ok: true })),
    };
    const onControlsChanged = vi.fn();
    const settings = createSettings({ root, api, onControlsChanged });

    return { root, api, onControlsChanged, settings, q: (s) => root.querySelector(s), ready: () => settings.sync() };
}

const course = (extra = {}) => ({ id: 7, name: "IT101", type: "course", controls: { lock: false, reactions: true, replies: true, forwarding: true, bump: true, delete_everyone: true }, ...extra });

describe("Settings", () => {
    beforeEach(() => { document.body.innerHTML = ""; });

    it("shows no tab bar to anyone but the administrator (the server sends them no chats)", async () => {
        const b = build({ chats: [] });

        await b.ready();

        expect(b.q("[data-role=settings-tabs]").hidden).toBe(true);
        expect(b.q("[data-role=settings-panel-alerts]").hidden).toBe(false);
    });

    it("shows both tabs and a picker of every chat to the administrator", async () => {
        const b = build({ chats: [course(), course({ id: 8, name: "Staff", type: "group" })] });

        await b.ready();

        expect(b.q("[data-role=settings-tabs]").hidden).toBe(false);
        expect([...b.q("[data-role=controls-picker]").options].map((o) => o.textContent)).toEqual(["IT101 · course chat", "Staff · group"]);
    });

    it("shows the lock switch only for course chats, and changes with the picker", async () => {
        const b = build({ chats: [course(), course({ id: 8, name: "Staff", type: "group" })] });

        await b.ready();
        expect(b.q("[data-control=lock]")).not.toBeNull();

        const picker = b.q("[data-role=controls-picker]");

        picker.value = "8";
        picker.dispatchEvent(new Event("change"));

        expect(b.q("[data-control=lock]")).toBeNull();
        expect(b.q("[data-control=reactions]")).not.toBeNull();
    });

    it("sends the chosen chat, the switch and the new value, and keeps the new state on success", async () => {
        const chat = course();
        const setControl = vi.fn().mockResolvedValue(ok({ controls: { ...chat.controls, forwarding: false } }));
        const b = build({ chats: [chat], setControl });

        await b.ready();

        const toggle = b.q("[data-control=forwarding]");

        expect(toggle.getAttribute("aria-checked")).toBe("true");
        toggle.click();
        await vi.waitFor(() => expect(b.onControlsChanged).toHaveBeenCalled());

        expect(setControl).toHaveBeenCalledWith(7, "forwarding", false);
        expect(b.q("[data-control=forwarding]").getAttribute("aria-checked")).toBe("false");
    });

    it("reverts the switch and says so when the server refuses", async () => {
        const setControl = vi.fn().mockResolvedValue({ status: 404, data: { error: "not_found" } });
        const b = build({ chats: [course()], setControl });

        await b.ready();

        const toggle = b.q("[data-control=reactions]");

        toggle.click();
        await vi.waitFor(() => expect(b.q("[data-role=controls-note]").textContent).toBe("Couldn't change that. Try again."));

        expect(toggle.getAttribute("aria-checked")).toBe("true");
    });

    it("lists blocked people with an Unblock button, or says no one is blocked", async () => {
        const none = build();
        none.settings.sync();

        await vi.waitFor(() => expect(none.q("[data-role=blocked-empty]").hidden).toBe(false));

        const some = build({ blocked: [{ id: 5, name: "Ann Lee" }] });
        some.settings.sync();

        await vi.waitFor(() => expect(some.q("[data-role=blocked-count]").textContent).toBe("1"));
        some.q("[data-unblock='5']").click();
        await vi.waitFor(() => expect(some.api.unblock).toHaveBeenCalledWith(5));
    });
});
