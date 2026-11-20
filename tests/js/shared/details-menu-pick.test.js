import { afterEach, beforeAll, describe, expect, it } from "vitest";

// details-menu.js: a <details data-menu-pick> closes when one of its actions is chosen (the people list's Actions).

beforeAll(async () => {
    await import("../../../resources/js/shared/details-menu.js");
});

afterEach(() => {
    document.body.innerHTML = "";
});

describe("details menu that closes on a pick", () => {
    it("closes when a button inside is chosen", () => {
        document.body.innerHTML = '<details data-menu data-menu-pick open><summary id="s">Actions</summary><button id="b" type="button">Edit</button></details>';
        const menu = document.querySelector("details");

        expect(menu.open).toBe(true);
        document.getElementById("b").click();
        expect(menu.open).toBe(false);
    });

    it("closes when a link inside is chosen", () => {
        document.body.innerHTML = '<details data-menu data-menu-pick open><summary>Actions</summary><a id="a" href="#x">Open</a></details>';

        document.getElementById("a").click();
        expect(document.querySelector("details").open).toBe(false);
    });

    it("does not count a click on the summary itself as a pick", () => {
        document.body.innerHTML = '<details data-menu data-menu-pick open><summary id="s">Actions</summary><button type="button">Edit</button></details>';
        const menu = document.querySelector("details");
        const summary = document.getElementById("s");

        // The pick handler alone (jsdom's own summary toggle is prevented here).
        summary.addEventListener("click", (event) => event.preventDefault());
        summary.click();
        expect(menu.open).toBe(true);
    });

    it("leaves an ordinary menu open when something inside is clicked", () => {
        document.body.innerHTML = '<details data-menu open><summary>Menu</summary><button id="b" type="button">Item</button></details>';

        document.getElementById("b").click();
        expect(document.querySelector("details").open).toBe(true);
    });
});
