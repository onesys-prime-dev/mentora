import { afterEach, describe, expect, it } from "vitest";

import { bindActionMenu, closeAllActionMenus, computeMenuPosition } from "../../../resources/js/pages/courses/features/modules/action-menus.js";

const VIEWPORT = { width: 1000, height: 800 };
const MENU = { width: 176, height: 200 };
const button = (top, right = 900) => ({ top, bottom: top + 36, left: right - 36, right });

describe("where the menu goes", () => {
    it("under the button when there is room", () => {
        const spot = computeMenuPosition(button(100), MENU, VIEWPORT);

        expect(spot.placement).toBe("below");
        expect(spot.top).toBe(100 + 36 + 6);
        expect(spot.maxHeight).toBeNull();
    });

    it("over the button when there is not room under it", () => {
        const spot = computeMenuPosition(button(700), MENU, VIEWPORT);

        expect(spot.placement).toBe("above");
        expect(spot.top).toBe(700 - 6 - MENU.height);
        expect(spot.maxHeight).toBeNull();
    });

    it("lines the menu's right edge up with the button's", () => {
        const spot = computeMenuPosition(button(100, 600), MENU, VIEWPORT);

        expect(spot.left).toBe(600 - MENU.width);
    });

    it("never goes past the left edge of the screen", () => {
        const spot = computeMenuPosition(button(100, 50), MENU, VIEWPORT);

        expect(spot.left).toBe(8);
    });

    it("never goes past the right edge of the screen", () => {
        const spot = computeMenuPosition(button(100, 1200), MENU, VIEWPORT);

        expect(spot.left).toBe(VIEWPORT.width - MENU.width - 8);
    });

    it("when neither side has room, takes the bigger one and scrolls inside it", () => {
        const spot = computeMenuPosition(button(300), { width: 176, height: 900 }, VIEWPORT);

        // More room under the button (800 - 336 - 6 - 8) than over it (300 - 6 - 8).
        expect(spot.placement).toBe("below");
        expect(spot.maxHeight).toBe(800 - 336 - 6 - 8);
    });

    it("when the bigger side is above, goes above and scrolls inside it", () => {
        const spot = computeMenuPosition(button(600), { width: 176, height: 900 }, VIEWPORT);

        expect(spot.placement).toBe("above");
        expect(spot.maxHeight).toBe(600 - 6 - 8);
        expect(spot.top).toBeGreaterThanOrEqual(8);
    });

    it("keeps a short viewport's menu on the screen", () => {
        const spot = computeMenuPosition(button(10), MENU, { width: 1000, height: 220 });

        expect(spot.top).toBeGreaterThanOrEqual(8);
    });
});

/** A row with a ⋮ button and its panel, as the module views draw it. */
const markup = (n) => `
    <div class="relative" id="row-${n}">
        <button type="button" data-test-actions id="trigger-${n}">⋮</button>
        <div class="absolute right-0 top-10 hidden" data-test-panel data-action-menu-panel id="panel-${n}">
            <button type="button" data-entry id="entry-${n}">Edit</button>
        </div>
    </div>`;

const rect = (top, right = 900) => ({ top, bottom: top + 36, left: right - 36, right, width: 36, height: 36, x: right - 36, y: top });

function lay(id, { top, right, panelHeight = 200, panelWidth = 176 }) {

    document.getElementById(`trigger-${id}`).getBoundingClientRect = () => rect(top, right);

    const panel = document.getElementById(`panel-${id}`);

    Object.defineProperty(panel, "offsetHeight", { configurable: true, get: () => panelHeight });
    Object.defineProperty(panel, "offsetWidth", { configurable: true, get: () => panelWidth });

}

const panel = (id) => document.getElementById(`panel-${id}`);
const trigger = (id) => document.getElementById(`trigger-${id}`);
const isOpen = (id) => !panel(id).classList.contains("hidden");

function setup(floating = true) {

    document.body.innerHTML = markup(1) + markup(2);

    Object.defineProperty(document.documentElement, "clientWidth", { configurable: true, value: 1000 });
    Object.defineProperty(document.documentElement, "clientHeight", { configurable: true, value: 800 });

    lay(1, { top: 100 });
    lay(2, { top: 700 });

    bindActionMenu("[data-test-actions]", "[data-test-panel]", { floating });

}

