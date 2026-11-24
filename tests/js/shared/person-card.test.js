import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { initPersonCard } from "../../../resources/js/shared/person-card.js";

const CARD = '<div data-person-card="7"><a href="/people/7" data-person-panel="7">See more</a></div>';
const PANEL = '<div data-person-profile="7"><button type="button" data-profile-copy-email="a@b.test">Copy email</button></div>';

const reply = (status, body = "") => ({ ok: status >= 200 && status < 300, status, text: () => Promise.resolve(body) });

const fire = (el, type, props = {}) => {
    const event = new Event(type, { bubbles: true, cancelable: true });

    Object.assign(event, props);
    el.dispatchEvent(event);

    return event;
};
const mouseOver = (el) => fire(el, "pointerover", { pointerType: "mouse" });
const mouseOut = (el) => fire(el, "pointerout", { pointerType: "mouse" });

let stop;
let avatar;

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = `
        <button type="button" data-person-avatar="7" aria-haspopup="dialog" aria-label="Open Maria Santos's profile">MS</button>
        <div id="person-dialog" data-dialog hidden role="dialog" aria-modal="true">
            <div data-dialog-panel tabindex="-1"><button type="button" data-dialog-close>Close</button><div data-dialog-body></div></div>
        </div>`;
    avatar = document.querySelector("[data-person-avatar]");
});

afterEach(() => {
    stop?.();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.innerHTML = "";
});

const start = (map) => {
    const fetchMock = vi.fn((url) => Promise.resolve(map[url] ?? reply(404)));

    vi.stubGlobal("fetch", fetchMock);
    stop = initPersonCard();

    return fetchMock;
};

describe("the popover", () => {

    it("opens 300 ms after the mouse arrives and asks the server once per person", async () => {

        const fetchMock = start({ "/people/7/card": reply(200, CARD) });

        mouseOver(avatar);
        await vi.advanceTimersByTimeAsync(299);
        expect(document.querySelector("[data-person-popover]")).toBeNull();

        await vi.advanceTimersByTimeAsync(1);
        expect(document.querySelector("[data-person-popover] [data-person-card]")).not.toBeNull();
        expect(fetchMock).toHaveBeenCalledWith("/people/7/card", expect.objectContaining({ credentials: "same-origin" }));

        mouseOut(avatar);
        await vi.advanceTimersByTimeAsync(500);
        expect(document.querySelector("[data-person-popover]")).toBeNull();

        mouseOver(avatar);
        await vi.advanceTimersByTimeAsync(300);
        expect(document.querySelector("[data-person-popover] [data-person-card]")).not.toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(1);

    });

    it("opens on keyboard focus, and Escape closes it with focus still on the avatar", async () => {

        start({ "/people/7/card": reply(200, CARD) });

        avatar.focus();
        await vi.advanceTimersByTimeAsync(300);
        expect(document.querySelector("[data-person-popover] [data-person-card]")).not.toBeNull();

        fire(document, "keydown", { key: "Escape" });
        expect(document.querySelector("[data-person-popover]")).toBeNull();
        expect(document.activeElement).toBe(avatar);

    });

    it("stays open while the pointer moves from the avatar into it", async () => {

        start({ "/people/7/card": reply(200, CARD) });

        mouseOver(avatar);
        await vi.advanceTimersByTimeAsync(300);
        mouseOut(avatar);
        mouseOver(document.querySelector("[data-person-popover]"));
        await vi.advanceTimersByTimeAsync(500);

        expect(document.querySelector("[data-person-popover]")).not.toBeNull();

    });

    it("shows a retry on failure that asks again", async () => {

        const fetchMock = start({ "/people/7/card": reply(500) });

        mouseOver(avatar);
        await vi.advanceTimersByTimeAsync(300);
        expect(document.querySelector("[data-person-popover]").textContent).toContain("Couldn't load this profile. Try again.");

        fetchMock.mockResolvedValue(reply(200, CARD));
        document.querySelector("[data-person-retry]").click();
        await vi.advanceTimersByTimeAsync(0);

        expect(document.querySelector("[data-person-card]")).not.toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(2);

    });

    it("says the profile isn't available on a 404, with no retry", async () => {

        start({ "/people/7/card": reply(404, "<p>x</p>") });

        mouseOver(avatar);
        await vi.advanceTimersByTimeAsync(300);

        expect(document.querySelector("[data-person-popover]").textContent).toContain("This profile isn't available");
        expect(document.querySelector("[data-person-retry]")).toBeNull();

    });

});

