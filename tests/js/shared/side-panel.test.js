// @vitest-environment jsdom
import { afterEach, describe, expect, it } from "vitest";
import { bindSidePanels } from "../../../resources/js/shared/side-panel.js";

const PAGE = `
    <button id="toggle" data-side-panel-toggle aria-controls="panel" aria-expanded="false">Students</button>
    <aside id="panel" data-side-panel><button id="inside" data-side-panel-close>Close</button></aside>`;

const panel = () => document.getElementById("panel");
const toggle = () => document.getElementById("toggle");

function page() {
    document.body.innerHTML = PAGE;
    bindSidePanels();
}

afterEach(() => {
    document.body.innerHTML = "";
});

describe("side panel", () => {
    it("starts closed, inert and aria-hidden", () => {
        page();
        expect(panel().inert).toBe(true);
        expect(panel().getAttribute("aria-hidden")).toBe("true");
    });

    it("toggle opens, sets aria-expanded and removes inert", () => {
        page();
        toggle().click();

        expect(toggle().getAttribute("aria-expanded")).toBe("true");
        expect(panel().hasAttribute("data-open")).toBe(true);
        expect(panel().inert).toBe(false);
        expect(panel().hasAttribute("aria-hidden")).toBe(false);
    });

    it("opening moves focus into the panel, closing returns it to the toggle", () => {
        page();
        toggle().click();
        expect(panel().contains(document.activeElement)).toBe(true);

        document.getElementById("inside").click();
        expect(document.activeElement).toBe(toggle());
    });

    it("Escape closes and returns focus to the toggle", () => {
        page();
        toggle().click();
        document.getElementById("inside").focus();
        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));

        expect(panel().hasAttribute("data-open")).toBe(false);
        expect(panel().inert).toBe(true);
        expect(document.activeElement).toBe(toggle());
    });

    it("backdrop click closes", () => {
        page();
        toggle().click();
        document.querySelector("[data-side-panel-backdrop]").click();

        expect(panel().hasAttribute("data-open")).toBe(false);
        expect(toggle().getAttribute("aria-expanded")).toBe("false");
        expect(document.activeElement).toBe(toggle());
    });

    it("binding twice does not double-handle", () => {
        page();
        bindSidePanels();
        toggle().click();

        expect(document.querySelectorAll("[data-side-panel-backdrop]")).toHaveLength(1);
        expect(panel().hasAttribute("data-open")).toBe(true);
    });
});
