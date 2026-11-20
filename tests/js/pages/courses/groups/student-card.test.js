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
        <div id="sg-rename-dialog" data-dialog hidden class="hidden">
            <h2 data-dialog-title></h2>
            <form method="post" action="#">
                <div data-field="name"><input name="name"><p data-field-error hidden></p></div><p data-form-error hidden></p>
                <button type="button" data-dialog-close>Cancel</button><button type="submit">Save</button>
            </form>
        </div>
        <div id="sg-propose-dialog" data-dialog hidden class="hidden">
            <h2 data-dialog-title></h2>
            <form method="post" action="#">
                <div data-field="name"><input name="name"><p data-field-error hidden></p></div>
                <p data-sg-size-hint></p>
                <fieldset data-field="invitee_ids"><div data-sg-invitees></div><p data-field-error hidden></p></fieldset>
                <p data-form-error hidden></p>
                <button type="button" data-dialog-close>Cancel</button><button type="submit">Send invitations</button>
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

const noLeader = { id: null, final: false, votes: [], needed: 2, myVote: null };
const leaderGroup = (leader = {}, group = {}) => inGroup({ candidates: [] }, { status: "active", canRename: true, canVote: true, leader: { ...noLeader, ...leader }, ...group });

describe("leader and name", () => {

    it("shows the leading nominee with the tally, and Agree sends that candidate", async () => {
        const fetch = server({
            "GET /": () => json(leaderGroup({ votes: [{ candidateId: 5, count: 1 }] })),
            "POST /vote": () => json({ success: true }),
        });

        await initializeStudentGroupCard(card());
        await flush();

        expect(card().querySelector("[data-sg-leader]").textContent).toContain("Nominated: Ana Cruz · 1 of 2 agreed");
        click("[data-sg-action=agree]");
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/vote")[0][1].body)).toEqual({ candidate_id: 5 });
        expect(card().querySelector("[data-sg-live]").textContent).toBe("You agreed to Ana Cruz as leader.");
    });

    it("Nominate sends the member chosen in the select", async () => {
        const fetch = server({ "GET /": () => json(leaderGroup()), "POST /vote": () => json({ success: true }) });

        await initializeStudentGroupCard(card());
        await flush();

        card().querySelector("[data-sg-nominee]").value = "6";
        click("[data-sg-action=nominate]");
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/vote")[0][1].body)).toEqual({ candidate_id: 6 });
    });

    it("a final leader shows as 'Leader: name' and, without canVote, no vote controls", async () => {
        server({ "GET /": () => json(leaderGroup({ id: 5, final: true, votes: [{ candidateId: 5, count: 2 }] }, { canVote: false, canRename: false })) });

        await initializeStudentGroupCard(card());
        await flush();

        expect(card().querySelector("[data-sg-leader]").textContent).toContain("Leader: Ana Cruz");
        expect(card().querySelector("[data-sg-action=agree]")).toBeNull();
        expect(card().querySelector("[data-sg-action=nominate]")).toBeNull();
    });

    it("Rename is hidden when canRename is false", async () => {
        server({ "GET /": () => json(leaderGroup({}, { canRename: false })) });

        await initializeStudentGroupCard(card());
        await flush();

        expect(card().querySelector("[data-sg-action=rename]")).toBeNull();
    });

    it("Rename opens a dialog with the name and posts the new one", async () => {
        const fetch = server({ "GET /": () => json(leaderGroup()), "POST /rename": () => json({ success: true }) });

        await initializeStudentGroupCard(card());
        await flush();
        click("[data-sg-action=rename]");

        const form = card().querySelector("#sg-rename-dialog form");

        expect(card().querySelector("#sg-rename-dialog").hidden).toBe(false);
        expect(form.elements.name.value).toBe("Team 1");

        form.elements.name.value = "Night Owls";
        form.requestSubmit();
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/rename")[0][1].body)).toEqual({ name: "Night Owls" });
        expect(card().querySelector("[data-sg-live]").textContent).toBe("Your group is now called Night Owls.");
    });

});

