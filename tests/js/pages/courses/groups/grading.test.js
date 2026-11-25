import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { initializeGroupGrading } from "../../../../../resources/js/pages/courses/groups/grading.js";

/*
 * Grade Work's group view (screen 2B) and its decisions (4D). The server's frame and dialogs are stood in by a
 * small copy of assessments/group-grade-work.php with the same hooks; the groups come from the group-grading JSON.
 */

const field = (name, control) => `<div data-field="${name}">${control}<p data-field-error hidden></p></div>`;

const dialog = (id, form) => `<div id="${id}" data-dialog hidden class="hidden"><h2 data-dialog-title></h2><p data-dialog-text></p>${form}</div>`;

const decision = (a, b) => `<fieldset data-field="decision"><input type="radio" name="decision" value="${a}" checked><input type="radio" name="decision" value="${b}"><p data-field-error hidden></p></fieldset>`;

function pageHtml() {
    return `
        <div data-group-grading data-assessment-id="7">
            <div data-gg-lock hidden><p data-lock="closed"><svg></svg><span>This is closed.</span></p></div>
            <div data-gg-status></div>
            <div data-gg-layout hidden><aside data-gg-list></aside><section data-gg-detail></section></div>
            <p data-gg-live></p>
            ${dialog("gg-late-dialog", `<form>${decision("separate", "inherit")}${field("reason", "<textarea name=\"reason\"></textarea>")}<p data-form-error hidden></p><button type="submit">Save decision</button></form>`)}
            ${dialog("gg-drop-dialog", `<form><div data-gg-drop-score></div>${decision("keep", "excuse")}${field("reason", "<textarea name=\"reason\"></textarea>")}<p data-form-error hidden></p><button type="submit">Save decision</button></form>`)}
            ${dialog("gg-reopen-confirm", "<form><p data-form-error hidden></p><button type=\"submit\">Reopen grading</button></form>")}
        </div>`;
}

const list = (over = {}) => ({
    success: true,
    groups: [
        { id: 11, name: "Team 1", status: "needs_grading", memberCount: 4 },
        { id: 12, name: "Team 2", status: "graded", memberCount: 3 },
        { id: 13, name: "Team 3", status: "not_submitted", memberCount: 2 },
    ],
    ungrouped: [{ student_user_id: 120, name: "Gil Reyes", hasSubmission: false }],
    ungroupedPolicy: "block",
    blockers: ["1 student is not in a group, so they cannot hand in work."],
    flags: { canWrite: true, finalized: false },
    lockReason: "",
    ...over,
});

const member = (id, name, over = {}) => ({
    student_user_id: id, name, isLeader: id === 101, hasSubmission: true, submission: null, individualScores: {}, individualScore: null,
    adjustment: 0, reason: null, note: null, final: null, finalPercentage: null, pending: false, excused: false, removed: false, ...over,
});

const detail = ({ rubric = false, canWrite = true, status = "needs_grading", members = null, graded = false } = {}) => ({
    success: true,
    group: { id: 11, name: "Team 1", leader_user_id: 101 },
    status,
    totalPoints: 20,
    config: { gradingMode: "collective_adjust", allowsAdjustment: true, usesAverage: false, submissionStyle: "shared", isRubric: rubric },
    submission: { id: 500, attemptNumber: 1, status: "Submitted", isGraded: graded, submittedAt: "2026-12-10 16:12:00", isLate: false, submittedBy: { id: 101, name: "Ana Cruz" }, workHtml: "<p data-work>final-report.pdf</p>" },
    criteria: rubric ? [{ id: 1, title: "Report quality", description: null, max_points: 10, scope: "group" }, { id: 2, title: "Contribution", description: null, max_points: 10, scope: "individual" }] : [],
    groupScores: {},
    groupScore: graded ? 16 : null,
    feedback: [],
    members: members ?? [member(101, "Ana Cruz"), member(102, "Ben Lim"), member(103, "Cai Ortiz"), member(104, "Dee Park")],
    flags: { canWrite, finalized: !canWrite, graded },
    lockReason: canWrite ? "" : "View only. You can see the groups and their grades but not change them.",
});

const json = (body, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } }));

const BASE = "/instructor/api/courses/9/assessments/7/group-grading";

function server(routes) {
    const fn = vi.fn((url, init = {}) => {
        const method = (init.method ?? "GET").toUpperCase();
        const key = `${method} ${String(url).replace(BASE, "") || "/"}`;
        const answer = routes[key];

        return answer ? answer(init) : Promise.reject(new Error(`unexpected ${key}`));
    });

    vi.stubGlobal("fetch", fn);

    return fn;
}

