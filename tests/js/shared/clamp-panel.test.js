import { beforeEach, describe, expect, it } from "vitest";
import { clampToViewport } from "../../../resources/js/shared/clamp-panel.js";

const setViewport = (width) => Object.defineProperty(document.documentElement, "clientWidth", { value: width, configurable: true });

// jsdom measures nothing, so fake a rect that follows the panel's inline max-width and translate.
const fakePanel = (left, width) => {
    const panel = document.createElement("div");
    panel.getBoundingClientRect = () => {
        const cap = parseFloat(panel.style.maxWidth);
        const shift = parseFloat(panel.style.translate) || 0;
        const w = Number.isNaN(cap) ? width : Math.min(width, cap);
        return { left: left + shift, right: left + shift + w, width: w };
    };
    return panel;
};

describe("clampToViewport", () => {
    beforeEach(() => setViewport(375));

    it("leaves a panel that fits untouched", () => {
        const panel = fakePanel(20, 288);
        clampToViewport(panel);
        expect(panel.style.translate).toBe("");
    });

    it("shifts a panel overflowing the right edge left", () => {
        const panel = fakePanel(200, 288);
        clampToViewport(panel);
        expect(panel.style.translate).toBe("-125px 0");
    });

    it("shifts a panel overflowing the left edge right", () => {
        const panel = fakePanel(-30, 288);
        clampToViewport(panel);
        expect(panel.style.translate).toBe("42px 0");
    });

    it("caps a panel wider than the viewport", () => {
        setViewport(320);
        const panel = fakePanel(0, 384);
        clampToViewport(panel);
        expect(panel.style.maxWidth).toBe("296px");
        expect(panel.getBoundingClientRect().left).toBe(12);
    });

    it("re-clamps from scratch on a second call", () => {
        const panel = fakePanel(200, 288);
        clampToViewport(panel);
        setViewport(1024);
        clampToViewport(panel);
        expect(panel.style.translate).toBe("");
    });

    it("measures with scale turned off and restores the inline scale after", () => {
        const panel = fakePanel(200, 288);
        const seen = [];
        const measure = panel.getBoundingClientRect;
        panel.getBoundingClientRect = () => { seen.push(panel.style.scale); return measure(); };
        panel.style.scale = "0.9";

        clampToViewport(panel);

        expect(seen.length).toBeGreaterThan(0);
        expect(seen.every((value) => value === "none")).toBe(true);
        expect(panel.style.scale).toBe("0.9");
    });

    // A running enter animation's `scale` beats a normal inline declaration; only !important wins over it.
    it("turns scale off with !important while measuring and restores value and priority after", () => {
        const panel = fakePanel(200, 288);
        const seen = [];
        const measure = panel.getBoundingClientRect;
        panel.getBoundingClientRect = () => { seen.push(panel.style.getPropertyPriority("scale")); return measure(); };

        clampToViewport(panel);
        expect(seen.every((priority) => priority === "important")).toBe(true);
        expect(panel.style.getPropertyValue("scale")).toBe("");

        panel.style.setProperty("scale", "0.95");
        clampToViewport(panel);
        expect(panel.style.getPropertyValue("scale")).toBe("0.95");
        expect(panel.style.getPropertyPriority("scale")).toBe("");
    });

    it("clears a previous max-width on a second call", () => {
        setViewport(320);
        const panel = fakePanel(20, 384);
        clampToViewport(panel);
        setViewport(1024);
        clampToViewport(panel);
        expect(panel.style.maxWidth).toBe("");
        expect(panel.style.translate).toBe("");
    });
});
