// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { bindDialogs } from "../../../resources/js/shared/dialog.js";
import { bindRowActions } from "../../../resources/js/shared/row-actions.js";

// One row as components/data-table.php renders it with phone rows on.
const row = (n, { primary = "", label = "", actions = "" } = {}) => `
    <tr data-row id="row-${n}">
        <td data-role="primary">${primary}</td>
        <td data-role="actions">${actions}</td>
        <td data-role="more">
            <button type="button" id="more-${n}" data-row-actions-open aria-haspopup="dialog" aria-controls="t-actions" data-row-label="${label}">⋯</button>
            <template data-row-fields><div><dt>Email</dt><dd><a id="mail-${n}" data-mail href="#mail">x${n}@example.test</a></dd></div></template>
        </td>
    </tr>`;

const SHEET = `
    <div id="t-actions" data-dialog data-dialog-variant="sheet" hidden class="hidden">
        <div data-dialog-panel>
            <h2 id="t-actions-title" data-dialog-title>Row</h2>
            <button type="button" data-dialog-close aria-label="Close">x</button>
            <dl data-row-actions-fields></dl>
            <div data-row-actions-slot></div>
        </div>
    </div>`;

function page(rows) {
    document.body.innerHTML = `<div data-table data-phone-rows><table><tbody>${rows}</tbody></table>${SHEET}</div>`;
    bindRowActions(document);
}

const $ = (selector) => document.querySelector(selector);
const sheet = () => $("#t-actions");
const slot = () => $("[data-row-actions-slot]");

afterEach(() => {
    document.body.innerHTML = "";
    document.body.className = "";
    vi.restoreAllMocks();
});