afterEach(() => {
    closeAllActionMenus();
    document.body.innerHTML = "";
});

describe("a floating menu", () => {
    it("is drawn over the page, under the button when there is room", () => {
        setup();

        trigger(1).click();

        expect(isOpen(1)).toBe(true);
        expect(panel(1).parentElement).toBe(document.body);
        expect(panel(1).style.position).toBe("fixed");
        expect(panel(1).style.top).toBe("142px");
        expect(panel(1).style.left).toBe("724px");
        expect(panel(1).dataset.placement).toBe("below");
        expect(trigger(1).getAttribute("aria-expanded")).toBe("true");
    });

    it("opens over the button for a button near the bottom", () => {
        setup();

        trigger(2).click();

        expect(panel(2).dataset.placement).toBe("above");
        expect(panel(2).style.top).toBe(`${700 - 6 - 200}px`);
    });

    it("is not left in its row's box while open (a row's clipping cannot reach it)", () => {
        setup();

        trigger(1).click();

        expect(document.getElementById("row-1").contains(panel(1))).toBe(false);
    });

    it("goes back into its row when it closes, with nothing of the placement left", () => {
        setup();

        trigger(1).click();
        document.body.click();

        expect(isOpen(1)).toBe(false);
        expect(document.getElementById("row-1").contains(panel(1))).toBe(true);
        expect(panel(1).hasAttribute("style")).toBe(false);
        expect(panel(1).dataset.placement).toBeUndefined();
        expect(trigger(1).getAttribute("aria-expanded")).toBe("false");
    });

    it("closes when an entry is picked, and the entry still gets its click", () => {
        setup();
        let picked = 0;
        document.getElementById("entry-1").addEventListener("click", () => picked++);

        trigger(1).click();
        document.getElementById("entry-1").click();

        expect(picked).toBe(1);
        expect(isOpen(1)).toBe(false);
        expect(document.getElementById("row-1").contains(panel(1))).toBe(true);
    });

    it("opening another closes the first", () => {
        setup();

        trigger(1).click();
        trigger(2).click();

        expect(isOpen(1)).toBe(false);
        expect(document.getElementById("row-1").contains(panel(1))).toBe(true);
        expect(isOpen(2)).toBe(true);
    });

    it("the same button closes it", () => {
        setup();

        trigger(1).click();
        trigger(1).click();

        expect(isOpen(1)).toBe(false);
    });

    it("closes with Escape, and focus goes back to the button", () => {
        setup();

        trigger(1).click();
        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));

        expect(isOpen(1)).toBe(false);
        expect(document.activeElement).toBe(trigger(1));
    });

    it("follows its button when the page scrolls", () => {
        setup();

        trigger(1).click();
        lay(1, { top: 160 });
        window.dispatchEvent(new Event("scroll"));

        expect(panel(1).style.top).toBe(`${160 + 36 + 6}px`);
    });

    it("moves to the other side of the button when scrolling takes away the room", () => {
        setup();

        trigger(1).click();
        lay(1, { top: 700 });
        window.dispatchEvent(new Event("resize"));

        expect(panel(1).dataset.placement).toBe("above");
    });

    it("scrolls inside itself when it is taller than the screen allows", () => {
        setup();
        lay(1, { top: 300, panelHeight: 900 });

        trigger(1).click();

        expect(panel(1).style.overflowY).toBe("auto");
        expect(panel(1).style.maxHeight).toBe(`${800 - 336 - 6 - 8}px`);

        closeAllActionMenus();
        expect(panel(1).hasAttribute("style")).toBe(false);
    });

    it("is simply removed if its row was redrawn while it was open", () => {
        setup();

        trigger(1).click();
        document.getElementById("row-1").remove();
        closeAllActionMenus();

        expect(document.getElementById("panel-1")).toBeNull();
    });
});

describe("an ordinary menu", () => {
    it("stays in its row, shown and hidden as before", () => {
        setup(false);

        trigger(1).click();

        expect(isOpen(1)).toBe(true);
        expect(document.getElementById("row-1").contains(panel(1))).toBe(true);
        expect(panel(1).style.position).toBe("");

        document.body.click();
        expect(isOpen(1)).toBe(false);
    });
});
