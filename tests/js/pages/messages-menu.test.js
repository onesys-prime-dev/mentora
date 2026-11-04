// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { createMenu } from "../../../resources/js/pages/messages/menu.js";
import { bindMenuTriggers } from "../../../resources/js/pages/messages/gestures.js";
import { PALETTE, QUICK } from "../../../resources/js/pages/messages/menu-items.js";

const key = (target, k, init = {}) => {
    const e = new KeyboardEvent("keydown", { key: k, bubbles: true, cancelable: true, ...init });
    target.dispatchEvent(e);

    return e;
};

describe("gestures", () => {
    let box;
    let onOpen;
    let unbind;

    beforeEach(() => {
        document.body.innerHTML = `<div id="box"><div class="row" tabindex="0">row</div><textarea class="row"></textarea></div>`;
        box = document.getElementById("box");
        onOpen = vi.fn();
        unbind = bindMenuTriggers(box, { selector: ".row", onOpen });
    });

    afterEach(() => {
        unbind();
        vi.useRealTimers();
    });

    it("opens on contextmenu and prevents default", () => {
        const row = box.querySelector("div.row");
        const e = new MouseEvent("contextmenu", { bubbles: true, cancelable: true, clientX: 5, clientY: 7 });
        row.dispatchEvent(e);
        expect(e.defaultPrevented).toBe(true);
        expect(onOpen).toHaveBeenCalledWith(row, { x: 5, y: 7, trigger: row });
    });

    it("leaves the native menu inside a textarea", () => {
        const e = new MouseEvent("contextmenu", { bubbles: true, cancelable: true });
        box.querySelector("textarea").dispatchEvent(e);
        expect(e.defaultPrevented).toBe(false);
        expect(onOpen).not.toHaveBeenCalled();
    });

    it("long-press opens at 500 ms and swallows the click", () => {
        vi.useFakeTimers();
        const row = box.querySelector("div.row");
        const t = new Event("touchstart", { bubbles: true });
        t.touches = [{ clientX: 3, clientY: 4 }];
        row.dispatchEvent(t);
        vi.advanceTimersByTime(499);
        expect(onOpen).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(onOpen).toHaveBeenCalledWith(row, { x: 3, y: 4, trigger: row });

        const click = new MouseEvent("click", { bubbles: true, cancelable: true });
        row.dispatchEvent(click);
        expect(click.defaultPrevented).toBe(true);
    });

    it("cancels the long-press on touchmove", () => {
        vi.useFakeTimers();
        const row = box.querySelector("div.row");
        row.dispatchEvent(new Event("touchstart", { bubbles: true }));
        row.dispatchEvent(new Event("touchmove", { bubbles: true }));
        vi.advanceTimersByTime(1000);
        expect(onOpen).not.toHaveBeenCalled();
    });

    it("opens on Shift+F10 and ContextMenu", () => {
        const row = box.querySelector("div.row");
        key(row, "F10", { shiftKey: true });
        key(row, "ContextMenu");
        expect(onOpen).toHaveBeenCalledTimes(2);
    });

    it("unbinds", () => {
        unbind();
        box.querySelector("div.row").dispatchEvent(new MouseEvent("contextmenu", { bubbles: true, cancelable: true }));
        expect(onOpen).not.toHaveBeenCalled();
    });
});

describe("menu", () => {
    let menu;
    let trigger;
    let onSelect;
    let onPick;
    let items;

    beforeEach(() => {
        document.body.innerHTML = `<button id="t">t</button>`;
        trigger = document.getElementById("t");
        onSelect = vi.fn();
        onPick = vi.fn();
        items = [
            { id: "reactions", type: "reactions", emojis: QUICK, selected: null, onPick },
            { id: "reply", label: "Reply", onSelect },
            { id: "delete-me", label: "Delete", separator: true, danger: true, onSelect: vi.fn() },
        ];
        menu = createMenu(document);
    });

    afterEach(() => menu.close());

    const el = () => document.querySelector('[data-role="menu"]');

    it("renders a menu with data hooks", () => {
        menu.open(items, { x: 0, y: 0, trigger });
        expect(menu.isOpen()).toBe(true);
        expect(el().getAttribute("role")).toBe("menu");
        expect(el().querySelector('[data-action="reply"]')).not.toBeNull();
        expect(el().querySelectorAll("[data-emoji]")).toHaveLength(5);
    });

    it("Escape closes and returns focus", () => {
        menu.open(items, { x: 0, y: 0, trigger });
        key(document.activeElement, "Escape");
        expect(menu.isOpen()).toBe(false);
        expect(el()).toBeNull();
        expect(document.activeElement).toBe(trigger);
    });

    it("arrows move focus and Enter selects", () => {
        menu.open(items, { x: 0, y: 0, trigger });
        const first = document.activeElement;
        expect(first.dataset.emoji).toBe(QUICK[0]);
        key(first, "End");
        expect(document.activeElement.dataset.action).toBe("delete-me");
        key(document.activeElement, "ArrowUp");
        expect(document.activeElement.dataset.action).toBe("reply");
        key(document.activeElement, "Enter");
        expect(onSelect).toHaveBeenCalledTimes(1);
        expect(menu.isOpen()).toBe(false);
    });

    it("outside pointerdown closes", () => {
        menu.open(items, { x: 0, y: 0, trigger });
        document.body.dispatchEvent(new Event("pointerdown", { bubbles: true }));
        expect(menu.isOpen()).toBe(false);
    });

    it("reaction buttons call onPick with the emoji", () => {
        menu.open(items, { x: 0, y: 0, trigger });
        [...el().querySelectorAll("[data-emoji]")].find((b) => b.dataset.emoji === "😂").click();
        expect(onPick).toHaveBeenCalledWith("😂");
        expect(menu.isOpen()).toBe(false);
    });

    it("the plus expands to the full palette", () => {
        menu.open(items, { x: 0, y: 0, trigger });
        el().querySelector('[data-action="more-reactions"]').click();
        expect(el().querySelectorAll("[data-emoji]")).toHaveLength(PALETTE.length);
    });

    it("stays inside a small viewport", () => {
        window.innerWidth = 200;
        window.innerHeight = 150;
        const spy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockReturnValue({
            width: 120, height: 100, top: 0, left: 0, right: 120, bottom: 100,
        });
        menu.open(items, { x: 190, y: 140, trigger });
        const left = parseFloat(el().style.left);
        const top = parseFloat(el().style.top);
        expect(left).toBeGreaterThanOrEqual(0);
        expect(left + 120).toBeLessThanOrEqual(200);
        expect(top).toBeGreaterThanOrEqual(0);
        expect(top + 100).toBeLessThanOrEqual(150);
        spy.mockRestore();
    });
});
