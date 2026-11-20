// @vitest-environment jsdom
import { afterEach, describe, expect, it } from "vitest";
import { bindPhoneTabs } from "../../../resources/js/shared/phone-tabs.js";

function page({ withPanelB = true } = {}) {
    document.body.innerHTML = `
        <div data-phone-tabs><div role="tablist">
            <button role="tab" data-phone-tab="a" aria-selected="true">A</button>
            <button role="tab" data-phone-tab="b" aria-selected="false" tabindex="-1">B</button>
            <button role="tab" data-phone-tab="c" aria-selected="false" tabindex="-1">C</button>
        </div></div>
        <section data-phone-panel="a" data-phone-active></section>
        ${withPanelB ? '<section data-phone-panel="b"></section><section data-phone-panel="b"></section>' : ""}
        <section data-phone-panel="c"></section>`;
    bindPhoneTabs(document);
    const tab = (k) => document.querySelector(`[data-phone-tab="${k}"]`);
    const active = () => [...document.querySelectorAll("[data-phone-active]")].map((p) => p.dataset.phonePanel);

    return { tab, active };
}

const key = (el, k) => el.dispatchEvent(new KeyboardEvent("keydown", { key: k, bubbles: true, cancelable: true }));

afterEach(() => {
    document.body.innerHTML = "";
});

describe("phone tabs", () => {
    it("click selects a tab and moves data-phone-active", () => {
        const { tab, active } = page();

        tab("b").click();

        expect(tab("b").getAttribute("aria-selected")).toBe("true");
        expect(tab("a").getAttribute("aria-selected")).toBe("false");
        expect(tab("b").tabIndex).toBe(0);
        expect(tab("a").tabIndex).toBe(-1);
        expect(active()).toEqual(["b", "b"]);
    });

    it("arrow keys wrap and move focus", () => {
        const { tab, active } = page();

        key(tab("a"), "ArrowLeft");
        expect(document.activeElement).toBe(tab("c"));
        expect(active()).toEqual(["c"]);

        key(tab("c"), "ArrowRight");
        expect(document.activeElement).toBe(tab("a"));
        expect(active()).toEqual(["a"]);
    });

    it("Home and End jump to the first and last", () => {
        const { tab, active } = page();

        key(tab("a"), "End");
        expect(active()).toEqual(["c"]);
        key(tab("c"), "Home");
        expect(active()).toEqual(["a"]);
    });

    it("a tab with no panel does nothing", () => {
        const { tab, active } = page({ withPanelB: false });

        tab("b").click();

        expect(tab("b").getAttribute("aria-selected")).toBe("false");
        expect(active()).toEqual(["a"]);
    });

    it("binding twice does not double-handle", () => {
        const { tab } = page();
        let calls = 0;

        document.querySelector("[data-phone-panel='b']").toggleAttribute = function (...args) {
            calls++;
            return Element.prototype.toggleAttribute.apply(this, args);
        };
        bindPhoneTabs(document);
        tab("b").click();

        expect(calls).toBe(1);
    });
});
