// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, onTestFinished, vi } from "vitest";

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
        const prev = [window.innerWidth, window.innerHeight];
        onTestFinished(() => { window.innerWidth = prev[0]; window.innerHeight = prev[1]; });
        window.innerWidth = 200;
        window.innerHeight = 150;
        const spy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockReturnValue({
            width: 120, height: 100, top: 0, left: 0, right: 120, bottom: 100,
        });
        menu.open(items, { x: 190, y: 140, trigger });
        const left = parseFloat(el().style.left);
        const top = parseFloat(el().style.top);
        expect(left).toBeGreaterThanOrEqual(4);
        expect(left).toBeLessThanOrEqual(200 - 120 - 4);
        expect(top).toBeGreaterThanOrEqual(4);
        expect(top).toBeLessThanOrEqual(150 - 100 - 4);
        spy.mockRestore();
    });
});

describe("gestures edge cases", () => {
    let box;
    let row;
    let area;
    let onOpen;
    let unbind;
    const touch = (target, type, x = 0, y = 0) => {
        const e = new Event(type, { bubbles: true });
        e.touches = [{ clientX: x, clientY: y }];
        target.dispatchEvent(e);
    };

    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = `<div id="box"><div class="row" tabindex="0">row</div><textarea class="row"></textarea></div>`;
        box = document.getElementById("box");
        row = box.querySelector("div.row");
        area = box.querySelector("textarea");
        onOpen = vi.fn();
        unbind = bindMenuTriggers(box, { selector: ".row", onOpen });
    });

    afterEach(() => {
        unbind();
        vi.useRealTimers();
    });

    it("opens nothing for Shift+F10 or long-press inside a textarea", () => {
        const e = key(area, "F10", { shiftKey: true });
        expect(e.defaultPrevented).toBe(false);
        touch(area, "touchstart");
        vi.advanceTimersByTime(1000);
        expect(onOpen).not.toHaveBeenCalled();
    });

    it("touchend and touchcancel cancel the timer", () => {
        ["touchend", "touchcancel"].forEach((type) => {
            touch(row, "touchstart");
            vi.advanceTimersByTime(300);
            row.dispatchEvent(new Event(type, { bubbles: true }));
            vi.advanceTimersByTime(1000);
        });
        expect(onOpen).not.toHaveBeenCalled();
    });

    it("Shift+F10 opens at the element bounds and prevents default", () => {
        vi.spyOn(row, "getBoundingClientRect").mockReturnValue({ left: 11, bottom: 22, top: 0, right: 0, width: 0, height: 0 });
        const e = key(row, "F10", { shiftKey: true });
        expect(e.defaultPrevented).toBe(true);
        expect(onOpen).toHaveBeenCalledWith(row, { x: 11, y: 22, trigger: row });
    });

    it("clears the swallowed click after one click", () => {
        touch(row, "touchstart");
        vi.advanceTimersByTime(500);
        const first = new MouseEvent("click", { bubbles: true, cancelable: true });
        row.dispatchEvent(first);
        const second = new MouseEvent("click", { bubbles: true, cancelable: true });
        row.dispatchEvent(second);
        expect(first.defaultPrevented).toBe(true);
        expect(second.defaultPrevented).toBe(false);
    });

    it("stops swallowing after the touchend grace period", () => {
        touch(row, "touchstart");
        vi.advanceTimersByTime(500);
        row.dispatchEvent(new Event("touchend", { bubbles: true }));
        vi.advanceTimersByTime(401);
        const click = new MouseEvent("click", { bubbles: true, cancelable: true });
        row.dispatchEvent(click);
        expect(click.defaultPrevented).toBe(false);
    });

    it("ignores the contextmenu a long-press also fires", () => {
        touch(row, "touchstart");
        vi.advanceTimersByTime(500);
        row.dispatchEvent(new MouseEvent("contextmenu", { bubbles: true, cancelable: true }));
        expect(onOpen).toHaveBeenCalledTimes(1);
    });

    it("tolerates small finger movement but cancels on large", () => {
        touch(row, "touchstart", 0, 0);
        touch(row, "touchmove", 5, 5);
        vi.advanceTimersByTime(500);
        expect(onOpen).toHaveBeenCalledTimes(1);

        touch(row, "touchstart", 0, 0);
        touch(row, "touchmove", 30, 0);
        vi.advanceTimersByTime(500);
        expect(onOpen).toHaveBeenCalledTimes(1);
    });
});

describe("menu edge cases", () => {
    let menu;
    let trigger;
    const items = () => [
        { id: "a", label: "A", onSelect: vi.fn() },
        { id: "b", label: "B", onSelect: vi.fn() },
        { id: "c", label: "C", onSelect: vi.fn() },
    ];

    beforeEach(() => {
        document.body.innerHTML = `<button id="t">t</button>`;
        trigger = document.getElementById("t");
        menu = createMenu(document);
        menu.open(items(), { x: 0, y: 0, trigger });
    });

    afterEach(() => menu.close());

    it("Tab closes", () => {
        key(document.activeElement, "Tab");
        expect(menu.isOpen()).toBe(false);
    });

    it("wraps with ArrowDown and Home", () => {
        key(document.activeElement, "End");
        key(document.activeElement, "ArrowDown");
        expect(document.activeElement.dataset.action).toBe("a");
        key(document.activeElement, "ArrowUp");
        expect(document.activeElement.dataset.action).toBe("c");
        key(document.activeElement, "Home");
        expect(document.activeElement.dataset.action).toBe("a");
    });

    it("closes on scroll and on resize", () => {
        document.dispatchEvent(new Event("scroll"));
        expect(menu.isOpen()).toBe(false);
        menu.open(items(), { x: 0, y: 0, trigger });
        window.dispatchEvent(new Event("resize"));
        expect(menu.isOpen()).toBe(false);
    });

    it("emoji buttons are radios and the plus focuses the first new one", () => {
        menu.open(
            [{ id: "reactions", type: "reactions", emojis: QUICK, selected: QUICK[1], onPick: vi.fn() }],
            { x: 0, y: 0, trigger },
        );
        const radios = [...document.querySelectorAll("[data-emoji]")];
        expect(radios[0].getAttribute("role")).toBe("menuitemradio");
        expect(radios[1].getAttribute("aria-checked")).toBe("true");
        expect(radios[0].getAttribute("aria-checked")).toBe("false");
        document.querySelector('[data-action="more-reactions"]').click();
        expect(document.activeElement.dataset.emoji).toBe(PALETTE[5]);
    });
});