import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

describe("auto-search", () => {
    beforeEach(async () => {
        vi.useFakeTimers();
        vi.resetModules();
        document.body.innerHTML = `<form><input type="search" name="q" data-auto-search><input name="other"></form>`;
        document.querySelector("form").requestSubmit = vi.fn();
        await import("../../../resources/js/shared/auto-search.js");
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    const type = (selector, value) => {
        const input = document.querySelector(selector);
        input.value = value;
        input.dispatchEvent(new Event("input", { bubbles: true }));
    };

    it("sends the form once, a moment after the last key", () => {
        type("[data-auto-search]", "q");
        vi.advanceTimersByTime(300);
        type("[data-auto-search]", "qu");
        vi.advanceTimersByTime(300);
        expect(document.querySelector("form").requestSubmit).not.toHaveBeenCalled();

        vi.advanceTimersByTime(200);
        expect(document.querySelector("form").requestSubmit).toHaveBeenCalledTimes(1);
    });

    it("leaves other inputs alone", () => {
        type("[name=other]", "x");
        vi.advanceTimersByTime(1000);
        expect(document.querySelector("form").requestSubmit).not.toHaveBeenCalled();
    });
});
