// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { createPhoneNav } from "../../../resources/js/pages/messages/phone-nav.js";

const SHELL = `
<div id="messages-app" data-view="list">
  <section data-pane="list"><div data-role="rows"><button data-id="7"></button></div></section>
  <section data-pane="thread">
    <button data-role="back"></button>
    <div data-role="scroller"><p>hi</p></div>
    <form data-role="composer"><textarea data-role="body"></textarea></form>
  </section>
</div>`;

function touch(el, type, x, y) {
    const event = new Event(type, { bubbles: true });
    event[type === "touchend" ? "changedTouches" : "touches"] = [{ clientX: x, clientY: y }];
    el.dispatchEvent(event);
}

function swipe(el, dx, dy = 0) {
    touch(el, "touchstart", 20, 200);
    touch(el, "touchend", 20 + dx, 200 + dy);
}

describe("messages phone navigation", () => {
    let root;
    let onBack;
    let phone;
    let nav;

    const thread = () => root.querySelector("[data-pane=thread]");
    const list = () => root.querySelector("[data-pane=list]");

    // What messages.js does around the module: it sets data-view, then tells the module.
    const open = (id, options) => { root.dataset.view = "thread"; nav.opened(id, options); };

    beforeEach(() => {
        document.body.innerHTML = SHELL;
        root = document.getElementById("messages-app");
        phone = true;
        onBack = vi.fn(() => { root.dataset.view = "list"; nav.closed(); });
        vi.spyOn(history, "pushState");
        vi.spyOn(history, "back").mockImplementation(() => {});
        nav = createPhoneNav({ root, isPhone: () => phone, onBack });
    });

    afterEach(() => {
        nav.destroy();
        vi.restoreAllMocks();
    });

    it("opening a thread on a phone pushes one history entry", () => {
        open(7);
        open(8);

        expect(history.pushState).toHaveBeenCalledTimes(1);
        expect(history.pushState).toHaveBeenCalledWith({ messagesThread: 7 }, "");
        expect(list().hasAttribute("inert")).toBe(true);
        expect(list().getAttribute("aria-hidden")).toBe("true");
        expect(thread().hasAttribute("inert")).toBe(false);
    });

    it("popstate calls onBack", () => {
        open(7);
        window.dispatchEvent(new PopStateEvent("popstate", { state: null }));

        expect(onBack).toHaveBeenCalledTimes(1);
        // The entry is already gone, so closing does not go back a second time.
        expect(history.back).not.toHaveBeenCalled();
        expect(thread().hasAttribute("inert")).toBe(true);
        expect(list().hasAttribute("inert")).toBe(false);
    });

    it("an in-app back after a push goes back through history", () => {
        open(7);
        root.dataset.view = "list";
        nav.closed();

        expect(history.back).toHaveBeenCalledTimes(1);
    });

    it("a thread opened without a push calls onBack directly", () => {
        // Opened on a wide window, then narrowed to a phone: there is no entry of this thread's own.
        phone = false;
        open(7);
        phone = true;
        window.dispatchEvent(new Event("resize"));
        expect(history.pushState).not.toHaveBeenCalled();
        expect(list().hasAttribute("inert")).toBe(true);

        swipe(thread(), 120);

        expect(onBack).toHaveBeenCalledTimes(1);
        // Nothing was pushed, so going back in history would leave the page.
        expect(history.back).not.toHaveBeenCalled();
    });

    it("a deep link puts the list under the thread, so Back lands on the list", () => {
        history.replaceState(null, "", "/index.php/messages/7?x=1");
        vi.spyOn(history, "replaceState");

        open(7, { deepLink: true });

        expect(history.replaceState).toHaveBeenCalledWith(null, "", "http://localhost:3000/index.php/messages?x=1");
        expect(history.pushState).toHaveBeenCalledWith({ messagesThread: 7 }, "", "http://localhost:3000/index.php/messages/7?x=1");
        expect(root.hasAttribute("data-instant")).toBe(true);

        window.dispatchEvent(new PopStateEvent("popstate", { state: null }));
        expect(onBack).toHaveBeenCalledTimes(1);
    });

    it("focus moves to the thread's Back on open and back to the row on close", () => {
        const row = root.querySelector("[data-role=rows] button");
        row.focus();

        open(7);
        expect(document.activeElement).toBe(root.querySelector("[data-role=back]"));

        root.dataset.view = "list";
        nav.closed();
        expect(document.activeElement).toBe(row);
    });

    it("a rightward swipe calls onBack", () => {
        open(7);
        swipe(thread(), 120, 10);

        expect(onBack).toHaveBeenCalledTimes(1);
        expect(history.back).toHaveBeenCalledTimes(1);
    });

    it("a short, vertical or leftward swipe does not", () => {
        open(7);
        swipe(thread(), 60);
        swipe(thread(), 100, 90);
        swipe(thread(), -120);

        expect(onBack).not.toHaveBeenCalled();
    });

    it("a swipe starting in the composer does not", () => {
        open(7);
        swipe(root.querySelector("[data-role=body]"), 150);

        expect(onBack).not.toHaveBeenCalled();
    });

    it("nothing is pushed above md", () => {
        phone = false;
        open(7);
        swipe(thread(), 150);
        root.dataset.view = "list";
        nav.closed();

        expect(history.pushState).not.toHaveBeenCalled();
        expect(history.back).not.toHaveBeenCalled();
        expect(onBack).not.toHaveBeenCalled();
        expect(list().hasAttribute("inert")).toBe(false);
        expect(thread().hasAttribute("inert")).toBe(false);
    });

    it("crossing md lifts or restores inert", () => {
        open(7);
        phone = false;
        window.dispatchEvent(new Event("resize"));
        expect(list().hasAttribute("inert")).toBe(false);

        phone = true;
        window.dispatchEvent(new Event("resize"));
        expect(list().hasAttribute("inert")).toBe(true);
    });

    it("destroy removes the listeners", () => {
        open(7);
        nav.destroy();
        window.dispatchEvent(new PopStateEvent("popstate", { state: null }));
        swipe(thread(), 150);

        expect(onBack).not.toHaveBeenCalled();
    });
});
