import { afterEach, describe, expect, it, vi } from "vitest";

import { bindProgram, placeTools } from "../../../resources/js/pages/grades/program.js";

// Nudge and Download: beside the pager from lg, in the section's action bar (its More panel) below lg.
const page = () => {
    document.body.innerHTML = `
        <div data-program-tools-home><p>pager</p><div data-program-tools><button data-nudge>Nudge</button></div></div>
        <div data-program-actions data-action-bar><div data-action-bar-panel><div><div data-program-tools-slot></div></div></div><button data-action-bar-toggle>More</button></div>`;
};

// A window whose width can cross lg: `change` listeners are kept so a test can fire them.
function screen(wide) {
    const listeners = [];
    const query = { matches: wide, addEventListener: (type, fn) => listeners.push(fn) };

    vi.stubGlobal("matchMedia", () => query);

    return {
        resize(toWide) {
            query.matches = toWide;
            listeners.forEach((fn) => fn());
        },
    };
}

const parentOfTools = () => document.querySelector("[data-program-tools]").parentElement;

describe("placeTools", () => {
    afterEach(() => vi.unstubAllGlobals());

    it("moves Nudge and Download into the action bar below lg and back from lg", () => {
        page();

        screen(false);
        placeTools();
        expect(parentOfTools().matches("[data-program-tools-slot]")).toBe(true);

        screen(true);
        placeTools();
        expect(parentOfTools().matches("[data-program-tools-home]")).toBe(true);
        expect(parentOfTools().lastElementChild.matches("[data-program-tools]")).toBe(true);
    });

    it("leaves them beside the pager without matchMedia", () => {
        page();
        vi.stubGlobal("matchMedia", undefined);
        placeTools();
        expect(parentOfTools().matches("[data-program-tools-home]")).toBe(true);
    });

    it("keeps the focus on a button it moves", () => {
        page();
        const nudge = document.querySelector("[data-nudge]");
        nudge.focus();

        screen(false);
        placeTools();

        expect(parentOfTools().matches("[data-program-tools-slot]")).toBe(true);
        expect(document.activeElement).toBe(nudge);
    });

    it("follows a resize across lg, and a live search's fresh, closed bar gets an inert panel", () => {
        page();
        const width = screen(false);

        bindProgram(document.body);
        expect(parentOfTools().matches("[data-program-tools-slot]")).toBe(true);

        width.resize(true);
        expect(parentOfTools().matches("[data-program-tools-home]")).toBe(true);
        width.resize(false);
        expect(parentOfTools().matches("[data-program-tools-slot]")).toBe(true);

        // The search swaps in the server's markup again: the tools beside the pager, the panel not yet inert.
        page();
        document.dispatchEvent(new CustomEvent("content:swapped", { detail: { root: document.body } }));

        expect(parentOfTools().matches("[data-program-tools-slot]")).toBe(true);
        expect(document.querySelector("[data-action-bar-panel]").inert).toBe(true);
    });
});
