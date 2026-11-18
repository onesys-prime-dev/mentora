// @vitest-environment jsdom
import { afterEach, beforeAll, describe, expect, it } from "vitest";

beforeAll(async () => {
    await import("../../../resources/js/shared/check-all.js");
});

afterEach(() => {
    document.body.innerHTML = "";
});

const form = () => {
    document.body.innerHTML = `<form>
        <input type="checkbox" id="all" data-check-all="o[]">
        <input type="checkbox" name="o[]" id="a" checked>
        <input type="checkbox" name="o[]" id="b">
        <input type="checkbox" name="o[]" id="c" disabled>
    </form>`;
    const el = (id) => document.getElementById(id);
    return { all: el("all"), a: el("a"), b: el("b"), c: el("c") };
};
const click = (box) => {
    box.checked = !box.checked;
    box.dispatchEvent(new Event("change", { bubbles: true }));
};

describe("check-all", () => {
    it("ticks the enabled boxes and leaves disabled ones alone", () => {
        const { all, a, b, c } = form();
        click(all);
        expect([a.checked, b.checked, c.checked]).toEqual([true, true, false]);
        click(all);
        expect([a.checked, b.checked]).toEqual([false, false]);
    });

    it("shows a partial selection as indeterminate", () => {
        const { all, a } = form();
        a.dispatchEvent(new Event("change", { bubbles: true }));
        expect(all.indeterminate).toBe(true);
        expect(all.checked).toBe(false);
    });

    it("ticks the header when every box is ticked", () => {
        const { all, b } = form();
        click(b);
        expect(all.checked).toBe(true);
        expect(all.indeterminate).toBe(false);
    });
});
