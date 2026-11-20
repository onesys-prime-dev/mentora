import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { initializeActivityGroups } from "../../../../../resources/js/pages/courses/groups/activity-panel.js";

/*
 * The assignment editor's "Group activity" card (screen 2A). The server's HTML (switch, settings form, dialogs)
 * is stood in by a small copy of assessments/partials/group-activity-section.php with the same hooks; the
 * groups come from the activity-groups JSON.
 */

const field = (name, control) => `<div data-field="${name}">${control}<p data-field-error hidden></p></div>`;

const dialog = (id, form) => `
    <div id="${id}" data-dialog hidden class="hidden">
        <h2 data-dialog-title></h2><p data-dialog-text></p>
        ${form}
    </div>`;

const select = (name, options) => `<select name="${name}">${options.map((o) => `<option value="${o}">${o}</option>`).join("")}</select>`;

function sectionHtml() {
    return `
        <section data-group-activity data-assessment-id="7">
            <input type="checkbox" data-ga-toggle>
            <div data-ga-lock hidden><p data-lock="closed"><svg></svg><span>This is closed.</span></p></div>
            <div data-ga-status></div>
            <div data-ga-empty hidden><span>Students work on this alone</span><span>Turn on Group activity to let students hand in work as a group.</span></div>
            <form data-ga-settings hidden><fieldset data-ga-fields>
                ${field("ungrouped_policy", select("ungrouped_policy", ["block", "solo"]))}
                ${field("submission_style", select("submission_style", ["shared", "leader_only", "per_member"]))}
                ${field("grading_mode", select("grading_mode", ["collective", "collective_adjust", "individual"]))}
                ${field("min_size", "<input name=\"min_size\" type=\"number\">")}
                ${field("max_size", "<input name=\"max_size\" type=\"number\">")}
                ${["nonleader_can_rename", "nonleader_can_vote", "nonleader_can_leave", "groups_visible"].map((n) => field(n, `<input type="checkbox" name="${n}">`)).join("")}
                <p data-ga-source></p>
                <button type="button" data-ga-action="recopy">Re-copy from set</button>
                <button type="button" data-ga-action="copy-from">Copy from another activity</button>
            </fieldset></form>
            <div data-ga-board></div>
            <p data-groups-live></p>
            ${dialog("ga-attach-dialog", `<form data-ga-attach-form>${field("set_id", "<select name=\"set_id\"></select>")}<p data-form-error hidden></p><button type="submit">Make it a group activity</button></form>`)}
            ${dialog("ga-detach-confirm", "<form><p data-form-error hidden></p><button type=\"submit\">Remove groups</button></form>")}
            ${dialog("ga-policy-confirm", "<form><p data-form-error hidden></p><button type=\"submit\" data-dialog-label>Change it</button></form>")}
            ${dialog("ga-unlock-dialog", `<form data-ga-unlock-form>${field("reason", "<textarea name=\"reason\"></textarea>")}<p data-form-error hidden></p><button type="submit">Unlock group</button></form>`)}
            ${dialog("ga-late-dialog", `<form data-ga-late-form>${field("student_user_id", "<select name=\"student_user_id\"></select>")}${field("reason", "<textarea name=\"reason\"></textarea>")}<p data-form-error hidden></p><button type="submit">Add to group</button></form>`)}
            ${dialog("ga-name-dialog", `<form data-ga-name-form>${field("name", "<input name=\"name\">")}<p data-form-error hidden></p><button type="submit" data-dialog-label>Save</button></form>`)}
            ${dialog("ga-move-dialog", `<form data-ga-move-form>${field("to_group", "<select name=\"to_group\"></select>")}<p data-form-error hidden></p><button type="submit">Move</button></form>`)}
            ${dialog("ga-delete-confirm", "<form><p data-form-error hidden></p><button type=\"submit\">Delete group</button></form>")}
            ${dialog("ga-recopy-confirm", "<form><p data-form-error hidden></p><button type=\"submit\">Re-copy</button></form>")}
            ${dialog("ga-copy-dialog", `<form data-ga-copy-form>${field("from_assessment_id", "<select name=\"from_assessment_id\"></select>")}<p data-form-error hidden></p><button type="submit">Copy groups</button></form>`)}
        </section>`;
}

