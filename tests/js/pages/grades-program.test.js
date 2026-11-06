import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const order = [];

vi.mock("../../../resources/js/pages/courses/features/grades/roster/roster.js", async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        flushPendingScores: vi.fn(async () => {
            order.push("flush");
            await actual.flushPendingScores();
        }),
    };
});

import { setGradesApiBase } from "../../../resources/js/pages/courses/features/grades/api.js";
import { bindProgram } from "../../../resources/js/pages/grades/program.js";

const ROSTER = (name) => `<div data-gradebook-roster>${name}</div>`;
const ok = (body) => Promise.resolve({ ok: true, json: () => Promise.resolve(body) });

const row = (id, slug, extra = "", status = "active", state = "ready") => `<a href="/chairperson/grades?tab=program&section=${id}" data-program-row data-section-id="${id}" data-label="C-${id}" data-slug="${slug}" data-state="${state}" data-has-scheme="1" data-offering-status="${status}" data-instructors='[{"id":${id},"name":"T ${id}"}]' data-waiting="${id}" data-oldest-days="${id === 4 ? "" : id}" data-term-end="2026-12-15" ${extra}><span title="Title ${id}">C-${id}</span></a>`;

function page({ canEdit = true } = {}) {
    document.body.innerHTML = `<div id="root">
        ${row(1, "alpha", "aria-current=\"true\"")}${row(2, "beta")}${row(3, "gamma", "", "completed")}${row(4, "delta", "", "active", "finalized")}
        <button type="button" data-nudge>Nudge</button>
        <div data-program-actions data-section-id="1" data-label="C-1" data-state="ready" data-instructors='[{"id":1,"name":"T 1"}]' data-waiting="1" data-oldest-days="1" data-term-end="2026-12-15">
            <span data-action-label>C-1</span><span data-action-title>Title 1</span><span data-readonly-label>Read-only</span>
            ${canEdit ? "<button data-edit-as-chair>Edit as chairperson</button><button data-edit-done hidden>Done</button>" : ""}
            <a data-open-in-course href="/chairperson/courses/1/alpha/grades"></a>
        </div>
        <div data-grades-grid data-api-base="/chairperson/api/courses/1" data-section-id="1" data-readonly="1" data-has-scheme="1"></div>
        <template data-no-scheme-template><div data-no-scheme><a data-no-scheme-open href="#">Open in course →</a></div></template>
    </div>`;

    return document.getElementById("root");
}

const SENTENCE = "You're editing C-1 as chairperson. Every change is recorded in the audit log and the instructor can see it.";

let urls;

beforeEach(() => {
    order.length = 0;
    urls = [];
    window.history.replaceState(null, "", "/chairperson/grades?term=4&tab=program&section=1");
    vi.stubGlobal("fetch", vi.fn((url) => {
        urls.push(url);
        return ok({ success: true, html: ROSTER(url) });
    }));
});

afterEach(() => {
    setGradesApiBase(null);
    vi.unstubAllGlobals();
});

const settle = () => vi.waitFor(() => expect(document.querySelector("[data-grades-grid]").textContent).toContain("roster"));

