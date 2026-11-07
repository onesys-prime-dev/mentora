import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { bindNudge, monthDay } from "../../../resources/js/pages/grades/nudge.js";

const ONE = JSON.stringify([{ id: 7, name: "Tess Teacher" }]);
const TWO = JSON.stringify([{ id: 7, name: "Tess Teacher" }, { id: 8, name: "Theo Tutor" }]);

const row = (id, extra) => `<a href="#" data-program-row data-section-id="${id}" data-label="IT101-${id}" data-state="waiting" data-instructors='${ONE}' data-waiting="23" data-oldest-days="9" data-term-end="2026-12-15" ${extra}></a>`;

// The action bar of the section shown below the table: what Nudge reads.
const bar = (id, extra = "") => `<div data-program-actions data-section-id="${id}" data-label="IT101-${id}" data-state="waiting" data-instructors='${ONE}' data-waiting="23" data-oldest-days="9" data-term-end="2026-12-15" ${extra}></div>`;

function page(shown = bar(2), rows = `${row(1, "")}${row(2, 'aria-current="true"')}`) {
    document.body.innerHTML = `${rows}${shown}
        <button type="button" data-nudge>Nudge</button>
        <div id="nudge-dialog" data-dialog data-nudge-dialog hidden class="hidden">
            <button type="button" data-dialog-close>x</button>
            <form action="/chairperson/follow-ups" method="post" data-nudge-form>
                <input type="hidden" name="offering_id" value="">
                <select name="recipient_id" data-nudge-recipient></select>
                <input name="title" data-nudge-title>
                <select name="reason" data-nudge-reason><option value="overdue_grading">a</option><option value="blocked_signoff">b</option><option value="other">c</option></select>
                <input name="due_date" type="date">
                <textarea name="message" data-nudge-message></textarea>
                <p data-form-error hidden></p>
                <button type="submit" data-nudge-send>Send</button>
            </form>
        </div>`;

    bindNudge(document.body);
    document.querySelector("[data-nudge]").click();
}

const form = () => document.querySelector("[data-nudge-form]");
const dialog = () => document.querySelector("[data-nudge-dialog]");

beforeEach(() => {
    vi.stubGlobal("fetch", vi.fn());
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = "";
});

describe("the nudge dialog", () => {
    it("the dialog is prefilled from the shown section", () => {
        page();

        expect(dialog().hidden).toBe(false);
        expect(form().elements.namedItem("offering_id").value).toBe("2");
        expect(form().elements.namedItem("title").value).toBe("IT101-2: 23 submissions waiting to be graded, oldest 9 days");
        expect(form().elements.namedItem("message").value).toContain("IT101-2: 23 submissions waiting to be graded, oldest 9 days");
        expect(form().elements.namedItem("reason").value).toBe("overdue_grading");
    });

    it("words a section with nothing waiting by the term's end", () => {
        page(bar(3).replace('data-waiting="23"', 'data-waiting="0"').replace('data-state="waiting"', 'data-state="ready"'));

        expect(form().elements.namedItem("title").value).toBe("IT101-3: not finalized; the term ends Dec 15");
        expect(monthDay("2026-12-05")).toBe("Dec 5");
    });

    it("two instructors give a choice, one is preselected", () => {
        page();

        const select = form().elements.namedItem("recipient_id");

        expect(select.options.length).toBe(1);
        expect(select.value).toBe("7");

        dialog().querySelector("[data-dialog-close]").click();
        document.querySelector("[data-program-actions]").dataset.instructors = TWO;
        document.querySelector("[data-nudge]").click();

        expect(select.options.length).toBe(3);
        expect(select.value).toBe("");
    });

    it("works for a section whose row is not on this page (no row is current)", () => {
        page(bar(42), `${row(1, "")}${row(2, "")}`);

        expect(dialog().hidden).toBe(false);
        expect(form().elements.namedItem("offering_id").value).toBe("42");
        expect(form().elements.namedItem("title").value).toBe("IT101-42: 23 submissions waiting to be graded, oldest 9 days");
        expect(form().elements.namedItem("recipient_id").value).toBe("7");
    });

    it("a finalized section opens no blank form", () => {
        page(bar(2).replace('data-state="waiting"', 'data-state="finalized"').replace('data-waiting="23"', 'data-waiting="0"'));

        expect(dialog().hidden).toBe(true);
    });

    it("nobody left to nudge: says so and Send is disabled", () => {
        page(bar(2).replace(`data-instructors='${ONE}'`, "data-instructors='[]'"));

        const select = form().elements.namedItem("recipient_id");

        expect(select.options.length).toBe(1);
        expect(select.options[0].textContent).toBe("No instructor to nudge");
        expect(form().querySelector("[data-nudge-send]").disabled).toBe(true);

        dialog().querySelector("[data-dialog-close]").click();
        document.querySelector("[data-program-actions]").dataset.instructors = ONE;
        document.querySelector("[data-nudge]").click();

        expect(form().querySelector("[data-nudge-send]").disabled).toBe(false);
    });

    it("one submission is singular; a submission from today is \"oldest today\"", () => {
        page(bar(2).replace('data-waiting="23"', 'data-waiting="1"').replace('data-oldest-days="9"', 'data-oldest-days="0"'));

        expect(form().elements.namedItem("title").value).toBe("IT101-2: 1 submission waiting to be graded, oldest today");

        dialog().querySelector("[data-dialog-close]").click();
        Object.assign(document.querySelector("[data-program-actions]").dataset, { waiting: "4", oldestDays: "1" });
        document.querySelector("[data-nudge]").click();

        expect(form().elements.namedItem("title").value).toBe("IT101-2: 4 submissions waiting to be graded, oldest 1 day");

        dialog().querySelector("[data-dialog-close]").click();
        document.querySelector("[data-program-actions]").dataset.oldestDays = "";
        document.querySelector("[data-nudge]").click();

        expect(form().elements.namedItem("title").value).toBe("IT101-2: 4 submissions waiting to be graded");
    });

    it("Send posts the fields and shows the toast", async () => {
        page();
        fetch.mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({ message: "Follow-up sent to Tess Teacher.", redirect: "/x" }) });

        form().dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));

        await vi.waitFor(() => expect(dialog().hidden).toBe(true));

        const [url, init] = fetch.mock.calls[0];

        expect(url).toContain("/chairperson/follow-ups");
        expect(init.method).toBe("POST");
        expect(init.headers["X-Requested-With"]).toBe("XMLHttpRequest");
        expect(init.body.get("offering_id")).toBe("2");
        expect(init.body.get("recipient_id")).toBe("7");
        expect(init.body.get("reason")).toBe("overdue_grading");
        expect(document.body.textContent).toContain("Follow-up sent to Tess Teacher.");
    });

    it("a 422 keeps the dialog open with the error", async () => {
        page();
        form().elements.namedItem("message").value = "My own words";
        fetch.mockResolvedValue({ ok: false, status: 422, json: () => Promise.resolve({ errors: { message: "No." }, message: "You cannot send a follow-up to that person about that course." }) });

        form().dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));

        await vi.waitFor(() => expect(document.querySelector("[data-form-error]").hidden).toBe(false));

        expect(document.querySelector("[data-form-error]").textContent).toBe("You cannot send a follow-up to that person about that course.");
        expect(dialog().hidden).toBe(false);
        expect(form().elements.namedItem("message").value).toBe("My own words");
    });
});
