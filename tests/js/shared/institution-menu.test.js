// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";

const MENU = `
    <nav data-institution-menu>
        <div><a href="/x" title="X">X</a><button type="button" data-menu-collapse>Collapse</button></div>
    </nav>
    <main><button id="outside">Outside</button></main>`;

// jsdom has no matchMedia; the rail query (lg to xl) matches when `rail` is true. The screen does not hover
// (a touch tablet), since jsdom counts the clicked element as :hover.
const stubViewport = (rail) => {
    const query = { matches: rail, addEventListener: vi.fn() };
    vi.stubGlobal("matchMedia", vi.fn((media) => (media === "(hover: hover)" ? { matches: false } : query)));
    return query;
};

const load = async (html) => {
    document.body.innerHTML = html;
    vi.resetModules();
    return import("../../../resources/js/shared/institution-menu.js");
};

afterEach(() => {
    document.body.innerHTML = "";
    localStorage.clear();
});

describe("side menu", () => {
    it("at xl the toggle collapses and remembers it", async () => {
        stubViewport(false);
        await load(MENU);
        const menu = document.querySelector("[data-institution-menu]");
        const toggle = menu.querySelector("[data-menu-collapse]");

        toggle.click();

        expect(menu.dataset.collapsed).toBe("true");
        expect(toggle.getAttribute("aria-pressed")).toBe("true");
        expect(toggle.getAttribute("aria-label")).toBe("Expand the menu");
        expect(localStorage.getItem("mentora-institution-menu")).toBe("collapsed");
        expect(menu.hasAttribute("data-peek")).toBe(false);
    });

    it("at lg the toggle sets data-peek and does not touch storage", async () => {
        stubViewport(true);
        await load(MENU);
        const menu = document.querySelector("[data-institution-menu]");

        menu.querySelector("[data-menu-collapse]").click();

        expect(menu.dataset.peek).toBe("true");
        expect(menu.hasAttribute("data-collapsed")).toBe(false);
        expect(localStorage.getItem("mentora-institution-menu")).toBeNull();

        menu.querySelector("[data-menu-collapse]").click();

        expect(menu.dataset.peek).toBe("false");
        expect(localStorage.getItem("mentora-institution-menu")).toBeNull();
    });

    it("a toggle click while open closes it with data-peek false", async () => {
        stubViewport(true);
        await load(MENU);
        const menu = document.querySelector("[data-institution-menu]");
        const toggle = menu.querySelector("[data-menu-collapse]");

        toggle.click();
        toggle.click();

        expect(menu.dataset.peek).toBe("false");
        expect(toggle.getAttribute("aria-label")).toBe("Expand the menu");
    });

    it("Escape while focus is inside sets data-peek false and leaves focus there", async () => {
        stubViewport(true);
        await load(MENU);
        const menu = document.querySelector("[data-institution-menu]");
        const link = menu.querySelector("a");
        link.focus();

        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));

        expect(menu.dataset.peek).toBe("false");
        expect(document.activeElement).toBe(link);
    });

    it("an outside click closes an open peek with data-peek false", async () => {
        stubViewport(true);
        await load(MENU);
        const menu = document.querySelector("[data-institution-menu]");
        menu.querySelector("[data-menu-collapse]").click();

        menu.querySelector("div").dispatchEvent(new MouseEvent("click", { bubbles: true }));
        expect(menu.dataset.peek).toBe("true");

        document.getElementById("outside").click();
        expect(menu.dataset.peek).toBe("false");
    });

    // jsdom matches neither :has(:focus-visible) nor a real :hover, so the menu reports the state it would be in.
    it("keyboard focus opening the rail relabels the toggle", async () => {
        stubViewport(true);
        await load(MENU);
        const menu = document.querySelector("[data-institution-menu]");
        const toggle = menu.querySelector("[data-menu-collapse]");
        expect(toggle.getAttribute("aria-label")).toBe("Expand the menu");

        menu.matches = (selector) => selector === ":has(:focus-visible)";
        menu.querySelector("a").dispatchEvent(new FocusEvent("focusin", { bubbles: true }));

        expect(toggle.getAttribute("aria-label")).toBe("Collapse the menu");
        expect(toggle.getAttribute("aria-pressed")).toBe("false");
    });

    it("a hover opening the rail relabels the toggle", async () => {
        const query = { matches: true, addEventListener: vi.fn() };
        vi.stubGlobal("matchMedia", vi.fn(() => query));
        await load(MENU);
        const menu = document.querySelector("[data-institution-menu]");
        const toggle = menu.querySelector("[data-menu-collapse]");

        menu.matches = (selector) => selector === ":hover";
        menu.dispatchEvent(new Event("pointerenter"));

        expect(toggle.getAttribute("aria-label")).toBe("Collapse the menu");

        menu.matches = () => false;
        menu.dispatchEvent(new Event("pointerleave"));

        expect(toggle.getAttribute("aria-label")).toBe("Expand the menu");
    });

    it("pointerleave clears the closed state", async () => {
        stubViewport(true);
        await load(MENU);
        const menu = document.querySelector("[data-institution-menu]");
        menu.setAttribute("data-peek", "false");

        menu.dispatchEvent(new Event("pointerleave"));

        expect(menu.hasAttribute("data-peek")).toBe(false);
    });

    it("focusout to outside the menu clears the closed state, focus moving inside does not", async () => {
        stubViewport(true);
        await load(MENU);
        const menu = document.querySelector("[data-institution-menu]");
        menu.setAttribute("data-peek", "false");

        menu.querySelector("a").dispatchEvent(new FocusEvent("focusout", { bubbles: true, relatedTarget: menu.querySelector("button") }));
        expect(menu.dataset.peek).toBe("false");

        menu.querySelector("a").dispatchEvent(new FocusEvent("focusout", { bubbles: true, relatedTarget: document.getElementById("outside") }));
        expect(menu.hasAttribute("data-peek")).toBe(false);
    });

    it("clears data-peek when the viewport crosses xl", async () => {
        const query = stubViewport(true);
        await load(MENU);
        const menu = document.querySelector("[data-institution-menu]");
        menu.querySelector("[data-menu-collapse]").click();

        query.matches = false;
        query.addEventListener.mock.calls.find(([type]) => type === "change")[1]();

        expect(menu.hasAttribute("data-peek")).toBe(false);
    });

    it("binds the admin menu too", async () => {
        stubViewport(true);
        await load(`<nav data-admin-menu data-peek="true"><a href="/a">A</a></nav><button id="outside">Outside</button>`);
        const menu = document.querySelector("[data-admin-menu]");

        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));

        expect(menu.dataset.peek).toBe("false");
    });
});
