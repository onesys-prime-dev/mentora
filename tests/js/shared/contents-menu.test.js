import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/*
 * contents-menu.js opens the module outline's <details data-contents> from lg up (a sidebar) and closes it below
 * (a "Contents" dropdown). matchMedia is stubbed with a list whose width can be changed.
 */

let listeners;
let mql;

const stubWidth = (wide) => {
    listeners = [];
    mql = {
        matches: wide,
        addEventListener: vi.fn((type, fn) => listeners.push(fn)),
    };
    vi.stubGlobal("matchMedia", vi.fn(() => mql));
};

const resize = (wide) => {
    mql.matches = wide;
    listeners.forEach((fn) => fn({ matches: wide }));
};

const outline = () => `<details data-menu data-contents><summary>Contents</summary><div>units</div></details>`;
const details = () => document.querySelector("details[data-contents]");

const load = async () => {
    vi.resetModules();
    return import("../../../resources/js/shared/contents-menu.js");
};

beforeEach(() => {
    document.body.innerHTML = outline();
});

afterEach(() => {
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
});

describe("contents menu", () => {
    it("opens the outline at 64rem and up", async () => {
        stubWidth(true);
        await load();

        expect(globalThis.matchMedia).toHaveBeenCalledWith("(width >= 64rem)");
        expect(details().open).toBe(true);
    });

    it("leaves it closed below 64rem", async () => {
        stubWidth(false);
        await load();

        expect(details().open).toBe(false);
    });

    it("follows the width when it changes, closing an open dropdown on the way down", async () => {
        stubWidth(false);
        await load();

        resize(true);
        expect(details().open).toBe(true);

        resize(false);
        expect(details().open).toBe(false);
    });

    it("opens a swapped-in outline, and swaps do not bind again", async () => {
        stubWidth(true);
        await load();

        document.body.innerHTML = outline();
        expect(details().open).toBe(false);

        document.dispatchEvent(new CustomEvent("content:swapped", { detail: { root: document.body } }));
        document.dispatchEvent(new CustomEvent("content:swapped", { detail: { root: document.body } }));
        expect(details().open).toBe(true);
        expect(mql.addEventListener).toHaveBeenCalledTimes(1);

        resize(false);
        expect(details().open).toBe(false);
    });

    it("syncContents can be called by a page that swaps the outline itself", async () => {
        stubWidth(true);
        const { syncContents } = await load();

        document.body.innerHTML = outline();
        syncContents();

        expect(details().open).toBe(true);
    });
});
