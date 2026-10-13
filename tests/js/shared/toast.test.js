// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { showFlashToasts, showToast } from "../../../resources/js/shared/toast.js";

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = "";
});

describe("showToast", () => {
    it("shows the message in a polite live region with a word for screen readers", () => {
        showToast("Ann Lee was invited", { tone: "success" });

        const region = document.querySelector("[data-toast-region]");
        expect(region.getAttribute("aria-live")).toBe("polite");

        const toast = region.querySelector('[data-toast="success"]');
        expect(toast.textContent).toContain("Ann Lee was invited");
        expect(toast.querySelector(".sr-only").textContent).toBe("Done: ");
    });

    it("uses one region for every toast", () => {
        showToast("One");
        showToast("Two");

        expect(document.querySelectorAll("[data-toast-region]")).toHaveLength(1);
        expect(document.querySelectorAll("[data-toast]")).toHaveLength(2);
    });

    it("goes away by itself after a few seconds", () => {
        showToast("Saved", { tone: "success" });

        vi.advanceTimersByTime(5001);

        expect(document.querySelector("[data-toast]")).toBeNull();
    });

    it("keeps an error until it is dismissed", () => {
        showToast("It failed", { tone: "danger" });

        vi.advanceTimersByTime(60_000);
        expect(document.querySelector('[data-toast="danger"]')).not.toBeNull();

        document.querySelector("[data-toast] button").click();
        expect(document.querySelector("[data-toast]")).toBeNull();
    });

    it("treats an unknown tone as information and never renders markup from the message", () => {
        showToast("<b>bold</b>", { tone: "mystery" });

        const toast = document.querySelector("[data-toast]");
        expect(toast.getAttribute("data-toast")).toBe("info");
        expect(toast.querySelector("b")).toBeNull();
        expect(toast.textContent).toContain("<b>bold</b>");
    });

    it("can be kept until closed with timeout 0", () => {
        showToast("Stay", { timeout: 0 });

        vi.advanceTimersByTime(60_000);

        expect(document.querySelector("[data-toast]")).not.toBeNull();
    });
});

describe("showFlashToasts", () => {
    it("shows what the server left in the page, once, and removes the element", () => {
        document.body.innerHTML = `
            <div data-toast-message data-tone="success">Saved.</div>
            <div data-toast-message data-tone="danger">Could not save.</div>
            <div data-toast-message>   </div>`;

        showFlashToasts();

        expect(document.querySelectorAll("[data-toast]")).toHaveLength(2);
        expect(document.querySelectorAll("[data-toast-message]")).toHaveLength(0);
        expect(document.querySelector('[data-toast="danger"]').textContent).toContain("Could not save.");
    });
});
