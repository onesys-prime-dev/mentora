import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { initializeStudentGroupCard } from "../../../../../resources/js/pages/courses/groups/student-card.js";

/*
 * The student's "Group activity" card (screens 3A, 3C, 3E). The server's HTML (container, alert templates,
 * leave confirmation) is stood in by a small copy of assessments/partials/student-group-card.php.
 */

const alertTpl = (tone) => `<template data-sg-alert="${tone}"><div data-alert="${tone}"><span class="sr-only">${tone}: </span><p></p></div></template>`;

const pageHtml = () => `
    <div data-student-group data-assessment-id="7" hidden>
        <div data-sg-body></div>
        <p aria-live="polite" data-sg-live></p>
        ${alertTpl("warning")}${alertTpl("info")}
        <div id="sg-leave-confirm" data-dialog hidden class="hidden">
            <h2 data-dialog-title></h2>
            <form method="post" action="#">
                <p data-dialog-text></p><p data-form-error hidden></p>
                <button type="button" data-dialog-close>Cancel</button>
                <button type="submit">Leave group</button>
            </form>
        </div>
    </div>`;

const base = { success: true, isGroupActivity: true, inPool: true, ungroupedPolicy: "block", submissionStyle: "shared", offeringFinalized: false };
const open = { mode: "self_signup", opensAt: null, closesAt: "2026-12-12 17:00:00", isOpen: true };
const cand = (id, name, names, full = false) => ({ id, name, members_count: names.length, max: 5, member_names: names, full });

const notIn = (over = {}) => ({ ...base, signup: open, myGroup: null, blocked: true, candidates: [cand(1, "Team 1", ["Ana", "Ben"]), cand(3, "Team 3", ["A", "B", "C", "D", "E"], true)], ...over });
const inGroup = (over = {}, group = {}) => ({
    ...base, signup: open, blocked: false, candidates: [cand(2, "Team 2", ["Eli"])],
    myGroup: { id: 1, name: "Team 1", members: [{ id: 5, name: "Ana Cruz" }, { id: 6, name: "Dee Park" }], frozen: false, canLeave: true, canLeaveReason: null, ...group }, ...over,
});

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } }));

