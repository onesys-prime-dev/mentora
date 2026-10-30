import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const page = (count) => `<main>
    <form method="get" action="/list"><input type="search" name="q" data-auto-search><input name="other"><button type="submit">Apply</button></form>
    <p data-count aria-live="polite">${count} people</p>
    <div data-results>${count} rows</div>
    <div data-dialog id="keep-me">dialog</div>
</main>`;

const answer = (html, url = "http://localhost:3000/list?q=qu") => ({ ok: true, url, text: async () => html });

describe("auto-search", () => {
    beforeEach(async () => {
        vi.useFakeTimers();
        vi.resetModules();
        sessionStorage.clear();
        document.body.innerHTML = page("10");
        document.querySelector("form").requestSubmit = vi.fn();
        globalThis.fetch = vi.fn().mockResolvedValue(answer(page("2")));
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

    it("asks once, a moment after the last key, and not by reloading the page", async () => {
        type("[data-auto-search]", "q");
        await vi.advanceTimersByTimeAsync(300);
        type("[data-auto-search]", "qu");
        await vi.advanceTimersByTimeAsync(300);
        expect(fetch).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(200);
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(String(fetch.mock.calls[0][0])).toContain("/list?q=qu");
        expect(document.querySelector("form").requestSubmit).not.toHaveBeenCalled();
    });

    it("swaps in only what follows the box, keeping the box and the dialogs", async () => {
        const box = document.querySelector("[data-auto-search]");
        const dialog = document.querySelector("#keep-me");
        const swapped = vi.fn();
        document.addEventListener("content:swapped", swapped);

        type("[data-auto-search]", "qu");
        await vi.advanceTimersByTimeAsync(400);

        expect(document.querySelector("[data-count]").textContent).toBe("10 people"); // the counter is left alone
        expect(document.querySelector("[data-results]").textContent).toBe("2 rows");
        expect(document.querySelector("[data-auto-search]")).toBe(box);
        expect(document.querySelector("#keep-me")).toBe(dialog);
        expect(swapped).toHaveBeenCalledTimes(1);
        expect(location.search).toBe("?q=qu");
    });

    it("falls back to sending the form when the request fails", async () => {
        fetch.mockRejectedValue(new Error("offline"));

        type("[data-auto-search]", "qu");
        await vi.advanceTimersByTimeAsync(400);

        expect(document.querySelector("form").requestSubmit).toHaveBeenCalledTimes(1);
        expect(JSON.parse(sessionStorage.getItem("autoSearchFocus")).name).toBe("q");
    });

    it("falls back when it was sent to another page (signed out)", async () => {
        fetch.mockResolvedValue(answer("<html></html>", "http://localhost:3000/login"));

        type("[data-auto-search]", "qu");
        await vi.advanceTimersByTimeAsync(400);

        expect(document.querySelector("form").requestSubmit).toHaveBeenCalledTimes(1);
    });

    it("searches again at once when the x empties the box", async () => {
        const input = document.querySelector("[data-auto-search]");
        input.value = "";
        input.dispatchEvent(new Event("search", { bubbles: true }));
        await vi.advanceTimersByTimeAsync(0);

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it("leaves other inputs alone", async () => {
        type("[name=other]", "x");
        await vi.advanceTimersByTimeAsync(1000);
        expect(fetch).not.toHaveBeenCalled();
    });
});
