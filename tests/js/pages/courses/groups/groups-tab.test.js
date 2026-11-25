import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { initialize } from "../../../../../resources/js/pages/courses/groups/index.js";
import { renderSetDetail } from "../../../../../resources/js/pages/courses/groups/detail.js";

/*
 * The Groups tab: the server's HTML (set list, dialogs) is stood in by a small copy of
 * features/groups/index.php with the same hooks; the set detail comes from the group-sets JSON.
 */

const field = (name, control) => `<div data-field="${name}">${control}<p data-field-error hidden></p></div>`;

const dialog = (id, form) => `
    <div id="${id}" data-dialog hidden class="hidden">
        <h2 data-dialog-title></h2><p data-dialog-text></p>
        ${form}
    </div>`;

function tabHtml({ canEdit = true, sets = true } = {}) {
    const list = sets ? `
        <table><tbody><tr>
            <td><button type="button" data-group-set-open="5">Project teams</button></td>
            <td><span data-set-groups="5">2 groups</span></td>
            <td><span data-set-placed="5">2 of 3</span></td>
        </tr></tbody></table>
        <section data-group-set-detail></section>` : "<div>No group sets yet</div>";

    const dialogs = canEdit ? [
        dialog("group-set-dialog", `<form data-group-set-form>${field("name", "<input name=\"name\">")}${field("formation_mode", "<select name=\"formation_mode\"><option value=\"manual\">m</option><option value=\"self_signup\">s</option></select>")}${field("min_size", "<input name=\"min_size\">")}${field("max_size", "<input name=\"max_size\">")}<div data-signup-window hidden>${field("signup_opens_at", "<input name=\"signup_opens_at\">")}${field("signup_closes_at", "<input name=\"signup_closes_at\">")}</div><p data-form-error hidden></p><button type="submit" data-dialog-label>Save</button></form>`),
        dialog("group-name-dialog", `<form data-group-name-form>${field("name", "<input name=\"name\">")}<p data-form-error hidden></p><button type="submit" data-dialog-label>Save</button></form>`),
        dialog("group-move-dialog", `<form data-group-move-form>${field("to_group", "<select name=\"to_group\"></select>")}<p data-form-error hidden></p><button type="submit">Move</button></form>`),
        dialog("group-set-archive-confirm", "<form><p data-form-error hidden></p><button type=\"submit\" data-dialog-label>Archive set</button></form>"),
        dialog("group-delete-confirm", "<form><p data-form-error hidden></p><button type=\"submit\" data-dialog-label>Delete group</button></form>"),
    ].join("") : "";

    return `<div data-groups-page data-can-edit="${canEdit ? "1" : "0"}" data-pool-size="3">${list}<p data-groups-live></p>${dialogs}</div>`;
}

const detail = () => ({
    success: true,
    set: { id: 5, name: "Project teams", formation_mode: "manual", min_size: 2, max_size: 3, signup_opens_at: null, signup_closes_at: null },
    groups: [
        { id: 11, name: "Team 1", members: [{ student_user_id: 101, name: "Ana Cruz" }, { student_user_id: 102, name: "Ben Lim" }] },
        { id: 12, name: "Team 2", members: [] },
    ],
    ungrouped: [{ student_user_id: 103, name: "Cai Ortiz" }],
    limits: { max_members: 30 },
});

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } }));

