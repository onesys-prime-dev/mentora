import { afterEach, beforeAll, describe, expect, it } from "vitest";

/*
 * details-menu.js closes an open <details data-menu> on an outside click or Escape, except one whose <summary>
 * is not displayed (the module outline from lg up, which is a sidebar, not a dropdown).
 */

beforeAll(async () => {
    await import("../../../resources/js/shared/details-menu.js");
});

afterEach(() => {
    document.body.innerHTML = "";
});

const build = (summaryStyle = "") => {
    document.body.innerHTML = `
        <details data-menu open><summary style="${summaryStyle}">Menu</summary><a id="inside" href="#">Item</a></details>
        <div id="elsewhere">page</div>`;

    return document.querySelector("details");
};

const escape = () => document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));

describe("details menu", () => {
    it("closes on an outside click, not on a click inside", () => {
        const menu = build();

        document.getElementById("inside").click();
        expect(menu.open).toBe(true);

        document.getElementById("elsewhere").click();
        expect(menu.open).toBe(false);
    });

    it("closes on Escape and puts focus back on its summary", () => {
        const menu = build();

        escape();

        expect(menu.open).toBe(false);
        expect(document.activeElement).toBe(menu.querySelector("summary"));
    });

    it("leaves a menu whose summary is hidden open on an outside click and on Escape", () => {
        const menu = build("display: none");

        document.getElementById("elsewhere").click();
        expect(menu.open).toBe(true);

        escape();
        expect(menu.open).toBe(true);
    });
});
