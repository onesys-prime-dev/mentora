import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
    endProgress,
    finishPageLoad,
    openWaits,
    resetProgress,
    startProgress,
} from "../../../../resources/js/shared/spa/progress.js";
import {
    CANCEL_AFTER_MS,
    leavesThePage,
    startPageProgress,
} from "../../../../resources/js/shared/spa/page-progress.js";

const bar = () => document.querySelector("[data-spa-progress]");
const active = () => bar()?.classList.contains("is-active") ?? false;

const click = (props = {}) => ({ defaultPrevented: false, button: 0, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false, ...props });

const link = (href, attrs = {}) => {
    const a = document.createElement("a");

    a.setAttribute("href", href);

    Object.entries(attrs).forEach(([k, v]) => a.setAttribute(k, v));

    document.body.append(a);

    return a;
};

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = "";
    resetProgress();
});

afterEach(() => {
    resetProgress();
    vi.useRealTimers();
});

describe("the shared bar", () => {

    it("stays up until every wait has ended", () => {
        startProgress();
        startProgress();

        expect(active()).toBe(true);
        expect(openWaits()).toBe(2);

        endProgress();
        expect(active()).toBe(true);

        endProgress();
        expect(active()).toBe(false);
    });

    it("never goes below zero waits", () => {
        endProgress();
        endProgress();

        expect(openWaits()).toBe(0);

        startProgress();
        expect(active()).toBe(true);
    });

    it("is cleared by a reset", () => {
        startProgress();
        resetProgress();

        expect(active()).toBe(false);
        expect(openWaits()).toBe(0);
    });

    it("finishes the bar that came with the server's html and fades it", () => {
        document.body.innerHTML = '<div class="spa-progress is-active" data-spa-progress data-page-load></div>';

        finishPageLoad();

        expect(bar().classList.contains("is-done")).toBe(true);
        expect(bar().dataset.pageLoad).toBeUndefined();

        vi.advanceTimersByTime(500);

        expect(active()).toBe(false);
    });

    it("leaves a bar that a wait is using alone when the page finishes loading", () => {
        document.body.innerHTML = '<div class="spa-progress is-active" data-spa-progress data-page-load></div>';
        startProgress();

        finishPageLoad();
        vi.advanceTimersByTime(500);

        expect(active()).toBe(true);
    });

});

describe("which clicks leave the page", () => {

    it("counts an ordinary same-site link", () => {
        expect(leavesThePage(click(), link("/dashboard"))).toBe(true);
    });

    it("ignores links that do not leave", () => {
        expect(leavesThePage(click(), link("#top"))).toBe(false);
        expect(leavesThePage(click(), link("mailto:a@b.c"))).toBe(false);
        expect(leavesThePage(click(), link("javascript:void(0)"))).toBe(false);
        expect(leavesThePage(click(), link("/file.pdf", { download: "" }))).toBe(false);
        expect(leavesThePage(click(), link("/x", { target: "_blank" }))).toBe(false);
        expect(leavesThePage(click(), link("https://elsewhere.example/page"))).toBe(false);
        expect(leavesThePage(click(), null)).toBe(false);
    });

    it("ignores a click that something else took over, or that opens elsewhere", () => {
        expect(leavesThePage(click({ defaultPrevented: true }), link("/a"))).toBe(false);
        expect(leavesThePage(click({ ctrlKey: true }), link("/a"))).toBe(false);
        expect(leavesThePage(click({ metaKey: true }), link("/a"))).toBe(false);
        expect(leavesThePage(click({ button: 1 }), link("/a"))).toBe(false);
    });

});

describe("a reload started by a script", () => {

    it("shows the bar and clears it if the page never changes", () => {
        startPageProgress();
        expect(active()).toBe(true);

        // A second request while the first is open adds no wait.
        startPageProgress();
        expect(openWaits()).toBe(1);

        vi.advanceTimersByTime(CANCEL_AFTER_MS + 10);

        expect(active()).toBe(false);
    });

});
