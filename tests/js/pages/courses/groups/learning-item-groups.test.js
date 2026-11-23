import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { initializeActivityGroups } from "../../../../../resources/js/pages/courses/groups/activity-panel.js";
import { initializeStudentGroupCard } from "../../../../../resources/js/pages/courses/groups/student-card.js";

/*
 * Groups on an ungraded learning item (screens 2A for the item editor, 3D for the student). The same panel and
 * card as an assignment's, in their learning-item flavour: no leaders, grading, extensions or freeze; a
 * completion setting instead. The server's HTML is stood in by small copies of the partials with the same hooks.
 */

const field = (name, control) => `<div data-field="${name}">${control}<p data-field-error hidden></p></div>`;
const radios = (name) => ["any", "each"].map((v) => `<label><input type="radio" name="${name}" value="${v}"> ${v}</label>`).join("");

const dialog = (id, form) => `
    <div id="${id}" data-dialog hidden class="hidden">
        <h2 data-dialog-title></h2><p data-dialog-text></p>
        ${form}
    </div>`;

const editorHtml = () => `
    <section data-group-activity data-learning-item-id="4">
        <input type="checkbox" data-ga-toggle>
        <div data-ga-lock hidden><p data-lock="closed"><svg></svg><span>This is closed.</span></p></div>
        <div data-ga-status></div>
        <div data-ga-empty hidden><span>Students do this on their own</span></div>
        <form data-ga-settings hidden><fieldset data-ga-fields>
            ${field("formation_mode", "<select name=\"formation_mode\"><option value=\"manual\">manual</option><option value=\"auto\">auto</option><option value=\"self_signup\">self_signup</option></select>")}
            <div data-ga-signup-window hidden>
                ${field("signup_opens_at", "<input name=\"signup_opens_at\" type=\"datetime-local\">")}
                ${field("signup_closes_at", "<input name=\"signup_closes_at\" type=\"datetime-local\">")}
            </div>
            ${field("min_size", "<input name=\"min_size\" type=\"number\">")}
            ${field("max_size", "<input name=\"max_size\" type=\"number\">")}
            ${field("completion_mode", radios("completion_mode"))}
            ${field("groups_visible", "<input type=\"checkbox\" name=\"groups_visible\">")}
            <p data-ga-source></p>
            <button type="button" data-ga-action="recopy">Re-copy from set</button>
            <button type="button" data-ga-action="copy-from">Copy from another activity</button>
        </fieldset></form>
        <div data-ga-board></div>
        <p data-groups-live></p>
        ${dialog("ga-attach-dialog", `<form data-ga-attach-form>${field("set_id", "<select name=\"set_id\"></select>")}${field("completion_mode", radios("completion_mode"))}<p data-form-error hidden></p><button type="submit">Use groups</button></form>`)}
        ${dialog("ga-detach-confirm", "<form><p data-form-error hidden></p><button type=\"submit\">Remove groups</button></form>")}
        ${dialog("ga-name-dialog", `<form data-ga-name-form>${field("name", "<input name=\"name\">")}<p data-form-error hidden></p><button type="submit">Save</button></form>`)}
        ${dialog("ga-move-dialog", `<form data-ga-move-form>${field("to_group", "<select name=\"to_group\"></select>")}<p data-form-error hidden></p><button type="submit">Move</button></form>`)}
        ${dialog("ga-delete-confirm", "<form><p data-form-error hidden></p><button type=\"submit\">Delete group</button></form>")}
        ${dialog("ga-recopy-confirm", "<form><p data-form-error hidden></p><button type=\"submit\">Re-copy</button></form>")}
        ${dialog("ga-copy-dialog", `<form data-ga-copy-form>${field("from_learning_item_id", "<select name=\"from_learning_item_id\"></select>")}<p data-form-error hidden></p><button type="submit">Copy groups</button></form>`)}
    </section>`;

const config = (over = {}) => ({
    id: 3, source_set_id: 5, source_set_name: "Pairs", formation_mode: "manual", signup_opens_at: null, signup_closes_at: null,
    min_size: 1, max_size: 3, completion_mode: "any", groups_visible: 0, learning_item_id: 4, assessment_id: null, ...over,
});

