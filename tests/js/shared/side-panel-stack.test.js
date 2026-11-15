// @vitest-environment jsdom
import { afterEach, describe, expect, it } from "vitest";

// A window that starts at phone width (below md). The queries keep their listeners, so a test can resize it.
const queries = {};
globalThis.matchMedia = (query) => {
    queries[query] ??= {
        matches: query === "(width < 48rem)",
        listeners: [],
        addEventListener(_, callback) { this.listeners.push(callback); },
    };

    return queries[query];
};
const { bindSidePanels } = await import("../../../resources/js/shared/side-panel.js");

/** Resizes to "phone" (below md), "tablet" (md–lg) or "laptop" (lg up) and fires the change events. */
function resize(to) {
    queries["(width < 48rem)"].matches = to === "phone";
    queries["(width >= 64rem)"].matches = to === "laptop";
    Object.values(queries).forEach((q) => q.listeners.forEach((callback) => callback()));
}

const panel = () => document.getElementById("p");
const toggle = () => document.getElementById("t");

afterEach(() => {
    document.body.innerHTML = "";
    resize("phone");
});

describe("side panel with data-side-panel-stack", () => {
    it("below md is an ordinary pane: never inert or aria-hidden, and never left open", () => {
        document.body.innerHTML = `<aside id="p" data-side-panel data-side-panel-stack data-open><input></aside>`;
        bindSidePanels();

        expect(panel().inert).toBe(false);
        expect(panel().hasAttribute("aria-hidden")).toBe(false);
        expect(panel().hasAttribute("data-open")).toBe(false);
    });

    it("a panel without it is still a closed slide-over", () => {
        document.body.innerHTML = `<aside id="p" data-side-panel><input></aside>`;
        bindSidePanels();

        expect(panel().inert).toBe(true);
    });

    it("crossing md: open at md–lg, closed and in the flow below md, a closed inert slide-over again above it", () => {
        document.body.innerHTML = `
            <button id="t" data-side-panel-toggle aria-controls="p" aria-expanded="false">Settings</button>
            <aside id="p" data-side-panel data-side-panel-stack><input></aside>`;
        resize("tablet");
        bindSidePanels();
        expect(panel().inert).toBe(true);

        toggle().click();
        expect(panel().hasAttribute("data-open")).toBe(true);
        expect(panel().inert).toBe(false);

        resize("phone");
        expect(panel().hasAttribute("data-open")).toBe(false);
        expect(panel().inert).toBe(false);
        expect(panel().previousElementSibling.hasAttribute("data-open")).toBe(false);
        expect(toggle().getAttribute("aria-expanded")).toBe("false");

        resize("tablet");
        expect(panel().hasAttribute("data-open")).toBe(false);
        expect(panel().inert).toBe(true);
        expect(panel().getAttribute("aria-hidden")).toBe("true");
    });
});

describe("Escape with a dialog open over the panel", () => {
    function openPanelWithDialog() {
        document.body.innerHTML = `
            <button id="t" data-side-panel-toggle aria-controls="p" aria-expanded="false">Settings</button>
            <aside id="p" data-side-panel><button id="choose">Choose students</button></aside>
            <div id="d" role="dialog" aria-modal="true"><button id="in-dialog">Save</button></div>`;
        resize("tablet");
        bindSidePanels();
        toggle().click();
        // jsdom lays nothing out: the dialog says it is shown.
        document.getElementById("d").checkVisibility = () => true;
    }

    const escape = (target) => target.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));

    it("leaves the panel open while the dialog is shown, wherever focus is", () => {
        openPanelWithDialog();
        escape(document.getElementById("choose"));
        escape(document.getElementById("in-dialog"));

        expect(panel().hasAttribute("data-open")).toBe(true);
    });

    it("closes the panel once the dialog is gone", () => {
        openPanelWithDialog();
        document.getElementById("d").checkVisibility = () => false;
        escape(document.getElementById("choose"));

        expect(panel().hasAttribute("data-open")).toBe(false);
        expect(document.activeElement).toBe(toggle());
    });
});