describe("row-actions sheet", () => {
    it("opening moves the actions into the sheet and closing moves them back", () => {
        page(row(1, { primary: "Ann Reyes", actions: `<a id="edit" href="#e">Edit</a><button type="button" id="drop" data-destructive>Remove</button>` }));
        const edit = $("#edit");
        const drop = $("#drop");

        $("#more-1").click();

        expect(sheet().hidden).toBe(false);
        expect(slot().contains(edit)).toBe(true);
        expect(slot().contains(drop)).toBe(true);
        expect($("#row-1 [data-role=actions]").children).toHaveLength(0);

        // The fields are an inert copy: no ids or hooks clash with the row's own cell.
        expect($("[data-row-actions-fields]").textContent).toContain("x1@example.test");
        expect($("[data-row-actions-fields] [id], [data-row-actions-fields] [data-mail]")).toBeNull();

        $("[data-dialog-close]").click();

        expect(sheet().hidden).toBe(true);
        expect($("#row-1 [data-role=actions]").firstElementChild).toBe(edit);
        expect($("#row-1 [data-role=actions]").lastElementChild).toBe(drop);
        expect($("[data-row-actions-fields]").children).toHaveLength(0);
    });

    it('the sheet title is the row\'s primary text, then its label, then "Row"', () => {
        page(row(1, { primary: " Ann Reyes " }) + row(2, { label: "Ben Cruz" }) + row(3));
        const title = () => $("[data-dialog-title]").textContent;

        $("#more-1").click();
        expect(title()).toBe("Ann Reyes");
        $("[data-dialog-close]").click();

        $("#more-2").click();
        expect(title()).toBe("Ben Cruz");
        $("[data-dialog-close]").click();

        $("#more-3").click();
        expect(title()).toBe("Row");
    });

    it("Escape closes the sheet and returns the nodes and the focus to the ⋯", () => {
        page(row(1, { primary: "Ann", actions: `<button type="button" id="edit">Edit</button>` }));
        const edit = $("#edit");
        const more = $("#more-1");

        more.focus();
        more.click();
        expect(document.activeElement).not.toBe(more);

        document.activeElement.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));

        expect(sheet().hidden).toBe(true);
        expect($("#row-1 [data-role=actions]").firstElementChild).toBe(edit);
        expect(document.activeElement).toBe(more);
    });

    it("a non-submit action closes the sheet before it runs", () => {
        page(row(1, { primary: "Ann", actions: `<button type="button" id="edit">Edit</button>` }));
        let hiddenWhenRun = null;
        $("#edit").addEventListener("click", () => (hiddenWhenRun = sheet().hidden));

        $("#more-1").click();
        // An exit animation would keep it up: the action must not wait for one.
        sheet().getAnimations = () => [{ finished: new Promise(() => {}), effect: { getTiming: () => ({ iterations: 1 }) } }];
        $("#edit").click();

        expect(hiddenWhenRun).toBe(true);
        expect($("#row-1 [data-role=actions]").contains($("#edit"))).toBe(true);
        expect(document.body.classList.contains("overflow-hidden")).toBe(false);

        // A cell's link among the fields closes it too.
        $("#more-1").click();
        $("[data-row-actions-fields] a").click();
        expect(sheet().hidden).toBe(true);
    });

    it("a submit action keeps its form values", () => {
        page(row(1, { primary: "Ann", actions: `<form id="f"><input name="note" value="a"><select name="why"><option>x</option><option>y</option></select><button id="go">Go</button></form>` }));
        const form = $("#f");
        let submitted = null;
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            submitted = Object.fromEntries(new FormData(form));
        });

        $("#more-1").click();
        form.elements.note.value = "typed";
        form.elements.why.value = "y";
        $("#go").click();

        // Submitting leaves the sheet up (navigation or dialog:close ends it).
        expect(submitted).toEqual({ note: "typed", why: "y" });
        expect(sheet().hidden).toBe(false);

        $("[data-dialog-close]").click();

        expect($("#row-1 [data-role=actions]").firstElementChild).toBe(form);
        expect(Object.fromEntries(new FormData(form))).toEqual({ note: "typed", why: "y" });
    });

    it("an action that opens a second dialog returns focus to the ⋯ when that dialog closes", () => {
        page(row(1, { primary: "Ann", actions: `<button type="button" id="review" data-dialog-open="other">Review</button>` }));
        document.body.insertAdjacentHTML("beforeend", `<div id="other" data-dialog hidden class="hidden"><button id="done" data-dialog-close>Done</button></div>`);
        bindDialogs();
        const review = $("#review");
        // Back in its max-md:hidden cell on a phone.
        review.checkVisibility = () => false;

        $("#more-1").click();
        review.click();

        expect(sheet().hidden).toBe(true);
        expect($("#other").hidden).toBe(false);

        $("#done").click();

        expect(document.activeElement).toBe($("#more-1"));
    });

    it("a live search swapping the table out from under the sheet releases the page", () => {
        page(row(1, { primary: "Ann", actions: `<a id="edit" href="#e">Edit</a>` }));
        $("#more-1").click();
        expect(document.body.classList.contains("overflow-hidden")).toBe(true);

        const fresh = document.createElement("div");
        fresh.innerHTML = `<div data-table data-phone-rows><table><tbody>${row(2, { primary: "Ben" })}</tbody></table>${SHEET}</div>`;
        expect(() => {
            $("[data-table]").replaceWith(fresh.firstElementChild);
            document.dispatchEvent(new CustomEvent("content:swapped", { detail: { root: document.body } }));
        }).not.toThrow();

        expect(document.body.classList.contains("overflow-hidden")).toBe(false);

        // The new table's sheet works.
        $("#more-2").click();
        expect(sheet().hidden).toBe(false);
        expect($("[data-dialog-title]").textContent).toBe("Ben");
    });

    it("two rows do not mix their actions", () => {
        page(row(1, { primary: "Ann", actions: `<a id="a1" href="#1">One</a>` }) + row(2, { primary: "Ben", actions: `<a id="a2" href="#2">Two</a>` }));

        $("#more-1").click();
        $("[data-dialog-close]").click();
        $("#more-2").click();

        expect(slot().contains($("#a2"))).toBe(true);
        expect(slot().contains($("#a1"))).toBe(false);
        expect($("#row-1 [data-role=actions]").contains($("#a1"))).toBe(true);
        expect($("[data-row-actions-fields]").textContent).toContain("x2@example.test");
        expect($("[data-row-actions-fields]").textContent).not.toContain("x1@example.test");
    });
});
