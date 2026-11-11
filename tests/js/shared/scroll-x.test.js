// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { bindScrollX } from "../../../resources/js/shared/scroll-x.js";

let observers;

beforeEach(() => {
    observers = [];
    globalThis.ResizeObserver = class {
        constructor(cb) {
            this.cb = cb;
            observers.push(this);
        }
        observe() {}
        disconnect() {}
    };
});

afterEach(() => {
    document.body.innerHTML = "";
});

// A scroller whose geometry the test controls (jsdom has no layout).
function scroller({ scrollLeft = 0, scrollWidth = 300, clientWidth = 100 } = {}) {
    document.body.innerHTML = '<div data-scroll-x tabindex="0" role="region" aria-label="t"><table></table></div>';
    const el = document.querySelector("[data-scroll-x]");
    const geo = { scrollLeft, scrollWidth, clientWidth };
    for (const key of Object.keys(geo)) {
        Object.defineProperty(el, key, { configurable: true, get: () => geo[key], set: (v) => { geo[key] = v; } });
    }
    bindScrollX(document.body);

    return { el, geo };
}

const fades = (el) => [el.hasAttribute("data-fade-start"), el.hasAttribute("data-fade-end")];

describe("bindScrollX", () => {
    it("no fades when the content fits", () => {
        const { el } = scroller({ scrollWidth: 100, clientWidth: 100 });
        expect(fades(el)).toEqual([false, false]);
    });

    it("only the end fade at scrollLeft 0", () => {
        const { el } = scroller();
        expect(fades(el)).toEqual([false, true]);
    });

    it("both fades in the middle", () => {
        const { el, geo } = scroller();
        geo.scrollLeft = 100;
        el.dispatchEvent(new Event("scroll"));
        expect(fades(el)).toEqual([true, true]);
    });

    it("only the start fade at the end", () => {
        const { el, geo } = scroller();
        geo.scrollLeft = 200;
        el.dispatchEvent(new Event("scroll"));
        expect(fades(el)).toEqual([true, false]);
    });

    it("a resize recomputes the fades", () => {
        const { el, geo } = scroller();
        geo.scrollWidth = 100;
        observers.forEach((o) => o.cb());
        expect(fades(el)).toEqual([false, false]);
    });

    it("binding twice does not double the listeners", () => {
        document.body.innerHTML = '<div data-scroll-x><table></table></div>';
        const el = document.querySelector("[data-scroll-x]");
        const add = vi.spyOn(el, "addEventListener");
        bindScrollX(document.body);
        bindScrollX(document.body);
        expect(add.mock.calls.filter(([type]) => type === "scroll")).toHaveLength(1);
        expect(observers).toHaveLength(1);
    });

    // A strip at x=50 in the window, already scrolled by 30, whose current tab is drawn at x=420 (80 wide).
    function strip(attr) {
        document.body.innerHTML = `<div data-scroll-x ${attr}><ul><li><a href="/a">A</a></li><li><a href="/b" aria-current="page">B</a></li></ul></div>`;
        const el = document.querySelector("[data-scroll-x]");
        const current = document.querySelector("[aria-current=page]");
        const geo = { scrollWidth: 600, clientWidth: 200, scrollLeft: 30 };
        for (const key of Object.keys(geo)) {
            Object.defineProperty(el, key, { configurable: true, get: () => geo[key], set: (v) => { geo[key] = v; } });
        }
        el.getBoundingClientRect = () => ({ left: 50, width: 200 });
        current.getBoundingClientRect = () => ({ left: 420, width: 80 });
        // offsetLeft is against the offset parent, not the scroller: a wrong origin must not be used.
        Object.defineProperty(current, "offsetLeft", { configurable: true, value: 999 });
        current.scrollIntoView = vi.fn();
        window.scrollTo = vi.fn();
        bindScrollX(document.body);

        return { geo, current };
    }

    it("centres the current tab of an opted-in strip on init without moving the page", () => {
        const { geo, current } = strip("data-scroll-current");
        // its left in the content: 420 - 50 + 30 = 400; centred: 400 - (200 - 80) / 2 = 340, inside [0, 400]
        expect(geo.scrollLeft).toBe(340);
        expect(current.scrollIntoView).not.toHaveBeenCalled();
        expect(window.scrollTo).not.toHaveBeenCalled();
    });

    it("leaves a current link alone in a scroller that has not opted in", () => {
        const { geo } = strip("");
        expect(geo.scrollLeft).toBe(30);
    });

    it("leaves tables alone", () => {
        document.body.innerHTML = '<div data-scroll-x><table><tr><td>x</td></tr></table></div>';
        expect(() => bindScrollX(document.body)).not.toThrow();
    });
});