const flush = async () => {
    for (let i = 0; i < 6; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};

const root = () => document.querySelector("[data-group-grading]");
const detailEl = () => document.querySelector("[data-gg-detail]");
const form = () => detailEl().querySelector("[data-gg-form]");
const input = (name) => form().elements[name];
const posts = (fetch, tail) => fetch.mock.calls.filter(([url, init = {}]) => init.method === "POST" && String(url).endsWith(tail));
const bodyOf = (call) => JSON.parse(call[1].body);

function type(name, value) {
    const el = input(name);
    el.value = String(value);
    el.dispatchEvent(new Event("input", { bubbles: true }));
}

async function submit() {
    form().dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
    await flush();
}

beforeEach(() => {
    window.coursesConfig = { apiBaseUrl: "/instructor/api/courses/9", role: "instructor", offeringId: 9 };
    document.body.innerHTML = pageHtml();
});

afterEach(() => {
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
});

describe("loading, empty, error and filled", () => {

    it("lists the groups with their status in words and opens the first group", async () => {
        server({ "GET /": () => json(list()), "GET /11": () => json(detail()) });

        await initializeGroupGrading(root());
        await flush();

        const items = [...root().querySelectorAll("[data-gg-group]")];
        expect(items.map((b) => b.dataset.ggGroup)).toEqual(["11", "12", "13"]);
        expect(items[0].textContent).toContain("Needs grading");
        expect(items[1].textContent).toContain("Graded");
        expect(items[2].textContent).toContain("Not submitted");
        expect(items[0].getAttribute("aria-current")).toBe("true");
        expect(root().querySelector("[data-gg-list]").textContent).toContain("Gil Reyes");
        expect(root().querySelector("[data-gg-list]").textContent).toContain("cannot hand in work");
        expect(detailEl().textContent).toContain("Submitted by Ana Cruz");
        expect(detailEl().querySelector("[data-work]")).not.toBeNull();
        expect(detailEl().querySelectorAll("[data-gg-member]")).toHaveLength(4);
        expect(root().querySelector("[data-gg-layout]").hidden).toBe(false);
    });

    it("draws each member's and ungrouped student's avatar beside their name", async () => {
        const face = (id, name) => ({ id, name, initials: "XY", colour: "#1D4ED8", photoUrl: null, photoUrlLg: null, interactive: true });
        server({
            "GET /": () => json(list({ ungrouped: [{ student_user_id: 120, name: "Gil Reyes", avatar: face(120, "Gil Reyes"), hasSubmission: false }] })),
            "GET /11": () => json(detail({ members: [member(101, "Ana Cruz", { avatar: face(101, "Ana Cruz") }), member(102, "Ben Lim", { avatar: null }), member(103, "Cai Ortiz", { final: 12, removed: true, avatar: face(103, "Cai Ortiz") })] })),
        });

        await initializeGroupGrading(root());
        await flush();

        expect(root().querySelectorAll("[data-gg-list] [data-avatar]")).toHaveLength(1);
        expect(detailEl().querySelector("[data-gg-member=\"101\"] [data-avatar]")).not.toBeNull();
        expect(detailEl().querySelector("[data-gg-member=\"102\"] [data-avatar]")).toBeNull();
        expect(detailEl().querySelector("[data-gg-removed=\"103\"] [data-avatar]")).not.toBeNull();
        // Someone the viewer may see opens their card (FR2); no row here is itself a button.
        expect(root().querySelector("[data-gg-list] [data-person-avatar=\"120\"]")).not.toBeNull();
    });

    it("shows a skeleton while loading", async () => {
        let release;
        server({ "GET /": () => new Promise((resolve) => (release = resolve)) });

        const started = initializeGroupGrading(root());
        expect(root().querySelector("[data-gg-status] [aria-busy=true]")).not.toBeNull();
        release(new Response(JSON.stringify(list({ groups: [] })), { status: 200 }));
        await started;
    });

    it("explains an activity with no groups yet", async () => {
        server({ "GET /": () => json(list({ groups: [], ungrouped: [] })) });

        await initializeGroupGrading(root());

        expect(root().querySelector("[data-gg-status]").textContent).toContain("No groups yet");
        expect(root().querySelector("[data-gg-layout]").hidden).toBe(true);
    });

    it("says the groups could not load and retries", async () => {
        let fail = true;
        server({ "GET /": () => (fail ? json({}, 500) : json(list({ groups: [] }))) });

        await initializeGroupGrading(root());
        expect(root().querySelector("[data-gg-status] [role=alert]").textContent).toContain("couldn't load");

        fail = false;
        root().querySelector("[data-retry]").click();
        await flush();
        expect(root().querySelector("[data-gg-status]").textContent).toContain("No groups yet");
    });

    it("switches to another group from the list", async () => {
        server({ "GET /": () => json(list()), "GET /11": () => json(detail()), "GET /12": () => json({ ...detail({ status: "graded", graded: true }), group: { id: 12, name: "Team 2", leader_user_id: null } }) });

        await initializeGroupGrading(root());
        await flush();
        root().querySelector("[data-gg-group=\"12\"]").click();
        await flush();

        expect(detailEl().querySelector("h2").textContent).toContain("Team 2");
        expect(root().querySelector("[data-gg-group=\"12\"]").getAttribute("aria-current")).toBe("true");
    });

});

describe("grading a group", () => {

    it("updates each member's total as the scores change", async () => {
        server({ "GET /": () => json(list()), "GET /11": () => json(detail({ rubric: true })) });

        await initializeGroupGrading(root());
        await flush();

        type("group_1", 8);
        type("ind_101_2", 6);
        type("adj_101", -2);
        type("ind_102_2", 15);

        expect(detailEl().querySelector("[data-gg-total=\"101\"]").textContent).toContain("12");
        expect(detailEl().querySelector("[data-gg-total=\"102\"]").textContent).toContain("18"); // 15 is capped at the criterion's 10
        expect(detailEl().querySelector("[data-gg-total=\"103\"]").textContent).toContain("8");
        expect(detailEl().textContent).toContain("visible only to that student");
    });

    it("asks for a reason beside an adjustment before anything is sent", async () => {
        const fetch = server({ "GET /": () => json(list()), "GET /11": () => json(detail()) });

        await initializeGroupGrading(root());
        await flush();
        type("group_score", 16);
        type("adj_103", -4);
        await submit();

        expect(posts(fetch, "/11")).toHaveLength(0);
        const slot = form().querySelector("[data-field=\"reason-103\"] [data-field-error]");
        expect(slot.hidden).toBe(false);
        expect(slot.textContent).toContain("reason");
    });

    it("saves every member in one request and names how many", async () => {
        let saved = false;
        const fetch = server({
            "GET /": () => json(list()),
            "GET /11": () => json(saved ? detail({ status: "graded", graded: true }) : detail()),
            "POST /11": () => {
                saved = true;

                return json({ success: true });
            },
        });

        await initializeGroupGrading(root());
        await flush();

        expect(form().querySelector("button[type=submit]").textContent).toContain("Save grades for 4 members");
        type("group_score", 16);
        type("feedback", "Clear structure.");
        type("adj_103", -4);
        type("reason_103", "Missed three meetings");
        type("note_103", "Talk to me after class");
        await submit();

        const [call] = posts(fetch, "/11");
        const body = bodyOf(call);
        expect(body.group_score).toBe(16);
        expect(body.feedback).toBe("Clear structure.");
        expect(Object.keys(body.members).sort()).toEqual(["101", "102", "103", "104"]);
        expect(body.members["103"]).toEqual({ adjustment: -4, reason: "Missed three meetings", note: "Talk to me after class" });
        expect(body.members["101"].adjustment).toBe(0);
        expect(root().querySelector("[data-gg-live]").textContent).toContain("4 members");
    });

    it("sends the rubric parts by scope", async () => {
        const fetch = server({ "GET /": () => json(list()), "GET /11": () => json(detail({ rubric: true })), "POST /11": () => json({ success: true }) });

        await initializeGroupGrading(root());
        await flush();
        type("group_1", 8);
        type("ind_101_2", 6);
        await submit();

        const body = bodyOf(posts(fetch, "/11")[0]);
        expect(body.group_scores).toEqual({ 1: 8 });
        expect(body.members["101"].individual).toEqual({ 2: 6 });
        expect(body.group_score).toBeUndefined();
    });

    it("shows the server's refusal beside the field and keeps what was typed", async () => {
        server({
            "GET /": () => json(list()),
            "GET /11": () => json(detail()),
            "POST /11": () => json({ success: false, message: "Give a reason of up to 500 characters for each adjustment.", errors: { reason: "Give a reason of up to 500 characters for each adjustment." } }, 422),
        });

        await initializeGroupGrading(root());
        await flush();
        type("group_score", 16);
        type("adj_103", -4);
        type("reason_103", "x".repeat(600));
        await submit();

        const slot = form().querySelector("[data-field=\"reason-103\"] [data-field-error]");
        expect(slot.hidden).toBe(false);
        expect(slot.textContent).toContain("500 characters");
        expect(input("group_score").value).toBe("16");
        expect(input("adj_103").value).toBe("-4");
    });

    it("shows a refusal with no field as the form's message", async () => {
        server({
            "GET /": () => json(list()),
            "GET /11": () => json(detail({ rubric: true })),
            "POST /11": () => json({ success: false, message: "That rubric criterion is not part of this grade.", errors: { rubric_scores: "That rubric criterion is not part of this grade." } }, 422),
        });

        await initializeGroupGrading(root());
        await flush();
        type("group_1", 7);
        await submit();

        expect(form().querySelector("[data-form-error]").textContent).toContain("not part of this grade");
        expect(input("group_1").value).toBe("7");
    });

    it("reopens a graded group after a confirm", async () => {
        const fetch = server({ "GET /": () => json(list()), "GET /11": () => json(detail({ status: "graded", graded: true })), "POST /11/reopen": () => json({ success: true }) });

        await initializeGroupGrading(root());
        await flush();

        expect(form()?.querySelector("input")).toBeFalsy();
        detailEl().querySelector("[data-gg-action=\"reopen\"]").click();
        expect(document.querySelector("#gg-reopen-confirm").hidden).toBe(false);

        document.querySelector("#gg-reopen-confirm form").dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        await flush();
        expect(posts(fetch, "/11/reopen")).toHaveLength(1);
    });

});

describe("members who changed after grading", () => {

    const withLate = () => detail({ status: "pending_members", graded: true, members: [member(101, "Ana Cruz", { final: 16 }), member(105, "Eli Sy", { pending: true })] });

    it("asks how to grade a late enrollee, with a reason, and blocks the save until then", async () => {
        const fetch = server({ "GET /": () => json(list()), "GET /11": () => json(withLate()), "POST /11/members/105/late-decision": () => json({ success: true }) });

        await initializeGroupGrading(root());
        await flush();

        const row = detailEl().querySelector("[data-gg-member=\"105\"]");
        expect(row.textContent).toContain("Joined after grading");
        expect(row.querySelector("input")).toBeNull();

        await submit();
        expect(posts(fetch, "/11")).toHaveLength(0);
        expect(form().querySelector("[data-form-error]").textContent).toContain("Eli Sy");

        row.querySelector("[data-gg-action=\"late\"]").click();
        const dlg = document.querySelector("#gg-late-dialog");
        expect(dlg.hidden).toBe(false);

        dlg.querySelector("form").dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        await flush();
        expect(posts(fetch, "/late-decision")).toHaveLength(0);
        expect(dlg.querySelector("[data-field=\"reason\"] [data-field-error]").hidden).toBe(false);

        dlg.querySelector("[value=inherit]").checked = true;
        dlg.querySelector("textarea[name=reason]").value = "Joined before the demo";
        dlg.querySelector("form").dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        await flush();

        expect(bodyOf(posts(fetch, "/late-decision")[0])).toEqual({ decision: "inherit", reason: "Joined before the demo" });
    });

    it("grading a late enrollee separately opens their inputs", async () => {
        server({ "GET /": () => json(list()), "GET /11": () => json(withLate()), "POST /11/members/105/late-decision": () => json({ success: true }) });

        await initializeGroupGrading(root());
        await flush();

        detailEl().querySelector("[data-gg-action=\"late\"]").click();
        const dlg = document.querySelector("#gg-late-dialog");
        dlg.querySelector("textarea[name=reason]").value = "Graded on their own work";
        dlg.querySelector("form").dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        await flush();

        expect(detailEl().querySelector("[data-gg-member=\"105\"] input[name=adj_105]")).not.toBeNull();
    });

    it("offers keep or excuse for a removed member who was graded", async () => {
        const fetch = server({
            "GET /": () => json(list()),
            "GET /11": () => json(detail({ status: "graded", graded: true, members: [member(101, "Ana Cruz", { final: 16 }), member(103, "Cai Ortiz", { final: 12, removed: true })] })),
            "POST /11/members/103/drop-decision": () => json({ success: true }),
        });

        await initializeGroupGrading(root());
        await flush();

        const row = detailEl().querySelector("[data-gg-removed=\"103\"]");
        expect(row.textContent).toContain("No longer in the group");
        row.querySelector("[data-gg-action=\"drop\"]").click();

        const dlg = document.querySelector("#gg-drop-dialog");
        expect(dlg.hidden).toBe(false);
        expect(dlg.textContent).toContain("12");
        dlg.querySelector("[value=excuse]").checked = true;
        dlg.querySelector("textarea[name=reason]").value = "Dropped on 5 Dec";
        dlg.querySelector("form").dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
        await flush();

        expect(bodyOf(posts(fetch, "/drop-decision")[0])).toEqual({ decision: "excuse", reason: "Dropped on 5 Dec" });
    });

    it("shows an excused member with the word", async () => {
        server({ "GET /": () => json(list()), "GET /11": () => json(detail({ status: "graded", graded: true, members: [member(103, "Cai Ortiz", { final: 12, removed: true, excused: true })] })) });

        await initializeGroupGrading(root());
        await flush();

        expect(detailEl().querySelector("[data-gg-removed=\"103\"]").textContent).toContain("Excused");
    });

});

describe("read only", () => {

    it("draws no write control for the dean or a finalized offering, and says why", async () => {
        server({
            "GET /": () => json(list({ flags: { canWrite: false, finalized: true }, lockReason: "View only." })),
            "GET /11": () => json(detail({ canWrite: false, status: "graded", graded: true, members: [member(101, "Ana Cruz", { final: 16 }), member(103, "Cai Ortiz", { final: 12, adjustment: -4, reason: "Missed meetings" }), member(105, "Eli Sy", { pending: true }), member(106, "Fay Go", { final: 9, removed: true })] })),
        });

        await initializeGroupGrading(root());
        await flush();

        expect(detailEl().querySelectorAll("input, textarea, select")).toHaveLength(0);
        expect(detailEl().querySelectorAll("button[type=submit], [data-gg-action]")).toHaveLength(0);
        expect(root().querySelector("[data-gg-lock]").hidden).toBe(false);
        expect(root().querySelector("[data-gg-lock]").textContent).toContain("View only");
        expect(detailEl().textContent).toContain("Missed meetings");
        expect(detailEl().querySelector("[data-gg-total=\"103\"]").textContent).toContain("12");
    });

    it("fills the dean's frame, which has no dialogs, with notes, excused and pending badges (4E)", async () => {
        window.coursesConfig = { apiBaseUrl: "/dean/api/courses/9", role: "dean", offeringId: 9 };
        document.body.innerHTML = `<section data-dean-groups>${pageHtml()}</section>`;
        document.querySelectorAll("[data-dialog]").forEach((el) => el.remove());

        const fetch = vi.fn((url) => String(url).endsWith("/group-grading")
            ? json(list({ flags: { canWrite: false, finalized: false }, lockReason: "View only. You can see the groups and their grades but not change them." }))
            : json(detail({ canWrite: false, status: "graded", graded: true, members: [member(101, "Ana Cruz", { final: 16 }), member(103, "Cai Ortiz", { final: 12, adjustment: -4, reason: "Missed meetings", note: "Talk after class" }), member(104, "Dee Park", { final: 0, excused: true }), member(105, "Eli Sy", { pending: true })] })));
        vi.stubGlobal("fetch", fetch);

        await initializeGroupGrading(root());
        await flush();

        expect(String(fetch.mock.calls[0][0])).toBe("/dean/api/courses/9/assessments/7/group-grading");
        expect(fetch.mock.calls.every(([, init = {}]) => (init.method ?? "GET") === "GET")).toBe(true);
        expect(root().querySelectorAll("input, textarea, select, button[type=submit], [data-gg-action]")).toHaveLength(0);
        expect(root().querySelector("[data-gg-lock]").textContent).toContain("View only.");
        expect(document.querySelector("[data-gg-list]").textContent).toContain("Graded");
        expect(detailEl().textContent).toContain("Private note: Talk after class");
        expect(detailEl().querySelector("[data-gg-member=\"104\"]").textContent).toContain("Excused");
        expect(detailEl().querySelector("[data-gg-member=\"105\"]").textContent).toContain("Joined after grading");
        expect(detailEl().querySelector("[data-gg-total=\"101\"]").textContent).toContain("Total 16 / 20");
    });

    it("does not tell a read-only viewer to add groups", async () => {
        server({ "GET /": () => json(list({ groups: [], ungrouped: [], blockers: [], flags: { canWrite: false, finalized: false }, lockReason: "View only." })) });

        await initializeGroupGrading(root());
        await flush();

        expect(root().querySelector("[data-gg-empty]").textContent).toContain("No groups yet");
        expect(root().querySelector("[data-gg-empty]").textContent).not.toContain("in its editor");
    });

});