const config = (over = {}) => ({
    id: 3, source_set_id: 5, source_set_name: "Project teams", ungrouped_policy: "block", submission_style: "shared", grading_mode: "collective",
    min_size: 1, max_size: 3, nonleader_can_rename: 0, nonleader_can_vote: 0, nonleader_can_leave: 1, groups_visible: 0, ...over,
});

const detail = ({ attached = true, frozen = false, canWrite = true } = {}) => ({
    success: true,
    config: attached ? config() : null,
    groups: attached ? [
        { id: 11, name: "Team 1", frozen, members: [{ student_user_id: 101, name: "Ana Cruz" }] },
        { id: 12, name: "Team 2", frozen: false, members: [] },
    ] : [],
    ungrouped: attached ? [{ student_user_id: 103, name: "Cai Ortiz" }] : [],
    sets: [{ id: 5, name: "Project teams", group_count: 2 }],
    activities: [],
    flags: { frozenGroups: frozen, canWrite, offeringFinalized: false },
    lockReason: canWrite ? "" : "View only.",
});

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } }));

/** fetch answers by address and method; the calls are kept to look at. */
function server(routes) {
    const fn = vi.fn((url, init = {}) => {
        const method = (init.method ?? "GET").toUpperCase();
        const key = `${method} ${String(url).replace("/instructor/api/courses/9/assessments/7/groups", "") || "/"}`;
        const answer = routes[key];

        if (!answer) {
            return Promise.reject(new Error(`unexpected ${key}`));
        }

        return answer(init);
    });

    vi.stubGlobal("fetch", fn);

    return fn;
}