describe("the profile modal", () => {

    it("opens on click with the panel, and focus goes back to the avatar on close", async () => {

        const fetchMock = start({ "/people/7/panel": reply(200, PANEL) });

        avatar.focus();
        avatar.click();
        await vi.advanceTimersByTimeAsync(0);

        const dialog = document.getElementById("person-dialog");

        expect(dialog.hidden).toBe(false);
        expect(dialog.querySelector("[data-person-profile]")).not.toBeNull();
        expect(dialog.contains(document.activeElement)).toBe(true);

        dialog.querySelector("[data-dialog-close]").click();
        expect(dialog.hidden).toBe(true);
        expect(document.activeElement).toBe(avatar);

        // Focus coming back does not raise the popover again.
        await vi.advanceTimersByTimeAsync(500);
        expect(document.querySelector("[data-person-popover]")).toBeNull();

        avatar.click();
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledTimes(1);

    });

    it("skips the popover on a touch tap", async () => {

        const fetchMock = start({ "/people/7/card": reply(200, CARD), "/people/7/panel": reply(200, PANEL) });

        fire(avatar, "pointerdown", { pointerType: "touch" });
        avatar.focus();
        avatar.click();
        await vi.advanceTimersByTimeAsync(500);

        expect(document.getElementById("person-dialog").hidden).toBe(false);
        expect(document.querySelector("[data-person-popover]")).toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe("/people/7/panel");

    });

    it("opens from See more in the popover", async () => {

        start({ "/people/7/card": reply(200, CARD), "/people/7/panel": reply(200, PANEL) });

        mouseOver(avatar);
        await vi.advanceTimersByTimeAsync(300);
        document.querySelector("[data-person-panel]").click();
        await vi.advanceTimersByTimeAsync(0);

        expect(document.getElementById("person-dialog").querySelector("[data-person-profile]")).not.toBeNull();
        expect(document.querySelector("[data-person-popover]")).toBeNull();

    });

    it("lets a modified click on See more navigate normally", async () => {

        const fetchMock = start({ "/people/7/card": reply(200, CARD) });

        mouseOver(avatar);
        await vi.advanceTimersByTimeAsync(300);
        const event = new MouseEvent("click", { bubbles: true, cancelable: true, ctrlKey: true });

        document.querySelector("[data-person-panel]").dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(1);

    });

    it("shows a retry on failure and a plain message on a 404", async () => {

        const fetchMock = start({ "/people/7/panel": reply(500) });

        avatar.click();
        await vi.advanceTimersByTimeAsync(0);
        const body = document.querySelector("#person-dialog [data-dialog-body]");

        expect(body.textContent).toContain("Couldn't load this profile. Try again.");

        fetchMock.mockResolvedValue(reply(404));
        body.querySelector("[data-person-retry]").click();
        await vi.advanceTimersByTimeAsync(0);

        expect(body.textContent).toContain("This profile isn't available");
        expect(body.querySelector("[data-person-retry]")).toBeNull();

    });

    it("copies the email and says so", async () => {

        start({ "/people/7/panel": reply(200, PANEL) });
        const writeText = vi.fn().mockResolvedValue();

        vi.stubGlobal("navigator", { clipboard: { writeText } });

        avatar.click();
        await vi.advanceTimersByTimeAsync(0);
        document.querySelector("[data-profile-copy-email]").click();
        await vi.advanceTimersByTimeAsync(0);

        expect(writeText).toHaveBeenCalledWith("a@b.test");
        expect(document.querySelector("[role=status]").textContent).toBe("Email copied");
        expect(document.querySelector("[data-profile-copy-email]").textContent).toBe("Copied");

    });

});
