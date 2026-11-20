// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { bindFooterClearance, caretRect, clearance } from "../../../resources/js/pages/courses/features/assessments/inspector/footer-clearance.js";
import { reopenSettingsAboveMd } from "../../../resources/js/pages/courses/features/assessments/inspector/inspector.js";

const rect = (top, bottom) => ({ top, bottom, height: bottom - top, left: 0, right: 100, width: 100 });

afterEach(() => {
    document.body.innerHTML = "";
    vi.restoreAllMocks();
});

describe("clearance", () => {
    it("scrolls a field that ends under the footer to 8px above it", () => {
        expect(clearance(rect(700, 744), 730)).toBe(22);
    });

    it("does nothing for a field already above the footer", () => {
        expect(clearance(rect(100, 144), 730)).toBe(0);
    });

    it("never pushes the field's top under the pinned header (a tall field ending far below)", () => {
        // A 900px-tall textarea starting at 200: its end is 370px under the footer, but only 144px of scroll keep its top at 56.
        expect(clearance(rect(200, 1100), 730, 56)).toBe(144);
        expect(clearance(rect(40, 1100), 730, 56)).toBe(0);
    });
});

describe("caretRect", () => {
    it("measures the caret in a contenteditable, not the whole editor", () => {
        document.body.innerHTML = `<div id="ed" contenteditable="true"><p id="line">Some text</p></div>`;
        const editor = document.getElementById("ed");
        Object.defineProperty(editor, "isContentEditable", { value: true });
        const range = { getClientRects: () => [rect(300, 320)], getBoundingClientRect: () => rect(300, 320) };
        vi.spyOn(globalThis, "getSelection").mockReturnValue({ rangeCount: 1, anchorNode: document.getElementById("line").firstChild, getRangeAt: () => range });
        editor.getBoundingClientRect = () => rect(100, 2000);

        expect(caretRect(editor).top).toBe(300);
    });

    it("uses the line's block when the caret has no box (an empty line)", () => {
        document.body.innerHTML = `<div id="ed" contenteditable="true"><p id="line"></p></div>`;
        const editor = document.getElementById("ed");
        Object.defineProperty(editor, "isContentEditable", { value: true });
        const line = document.getElementById("line");
        line.getBoundingClientRect = () => rect(500, 520);
        const range = { getClientRects: () => [], getBoundingClientRect: () => rect(0, 0) };
        vi.spyOn(globalThis, "getSelection").mockReturnValue({ rangeCount: 1, anchorNode: line, getRangeAt: () => range });

        expect(caretRect(editor).top).toBe(500);
    });

    it("uses a form field's own box", () => {
        document.body.innerHTML = `<input id="f">`;
        const field = document.getElementById("f");
        field.getBoundingClientRect = () => rect(10, 54);

        expect(caretRect(field).top).toBe(10);
    });
});

describe("bindFooterClearance", () => {
    const PAGE = `
        <aside id="editor-inspector-panel"><input id="setting"></aside>
        <div role="dialog"><input id="in-dialog"></div>
        <div data-side-panel data-open><input id="in-panel"></div>
        <footer id="footer"><button data-publish>Publish</button></footer>`;

    function bind(matches = true) {
        const doc = document.implementation.createHTMLDocument("t");
        doc.body.innerHTML = PAGE;
        const scrollBy = vi.fn();
        Object.defineProperty(doc, "defaultView", { value: { scrollBy } });
        doc.getElementById("footer").getBoundingClientRect = () => rect(730, 790);
        for (const id of ["setting", "in-dialog", "in-panel"]) {
            doc.getElementById(id).getBoundingClientRect = () => rect(720, 764);
        }
        bindFooterClearance({ doc, belowLg: { matches }, frame: (callback) => callback() });

        const focus = (id) => doc.getElementById(id).dispatchEvent(new FocusEvent("focusin", { bubbles: true }));

        return { doc, scrollBy, focus };
    }

    it("scrolls a field of the editor out from under its footer", () => {
        const { scrollBy, focus } = bind();
        focus("setting");

        expect(scrollBy).toHaveBeenCalledWith(0, 42);
    });

    it("leaves dialogs, the open side panel and lg+ alone", () => {
        const { scrollBy, focus } = bind();
        focus("in-dialog");
        focus("in-panel");
        expect(scrollBy).not.toHaveBeenCalled();

        const wide = bind(false);
        wide.focus("setting");
        expect(wide.scrollBy).not.toHaveBeenCalled();
    });

    it("does nothing on a page with neither the assessment editor nor the learning item editor", () => {
        const { doc, scrollBy, focus } = bind();
        doc.getElementById("editor-inspector-panel").removeAttribute("id");
        focus("setting");

        expect(scrollBy).not.toHaveBeenCalled();
    });
});

describe("reopenSettingsAboveMd", () => {
    it("unfolds a section folded on a phone once the window grows past md", () => {
        document.body.innerHTML = `<details data-inspector-settings><summary>Settings</summary></details>`;
        let onChange;
        const phone = { matches: true, addEventListener: (_, callback) => { onChange = callback; } };
        reopenSettingsAboveMd(phone);

        onChange();
        expect(document.querySelector("details").open).toBe(false);

        phone.matches = false;
        onChange();
        expect(document.querySelector("details").open).toBe(true);
    });
});
