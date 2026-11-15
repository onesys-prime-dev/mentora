// @vitest-environment jsdom
import { describe, expect, it, vi } from "vitest";
import { bindFooterClearance } from "../../../resources/js/pages/courses/features/assessments/inspector/footer-clearance.js";

const rect = (top, bottom) => ({ top, bottom, height: bottom - top, left: 0, right: 100, width: 100 });

/** A learning item editor page (no assessment inspector) with its pinned footer. */
function page() {
    const doc = document.implementation.createHTMLDocument("t");
    doc.body.innerHTML = `
        <div data-learning-item-editor>
            <input id="url">
            <footer id="footer"><button data-publish>Publish</button></footer>
        </div>`;
    const scrollBy = vi.fn();
    Object.defineProperty(doc, "defaultView", { value: { scrollBy } });
    doc.getElementById("footer").getBoundingClientRect = () => rect(730, 790);
    doc.getElementById("url").getBoundingClientRect = () => rect(720, 764);
    const focus = () => doc.getElementById("url").dispatchEvent(new FocusEvent("focusin", { bubbles: true }));

    return { doc, scrollBy, focus };
}

describe("footer clearance in the learning item editor", () => {
    it("scrolls a field of the item editor out from under its pinned footer", () => {
        const { doc, scrollBy, focus } = page();
        bindFooterClearance({ doc, belowLg: { matches: true }, frame: (callback) => callback() });
        focus();

        expect(scrollBy).toHaveBeenCalledWith(0, 42);
    });

    it("scrolls once when both the assessments and the modules bundles bind it", () => {
        const { doc, scrollBy, focus } = page();
        bindFooterClearance({ doc, belowLg: { matches: true }, frame: (callback) => callback() });
        bindFooterClearance({ doc, belowLg: { matches: true }, frame: (callback) => callback() });
        focus();

        expect(scrollBy).toHaveBeenCalledTimes(1);
    });
});
