import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { setBusy } from "../../../../resources/js/shared/spa/busy-button.js";
import { showLoadError } from "../../../../resources/js/shared/spa/error-state.js";
import { highlightRow } from "../../../../resources/js/shared/spa/highlight-row.js";
import {
    MAX_ROWS,
    MIN_ROWS,
    clampRows,
    rememberRows,
    rememberedRows,
} from "../../../../resources/js/shared/spa/skeleton-rows.js";

beforeEach(() => {
    document.body.innerHTML = "";
});

describe("setBusy", () => {
    const button = () => {
        document.body.innerHTML = '<button id="b">Post</button>';
        return document.getElementById("b");
    };

    it("disables the button, marks it busy and swaps the label", () => {
        const b = button();

        setBusy(b, true, "Posting…");

        expect(b.disabled).toBe(true);
        expect(b.getAttribute("aria-busy")).toBe("true");
        expect(b.textContent).toBe("Posting…");
    });

    it("keeps the label when none is given", () => {
        const b = button();

        setBusy(b, true);

        expect(b.textContent).toBe("Post");
        expect(b.disabled).toBe(true);
    });

    it("puts the original label back and re-enables it", () => {
        const b = button();

        setBusy(b, true, "Posting…");
        setBusy(b, false);

        expect(b.textContent).toBe("Post");
        expect(b.disabled).toBe(false);
        expect(b.hasAttribute("aria-busy")).toBe(false);
        expect(b.dataset.idleLabel).toBeUndefined();
    });

    it("remembers the first label across repeated busy calls", () => {
        const b = button();

        setBusy(b, true, "Posting…");
        setBusy(b, true, "Still posting…");
        setBusy(b, false);

        expect(b.textContent).toBe("Post");
    });

    it("is harmless to clear a button that was never busy", () => {
        const b = button();

        setBusy(b, false);

        expect(b.textContent).toBe("Post");
        expect(b.disabled).toBe(false);
    });
});

describe("showLoadError", () => {
    const target = () => {
        document.body.innerHTML = '<div id="t" data-spa-skeleton><i>skeleton</i></div>';
        return document.getElementById("t");
    };

    it("replaces the skeleton with an announced error naming what failed", () => {
        const t = target();

        showLoadError(t, "Announcements");

        expect(t.hasAttribute("data-spa-skeleton")).toBe(false);
        expect(t.querySelector('[role="alert"]')).not.toBeNull();
        expect(t.textContent).toContain("We couldn't load Announcements");
        expect(t.querySelector("[data-retry]")).toBeNull();
    });

    it("escapes the name so it cannot inject markup", () => {
        const t = target();

        showLoadError(t, '<img src=x onerror="alert(1)"> & "q" \'s\'');

        expect(t.querySelector("img")).toBeNull();
        expect(t.textContent).toContain('<img src=x onerror="alert(1)"> & "q" \'s\'');
    });

    it("offers a focused Try again that re-runs the load", () => {
        const t = target();
        const retry = vi.fn();

        showLoadError(t, "Grades", retry);

        const button = t.querySelector("[data-retry]");
        expect(button.textContent).toBe("Try again");
        expect(document.activeElement).toBe(button);

        button.click();
        button.click();

        expect(retry).toHaveBeenCalledTimes(2);
    });
});

describe("highlightRow", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("returns null and does nothing when the row is not on the page", () => {
        expect(highlightRow("#nope")).toBeNull();
    });

    it("scrolls to the row, highlights it, then removes the highlight", () => {
        document.body.innerHTML = '<div id="row" class="p-2"></div>';
        const row = document.getElementById("row");

        expect(highlightRow("#row")).toBe(row);

        expect(row.scrollIntoView).toHaveBeenCalledWith({ behavior: "smooth", block: "center" });
        expect(row.classList.contains("ring-2")).toBe(true);
        expect(row.classList.contains("ring-[#0040A6]")).toBe(true);

        vi.advanceTimersByTime(1499);
        expect(row.classList.contains("ring-2")).toBe(true);

        vi.advanceTimersByTime(1);
        expect(row.classList.contains("ring-2")).toBe(false);
        expect(row.className).toBe("p-2");
    });

    it("takes its own classes and duration", () => {
        document.body.innerHTML = '<div id="row"></div>';
        const row = document.getElementById("row");

        highlightRow("#row", { highlightClasses: ["bg-yellow-100"], durationMs: 300 });

        expect(row.classList.contains("bg-yellow-100")).toBe(true);
        vi.advanceTimersByTime(300);
        expect(row.classList.contains("bg-yellow-100")).toBe(false);
    });
});

describe("skeleton rows", () => {
    beforeEach(() => {
        sessionStorage.clear();
        window.coursesConfig = { offeringId: 7, role: "student" };
    });

    afterEach(() => {
        delete window.coursesConfig;
    });

    it("always draws between one and three rows", () => {
        expect(MIN_ROWS).toBe(1);
        expect(MAX_ROWS).toBe(3);
        expect([-4, 0, 1, 2, 3, 4, 99].map(clampRows)).toEqual([1, 1, 1, 2, 3, 3, 3]);
        expect(clampRows("2")).toBe(2);
        expect(clampRows("nonsense")).toBe(1);
        expect(clampRows(undefined)).toBe(1);
    });

    it("starts at the maximum the first time", () => {
        expect(rememberedRows("announcements")).toBe(3);
    });

    it("remembers how many rows a list last showed, clamped", () => {
        rememberRows("announcements", 2);
        expect(rememberedRows("announcements")).toBe(2);

        rememberRows("announcements", 0);
        expect(rememberedRows("announcements")).toBe(1);

        rememberRows("announcements", 40);
        expect(rememberedRows("announcements")).toBe(3);
    });

    it("keeps separate counts per feature, course and role", () => {
        rememberRows("announcements", 1);

        expect(rememberedRows("resources")).toBe(3);

        window.coursesConfig = { offeringId: 8, role: "student" };
        expect(rememberedRows("announcements")).toBe(3);

        window.coursesConfig = { offeringId: 7, role: "instructor" };
        expect(rememberedRows("announcements")).toBe(3);

        window.coursesConfig = { offeringId: 7, role: "student" };
        expect(rememberedRows("announcements")).toBe(1);
    });

    it("falls back to the maximum when storage is unavailable", () => {
        vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
            throw new Error("blocked");
        });
        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
            throw new Error("blocked");
        });

        expect(() => rememberRows("announcements", 1)).not.toThrow();
        expect(rememberedRows("announcements")).toBe(3);
    });

    it("works without a course config on the page", () => {
        delete window.coursesConfig;

        rememberRows("grades", 2);

        expect(rememberedRows("grades")).toBe(2);
    });
});