describe("proposals and invitations", () => {

    const proposedSignup = { ...open, mode: "proposed", isOpen: false, minSize: 3, maxSize: 4 };
    const canPropose = (over = {}) => notIn({ signup: proposedSignup, candidates: [], canPropose: true, invitable: [{ id: 11, name: "Ben Ong" }, { id: 12, name: "Cai Lim" }], pendingInvitations: [], ...over });

    it("Propose a new group posts the name and the chosen invitees, with the size hint", async () => {
        let proposed = false;
        const fetch = server({
            "GET /": () => json(proposed ? inGroup({ candidates: [] }, { status: "proposed", canRename: false, canVote: false, leader: noLeader, proposal: { pending: ["Ben Ong"] } }) : canPropose()),
            "POST /propose": () => {
                proposed = true;

                return json({ success: true, id: 40 });
            },
        });

        await initializeStudentGroupCard(card());
        await flush();
        click("[data-sg-action=propose]");

        const form = card().querySelector("#sg-propose-dialog form");

        expect(form.querySelector("[data-sg-size-hint]").textContent).toBe("Groups have 3 to 4 members, you included.");
        expect(form.querySelectorAll("input[name=invitee_ids]").length).toBe(2);

        form.elements.name.value = "Night Owls";
        form.querySelector("input[name=invitee_ids][value=\"11\"]").checked = true;
        form.requestSubmit();
        await flush();

        expect(JSON.parse(sent(fetch, "POST", "/propose")[0][1].body)).toEqual({ name: "Night Owls", invitee_ids: [11] });
        expect(card().querySelector("[data-sg-waiting]").textContent).toContain("Waiting for your instructor to approve");
        expect(card().textContent).toContain("Invited: Ben Ong");
    });

    it("lists invitations; Accept and Decline send to the group's address and refresh", async () => {
        let answered = null;
        const invitation = { groupId: 40, name: "Night Owls", proposer: "Ana Cruz", members: ["Ana Cruz"] };
        const fetch = server({
            "GET /": () => json(canPropose({ pendingInvitations: answered ? [] : [invitation, { ...invitation, groupId: 41, name: "Bug Hunters" }] })),
            "POST /invitations/40/accept": () => {
                answered = "accept";

                return json({ success: true });
            },
            "POST /invitations/41/decline": () => json({ success: true }),
        });

        await initializeStudentGroupCard(card());
        await flush();

        const rows = card().querySelectorAll("[data-sg-invitation]");

        expect(rows.length).toBe(2);
        expect(rows[0].textContent).toContain("Night Owls");
        expect(rows[0].textContent).toContain("Ana Cruz invited you");

        card().querySelector("[data-sg-action=decline-invite][data-group-id=\"41\"]").click();
        await flush();
        expect(sent(fetch, "POST", "/invitations/41/decline").length).toBe(1);

        card().querySelector("[data-sg-action=accept-invite][data-group-id=\"40\"]").click();
        await flush();
        expect(sent(fetch, "POST", "/invitations/40/accept").length).toBe(1);
        expect(card().querySelector("[data-sg-live]").textContent).toBe("You joined Night Owls.");
        expect(card().querySelectorAll("[data-sg-invitation]").length).toBe(0);
    });

    it("a refused proposal keeps the dialog open with the server's message", async () => {
        server({
            "GET /": () => json(canPropose()),
            "POST /propose": () => json({ success: false, message: "Someone you invited is already in a group.", errors: [] }, 422),
        });

        await initializeStudentGroupCard(card());
        await flush();
        click("[data-sg-action=propose]");
        card().querySelector("#sg-propose-dialog form").requestSubmit();
        await flush();

        expect(card().querySelector("#sg-propose-dialog").hidden).toBe(false);
        expect(card().querySelector("#sg-propose-dialog [data-form-error]").textContent).toBe("Someone you invited is already in a group.");
    });

});
