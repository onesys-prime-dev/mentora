import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { initialize } from "../../../../../resources/js/pages/courses/groups/index.js";

/*
 * Generate groups (screen 4A): the dialog markup is a small copy of features/groups/partials/dialogs.php
 * with the same hooks; the server's answers are stood in by fetch.
 */

const field = (name, control) => `<div data-field="${name}">${control}<p data-field-error hidden></p></div>`;
const dialog = (id, body) => `<div id="${id}" data-dialog hidden class="hidden"><h2 data-dialog-title></h2><p data-dialog-text></p>${body}</div>`;
const simple = (id, formAttr) => dialog(id, `<form ${formAttr}>${field("name", "<input name=\"name\">")}${field("formation_mode", "<select name=\"formation_mode\"><option value=\"manual\">m</option></select>")}${field("min_size", "<input name=\"min_size\">")}${field("max_size", "<input name=\"max_size\">")}${field("to_group", "<select name=\"to_group\"></select>")}<div data-signup-window hidden></div><p data-form-error hidden></p><button type="submit" data-dialog-label>Save</button></form>`);

const pairs = (kind) => `<fieldset data-pairs="${kind}">${field(`${kind}_a`, `<select name="${kind}_a"></select>`)}${field(`${kind}_b`, `<select name="${kind}_b"></select>`)}<button type="button" data-pair-add="${kind}">Add pair</button><ul data-pair-list="${kind}"></ul></fieldset>`;

const generate = dialog("group-generate-dialog", `
    <div data-generate-step1><form data-group-generate-form>
        <p data-generate-pool></p>
        ${field("by", "<select name=\"by\"><option value=\"size\">Group size</option><option value=\"count\">Number of groups</option></select>")}
        ${field("n", "<input name=\"n\" type=\"number\" value=\"2\">")}
        ${pairs("together")}${pairs("apart")}
        <p data-form-error hidden></p><button type="submit">Preview</button>
    </form></div>
    <div data-generate-step2 hidden><form data-group-generate-save-form>
        <p data-generate-status>Step 2 of 2 · Preview. Nothing has been saved yet.</p>
        <div data-generate-preview></div><div data-generate-alerts></div><p data-form-error hidden></p>
        <button type="button" data-generate-back>Back</button><button type="button" data-generate-shuffle>Shuffle again</button><button type="submit">Save groups</button>
    </form></div>`);

function tabHtml() {
    return `<div data-groups-page data-can-edit="1" data-pool-size="3">
        <table><tbody><tr><td><button type="button" data-group-set-open="5">Teams</button></td><td data-set-groups="5"></td><td data-set-placed="5"></td></tr></tbody></table>
        <section data-group-set-detail></section><p data-groups-live></p>
        ${simple("group-set-dialog", "data-group-set-form")}${simple("group-name-dialog", "data-group-name-form")}${simple("group-move-dialog", "data-group-move-form")}
        ${simple("group-set-archive-confirm", "")}${simple("group-delete-confirm", "")}
        ${generate}
        ${dialog("group-generate-replace-confirm", "<form><p data-form-error hidden></p><button type=\"submit\" data-dialog-label>Replace groups</button></form>")}
    </div>`;
}

const detail = (groups = []) => ({
    success: true,
    set: { id: 5, name: "Teams", formation_mode: "auto", min_size: 2, max_size: 3, signup_opens_at: null, signup_closes_at: null },
    groups,
    ungrouped: groups.length ? [] : [{ student_user_id: 101, name: "Ana Cruz" }, { student_user_id: 102, name: "Ben Lim" }, { student_user_id: 103, name: "Cai Ortiz" }],
    limits: { max_members: 30 },
});

const person = (id, name) => ({ student_user_id: id, name });
const preview = (replaces = 0, extra = {}) => ({
    success: true,
    groups: [{ members: [person(101, "Ana Cruz"), person(102, "Ben Lim")] }],
    ungrouped: [person(103, "Cai Ortiz")],
    conflicts: [],
    replaces,
    ...extra,
});

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } }));