describe("bindProgram", () => {
    it("loads the first grid read-only", async () => {
        bindProgram(page());
        await settle();

        expect(urls).toEqual(["/chairperson/api/courses/1/grades/roster?embedded=1&readonly=1"]);
    });

    it("clicking a row flushes pending scores, then loads that section read-only", async () => {
        const root = page();
        bindProgram(root);
        await settle();
        urls.length = 0;
        order.length = 0;

        root.querySelector("[data-section-id=\"2\"][data-program-row]").click();

        await vi.waitFor(() => expect(urls).toEqual(["/chairperson/api/courses/2/grades/roster?embedded=1&readonly=1"]));
        expect(order).toEqual(["flush"]);
        expect(document.querySelector("[data-grades-grid]").dataset.apiBase).toBe("/chairperson/api/courses/2");
        expect(document.querySelector("[data-action-label]").textContent).toBe("C-2");
        expect(document.querySelector("[data-open-in-course]").getAttribute("href")).toBe("/chairperson/courses/2/beta/grades");
    });

    it("the address and aria-current follow the selected row", async () => {
        const root = page();
        bindProgram(root);
        await settle();

        root.querySelector("[data-section-id=\"2\"][data-program-row]").click();
        await vi.waitFor(() => expect(window.location.search).toBe("?term=4&tab=program&section=2"));

        expect(root.querySelector("[data-section-id=\"2\"][data-program-row]").getAttribute("aria-current")).toBe("true");
        expect(root.querySelector("[data-section-id=\"1\"][data-program-row]").hasAttribute("aria-current")).toBe(false);
    });

    it("a slower earlier load never overwrites a later one", async () => {
        const pending = {};

        const root = page();
        bindProgram(root);
        await settle();

        vi.stubGlobal("fetch", vi.fn((url) => new Promise((resolve) => {
            const id = url.match(/courses\/(\d+)/)[1];
            pending[id] = () => resolve({ ok: true, json: () => Promise.resolve({ success: true, html: ROSTER(`section ${id}`) }) });
        })));

        root.querySelector("[data-section-id=\"2\"][data-program-row]").click();
        await vi.waitFor(() => expect(pending[2]).toBeTypeOf("function"));
        root.querySelector("[data-section-id=\"3\"][data-program-row]").click();
        await vi.waitFor(() => expect(pending[3]).toBeTypeOf("function"));

        pending[3]();
        pending[2]();

        await vi.waitFor(() => expect(document.querySelector("[data-grades-grid]").textContent).toContain("section 3"));
        await new Promise((resolve) => setTimeout(resolve, 10));
        expect(document.querySelector("[data-grades-grid]").textContent).not.toContain("section 2");
    });

    it("Edit as chairperson asks with the exact sentence; Continue reloads the grid without readonly and shows Done", async () => {
        const root = page();
        bindProgram(root);
        await settle();
        urls.length = 0;

        root.querySelector("[data-edit-as-chair]").click();

        const dialog = document.querySelector("[data-edit-confirm]");
        expect(dialog.hidden).toBe(false);
        expect(dialog.querySelector("[data-dialog-text]").textContent).toBe(SENTENCE);
        expect(urls).toEqual([]);

        dialog.querySelector("[data-edit-continue]").click();

        await vi.waitFor(() => expect(urls).toEqual(["/chairperson/api/courses/1/grades/roster?embedded=1"]));
        expect(dialog.hidden).toBe(true);
        expect(root.querySelector("[data-edit-done]").hidden).toBe(false);
        expect(root.querySelector("[data-edit-as-chair]").hidden).toBe(true);
        expect(document.querySelector("[data-grades-grid]").dataset.readonly).toBe("0");
    });

    it("Cancel changes nothing", async () => {
        const root = page();
        bindProgram(root);
        await settle();
        urls.length = 0;

        root.querySelector("[data-edit-as-chair]").click();
        document.querySelector("[data-edit-confirm] [data-dialog-close]").click();

        expect(urls).toEqual([]);
        expect(document.querySelector("[data-grades-grid]").dataset.readonly).toBe("1");
        expect(root.querySelector("[data-edit-done]").hidden).toBe(true);
        expect(root.querySelector("[data-edit-as-chair]").hidden).toBe(false);
    });

    it("Done returns to read-only", async () => {
        const root = page();
        bindProgram(root);
        await settle();

        root.querySelector("[data-edit-as-chair]").click();
        document.querySelector("[data-edit-continue]").click();
        await vi.waitFor(() => expect(root.querySelector("[data-edit-done]").hidden).toBe(false));
        urls.length = 0;

        root.querySelector("[data-edit-done]").click();

        await vi.waitFor(() => expect(urls).toEqual(["/chairperson/api/courses/1/grades/roster?embedded=1&readonly=1"]));
        expect(root.querySelector("[data-edit-done]").hidden).toBe(true);
        expect(root.querySelector("[data-edit-as-chair]").hidden).toBe(false);
    });

    it("opening another row while editing returns to read-only, hiding Edit for a closed section", async () => {
        const root = page();
        bindProgram(root);
        await settle();

        root.querySelector("[data-edit-as-chair]").click();
        document.querySelector("[data-edit-continue]").click();
        await vi.waitFor(() => expect(root.querySelector("[data-edit-done]").hidden).toBe(false));
        urls.length = 0;

        root.querySelector("[data-section-id=\"3\"][data-program-row]").click();

        await vi.waitFor(() => expect(urls).toEqual(["/chairperson/api/courses/3/grades/roster?embedded=1&readonly=1"]));
        expect(document.querySelector("[data-grades-grid]").dataset.readonly).toBe("1");
        expect(root.querySelector("[data-edit-done]").hidden).toBe(true);
        expect(root.querySelector("[data-edit-as-chair]").hidden).toBe(true);
    });

    it("without data-edit-as-chair nothing unlocks", async () => {
        const root = page({ canEdit: false });
        bindProgram(root);
        await settle();
        urls.length = 0;

        // A forged Continue, and opening another row: the grid is only ever asked for read-only.
        const forged = document.createElement("button");

        forged.dataset.editContinue = "";
        root.append(forged);
        forged.click();
        root.querySelector("[data-grades-grid]").click();
        root.querySelector("[data-section-id=\"2\"][data-program-row]").click();

        await vi.waitFor(() => expect(urls).toEqual(["/chairperson/api/courses/2/grades/roster?embedded=1&readonly=1"]));
        expect(document.querySelector("[data-edit-confirm]")).toBeNull();
        expect(document.querySelector("[data-grades-grid]").dataset.readonly).toBe("1");
        expect(urls.every((url) => url.endsWith("readonly=1"))).toBe(true);
    });

    it("a row switch carries the nudge data to the action bar, and a finalized section hides Nudge and Edit", async () => {
        const root = page();
        bindProgram(root);
        await settle();

        root.querySelector("[data-section-id=\"4\"][data-program-row]").click();
        await vi.waitFor(() => expect(urls.at(-1)).toBe("/chairperson/api/courses/4/grades/roster?embedded=1&readonly=1"));

        const bar = root.querySelector("[data-program-actions]");

        expect(bar.dataset).toMatchObject({ sectionId: "4", label: "C-4", state: "finalized", instructors: '[{"id":4,"name":"T 4"}]', waiting: "4", oldestDays: "", termEnd: "2026-12-15" });
        expect(root.querySelector("[data-nudge]").hidden).toBe(true);
        expect(root.querySelector("[data-edit-as-chair]").hidden).toBe(true);

        root.querySelector("[data-section-id=\"2\"][data-program-row]").click();
        await vi.waitFor(() => expect(bar.dataset.sectionId).toBe("2"));

        expect(bar.dataset).toMatchObject({ state: "ready", waiting: "2", oldestDays: "2" });
        expect(root.querySelector("[data-nudge]").hidden).toBe(false);
        expect(root.querySelector("[data-edit-as-chair]").hidden).toBe(false);
    });

    it("a grid swapped in by the live search is loaded again", async () => {
        const root = page();
        bindProgram(root);
        await settle();
        urls.length = 0;

        const fresh = document.createElement("div");

        fresh.setAttribute("data-grades-grid", "");
        fresh.dataset.apiBase = "/chairperson/api/courses/2";
        fresh.dataset.readonly = "1";
        fresh.dataset.hasScheme = "1";
        root.querySelector("[data-grades-grid]").replaceWith(fresh);

        document.dispatchEvent(new CustomEvent("content:swapped", { detail: { root } }));

        await vi.waitFor(() => expect(urls).toEqual(["/chairperson/api/courses/2/grades/roster?embedded=1&readonly=1"]));
    });
});