/** fetch answers by address and method; the calls are kept to look at. */
function server(routes) {
    const fn = vi.fn((url, init = {}) => {
        const method = (init.method ?? "GET").toUpperCase();
        const key = `${method} ${String(url).replace("/instructor/api/courses/9", "")}`;
        const answer = routes[key];

        if (!answer) {
            return Promise.reject(new Error(`unexpected ${key}`));
        }

        return answer(init);
    });

    vi.stubGlobal("fetch", fn);

    return fn;
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const card = (id) => document.querySelector(`[data-group-card="${id}"]`);
const countOf = (id) => document.querySelector(`[data-group-count="${id}"] [data-status-badge]`).textContent.trim();

beforeEach(() => {
    window.coursesConfig = { apiBaseUrl: "/instructor/api/courses/9", role: "instructor", offeringId: 9 };
    document.body.innerHTML = "<div id=\"course-content\"></div>";
});

afterEach(() => {
    document.body.innerHTML = "";
});

describe("moving a student with the picker", () => {

    it("sends the move and updates both groups' counts", async () => {
        const fetch = server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => json(detail()),
            "POST /group-sets/5/members/move": () => json({ success: true }),
        });

        await initialize();

        expect(countOf(11)).toBe("2 / 3");
        expect(countOf(12)).toBe("0 / 3 · below minimum");

        document.querySelector("[data-group-action=\"move\"][data-student=\"101\"]").click();

        const select = document.querySelector("[data-group-move-form] select");
        expect(document.querySelector("#group-move-dialog").hidden).toBe(false);
        expect(document.querySelector("#group-move-dialog [data-dialog-title]").textContent).toBe("Move Ana Cruz");

        select.value = "12";
        document.querySelector("[data-group-move-form]").requestSubmit();
        await flush();

        const call = fetch.mock.calls.find(([url, init]) => String(url).endsWith("/members/move") && init.method === "POST");
        expect(JSON.parse(call[1].body)).toEqual({ student_user_id: 101, to_group_id: 12 });

        expect(countOf(11)).toBe("1 / 3 · below minimum");
        expect(countOf(12)).toBe("1 / 3 · below minimum");
        expect(card(12).textContent).toContain("Ana Cruz");
        expect(card(11).textContent).not.toContain("Ana Cruz");
        expect(document.querySelector("#group-move-dialog").hidden).toBe(true);
        expect(document.querySelector("[data-groups-live]").textContent).toBe("Ana Cruz moved to Team 2");
        expect(document.querySelector("[data-set-groups=\"5\"]").textContent).toBe("2 groups · 2 below minimum");
    });

    it("places an ungrouped student through the add-member endpoint", async () => {
        const fetch = server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => json(detail()),
            "POST /group-sets/5/groups/12/members": () => json({ success: true }),
        });

        await initialize();

        document.querySelector("[data-group-action=\"move\"][data-student=\"103\"]").click();
        document.querySelector("[data-group-move-form] select").value = "12";
        document.querySelector("[data-group-move-form]").requestSubmit();
        await flush();

        expect(fetch.mock.calls.some(([url]) => String(url).endsWith("/groups/12/members"))).toBe(true);
        expect(document.querySelector("[data-ungrouped-count]").textContent).toBe("0");
        expect(document.querySelector("[data-set-placed=\"5\"]").textContent).toBe("3 of 3");
    });

    it("keeps the group as it was and shows the reason on a 422", async () => {
        server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => json(detail()),
            "POST /group-sets/5/members/move": () => json({ success: false, message: "Group is full.", errors: {} }, 422),
        });

        await initialize();

        document.querySelector("[data-group-action=\"move\"][data-student=\"101\"]").click();
        document.querySelector("[data-group-move-form] select").value = "12";
        document.querySelector("[data-group-move-form]").requestSubmit();
        await flush();

        const error = document.querySelector("[data-group-move-form] [data-form-error]");
        expect(error.hidden).toBe(false);
        expect(error.textContent).toBe("Group is full.");
        expect(document.querySelector("#group-move-dialog").hidden).toBe(false);
        expect(countOf(11)).toBe("2 / 3");
        expect(card(11).textContent).toContain("Ana Cruz");
        expect(card(12).textContent).not.toContain("Ana Cruz");
    });

    it("does not offer a full group", async () => {
        const full = detail();
        full.groups[1].members = [{ student_user_id: 201, name: "X" }, { student_user_id: 202, name: "Y" }, { student_user_id: 203, name: "Z" }];

        server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => json(full),
        });

        await initialize();
        document.querySelector("[data-group-action=\"move\"][data-student=\"101\"]").click();

        expect(document.querySelector("[data-group-move-form] option[value=\"12\"]").disabled).toBe(true);
    });

});

