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
    vi.doUnmock("../../../resources/js/shared/clamp-panel.js");
    vi.unstubAllGlobals();
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
            <button id="profile-menu-button" data-profile-menu-trigger aria-expanded="false">Me</button>
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

describe("profile menu with the phone ⋯ trigger", () => {
    beforeEach(async () => {
        document.body.innerHTML = `
            <button id="profile-menu-button" data-profile-menu-trigger aria-expanded="false">Me</button>
            <svg id="profile-menu-arrow"></svg>
            <button id="more" data-profile-menu-trigger aria-expanded="false">More</button>
            <div id="profile-menu-panel" class="hidden"><a id="inside" href="#">Settings</a></div>
            <div id="elsewhere">page</div>`;

        await load("profile-menu");
    });

    const button = () => document.getElementById("profile-menu-button");
    const more = () => document.getElementById("more");
    const panel = () => document.getElementById("profile-menu-panel");

    it("the ⋯ trigger opens the account panel and sets its own aria-expanded", () => {
        more().click();

        expect(panel().classList.contains("hidden")).toBe(false);
        expect(more().getAttribute("aria-expanded")).toBe("true");
        expect(button().getAttribute("aria-expanded")).toBe("false");

        more().click();

        expect(panel().classList.contains("hidden")).toBe(true);
        expect(more().getAttribute("aria-expanded")).toBe("false");
    });

    it("outside click ignores clicks on either trigger", () => {
        more().click();
        button().click();

        // The avatar button toggles the shared panel shut; it is not treated as an outside click first.
        expect(panel().classList.contains("hidden")).toBe(true);
        expect(more().getAttribute("aria-expanded")).toBe("false");

        button().click();
        expect(panel().classList.contains("hidden")).toBe(false);
        expect(button().getAttribute("aria-expanded")).toBe("true");

        document.getElementById("elsewhere").click();
        expect(panel().classList.contains("hidden")).toBe(true);
        expect(button().getAttribute("aria-expanded")).toBe("false");
    });

    it("Escape returns focus to the trigger that opened it", () => {
        more().click();
        key("Escape");

        expect(panel().classList.contains("hidden")).toBe(true);
        expect(document.activeElement).toBe(more());

        button().click();
        key("Escape");

        expect(document.activeElement).toBe(button());
    });
});

describe("header panels", () => {
    it("opening a header panel clamps it to the viewport", async () => {
        const clampToViewport = vi.fn();
        vi.doMock("../../../resources/js/shared/clamp-panel.js", () => ({ clampToViewport }));

        document.body.innerHTML = `
            <button id="notifications-button" aria-expanded="false">Bell</button>
            <div id="notifications-panel" class="hidden" data-feed-url="/notifications/feed">
                <div data-notifications-scroll><ul data-notifications-list></ul></div>
                <p data-notifications-empty></p><p data-notifications-error></p>
            </div>
            <button id="app-menu-button" aria-expanded="false">Apps</button>
            <div id="app-menu-panel" class="hidden"></div>
            <button id="profile-menu-button" data-profile-menu-trigger aria-expanded="false">Me</button>
            <svg id="profile-menu-arrow"></svg>
            <div id="profile-menu-panel" class="hidden"></div>`;

        vi.stubGlobal("fetch", vi.fn(() => new Promise(() => {})));

        await load("notifications-menu");
        await import("../../../resources/js/shared/app-menu.js");
        await import("../../../resources/js/shared/profile-menu.js");

        for (const id of ["notifications", "app-menu", "profile-menu"]) {
            clampToViewport.mockClear();
            document.getElementById(`${id}-button`).click();

            expect(clampToViewport).toHaveBeenCalledWith(document.getElementById(`${id}-panel`));
        }
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
