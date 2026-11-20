// @vitest-environment jsdom
import { afterEach, describe, expect, it } from "vitest";
import { bindActionBars } from "../../../resources/js/shared/action-bar.js";

const BAR = (n) => `
    <div data-action-bar id="bar${n}">
        <div id="panel${n}" data-action-bar-panel><button>Criterion</button></div>
        <button id="toggle${n}" data-action-bar-toggle aria-controls="panel${n}" aria-expanded="false">Rubric</button>
    </div>`;

function page(html) {
    document.body.innerHTML = html;
    bindActionBars();
}

afterEach(() => {
    document.body.innerHTML = "";
});

describe("action bar", () => {
    it("toggle opens and sets aria-expanded=true and data-open", () => {
        page(BAR(1));
        document.getElementById("toggle1").click();

        expect(document.getElementById("toggle1").getAttribute("aria-expanded")).toBe("true");
        expect(document.getElementById("bar1").hasAttribute("data-open")).toBe(true);

        document.getElementById("toggle1").click();
        expect(document.getElementById("toggle1").getAttribute("aria-expanded")).toBe("false");
        expect(document.getElementById("bar1").hasAttribute("data-open")).toBe(false);
    });

    it("Escape closes and focuses the toggle", () => {
        page(BAR(1));
        document.getElementById("toggle1").click();
        document.getElementById("toggle2")?.focus();
        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));

        expect(document.getElementById("bar1").hasAttribute("data-open")).toBe(false);
        expect(document.activeElement).toBe(document.getElementById("toggle1"));
    });

    it("opening a second bar closes the first", () => {
        page(BAR(1) + BAR(2));
        document.getElementById("toggle1").click();
        document.getElementById("toggle2").click();

        expect(document.getElementById("bar1").hasAttribute("data-open")).toBe(false);
        expect(document.getElementById("toggle1").getAttribute("aria-expanded")).toBe("false");
        expect(document.getElementById("bar2").hasAttribute("data-open")).toBe(true);
    });

    it("collapsed panel is inert", () => {
        page(BAR(1));
        const panel = document.getElementById("panel1");
        expect(panel.inert).toBe(true);

        document.getElementById("toggle1").click();
        expect(panel.inert).toBe(false);

        document.getElementById("toggle1").click();
        expect(panel.inert).toBe(true);
    });
});