const detail = ({ attached = true, canWrite = true, activities = [] } = {}) => ({
    success: true,
    config: attached ? config() : null,
    groups: attached ? [{ id: 11, name: "Pair 7", status: "active", frozen: false, leader: { id: null, final: false }, members: [{ student_user_id: 101, name: "Ana Cruz" }], extension: null }] : [],
    ungrouped: attached ? [{ student_user_id: 103, name: "Cai Ortiz" }] : [],
    sets: [{ id: 5, name: "Pairs", group_count: 1 }],
    activities,
    dueDate: null,
    flags: { frozenGroups: false, canWrite, offeringFinalized: false },
    lockReason: canWrite ? "" : "View only.",
});

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } }));

/** fetch answers by method and the part after the given base; the calls are kept to look at. */
function server(base, routes) {
    const fn = vi.fn((url, init = {}) => {
        const key = `${(init.method ?? "GET").toUpperCase()} ${String(url).replace(base, "") || "/"}`;

        if (!routes[key]) {
            return Promise.reject(new Error(`unexpected ${key}`));
        }

        return routes[key](init);
    });

    vi.stubGlobal("fetch", fn);

    return fn;
}

const flush = async () => {
    for (let i = 0; i < 6; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};

const sent = (fetch, method, tail) => fetch.mock.calls.filter(([url, init = {}]) => (init.method ?? "GET") === method && String(url).endsWith(tail));

afterEach(() => {
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
});

describe("the item editor's Group activity card", () => {

    const EDITOR = "/instructor/api/courses/9/learning-items/4/groups";
    const panel = () => document.querySelector("[data-group-activity]");
    const toggle = () => panel().querySelector("[data-ga-toggle]");

    beforeEach(() => {
        window.coursesConfig = { apiBaseUrl: "/instructor/api/courses/9", role: "instructor", offeringId: 9 };
        document.body.innerHTML = editorHtml();
    });

    it("loads the item's groups and, switched on, attaches the set with the chosen completion setting", async () => {
        let attached = false;
        const fetch = server(EDITOR, {
            "GET /": () => json(detail({ attached })),
            "POST /attach": () => {
                attached = true;

                return json({ success: true, id: 3 });
            },
        });

        await initializeActivityGroups(panel());

        expect(panel().querySelector("[data-ga-empty]").hidden).toBe(false);
        expect(toggle().checked).toBe(false);

        toggle().checked = true;
        toggle().dispatchEvent(new Event("change", { bubbles: true }));

        const form = document.querySelector("[data-ga-attach-form]");
        expect(document.querySelector("#ga-attach-dialog").hidden).toBe(false);
        expect(form.elements.completion_mode.value).toBe("each");

        form.elements.set_id.value = "5";
        form.querySelector("input[value=any]").checked = true;
        form.requestSubmit();
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/learning-items/4/groups/attach")[0][1].body)).toEqual({ set_id: 5, completion_mode: "any", settings: {} });
        expect(panel().querySelector("[data-group-card=\"11\"]").textContent).toContain("Ana Cruz");
        expect(panel().querySelector("[data-ga-settings]").elements.completion_mode.value).toBe("any");
        expect(panel().querySelector("[data-groups-live]").textContent).toContain("uses groups");
    });

    it("saves the completion setting at once and has no leader, deadline or unlock actions", async () => {
        const fetch = server(EDITOR, {
            "GET /": () => json(detail()),
            "PUT /settings": () => json({ success: true }),
        });

        await initializeActivityGroups(panel());

        expect(panel().querySelector("[data-group-action=set-leader]")).toBeNull();
        expect(panel().querySelector("[data-group-action=extend]")).toBeNull();
        expect(panel().querySelector("[data-group-action=unlock]")).toBeNull();
        expect(panel().querySelector("[data-group-action=add-group]")).not.toBeNull();

        const each = panel().querySelector("[data-ga-settings] input[value=each]");
        each.checked = true;
        each.dispatchEvent(new Event("change", { bubbles: true }));
        await flush();

        expect(JSON.parse(sent(fetch, "PUT", "/settings")[0][1].body)).toEqual({ changes: { completion_mode: "each" }, confirmed: false });
    });

    it("asks before switching off, then the item works without groups", async () => {
        let attached = true;
        const fetch = server(EDITOR, {
            "GET /": () => json(detail({ attached })),
            "DELETE /": () => {
                attached = false;

                return json({ success: true });
            },
        });

        await initializeActivityGroups(panel());
        toggle().checked = false;
        toggle().dispatchEvent(new Event("change", { bubbles: true }));

        expect(document.querySelector("#ga-detach-confirm").hidden).toBe(false);
        expect(sent(fetch, "DELETE", "/groups")).toHaveLength(0);

        document.querySelector("#ga-detach-confirm form").requestSubmit();
        await flush();

        expect(sent(fetch, "DELETE", "/learning-items/4/groups")).toHaveLength(1);
        expect(panel().querySelector("[data-groups-live]").textContent).toBe("This item no longer uses groups");
        expect(panel().querySelector("[data-ga-settings]").hidden).toBe(true);
    });

    it("copies groups from another item of the course", async () => {
        const fetch = server(EDITOR, {
            "GET /": () => json(detail({ activities: [{ learning_item_id: 8, title: "Lab 2" }] })),
            "POST /copy-from": () => json({ success: true }),
        });

        await initializeActivityGroups(panel());
        panel().querySelector("[data-ga-action=copy-from]").click();

        const form = document.querySelector("[data-ga-copy-form]");
        expect(form.elements.from_learning_item_id.options[0].textContent).toBe("Lab 2");
        form.requestSubmit();
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/copy-from")[0][1].body)).toEqual({ from_learning_item_id: 8 });
    });

    it("is read-only for the dean: nothing can be switched, edited or moved", async () => {
        window.coursesConfig = { apiBaseUrl: "/dean/api/courses/9", role: "dean", offeringId: 9 };
        const fetch = server("/dean/api/courses/9/learning-items/4/groups", { "GET /": () => json(detail({ canWrite: false })) });

        await initializeActivityGroups(panel());

        expect(toggle().disabled).toBe(true);
        expect(panel().querySelector("[data-ga-fields]").disabled).toBe(true);
        expect(panel().querySelector("[data-ga-lock]").hidden).toBe(false);
        expect(panel().querySelector("[data-group-action=add-group]")).toBeNull();
        expect(panel().querySelector("[data-group-action=move]")).toBeNull();

        toggle().checked = false;
        toggle().dispatchEvent(new Event("change", { bubbles: true }));

        expect(document.querySelector("#ga-detach-confirm").hidden).toBe(true);
        expect(fetch.mock.calls.every(([, init = {}]) => (init.method ?? "GET") === "GET")).toBe(true);
    });

});

describe("the student's group card on a learning item", () => {

    const CARD = "/student/api/courses/9/learning-items/4/my-group";
    const card = () => document.querySelector("[data-student-group]");
    const label = () => document.querySelector("[data-mark-complete-label]").textContent.trim();

    const alertTpl = (tone) => `<template data-sg-alert="${tone}"><div data-alert="${tone}"><p></p></div></template>`;

    const pageHtml = (completed = false) => `
        <div data-student-group data-learning-item-id="4" hidden>
            <div data-sg-body></div>
            <p aria-live="polite" data-sg-live></p>
            ${alertTpl("warning")}${alertTpl("info")}
            <div id="sg-leave-confirm" data-dialog hidden class="hidden">
                <h2 data-dialog-title></h2>
                <form method="post" action="#"><p data-dialog-text></p><p data-form-error hidden></p><button type="submit">Leave group</button></form>
            </div>
        </div>
        <button type="button" data-mark-complete data-learning-item-id="4" data-completed="${completed ? "1" : "0"}"><span data-mark-complete-label>${completed ? "Completed" : "Mark as Completed"}</span></button>`;

    const signup = { mode: "manual", opensAt: null, closesAt: null, isOpen: false, minSize: 1, maxSize: 3 };
    const base = { success: true, isGroupActivity: true, inPool: true, blocked: false, offeringFinalized: false, canPropose: false, invitable: [], pendingInvitations: [], candidates: [], signup };
    const group = (over = {}) => ({
        id: 11, name: "Pair 7", status: "active", frozen: false, canLeave: true, canLeaveReason: null, canRename: false, canVote: false, leader: null, extension: null,
        members: [{ id: 5, name: "Dee Park", isMe: true }, { id: 6, name: "Eli Tan", isMe: false }], completed: false, completedBy: null, ...over,
    });

    beforeEach(() => {
        window.coursesConfig = { apiBaseUrl: "/student/api/courses/9", role: "student", offeringId: 9 };
        document.body.innerHTML = pageHtml();
    });

    it("'any': the group shares one status and the button marks it for the group", async () => {
        const fetch = server(CARD, { "GET /": () => json({ ...base, completionMode: "any", myGroup: group() }) });

        await initializeStudentGroupCard(card());
        await flush();

        expect(sent(fetch, "GET", "/learning-items/4/my-group")).toHaveLength(1);
        expect(card().textContent).toContain("Your group: Pair 7");
        expect(card().textContent).toContain("You (Dee Park)");
        expect(card().textContent).toContain("Any member can mark this done for the group.");
        expect(label()).toBe("Mark complete for the group");
    });

    it("'any', done: says who marked it for the group and leaves the Completed button alone", async () => {
        document.body.innerHTML = pageHtml(true);
        server(CARD, { "GET /": () => json({ ...base, completionMode: "any", myGroup: group({ completed: true, completedBy: "Eli Tan" }) }) });

        await initializeStudentGroupCard(card());
        await flush();

        expect(card().querySelector("[data-alert=info]").textContent).toContain("Marked complete by Eli Tan for the group.");
        expect(label()).toBe("Completed");
    });

    it("'each': every partner's own status, and the usual button", async () => {
        server(CARD, { "GET /": () => json({ ...base, completionMode: "each", myGroup: group({ members: [{ id: 5, name: "Dee Park", isMe: true, completed: false }, { id: 6, name: "Eli Tan", isMe: false, completed: true }] }) }) });

        await initializeStudentGroupCard(card());
        await flush();

        const chips = Array.from(card().querySelectorAll("[data-sg-member]")).map((c) => c.textContent.replace(/\s+/g, " ").trim());
        expect(chips).toEqual(["You (Dee Park) · Not done yet", "Eli Tan · Done"]);
        expect(card().textContent).toContain("Each member marks this done on their own.");
        expect(label()).toBe("Mark as Completed");
    });

    it("not in a group: says so without blocking, and Join refreshes the card", async () => {
        let joined = false;
        const open = { ...signup, mode: "self_signup", isOpen: true, closesAt: "2026-12-12 17:00:00" };
        const fetch = server(CARD, {
            "GET /": () => json(joined
                ? { ...base, signup: open, completionMode: "each", myGroup: group() }
                : { ...base, signup: open, completionMode: "each", myGroup: null, candidates: [{ id: 11, name: "Pair 7", members_count: 1, max: 3, member_names: ["Eli Tan"], full: false }] }),
            "POST /join": () => {
                joined = true;

                return json({ success: true });
            },
        });

        await initializeStudentGroupCard(card());
        await flush();

        expect(card().querySelector("[data-alert=warning]")).toBeNull();
        expect(card().querySelector("[data-alert=info]").textContent).toContain("You're not in a group yet. You can still complete this item on your own.");
        expect(label()).toBe("Mark as Completed");

        card().querySelector("[data-sg-action=join]").click();
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/join")[0][1].body)).toEqual({ group_id: 11 });
        expect(card().textContent).toContain("Your group: Pair 7");
        expect(card().querySelector("[data-sg-live]").textContent).toBe("You joined Pair 7.");
    });

    it("refreshes after the student marks the item complete", async () => {
        let done = false;
        const fetch = server(CARD, { "GET /": () => json({ ...base, completionMode: "any", myGroup: group({ completed: done, completedBy: done ? "Dee Park" : null }) }) });

        await initializeStudentGroupCard(card());
        await flush();

        done = true;
        document.dispatchEvent(new CustomEvent("learning-item:completed", { detail: { itemId: 4 } }));
        await flush();

        expect(sent(fetch, "GET", "/my-group")).toHaveLength(2);
        expect(card().textContent).toContain("Marked complete by Dee Park for the group.");
    });

});