describe("the tab's states", () => {

    it("shows the loading shape while the tab loads", async () => {
        vi.useFakeTimers();
        server({ "GET /groups": () => new Promise(() => {}) });

        initialize();
        await vi.advanceTimersByTimeAsync(200);

        expect(document.querySelector("#course-content .animate-pulse")).not.toBeNull();
        vi.useRealTimers();
    });

    it("says it could not load, with Try again that loads again", async () => {
        let calls = 0;
        server({ "GET /groups": () => (++calls === 1 ? Promise.reject(new TypeError("offline")) : json({ html: tabHtml({ sets: false }) })) });

        await initialize();

        expect(document.querySelector("[role=alert]").textContent).toContain("We couldn't load Groups");
        document.querySelector("[data-retry]").click();
        await flush();
        await flush();

        expect(calls).toBe(2);
        expect(document.querySelector("#course-content").textContent).toContain("No group sets yet");
    });

    it("shows the set's own error with Try again when one set fails", async () => {
        let calls = 0;
        server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => (++calls === 1 ? json({}, 500) : json(detail())),
        });

        await initialize();

        expect(document.querySelector("[data-detail-error]")).not.toBeNull();
        document.querySelector("[data-detail-retry]").click();
        await flush();
        await flush();

        expect(card(11)).not.toBeNull();
    });

    it("shows the set's loading shape while it loads", async () => {
        server({
            "GET /groups": () => json({ html: tabHtml() }),
            "GET /group-sets/5": () => new Promise(() => {}),
        });

        initialize();
        await flush();
        await flush();

        expect(document.querySelector("[data-detail-skeleton]")).not.toBeNull();
    });

    it("puts each member's avatar from the JSON on their chip: a button when interactive, decoration when not", () => {
        const avatar = (id, name, interactive) => ({ id, name, initials: name.slice(0, 2).toUpperCase(), colour: "#1D4ED8", photoUrl: null, photoUrlLg: null, interactive });
        const withAvatars = {
            ...detail(),
            groups: [{ id: 1, name: "Team 1", members: [{ student_user_id: 41, name: "Ana Cruz", avatar: avatar(41, "Ana Cruz", true) }] }],
            ungrouped: [{ student_user_id: 42, name: "Ben Dela", avatar: avatar(42, "Ben Dela", false) }],
        };

        document.body.innerHTML = renderSetDetail(withAvatars, true);

        const ana = document.querySelector("[data-member=\"41\"]");
        const ben = document.querySelector("[data-member=\"42\"]");

        expect(ana.querySelector("button[data-person-avatar=\"41\"]").getAttribute("aria-label")).toBe("Open Ana Cruz's profile");
        expect(ana.querySelector("[data-member-name]").textContent).toBe("Ana Cruz");
        expect(ben.querySelector("[data-person-avatar]")).toBeNull();
        expect(ben.querySelector("[data-avatar]").getAttribute("aria-hidden")).toBe("true");
    });

    it("says a set without groups has none yet, with Add group", () => {
        const empty = { ...detail(), groups: [] };
        document.body.innerHTML = renderSetDetail(empty, true);

        expect(document.querySelector("[data-groups-empty]").textContent).toContain("No groups yet");
        expect(document.querySelector("[data-group-action=\"add-group\"]")).not.toBeNull();
    });

});

describe("read only (the dean, finalized grades)", () => {

    it("draws no write control", async () => {
        server({
            "GET /groups": () => json({ html: tabHtml({ canEdit: false }) }),
            "GET /group-sets/5": () => json(detail()),
        });

        await initialize();

        expect(card(11).textContent).toContain("Ana Cruz");
        expect(document.querySelectorAll("[data-group-action]")).toHaveLength(0);
        expect(document.querySelectorAll("[draggable=true]")).toHaveLength(0);
    });

});
