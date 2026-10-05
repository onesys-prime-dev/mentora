import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/*
 * app-menu.js and profile-menu.js wire themselves up when imported, so each
 * test builds the markup first and then imports a fresh copy of the module.
 */

const load = async (file) => {
    vi.resetModules();
    await import(`../../../resources/js/shared/${file}.js`);
};

const key = (name) => document.dispatchEvent(new KeyboardEvent("keydown", { key: name, bubbles: true }));

afterEach(() => {
    document.body.innerHTML = "";
});

describe("app menu", () => {
    beforeEach(async () => {
        document.body.innerHTML = `
            <button id="app-menu-button" aria-expanded="false">Apps</button>
            <div id="app-menu-panel" class="hidden"><a id="inside" href="#">Courses</a></div>
            <div id="elsewhere">page</div>`;

        await load("app-menu");
    });

    const button = () => document.getElementById("app-menu-button");
    const panel = () => document.getElementById("app-menu-panel");

    it("opens on click and tells assistive tech", () => {
        button().click();

        expect(panel().classList.contains("hidden")).toBe(false);
        expect(button().getAttribute("aria-expanded")).toBe("true");
    });

    it("closes on a second click of the button", () => {
        button().click();
        button().click();

        expect(panel().classList.contains("hidden")).toBe(true);
        expect(button().getAttribute("aria-expanded")).toBe("false");
    });

    it("closes when clicking elsewhere on the page", () => {
        button().click();
        document.getElementById("elsewhere").click();

        expect(panel().classList.contains("hidden")).toBe(true);
    });

    it("stays open when clicking inside the panel", () => {
        button().click();
        document.getElementById("inside").click();

        expect(panel().classList.contains("hidden")).toBe(false);
    });

    it("closes on Escape but ignores other keys", () => {
        button().click();

        key("Enter");
        expect(panel().classList.contains("hidden")).toBe(false);

        key("Escape");
        expect(panel().classList.contains("hidden")).toBe(true);
        expect(button().getAttribute("aria-expanded")).toBe("false");
    });

    it("does nothing on Escape while already closed", () => {
        key("Escape");

        expect(panel().classList.contains("hidden")).toBe(true);
        expect(button().getAttribute("aria-expanded")).toBe("false");
    });
});

describe("profile menu", () => {
    beforeEach(async () => {
        document.body.innerHTML = `
            <button id="profile-menu-button" aria-expanded="false">Me</button>
            <svg id="profile-menu-arrow"></svg>
            <div id="profile-menu-panel" class="hidden"><a id="inside" href="#">Settings</a></div>
            <div id="elsewhere">page</div>`;

        await load("profile-menu");
    });

    const button = () => document.getElementById("profile-menu-button");
    const panel = () => document.getElementById("profile-menu-panel");
    const arrow = () => document.getElementById("profile-menu-arrow");

    it("opens, flips the arrow and updates aria-expanded", () => {
        button().click();

        expect(panel().classList.contains("hidden")).toBe(false);
        expect(arrow().classList.contains("rotate-180")).toBe(true);
        expect(button().getAttribute("aria-expanded")).toBe("true");
    });

    it("closes and flips the arrow back on click, outside click and Escape", () => {
        for (const close of [() => button().click(), () => document.getElementById("elsewhere").click(), () => key("Escape")]) {
            button().click();
            expect(panel().classList.contains("hidden")).toBe(false);

            close();

            expect(panel().classList.contains("hidden")).toBe(true);
            expect(arrow().classList.contains("rotate-180")).toBe(false);
            expect(button().getAttribute("aria-expanded")).toBe("false");
        }
    });

    it("stays open for clicks inside the panel", () => {
        button().click();
        document.getElementById("inside").click();

        expect(panel().classList.contains("hidden")).toBe(false);
    });
});

describe("pages without a menu", () => {
    it("import cleanly and wire nothing", async () => {
        document.body.innerHTML = "<main>no header here</main>";

        await expect(load("app-menu")).resolves.toBeUndefined();
        await expect(load("profile-menu")).resolves.toBeUndefined();

        expect(() => key("Escape")).not.toThrow();
    });
});
