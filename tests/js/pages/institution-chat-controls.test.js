import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { initChatControls } from "../../../resources/js/pages/institution-settings/chat-controls.js";

const ok = (data) => ({ status: 200, data });
const chat = (extra = {}) => ({ id: 7, name: "IT101", type: "course", controls: { lock: false, reactions: true, replies: true, forwarding: true, bump: true, delete_everyone: true }, ...extra });

beforeEach(() => {
    document.body.innerHTML = `
<form id="f">
  <input type="radio" name="enabled" value="1" checked><input type="radio" name="enabled" value="0">
<section data-chat-controls>
  <select data-role="controls-picker" hidden></select>
  <p data-role="controls-empty"></p>
  <div data-role="controls-list"></div>
  <p data-role="controls-note"></p>
</section>
</form>`;
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

    it("lists the chats and shows the lock only for a course chat", async () => {
        await initChatControls(q("[data-chat-controls]"), {
            chatControls: vi.fn().mockResolvedValue(ok({ chats: [chat(), chat({ id: 8, name: "Staff", type: "group" })] })),
            setControl: vi.fn(),
        });

        expect([...q("[data-role=controls-picker]").options].map((o) => o.textContent)).toEqual(["IT101 · course chat", "Staff · staff group"]);
        expect(q("[data-control=lock]")).not.toBeNull();

        const picker = q("[data-role=controls-picker]");

        picker.value = "8";
        picker.dispatchEvent(new Event("change"));

        expect(q("[data-control=lock]")).toBeNull();
    });

    it("sends nothing when a switch is flipped, and saves it when the form is saved, saying what changed", async () => {
        const setControl = vi.fn().mockResolvedValue(ok({ controls: { ...chat().controls, forwarding: false } }));
        const submitted = vi.fn((e) => e.preventDefault());

        await initChatControls(q("[data-chat-controls]"), { chatControls: vi.fn().mockResolvedValue(ok({ chats: [chat()] })), setControl });
        q("#f").addEventListener("submit", submitted);

        q("[data-control=forwarding]").click();
        expect(q("[data-control=forwarding]").getAttribute("aria-checked")).toBe("false");
        expect(setControl).not.toHaveBeenCalled();

        q("#f").dispatchEvent(new Event("submit", { cancelable: true }));
        await vi.waitFor(() => expect(setControl).toHaveBeenCalledWith(7, "forwarding", false));
        await vi.waitFor(() => expect(document.querySelector('[data-toast="success"]')).not.toBeNull());

        expect(document.querySelector('[data-toast="success"]').textContent).toContain("Forwarding is now off in IT101.");
        expect(submitted).not.toHaveBeenCalled(); // the students setting did not change, so the form is not sent as well
    });

    it("says there is nothing to save when nothing changed", async () => {
        await initChatControls(q("[data-chat-controls]"), { chatControls: vi.fn().mockResolvedValue(ok({ chats: [chat()] })), setControl: vi.fn() });

        q("#f").dispatchEvent(new Event("submit", { cancelable: true }));

        expect(document.querySelector('[data-toast="info"]').textContent).toContain("Nothing to save.");
    });

    it("lets the form save the students setting when it was changed while the chats were still loading", async () => {
        let answer;
        const chatControls = vi.fn(() => new Promise((resolve) => { answer = resolve; }));
        const loading = initChatControls(q("[data-chat-controls]"), { chatControls, setControl: vi.fn() });

        q('input[value="0"]').checked = true;
        answer(ok({ chats: [chat()] }));
        await loading;

        const submit = new Event("submit", { cancelable: true });
        q("#f").dispatchEvent(submit);

        expect(submit.defaultPrevented).toBe(false);
        expect(document.querySelector('[data-toast="info"]')).toBeNull();
    });
});
