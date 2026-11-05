import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { initChatControls } from "../../../resources/js/pages/institution-settings/chat-controls.js";

const ok = (data) => ({ status: 200, data });
const chat = (extra = {}) => ({ id: 7, name: "IT101", type: "course", controls: { lock: false, reactions: true, replies: true, forwarding: true, bump: true, delete_everyone: true }, ...extra });

beforeEach(() => {
    document.body.innerHTML = `
<section data-chat-controls>
  <select data-role="controls-picker" hidden></select>
  <p data-role="controls-empty"></p>
  <div data-role="controls-list"></div>
  <p data-role="controls-note"></p>
</section>`;
});

afterEach(() => {
    document.body.innerHTML = "";
});

const q = (s) => document.querySelector(s);

describe("the institution settings chat controls", () => {
    it("says there are no chats when the server sends none", async () => {
        await initChatControls(q("[data-chat-controls]"), { chatControls: vi.fn().mockResolvedValue(ok({ chats: [] })), setControl: vi.fn() });

        expect(q("[data-role=controls-empty]").hidden).toBe(false);
        expect(q("[data-role=controls-picker]").hidden).toBe(true);
    });

    it("lists the chats, shows the lock only for a course chat, and sends a switch", async () => {
        const setControl = vi.fn().mockResolvedValue(ok({ controls: { ...chat().controls, forwarding: false } }));

        await initChatControls(q("[data-chat-controls]"), {
            chatControls: vi.fn().mockResolvedValue(ok({ chats: [chat(), chat({ id: 8, name: "Staff", type: "group" })] })),
            setControl,
        });

        expect([...q("[data-role=controls-picker]").options].map((o) => o.textContent)).toEqual(["IT101 · course chat", "Staff · staff group"]);
        expect(q("[data-control=lock]")).not.toBeNull();

        q("[data-control=forwarding]").click();
        await vi.waitFor(() => expect(q("[data-control=forwarding]").getAttribute("aria-checked")).toBe("false"));
        expect(setControl).toHaveBeenCalledWith(7, "forwarding", false);

        const picker = q("[data-role=controls-picker]");

        picker.value = "8";
        picker.dispatchEvent(new Event("change"));

        expect(q("[data-control=lock]")).toBeNull();
    });
});
