import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { swapContent } from "../../../../resources/js/shared/spa/swap.js";

let container;

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
};

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '<div id="c"><p>old screen</p></div>';
    container = document.getElementById("c");
    window.scrollTo = vi.fn();
    window.matchMedia = vi.fn(() => ({ matches: false }));
    vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(() => {
    vi.useRealTimers();
});

const bar = () => document.querySelector("[data-spa-progress]");
const barShown = () => bar()?.classList.contains("is-active") ?? false;
const notice = () => document.querySelector("[data-spa-notice]");

describe("a fast load", () => {
    it("swaps the html in and shows no dimming or progress at all", async () => {
        const ok = await swapContent(container, async () => "<p>new screen</p>");

        expect(ok).toBe(true);
        expect(container.innerHTML).toBe("<p>new screen</p>");
        expect(container.classList.contains("spa-loading")).toBe(false);
        expect(barShown()).toBe(false);
    });

    it("fades the new screen in when navigating", async () => {
        await swapContent(container, async () => "<p>new</p>");

        expect(container.classList.contains("spa-enter")).toBe(true);
    });

    it("skips the fade for a refresh and keeps the scroll position", async () => {
        Object.defineProperty(window, "scrollY", { value: 420, configurable: true });

        await swapContent(container, async () => "<p>new</p>", { mode: "refresh" });

        expect(container.classList.contains("spa-enter")).toBe(false);
        expect(window.scrollTo).toHaveBeenCalledWith({ top: 420, behavior: "instant" });
    });

    it("skips the fade when the person prefers reduced motion", async () => {
        window.matchMedia = vi.fn(() => ({ matches: true }));

        await swapContent(container, async () => "<p>new</p>");

        expect(container.classList.contains("spa-enter")).toBe(false);
        expect(container.innerHTML).toBe("<p>new</p>");
    });

    it("uses a view transition where the browser has one", async () => {
        const updateCallbackDone = Promise.resolve();
        document.startViewTransition = vi.fn((apply) => {
            apply();
            return { updateCallbackDone, finished: Promise.resolve() };
        });

        await swapContent(container, async () => "<p>new</p>");
        await Promise.resolve();

        expect(document.startViewTransition).toHaveBeenCalledTimes(1);
        expect(container.innerHTML).toBe("<p>new</p>");
        expect(container.classList.contains("spa-transitioning")).toBe(false);

        delete document.startViewTransition;
    });
});

describe("a slow load", () => {
    it("keeps the old screen as it is and shows the progress bar only after 150 ms", async () => {
        const load = deferred();

        const swap = swapContent(container, () => load.promise);

        vi.advanceTimersByTime(149);
        expect(container.classList.contains("spa-loading")).toBe(false);
        expect(barShown()).toBe(false);

        vi.advanceTimersByTime(1);
        expect(container.classList.contains("spa-loading")).toBe(false);
        expect(bar().classList.contains("is-active")).toBe(true);
        expect(bar().getAttribute("role")).toBe("progressbar");
        expect(container.innerHTML).toBe("<p>old screen</p>");

        load.resolve("<p>new screen</p>");
        await swap;

        expect(container.innerHTML).toBe("<p>new screen</p>");
        expect(container.classList.contains("spa-loading")).toBe(false);
        expect(bar().classList.contains("is-active")).toBe(false);
    });

    it("does not dim a refresh, only shows the bar", async () => {
        const load = deferred();

        const swap = swapContent(container, () => load.promise, { mode: "refresh" });
        vi.advanceTimersByTime(200);

        expect(container.classList.contains("spa-loading")).toBe(false);
        expect(bar().classList.contains("is-active")).toBe(true);

        load.resolve("<p>new</p>");
        await swap;
    });
});

describe("skeletons", () => {
    it("draws the skeleton straight away when there is nothing on screen to keep", async () => {
        container.innerHTML = "";
        const load = deferred();

        const swap = swapContent(container, () => load.promise, { skeleton: "<i>shape</i>" });

        expect(container.innerHTML).toBe("<i>shape</i>");
        expect(container.dataset.spaSkeleton).toBeDefined();

        vi.advanceTimersByTime(500);
        expect(container.classList.contains("spa-loading")).toBe(false);
        expect(barShown()).toBe(false);

        load.resolve("<p>real</p>");
        await swap;

        expect(container.innerHTML).toBe("<p>real</p>");
        expect(container.dataset.spaSkeleton).toBeUndefined();
        expect(container.classList.contains("spa-enter")).toBe(false);
    });

    it("keeps the current screen up instead of a skeleton by default", async () => {
        const load = deferred();

        const swap = swapContent(container, () => load.promise, { skeleton: "<i>shape</i>" });

        expect(container.innerHTML).toBe("<p>old screen</p>");

        load.resolve("<p>new</p>");
        await swap;
    });

    it("replaces the current screen with the skeleton when forced", async () => {
        const load = deferred();

        const swap = swapContent(container, () => load.promise, { skeleton: "<i>shape</i>", forceSkeleton: true });

        expect(container.innerHTML).toBe("<i>shape</i>");

        load.resolve("<p>new</p>");
        await swap;
    });
});

describe("a failed load", () => {
    it("keeps the old screen, tells the person, and reports failure", async () => {
        const ok = await swapContent(container, async () => {
            throw new Error("offline");
        });

        expect(ok).toBe(false);
        expect(container.innerHTML).toBe("<p>old screen</p>");
        expect(notice().textContent).toBe("Couldn't load that. Please try again.");
        expect(notice().classList.contains("is-visible")).toBe(true);
        expect(notice().getAttribute("role")).toBe("status");
        expect(console.error).toHaveBeenCalled();

        vi.advanceTimersByTime(4000);
        expect(notice().classList.contains("is-visible")).toBe(false);
    });

    it("turns a skeleton into a plain message rather than leaving it forever", async () => {
        container.innerHTML = "";

        const ok = await swapContent(container, async () => {
            throw new Error("offline");
        }, { skeleton: "<i>shape</i>" });

        expect(ok).toBe(false);
        expect(container.textContent).toContain("This page couldn't be loaded");
        expect(container.dataset.spaSkeleton).toBeUndefined();
    });

    it("stops the progress bar", async () => {
        const load = deferred();

        const swap = swapContent(container, () => load.promise);
        vi.advanceTimersByTime(200);
        expect(bar().classList.contains("is-active")).toBe(true);

        load.reject(new Error("boom"));
        await swap;

        expect(bar().classList.contains("is-active")).toBe(false);
        expect(container.classList.contains("spa-loading")).toBe(false);
    });
});

describe("overlapping swaps", () => {
    it("lets the newer swap win and drops the older result", async () => {
        const slow = deferred();
        const fast = deferred();

        const first = swapContent(container, () => slow.promise);
        const second = swapContent(container, () => fast.promise);

        fast.resolve("<p>second</p>");
        expect(await second).toBe(true);

        slow.resolve("<p>first</p>");
        expect(await first).toBe(false);

        expect(container.innerHTML).toBe("<p>second</p>");
    });

    it("does not show an error for a superseded swap that fails", async () => {
        const slow = deferred();
        const fast = deferred();

        const first = swapContent(container, () => slow.promise);
        const second = swapContent(container, () => fast.promise);

        slow.reject(new Error("late failure"));
        expect(await first).toBe(false);
        expect(notice()).toBeNull();

        fast.resolve("<p>second</p>");
        await second;
        expect(container.innerHTML).toBe("<p>second</p>");
    });
});