const flush = async () => {
    for (let i = 0; i < 4; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};

const root = () => document.querySelector("[data-group-activity]");
const sent = (fetch, method, tail) => fetch.mock.calls.filter(([url, init = {}]) => (init.method ?? "GET") === method && String(url).endsWith(tail));

beforeEach(() => {
    window.coursesConfig = { apiBaseUrl: "/instructor/api/courses/9", role: "instructor", offeringId: 9 };
    document.body.innerHTML = sectionHtml();
});

afterEach(() => {
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
});

describe("turning group work on", () => {

    it("explains group activities while off, and attaches the chosen set when switched on", async () => {
        let attached = false;
        const fetch = server({
            "GET /": () => json(detail({ attached })),
            "POST /attach": () => {
                attached = true;

                return json({ success: true, id: 3 });
            },
        });

        await initializeActivityGroups(root());

        expect(root().querySelector("[data-ga-empty]").hidden).toBe(false);
        expect(root().querySelector("[data-ga-empty]").textContent).toContain("as a group");
        expect(root().querySelector("[data-ga-settings]").hidden).toBe(true);
        expect(root().querySelector("[data-ga-toggle]").checked).toBe(false);

        const toggle = root().querySelector("[data-ga-toggle]");
        toggle.checked = true;
        toggle.dispatchEvent(new Event("change", { bubbles: true }));

        expect(document.querySelector("#ga-attach-dialog").hidden).toBe(false);
        const options = Array.from(document.querySelectorAll("#ga-attach-dialog select option")).map((o) => o.textContent);
        expect(options).toContain("Start from scratch");
        expect(options.some((o) => o.includes("Project teams"))).toBe(true);

        document.querySelector("#ga-attach-dialog select").value = "5";
        document.querySelector("[data-ga-attach-form]").requestSubmit();
        await flush();

        const [call] = sent(fetch, "POST", "/attach");
        expect(JSON.parse(call[1].body)).toEqual({ set_id: 5, settings: {} });
        expect(root().querySelector("[data-ga-settings]").hidden).toBe(false);
        expect(root().querySelector("[data-group-card=\"11\"]").textContent).toContain("Ana Cruz");
        expect(root().querySelector("[data-ga-toggle]").checked).toBe(true);
    });

    it("sends no set when starting from scratch", async () => {
        const fetch = server({
            "GET /": () => json(detail({ attached: false })),
            "POST /attach": () => json({ success: true, id: 3 }),
        });

        await initializeActivityGroups(root());
        const toggle = root().querySelector("[data-ga-toggle]");
        toggle.checked = true;
        toggle.dispatchEvent(new Event("change", { bubbles: true }));
        document.querySelector("#ga-attach-dialog select").value = "";
        document.querySelector("[data-ga-attach-form]").requestSubmit();
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/attach")[0][1].body)).toEqual({ set_id: null, settings: {} });
    });

    it("asks before switching off, then removes the activity's groups", async () => {
        let attached = true;
        const fetch = server({
            "GET /": () => json(detail({ attached })),
            "DELETE /": () => {
                attached = false;

                return json({ success: true });
            },
        });

        await initializeActivityGroups(root());
        const toggle = root().querySelector("[data-ga-toggle]");
        toggle.checked = false;
        toggle.dispatchEvent(new Event("change", { bubbles: true }));

        expect(document.querySelector("#ga-detach-confirm").hidden).toBe(false);
        expect(sent(fetch, "DELETE", "/groups")).toHaveLength(0);
        expect(toggle.checked).toBe(true);

        document.querySelector("#ga-detach-confirm form").requestSubmit();
        await flush();

        expect(sent(fetch, "DELETE", "/groups")).toHaveLength(1);
        expect(root().querySelector("[data-ga-settings]").hidden).toBe(true);
    });

});

describe("changing a setting", () => {

    it("saves a change at once", async () => {
        const fetch = server({
            "GET /": () => json(detail()),
            "PUT /settings": () => json({ success: true }),
        });

        await initializeActivityGroups(root());
        const form = root().querySelector("[data-ga-settings]");
        expect(form.elements.submission_style.value).toBe("shared");
        expect(form.elements.nonleader_can_leave.checked).toBe(true);

        form.elements.grading_mode.value = "individual";
        form.elements.grading_mode.dispatchEvent(new Event("change", { bubbles: true }));
        await flush();

        expect(JSON.parse(sent(fetch, "PUT", "/settings")[0][1].body)).toEqual({ changes: { grading_mode: "individual" }, confirmed: false });
    });

    it("asks to confirm an ungrouped-policy change after a freeze, then sends it confirmed", async () => {
        const bodies = [];
        server({
            "GET /": () => json(detail({ frozen: true })),
            "PUT /settings": (init) => {
                const body = JSON.parse(init.body);
                bodies.push(body);

                return body.confirmed
                    ? json({ success: true })
                    : json({ success: false, message: "Groups have already submitted.", errors: { confirm_required: "Groups have already submitted." } }, 422);
            },
        });

        await initializeActivityGroups(root());
        const form = root().querySelector("[data-ga-settings]");
        form.elements.ungrouped_policy.value = "solo";
        form.elements.ungrouped_policy.dispatchEvent(new Event("change", { bubbles: true }));
        await flush();

        const confirm = document.querySelector("#ga-policy-confirm");
        expect(confirm.hidden).toBe(false);
        expect(confirm.querySelector("[data-dialog-text]").textContent).toContain("work alone");

        confirm.querySelector("form").requestSubmit();
        await flush();

        expect(bodies).toEqual([
            { changes: { ungrouped_policy: "solo" }, confirmed: false },
            { changes: { ungrouped_policy: "solo" }, confirmed: true },
        ]);
        expect(form.elements.ungrouped_policy.value).toBe("solo");
    });

    it("shows the server's reason and puts the setting back when it is refused", async () => {
        server({
            "GET /": () => json(detail({ frozen: true })),
            "PUT /settings": () => json({ success: false, message: "Unlock the group first.", errors: { submission_style: "Unlock the group first." } }, 422),
        });

        await initializeActivityGroups(root());
        const form = root().querySelector("[data-ga-settings]");
        form.elements.submission_style.value = "per_member";
        form.elements.submission_style.dispatchEvent(new Event("change", { bubbles: true }));
        await flush();

        expect(form.elements.submission_style.value).toBe("shared");
        const error = form.querySelector("[data-field=\"submission_style\"] [data-field-error]");
        expect(error.hidden).toBe(false);
        expect(error.textContent).toBe("Unlock the group first.");
    });

});

describe("frozen groups", () => {

    it("marks a frozen group and unlocks it only with a reason", async () => {
        const fetch = server({
            "GET /": () => json(detail({ frozen: true })),
            "POST /groups/11/unlock": () => json({ success: true }),
        });

        await initializeActivityGroups(root());
        const card = root().querySelector("[data-group-card=\"11\"]");
        expect(card.textContent).toContain("Frozen");
        expect(card.querySelector("[data-group-action=\"move\"]")).toBeNull();

        card.querySelector("[data-group-action=\"unlock\"]").click();
        const form = document.querySelector("[data-ga-unlock-form]");
        expect(document.querySelector("#ga-unlock-dialog").hidden).toBe(false);

        form.requestSubmit();
        await flush();

        expect(sent(fetch, "POST", "/unlock")).toHaveLength(0);
        expect(form.querySelector("[data-field=\"reason\"] [data-field-error]").hidden).toBe(false);

        form.elements.reason.value = "Cai transferred";
        form.requestSubmit();
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/unlock")[0][1].body)).toEqual({ reason: "Cai transferred" });
    });

    it("adds a late enrollee from the ungrouped students with a reason", async () => {
        const fetch = server({
            "GET /": () => json(detail({ frozen: true })),
            "POST /groups/11/late-members": () => json({ success: true }),
        });

        await initializeActivityGroups(root());
        root().querySelector("[data-group-card=\"11\"] [data-group-action=\"late-member\"]").click();

        const form = document.querySelector("[data-ga-late-form]");
        expect(Array.from(form.elements.student_user_id.options).map((o) => o.value)).toEqual(["103"]);
        form.elements.reason.value = "Enrolled late";
        form.requestSubmit();
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/late-members")[0][1].body)).toEqual({ student_user_id: 103, reason: "Enrolled late" });
        expect(root().querySelector("[data-group-card=\"11\"]").textContent).toContain("Cai Ortiz");
    });

});

describe("moving students", () => {

    it("places an ungrouped student with the shared Move picker", async () => {
        const fetch = server({
            "GET /": () => json(detail()),
            "POST /groups/12/members": () => json({ success: true }),
        });

        await initializeActivityGroups(root());
        root().querySelector("[data-group-action=\"move\"][data-student=\"103\"]").click();
        document.querySelector("[data-ga-move-form] select").value = "12";
        document.querySelector("[data-ga-move-form]").requestSubmit();
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/groups/12/members")[0][1].body)).toEqual({ student_user_id: 103 });
        expect(root().querySelector("[data-group-card=\"12\"]").textContent).toContain("Cai Ortiz");
        expect(root().querySelector("[data-ungrouped-count]").textContent).toBe("0");
    });

});

describe("the card's states", () => {

    it("shows a loading shape while it loads", async () => {
        server({ "GET /": () => new Promise(() => {}) });

        initializeActivityGroups(root());
        await flush();

        expect(root().querySelector("[data-ga-status] .animate-pulse")).not.toBeNull();
    });

    it("says it could not load, with Try again", async () => {
        let calls = 0;
        server({ "GET /": () => (++calls === 1 ? Promise.reject(new TypeError("offline")) : json(detail())) });

        await initializeActivityGroups(root());

        expect(root().querySelector("[role=alert]").textContent).toContain("couldn't load");
        root().querySelector("[data-retry]").click();
        await flush();

        expect(calls).toBe(2);
        expect(root().querySelector("[data-group-card=\"11\"]")).not.toBeNull();
    });

    it("read only: draws no write control and says why", async () => {
        server({ "GET /": () => json(detail({ frozen: true, canWrite: false })) });

        await initializeActivityGroups(root());

        expect(root().querySelector("[data-group-card=\"11\"]").textContent).toContain("Ana Cruz");
        expect(root().querySelectorAll("[data-group-action]")).toHaveLength(0);
        expect(root().querySelectorAll("[draggable=true]")).toHaveLength(0);
        expect(root().querySelector("[data-ga-toggle]").disabled).toBe(true);
        expect(root().querySelector("[data-ga-fields]").disabled).toBe(true);
        expect(root().querySelector("[data-ga-lock]").hidden).toBe(false);
        expect(root().querySelector("[data-ga-lock] [data-lock] > span").textContent).toBe("View only.");
    });

});