function server(routes) {
    const fn = vi.fn((url, init = {}) => {
        const key = `${(init.method ?? "GET").toUpperCase()} ${String(url).replace("/instructor/api/courses/9", "")}`;

        return routes[key] ? routes[key](init) : Promise.reject(new Error(`unexpected ${key}`));
    });

    vi.stubGlobal("fetch", fn);

    return fn;
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const bodyOf = (fetch, suffix) => fetch.mock.calls.filter(([url, init]) => String(url).endsWith(suffix) && init?.method === "POST").map(([, init]) => JSON.parse(init.body));

async function toPreview(fetch) {
    await initialize();
    document.querySelector("[data-group-action=\"generate\"]").click();
    document.querySelector("[data-group-generate-form]").requestSubmit();
    await flush();

    return fetch;
}

beforeEach(() => {
    window.coursesConfig = { apiBaseUrl: "/instructor/api/courses/9", role: "instructor", offeringId: 9 };
    document.body.innerHTML = "<div id=\"course-content\"></div>";
});

afterEach(() => {
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
});

describe("generate groups", () => {

    it("opens with the pool line and shows the preview as 'nothing saved yet'", async () => {
        const fetch = server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => json(detail()),
            "POST /group-sets/5/generate/preview": () => json(preview()),
        });

        await initialize();
        document.querySelector("[data-group-action=\"generate\"]").click();

        expect(document.querySelector("[data-generate-pool]").textContent).toBe("Pool: 3 students");

        document.querySelector("[data-group-generate-form]").requestSubmit();
        await flush();

        expect(bodyOf(fetch, "/generate/preview")[0]).toEqual({ by: "size", n: 2, together: [], apart: [] });
        expect(document.querySelector("[data-generate-step2]").hidden).toBe(false);
        expect(document.querySelector("[data-generate-status]").textContent).toContain("Nothing has been saved yet");
        expect(document.querySelector("[data-preview-group]").textContent).toContain("Ana Cruz");
        expect(document.querySelector("[data-preview-ungrouped]").textContent).toContain("Cai Ortiz");
        expect(document.querySelector("[data-preview-replaces]")).toBeNull();
    });

    it("sends the picked pairs and warns about conflicts", async () => {
        const fetch = server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => json(detail()),
            "POST /group-sets/5/generate/preview": () => json(preview(0, { conflicts: ["Ana and Ben could not be kept apart."] })),
        });

        await initialize();
        document.querySelector("[data-group-action=\"generate\"]").click();

        const form = document.querySelector("[data-group-generate-form]");
        form.elements.apart_a.value = "101";
        form.elements.apart_b.value = "102";
        document.querySelector("[data-pair-add=\"apart\"]").click();
        expect(document.querySelector("[data-pair-list=\"apart\"]").textContent).toContain("Ana Cruz and Ben Lim");

        form.requestSubmit();
        await flush();

        expect(bodyOf(fetch, "/generate/preview")[0].apart).toEqual([[101, 102]]);
        expect(document.querySelector("[data-preview-conflict]").textContent).toContain("could not be kept apart");
    });

    it("Shuffle again asks again with a new seed", async () => {
        const fetch = server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => json(detail()),
            "POST /group-sets/5/generate/preview": () => json(preview()),
        });

        await toPreview(fetch);
        document.querySelector("[data-generate-shuffle]").click();
        await flush();
        document.querySelector("[data-generate-shuffle]").click();
        await flush();

        const [first, second, third] = bodyOf(fetch, "/generate/preview");
        expect(first.seed).toBeUndefined();
        expect(Number.isInteger(second.seed)).toBe(true);
        expect(second.seed).not.toBe(third.seed);
        expect(second.n).toBe(2);
    });

    it("saves the previewed groups without replace when the set is empty", async () => {
        const fetch = server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => json(detail()),
            "POST /group-sets/5/generate/preview": () => json(preview()),
            "POST /group-sets/5/generate": () => json({ success: true, count: 1 }),
        });

        await toPreview(fetch);
        document.querySelector("[data-group-generate-save-form]").requestSubmit();
        await flush();

        expect(bodyOf(fetch, "/5/generate")[0]).toEqual({ groups: [[101, 102]], replace: false });
        expect(document.querySelector("#group-generate-dialog").hidden).toBe(true);
        expect(fetch.mock.calls.filter(([url]) => String(url).endsWith("/group-sets/5")).length).toBe(2);
    });

    it("needs the confirmation before sending replace:true", async () => {
        const fetch = server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => json(detail([{ id: 11, name: "Team 1", members: [] }, { id: 12, name: "Team 2", members: [] }])),
            "POST /group-sets/5/generate/preview": () => json(preview(2)),
            "POST /group-sets/5/generate": () => json({ success: true, count: 1 }),
        });

        await toPreview(fetch);

        expect(document.querySelector("[data-preview-replaces]").textContent).toContain("This set already has 2 groups. Saving replaces them.");

        document.querySelector("[data-group-generate-save-form]").requestSubmit();
        await flush();

        expect(bodyOf(fetch, "/5/generate")).toEqual([]);
        expect(document.querySelector("#group-generate-replace-confirm").hidden).toBe(false);

        document.querySelector("#group-generate-replace-confirm form").requestSubmit();
        await flush();

        expect(bodyOf(fetch, "/5/generate")).toEqual([{ groups: [[101, 102]], replace: true }]);
        expect(document.querySelector("#group-generate-replace-confirm").hidden).toBe(true);
    });

    it("keeps the dialog open and shows the message on a 422", async () => {
        const fetch = server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => json(detail()),
            "POST /group-sets/5/generate/preview": () => json(preview()),
            "POST /group-sets/5/generate": () => json({ success: false, message: "Every student must be enrolled in this course and in only one group.", errors: {} }, 422),
        });

        await toPreview(fetch);
        document.querySelector("[data-group-generate-save-form]").requestSubmit();
        await flush();

        const error = document.querySelector("[data-group-generate-save-form] [data-form-error]");
        expect(error.hidden).toBe(false);
        expect(error.textContent).toContain("only one group");
        expect(document.querySelector("#group-generate-dialog").hidden).toBe(false);
        expect(document.querySelector("[data-generate-step2]").hidden).toBe(false);
    });

    it("shows a preview refusal beside the form and keeps the input", async () => {
        server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => json(detail()),
            "POST /group-sets/5/generate/preview": () => json({ success: false, message: "Give a group size or a number of groups of at least 1.", errors: {} }, 422),
        });

        await initialize();
        document.querySelector("[data-group-action=\"generate\"]").click();
        document.querySelector("[data-group-generate-form] [name=n]").value = "0";
        document.querySelector("[data-group-generate-form]").requestSubmit();
        await flush();

        expect(document.querySelector("[data-group-generate-form] [data-form-error]").textContent).toContain("at least 1");
        expect(document.querySelector("[data-group-generate-form] [name=n]").value).toBe("0");
        expect(document.querySelector("[data-generate-step1]").hidden).toBe(false);
    });

});