// The dean's College view draws the same table with no Edit as chairperson and a grid through dean/api/courses.
function deanPage({ grid = true } = {}) {
    const deanRow = (id, slug) => row(id, slug).replace(`/chairperson/grades?tab=program&section=${id}`, `/dean/grades?section=${id}`);

    document.body.innerHTML = `<div id="root">
        ${deanRow(1, "alpha")}${deanRow(2, "beta")}
        <button type="button" data-nudge>Nudge</button>
        <div data-program-actions data-section-id="1" data-label="C-1" data-state="ready" data-instructors="[]" data-waiting="1" data-oldest-days="1" data-term-end="2026-12-15">
            <span data-action-label>C-1</span><span data-action-title>Title 1</span><span data-readonly-label>Read-only</span>
            ${grid ? "<a data-open-in-course href=\"/dean/courses/1/alpha/grades\"></a>" : ""}
        </div>
        ${grid ? "<div data-grades-grid data-api-base=\"/dean/api/courses/1\" data-section-id=\"1\" data-readonly=\"1\" data-has-scheme=\"1\"></div>" : "<p data-grid-unavailable></p>"}
    </div>`;

    return document.getElementById("root");
}

describe("bindProgram on the dean's College view", () => {
    it("loads and switches grids through the dean's API, read-only, and never offers an unlock", async () => {
        window.history.replaceState(null, "", "/dean/grades?term=4&section=1");

        const root = deanPage();
        bindProgram(root);
        await settle();

        // A forged Continue and Done change nothing: without the server's button nothing unlocks.
        for (const name of ["editContinue", "editDone"]) {
            const forged = document.createElement("button");

            forged.dataset[name] = "";
            root.append(forged);
            forged.click();
        }

        root.querySelector("[data-section-id=\"2\"][data-program-row]").click();

        await vi.waitFor(() => expect(urls).toEqual([
            "/dean/api/courses/1/grades/roster?embedded=1&readonly=1",
            "/dean/api/courses/2/grades/roster?embedded=1&readonly=1",
        ]));
        expect(document.querySelector("[data-edit-confirm]")).toBeNull();
        expect(document.querySelector("[data-edit-as-chair]")).toBeNull();
        expect(document.querySelector("[data-grades-grid]").dataset.readonly).toBe("1");
        expect(document.querySelector("[data-open-in-course]").getAttribute("href")).toBe("/dean/courses/2/beta/grades");
        expect(window.location.search).toBe("?term=4&section=2");
    });

    it("without a grid on the page, a row is an ordinary link", () => {
        const root = deanPage({ grid: false });
        bindProgram(root);

        const event = new MouseEvent("click", { bubbles: true, cancelable: true, button: 0 });

        root.querySelector("[data-section-id=\"2\"][data-program-row]").dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(urls).toEqual([]);
    });
});
