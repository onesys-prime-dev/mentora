import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { SKELETON_DELAY_MS, beginLoad, supersede } from "../../../../resources/js/shared/spa/load-guard.js";

let el;

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '<div id="region">old</div>';
    el = document.getElementById("region");
});

afterEach(() => {
    vi.useRealTimers();
});

describe("beginLoad", () => {
    it("shows no skeleton at all when the load finishes quickly", () => {
        const load = beginLoad(el, "<i>skeleton</i>");

        vi.advanceTimersByTime(SKELETON_DELAY_MS - 1);
        load.finish();
        vi.advanceTimersByTime(1000);

        expect(el.innerHTML).toBe("old");
    });

    it("shows the skeleton once the delay has passed", () => {
        beginLoad(el, "<i>skeleton</i>");

        expect(el.innerHTML).toBe("old");

        vi.advanceTimersByTime(SKELETON_DELAY_MS);

        expect(el.innerHTML).toBe("<i>skeleton</i>");
    });

    it("shows it straight away with no delay", () => {
        beginLoad(el, "<i>skeleton</i>", 0);

        expect(el.innerHTML).toBe("<i>skeleton</i>");
    });

    it("can draw the skeleton in a part of the region", () => {
        el.innerHTML = '<h1>Title</h1><div id="body">old body</div>';
        const body = el.querySelector("#body");

        beginLoad(el, "<i>skeleton</i>", 0, body);

        expect(el.querySelector("h1").textContent).toBe("Title");
        expect(body.innerHTML).toBe("<i>skeleton</i>");
    });

    it("reports whether a load is still the current one", () => {
        const first = beginLoad(el, "<i>1</i>");
        expect(first.current()).toBe(true);

        const second = beginLoad(el, "<i>2</i>");

        expect(first.current()).toBe(false);
        expect(second.current()).toBe(true);
    });

    it("never lets a superseded load draw its skeleton", () => {
        beginLoad(el, "<i>first</i>");
        const second = beginLoad(el, "<i>second</i>");

        vi.advanceTimersByTime(SKELETON_DELAY_MS * 2);

        expect(el.innerHTML).toBe("<i>second</i>");
        expect(second.current()).toBe(true);
    });

    it("lets finish() of an old load leave the new load's skeleton alone", () => {
        const first = beginLoad(el, "<i>first</i>");
        beginLoad(el, "<i>second</i>");

        first.finish();
        vi.advanceTimersByTime(SKELETON_DELAY_MS);

        expect(el.innerHTML).toBe("<i>second</i>");
    });

    it("keeps separate elements independent", () => {
        document.body.innerHTML += '<div id="other">other</div>';
        const other = document.getElementById("other");

        const a = beginLoad(el, "<i>a</i>");
        const b = beginLoad(other, "<i>b</i>");

        expect(a.current()).toBe(true);
        expect(b.current()).toBe(true);

        vi.advanceTimersByTime(SKELETON_DELAY_MS);

        expect(el.innerHTML).toBe("<i>a</i>");
        expect(other.innerHTML).toBe("<i>b</i>");
    });
});

describe("supersede", () => {
    it("invalidates a load in flight and cancels its skeleton", () => {
        const load = beginLoad(el, "<i>skeleton</i>");

        supersede(el);
        vi.advanceTimersByTime(SKELETON_DELAY_MS * 2);

        expect(load.current()).toBe(false);
        expect(el.innerHTML).toBe("old");
    });

    it("is safe on an element that never loaded", () => {
        const fresh = document.createElement("div");

        expect(() => supersede(fresh)).not.toThrow();
    });
});

describe("a region the server already drew a skeleton in", () => {

    it("does not draw a second skeleton over it after the delay", async () => {
        vi.useFakeTimers();

        const el = document.createElement("div");

        el.dataset.serverSkeleton = "";
        el.innerHTML = "<p>server shape</p>";

        const { beginLoad, SKELETON_DELAY_MS } = await import("../../../../resources/js/shared/spa/load-guard.js");

        beginLoad(el, "<p>js shape</p>");
        vi.advanceTimersByTime(SKELETON_DELAY_MS + 10);

        expect(el.innerHTML).toBe("<p>server shape</p>");

        vi.useRealTimers();
    });

    it("still draws at once when asked to (a child screen)", async () => {
        const el = document.createElement("div");

        el.dataset.serverSkeleton = "";

        const { beginLoad } = await import("../../../../resources/js/shared/spa/load-guard.js");

        beginLoad(el, "<p>child shape</p>", 0);

        expect(el.innerHTML).toBe("<p>child shape</p>");
    });

});
