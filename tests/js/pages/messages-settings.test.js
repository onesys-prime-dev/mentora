import { beforeEach, describe, expect, it, vi } from "vitest";
import { createSettings } from "../../../resources/js/pages/messages/settings.js";

const markup = `
<div id="root">
  <div data-role="settings-panel-alerts">
    <span data-role="blocked-count"></span><ul data-role="blocked-list"></ul><p data-role="blocked-empty"></p>
  </div>
</div>`;

const ok = (data) => ({ status: 200, data });

function build({ blocked = [], unblock = vi.fn().mockResolvedValue(ok({ ok: true })) } = {}) {
    document.body.innerHTML = markup;

    const root = document.getElementById("root");
    const api = { blocks: vi.fn().mockResolvedValue(ok({ blocked })), unblock };
    const settings = createSettings({ root, api });

    return { root, api, settings, q: (s) => root.querySelector(s) };
}

describe("Settings", () => {
    beforeEach(() => { document.body.innerHTML = ""; });

    it("says no one is blocked, or lists the people with an Unblock button", async () => {
        const none = build();

        none.settings.sync();
        await vi.waitFor(() => expect(none.q("[data-role=blocked-empty]").hidden).toBe(false));

        const some = build({ blocked: [{ id: 5, name: "Ann Lee" }] });

        some.settings.sync();
        await vi.waitFor(() => expect(some.q("[data-role=blocked-count]").textContent).toBe("1"));
        expect(some.q("[data-role=blocked-list]").textContent).toContain("Ann Lee");

        some.q("[data-unblock='5']").click();
        await vi.waitFor(() => expect(some.api.unblock).toHaveBeenCalledWith(5));
    });

    it("has no chat controls: those are in Institution settings", () => {
        const b = build();

        expect(b.q("[data-role=controls-picker]")).toBeNull();
        expect(b.q("[data-control]")).toBeNull();
    });

    it("does nothing on a page without the Settings markup", () => {
        document.body.innerHTML = `<div id="root"></div>`;

        expect(() => createSettings({ root: document.getElementById("root"), api: {} }).sync()).not.toThrow();
    });
});