/** fetch answers by method and the part after /my-group; the state may change between reads. */
function server(routes) {
    const fn = vi.fn((url, init = {}) => {
        const key = `${(init.method ?? "GET").toUpperCase()} ${String(url).replace("/student/api/courses/9/assessments/7/my-group", "") || "/"}`;

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

const card = () => document.querySelector("[data-student-group]");
const sent = (fetch, method, tail) => fetch.mock.calls.filter(([url, init = {}]) => (init.method ?? "GET") === method && String(url).endsWith(tail));
const click = (selector) => card().querySelector(selector).click();

beforeEach(() => {
    window.coursesConfig = { apiBaseUrl: "/student/api/courses/9", role: "student", offeringId: 9 };
    document.body.innerHTML = pageHtml();
});

afterEach(() => {
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
});

describe("the card's states", () => {

    it("shows a skeleton while loading and an error with Try again when it fails", async () => {
        let fail = true;
        server({ "GET /": () => (fail ? Promise.reject(new Error("down")) : json(notIn())) });
        vi.spyOn(console, "error").mockImplementation(() => {});

        const done = initializeStudentGroupCard(card());

        expect(card().hidden).toBe(false);
        expect(card().querySelector("[data-sg-skeleton]")).not.toBeNull();

        await done;
        await flush();

        expect(card().textContent).toContain("We couldn't load your group");

        fail = false;
        click("[data-retry]");
        await flush();

        expect(card().querySelectorAll("[data-sg-candidate]").length).toBe(2);
    });

    it("leaves no card for an assignment that is not a group activity", async () => {
        server({ "GET /": () => json({ success: true, isGroupActivity: false }) });

        await initializeStudentGroupCard(card());
        await flush();

        expect(card()).toBeNull();
    });

    it("not in a group, policy block: warning, the signup window, Join and Full", async () => {
        server({ "GET /": () => json(notIn()) });

        await initializeStudentGroupCard(card());
        await flush();

        const warning = card().querySelector("[data-alert=warning]");

        expect(warning.textContent).toContain("You're not in a group yet. You can't submit until you join one.");
        expect(warning.textContent).toContain("Signup closes 12 Dec 2026, 5:00 PM.");

        const rows = card().querySelectorAll("[data-sg-candidate]");

        expect(rows[0].textContent).toContain("Team 1 · 2 / 5 · Ana, Ben");
        expect(rows[0].querySelector("button").textContent).toBe("Join");
        expect(rows[1].querySelector("button").textContent).toBe("Full");
        expect(rows[1].querySelector("button").disabled).toBe(true);
    });

    it("policy solo: an info note, and a closed or not yet open window says so", async () => {
        server({ "GET /": () => json(notIn({ blocked: false, ungroupedPolicy: "solo", candidates: [], signup: { ...open, isOpen: false, closesAt: "2026-01-01 10:00:00" } })) });

        await initializeStudentGroupCard(card());
        await flush();

        expect(card().querySelector("[data-alert=info]").textContent).toContain("You may work alone or join a group.");
        expect(card().textContent).toContain("Signup is closed.");
        expect(card().querySelector("[data-alert=warning]")).toBeNull();
    });

    it("shows your group with member chips, Switch and Leave", async () => {
        server({ "GET /": () => json(inGroup()) });

        await initializeStudentGroupCard(card());
        await flush();

        expect(card().textContent).toContain("Your group: Team 1");
        expect([...card().querySelectorAll("[data-sg-member]")].map((c) => c.textContent)).toEqual(["Ana Cruz", "Dee Park"]);
        expect(card().querySelector("[data-sg-action=leave]")).not.toBeNull();
        expect(card().querySelector("[data-sg-action=toggle-switch]")).not.toBeNull();
    });

    it("hides Leave when the API says canLeave is false, and gives the reason", async () => {
        server({ "GET /": () => json(inGroup({ candidates: [] }, { canLeave: false, canLeaveReason: "Your group has chosen its leader, so members cannot leave. Ask your instructor." })) });

        await initializeStudentGroupCard(card());
        await flush();

        expect(card().querySelector("[data-sg-action=leave]")).toBeNull();
        expect(card().querySelector("[data-sg-reason]").textContent).toContain("chosen its leader");
    });

    it("frozen: the membership-frozen notice and no Leave or Switch", async () => {
        server({ "GET /": () => json(inGroup({ candidates: [] }, { frozen: true, canLeave: false })) });

        await initializeStudentGroupCard(card());
        await flush();

        expect(card().querySelector("[data-alert=warning]").textContent).toContain("Your group has submitted. Membership is frozen. Ask your instructor if someone must change.");
        expect(card().querySelector("[data-sg-action]")).toBeNull();
    });

});

describe("changing groups", () => {

    it("Join sends the group, toasts, announces and refreshes the card", async () => {
        let joined = false;
        const fetch = server({
            "GET /": () => json(joined ? inGroup() : notIn()),
            "POST /join": () => {
                joined = true;

                return json({ success: true });
            },
        });

        await initializeStudentGroupCard(card());
        await flush();
        click("[data-sg-action=join]");
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/join")[0][1].body)).toEqual({ group_id: 1 });
        expect(card().textContent).toContain("Your group: Team 1");
        expect(card().querySelector("[data-sg-live]").textContent).toBe("You joined Team 1.");
        expect(document.querySelector("[data-toast=success]").textContent).toContain("You joined Team 1.");
    });

    it("a 422 shows the message inline and in a toast, and the card stays as it was", async () => {
        const fetch = server({
            "GET /": () => json(notIn()),
            "POST /join": () => json({ success: false, message: "Group is full", errors: [] }, 422),
        });

        await initializeStudentGroupCard(card());
        await flush();
        click("[data-sg-action=join]");
        await flush();

        expect(sent(fetch, "POST", "/join").length).toBe(1);
        expect(card().querySelector("[data-sg-error]").textContent).toBe("Group is full");
        expect(card().querySelector("[data-sg-error]").hidden).toBe(false);
        expect(document.querySelector("[data-toast=danger]").textContent).toContain("Group is full");
        expect(card().querySelectorAll("[data-sg-candidate]").length).toBe(2);
    });

    it("Switch lists the other open groups and sends the chosen one", async () => {
        const fetch = server({
            "GET /": () => json(inGroup()),
            "POST /switch": () => json({ success: true }),
        });

        await initializeStudentGroupCard(card());
        await flush();
        click("[data-sg-action=toggle-switch]");
        click("[data-sg-action=switch]");
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/switch")[0][1].body)).toEqual({ group_id: 2 });
        expect(card().querySelector("[data-sg-live]").textContent).toBe("You switched to Team 2.");
    });

    it("Leave asks first, stating the consequence, and only then sends", async () => {
        let left = false;
        const fetch = server({
            "GET /": () => json(left ? notIn() : inGroup()),
            "POST /leave": () => {
                left = true;

                return json({ success: true });
            },
        });

        await initializeStudentGroupCard(card());
        await flush();
        click("[data-sg-action=leave]");

        expect(sent(fetch, "POST", "/leave").length).toBe(0);

        const dialog = card().querySelector("#sg-leave-confirm");

        expect(dialog.hidden).toBe(false);
        expect(dialog.querySelector("[data-dialog-text]").textContent).toBe("You will leave Team 1. You can join another group while signup is open.");

        dialog.querySelector("form").requestSubmit();
        await flush();

        expect(sent(fetch, "POST", "/leave").length).toBe(1);
        expect(card().textContent).toContain("Open groups");
        expect(card().querySelector("[data-sg-live]").textContent).toBe("You left the group.");
    });

    it("a refused Leave keeps the confirmation open with the server's message", async () => {
        server({
            "GET /": () => json(inGroup()),
            "POST /leave": () => json({ success: false, message: "Your group has submitted; ask your instructor", errors: [] }, 422),
        });

        await initializeStudentGroupCard(card());
        await flush();
        click("[data-sg-action=leave]");
        card().querySelector("#sg-leave-confirm form").requestSubmit();
        await flush();

        expect(card().querySelector("#sg-leave-confirm [data-form-error]").textContent).toBe("Your group has submitted; ask your instructor");
        expect(card().querySelector("#sg-leave-confirm").hidden).toBe(false);
    });

});
