import { beforeEach, describe, expect, it } from "vitest";

import { highlightFeatureTab } from "../../../resources/js/pages/courses/core/featureLoader.js";

// The two ways a tab arrives: the opened one as the server writes it (white, no blue/white-text
// classes), the others as the server writes them.
const ACTIVE = "course-feature bg-white !text-brand font-semibold";
const INACTIVE = "course-feature text-white bg-blue-900 hover:bg-blue-800";

beforeEach(() => {
    document.body.innerHTML = `
        <button data-course-feature="modules" class="${ACTIVE}" aria-selected="true"></button>
        <button data-course-feature="overview" class="${INACTIVE}" aria-selected="false"></button>`;
});

const tab = (name) => document.querySelector(`[data-course-feature="${name}"]`);

describe("the tab colours", () => {

    it("gives the tab that was opened by the server its default colours back when another is chosen", () => {
        highlightFeatureTab("overview");

        const left = tab("modules");

        expect(left.classList.contains("bg-blue-900")).toBe(true);
        expect(left.classList.contains("text-white")).toBe(true);
        expect(left.classList.contains("hover:bg-blue-800")).toBe(true);
        expect(left.classList.contains("bg-white")).toBe(false);
        expect(left.classList.contains("!text-brand")).toBe(false);
        expect(left.classList.contains("font-semibold")).toBe(false);
        expect(left.getAttribute("aria-selected")).toBe("false");
    });

    it("makes the chosen tab white without the blue ones fighting it", () => {
        highlightFeatureTab("overview");

        const chosen = tab("overview");

        expect(chosen.classList.contains("bg-white")).toBe(true);
        expect(chosen.classList.contains("!text-brand")).toBe(true);
        expect(chosen.classList.contains("bg-blue-900")).toBe(false);
        expect(chosen.classList.contains("text-white")).toBe(false);
        expect(chosen.classList.contains("hover:bg-blue-800")).toBe(false);
        expect(chosen.getAttribute("aria-selected")).toBe("true");
    });

    it("goes back and forth without losing anything", () => {
        highlightFeatureTab("overview");
        highlightFeatureTab("modules");

        expect(tab("modules").classList.contains("bg-white")).toBe(true);
        expect(tab("modules").classList.contains("bg-blue-900")).toBe(false);
        expect(tab("overview").className).toContain("bg-blue-900");
        expect(tab("overview").className).toContain("text-white");
    });

});
