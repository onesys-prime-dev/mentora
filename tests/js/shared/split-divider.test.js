// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { bindSplitDivider } from "../../../resources/js/shared/split-divider.js";

// jsdom's root font size is unset, so 1rem falls back to 16px: min 256, max 448, middle 352.
const PAGE = `
    <div data-split data-split-key="t" data-split-min="16rem" data-split-max="28rem">
        <div data-split-start></div>
        <div data-split-handle role="separator" aria-orientation="vertical" tabindex="0"></div>
        <div data-split-end></div>
    </div>`;

const split = () => document.querySelector("[data-split]");
const handle = () => document.querySelector("[data-split-handle]");
const width = () => split().style.getPropertyValue("--split-start");
const fire = (type, init) => document.dispatchEvent(new MouseEvent(type, { bubbles: true, ...init }));

function page() {
    document.body.innerHTML = PAGE;
    bindSplitDivider();
}

afterEach(() => {
    document.body.innerHTML = "";
    localStorage.clear();
    vi.restoreAllMocks();
});

describe("split divider", () => {
    it("restores the saved width", () => {
        localStorage.setItem("split:t", "300");
        page();

        expect(width()).toBe("300px");
        expect(handle().getAttribute("aria-valuenow")).toBe("300");
        expect(handle().getAttribute("aria-valuemin")).toBe("256");
        expect(handle().getAttribute("aria-valuemax")).toBe("448");
    });

    it("pointer drag clamps to min and max and saves", () => {
        page();
        handle().dispatchEvent(new MouseEvent("pointerdown", { bubbles: true, clientX: 100, button: 0 }));
        fire("pointermove", { clientX: 1000 });
        expect(width()).toBe("448px");
        fire("pointermove", { clientX: -1000 });
        expect(width()).toBe("256px");
        fire("pointerup", {});

        expect(localStorage.getItem("split:t")).toBe("256");
    });

    it("a plain click without movement does not save", () => {
        page();
        handle().dispatchEvent(new MouseEvent("pointerdown", { bubbles: true, clientX: 100, button: 0 }));
        fire("pointerup", {});

        expect(localStorage.getItem("split:t")).toBeNull();
    });

    it("arrow keys move 16px", () => {
        page();
        handle().dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowRight", bubbles: true }));
        expect(width()).toBe("368px");
        handle().dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowLeft", bubbles: true }));
        handle().dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowLeft", bubbles: true }));
        expect(width()).toBe("336px");
        handle().dispatchEvent(new KeyboardEvent("keydown", { key: "End", bubbles: true }));
        expect(width()).toBe("448px");
        handle().dispatchEvent(new KeyboardEvent("keydown", { key: "Home", bubbles: true }));
        expect(width()).toBe("256px");
    });

    it("a broken localStorage does not throw", () => {
        vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
            throw new Error("blocked");
        });
        vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
            throw new Error("blocked");
        });

        expect(() => page()).not.toThrow();
        expect(() => handle().dispatchEvent(new KeyboardEvent("keydown", { key: "End", bubbles: true }))).not.toThrow();
        expect(width()).toBe("448px");
    });

    it("double click resets", () => {
        localStorage.setItem("split:t", "300");
        page();
        handle().dispatchEvent(new MouseEvent("dblclick", { bubbles: true }));

        expect(width()).toBe("352px");
        expect(localStorage.getItem("split:t")).toBeNull();
    });

    it("data-split-default replaces the middle, and a saved width still wins", () => {
        document.body.innerHTML = PAGE.replace("data-split ", 'data-split data-split-default="290px" ');
        bindSplitDivider();
        expect(width()).toBe("290px");

        handle().dispatchEvent(new KeyboardEvent("keydown", { key: "End", bubbles: true }));
        expect(width()).toBe("448px");
        handle().dispatchEvent(new MouseEvent("dblclick", { bubbles: true }));
        expect(width()).toBe("290px");

        localStorage.setItem("split:t", "400");
        bindSplitDivider();
        expect(width()).toBe("400px");
    });
});
