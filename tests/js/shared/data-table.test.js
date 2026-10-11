// @vitest-environment jsdom
import { afterEach, describe, expect, it } from "vitest";
import { bindDataTable } from "../../../resources/js/shared/data-table.js";

const TABLE = `
    <div id="t" data-table>
        <div data-bulk-bar hidden>
            <span data-selected-count data-noun-one="person" data-noun-many="people"></span>
            <form data-bulk-form action="/bulk" method="post"><button type="submit">Export</button></form>
        </div>
        <table><tbody>
            <tr><td><input type="checkbox" data-select-all></td></tr>
            <tr><td><input type="checkbox" data-row-select value="1"></td></tr>
            <tr><td><input type="checkbox" data-row-select value="2"></td></tr>
            <tr><td><input type="checkbox" data-row-select value="3"></td></tr>
        </tbody></table>
    </div>`;

function mount() {
    document.body.innerHTML = TABLE;
    const root = document.getElementById("t");
    const api = bindDataTable(root);
    const rows = Array.from(root.querySelectorAll("[data-row-select]"));
    const all = root.querySelector("[data-select-all]");
    const tick = (box, checked = true) => {
        box.checked = checked;
        box.dispatchEvent(new Event("change", { bubbles: true }));
    };

    return { root, api, rows, all, tick, bar: root.querySelector("[data-bulk-bar]"), count: root.querySelector("[data-selected-count]") };
}

afterEach(() => {
    document.body.innerHTML = "";
});

describe("data table selection", () => {
    it("starts with nothing ticked and the bulk bar hidden", () => {
        const { bar, all } = mount();

        expect(bar.hidden).toBe(true);
        expect(all.checked).toBe(false);
        expect(all.indeterminate).toBe(false);
    });

    it("shows the bulk bar and the count in the table's own words", () => {
        const { rows, tick, bar, count } = mount();

        tick(rows[0]);
        expect(bar.hidden).toBe(false);
        expect(count.textContent).toBe("1 person selected");

        tick(rows[1]);
        expect(count.textContent).toBe("2 people selected");
    });

    it("shows the header box as partly ticked, then fully ticked", () => {
        const { rows, tick, all } = mount();

        tick(rows[0]);
        expect(all.indeterminate).toBe(true);
        expect(all.checked).toBe(false);

        rows.slice(1).forEach((box) => tick(box));
        expect(all.indeterminate).toBe(false);
        expect(all.checked).toBe(true);
    });

    it("selects and clears every row from the header box", () => {
        const { rows, all, tick, bar } = mount();

        tick(all);
        expect(rows.every((box) => box.checked)).toBe(true);

        tick(all, false);
        expect(rows.some((box) => box.checked)).toBe(false);
        expect(bar.hidden).toBe(true);
    });

    it("highlights ticked rows", () => {
        const { rows, tick } = mount();

        tick(rows[1]);

        expect(rows[1].closest("tr").classList.contains("bg-[#F2F7FF]")).toBe(true);
        expect(rows[0].closest("tr").classList.contains("bg-[#F2F7FF]")).toBe(false);
    });

    it("sends the ticked ids with the bulk form, once each time", () => {
        const { root, rows, tick } = mount();
        tick(rows[0]);
        tick(rows[2]);
        const form = root.querySelector("form");

        form.dispatchEvent(new Event("submit", { cancelable: true }));
        form.dispatchEvent(new Event("submit", { cancelable: true }));

        const ids = Array.from(form.querySelectorAll('input[name="ids[]"]')).map((input) => input.value);
        expect(ids).toEqual(["1", "3"]);
    });

    it("does not submit the bulk form with nothing ticked", () => {
        const { root } = mount();
        const event = new Event("submit", { cancelable: true });

        root.querySelector("form").dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });
});
