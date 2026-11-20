import { applyFieldErrors, createDialog } from "../../../shared/dialog.js";
import { showToast } from "../../../shared/toast.js";
import { beginLoad } from "../../../shared/spa/load-guard.js";
import { showLoadError } from "../../../shared/spa/error-state.js";

import { fetchGroupGrading, fetchGroupGradingDetail, sendGroupGrading } from "./api.js";
import { NETWORK, onSubmit } from "./board.js";
import { badge, escapeHtml, formatDateTime, SMALL } from "./detail.js";
import { renderDetailSkeleton } from "./skeleton.js";

/*
|--------------------------------------------------------------------------
| Grade Work's group view (screen 2B) and its decisions (4D)
|--------------------------------------------------------------------------
| The server draws the frame and the dialogs
| (assessments/group-grade-work.php); this fills the group list and one
| group's work and grades from the group-grading JSON. One save sends every
| member's grade; the server writes all of them or none. A late enrollee is
| graded only once the instructor chose how (separately or inherit), a
| graded member who left is kept or excused, each with a reason. With
| flags.canWrite false (the dean, finalized grades) nothing can be changed
| and no control is drawn.
*/

const STATUS = {
    needs_grading: ["Needs grading", "warning"],
    pending_members: ["Needs a decision", "warning"],
    graded: ["Graded", "success"],
    not_submitted: ["Not submitted", "neutral"],
};

const DIALOGS = ["gg-late-dialog", "gg-drop-dialog", "gg-reopen-confirm"];
const LABEL = "font-heading text-xs font-semibold uppercase tracking-wide text-[#646464]";
const INPUT = "w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-[#1A1C1E] focus:border-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11";
const PRIMARY = "inline-flex items-center justify-center rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 focus-visible:ring-offset-2 pointer-coarse:min-h-11 max-sm:w-full";
const DANGER = "inline-flex items-center justify-center rounded-full border border-[#C33939] bg-white px-5 py-2 text-sm font-semibold text-[#C33939] transition hover:bg-[#FDEDEC] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11 max-sm:w-full";
const REASON = "Give a reason. It is kept with the change.";

const fmt = (n) => String(Math.round(Number(n) * 100) / 100);
const capped = (value, max) => Math.max(0, Math.min(Number(value) || 0, Number(max)));
const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

/** @param {HTMLElement|null} root  [data-group-grading]; gradeStudent(id) opens the per-student page (solo work) */
export async function initializeGroupGrading(root, { gradeStudent = null } = {}) {

    if (!root) {
        return;
    }

    const view = {
        root,
        id: Number(root.dataset.assessmentId),
        list: null,
        detail: null,
        groupId: null,
        decided: new Set(),
        pending: null,
        dialogs: {},
        gradeStudent,
        part: (selector) => root.querySelector(selector),
    };

    for (const id of DIALOGS) {
        const el = root.querySelector(`#${id}`);

        if (el) {
            view.dialogs[id] = createDialog(el);
        }
    }

    bind(view);
    await loadList(view);

}

/*
|--------------------------------------------------------------------------
| Loading
|--------------------------------------------------------------------------
*/

async function loadList(view) {

    const status = view.part("[data-gg-status]");
    const guard = beginLoad(status, renderDetailSkeleton("Loading the groups…"), 0);

    try {
        const list = await fetchGroupGrading(view.id);

        if (!guard.current()) {
            return;
        }

        guard.finish();
        view.list = list;
        showLock(view, list.lockReason);

        if (list.groups.length === 0) {
            view.part("[data-gg-layout]").hidden = true;
            status.innerHTML = `
                <div class="flex flex-col items-center gap-2 rounded-2xl border border-gray-200 bg-white px-6 py-12 text-center shadow-sm" data-gg-empty>
                    <b class="text-sm font-semibold text-[#1A1C1E]">No groups yet</b>
                    <span class="max-w-md text-xs text-[#646464]">Add groups to this assignment in its editor (Group activity). Each group is then graded here once for all its members.</span>
                </div>`;

            return;
        }

        status.innerHTML = "";
        view.part("[data-gg-layout]").hidden = false;

        if (!list.groups.some((g) => g.id === view.groupId)) {
            view.groupId = (list.groups.find((g) => g.status === "needs_grading" || g.status === "pending_members") ?? list.groups[0]).id;
        }

        renderList(view);
        await loadDetail(view, view.groupId);
    } catch (error) {
        console.error(error);

        if (guard.current()) {
            guard.finish();
            view.part("[data-gg-layout]").hidden = true;
            showLoadError(status, "the groups to grade", () => loadList(view));
        }
    }

}

async function loadDetail(view, groupId) {

    const box = view.part("[data-gg-detail]");
    const guard = beginLoad(box, renderDetailSkeleton("Loading the group's work…"), 0);

    view.groupId = groupId;
    markSelected(view);

    try {
        const detail = await fetchGroupGradingDetail(view.id, groupId);

        if (!guard.current()) {
            return;
        }

        guard.finish();
        view.detail = detail;
        showLock(view, detail.lockReason);
        renderDetail(view);
    } catch (error) {
        console.error(error);

        if (guard.current()) {
            guard.finish();
            showLoadError(box, "the group's work", () => loadDetail(view, groupId));
        }
    }

}

function showLock(view, reason) {

    const lock = view.part("[data-gg-lock]");

    lock.hidden = !reason;
    lock.querySelector("[data-lock] > span").textContent = reason ?? "";

}

/*
|--------------------------------------------------------------------------
| The group list (left)
|--------------------------------------------------------------------------
*/

function renderList(view) {

    const { groups, ungrouped, ungroupedPolicy, blockers } = view.list;
    const items = groups.map((g) => {
        const [label, tone] = STATUS[g.status] ?? STATUS.not_submitted;

        return `
            <li>
                <button type="button" class="flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-left text-sm text-[#3D3D3D] transition hover:bg-brand-tint aria-[current=true]:bg-brand-tint aria-[current=true]:font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11" data-gg-group="${g.id}">
                    <span class="flex min-w-0 flex-col"><span class="truncate">${escapeHtml(g.name)}</span><span class="text-xs font-normal text-[#646464]">${plural(g.memberCount, "member", "members")}</span></span>
                    ${badge(label, tone)}
                </button>
            </li>`;
    }).join("");

    const solo = ungroupedPolicy === "solo" && typeof view.gradeStudent === "function";
    const free = ungrouped.map((s) => `
        <li class="flex items-center justify-between gap-2 text-sm text-[#3D3D3D]">
            <span class="truncate">${escapeHtml(s.name)}</span>
            ${solo && s.hasSubmission ? `<button type="button" class="${SMALL} text-brand hover:bg-brand-tint" data-gg-student="${s.student_user_id}" aria-label="Grade ${escapeHtml(s.name)}'s own work">Grade</button>` : ""}
        </li>`).join("");

    view.part("[data-gg-list]").innerHTML = `
        <h3 class="${LABEL}">Groups</h3>
        <ul class="flex flex-col gap-1">${items}</ul>
        ${ungrouped.length ? `<h3 class="${LABEL} mt-2">Ungrouped (${ungrouped.length})</h3><ul class="flex flex-col gap-1">${free}</ul>` : ""}
        ${blockers.map((b) => `<p class="rounded-xl bg-[#FFF1D6] px-3 py-2 text-xs text-[#946200]">${escapeHtml(b)}</p>`).join("")}`;

    markSelected(view);

}

function markSelected(view) {

    for (const button of view.root.querySelectorAll("[data-gg-group]")) {
        button.setAttribute("aria-current", String(Number(button.dataset.ggGroup) === view.groupId));
    }

}

/*
|--------------------------------------------------------------------------
| One group (right)
|--------------------------------------------------------------------------
*/

const isGradable = (view, m) => !m.removed && m.hasSubmission && (!m.pending || view.decided.has(m.student_user_id));

const criteriaOf = (detail, scope) => detail.criteria.filter((c) => c.scope === scope);

function canEdit(view) {

    const d = view.detail;

    return d.flags.canWrite && d.status !== "graded" && d.status !== "not_submitted";

}

function field(key, label, control) {

    return `
        <div class="flex min-w-0 flex-col gap-1" data-field="${key}">
            <label class="text-xs font-medium text-gray-600" for="gg-${key}">${label}</label>
            ${control}
            <p class="text-xs font-medium text-[#C33939]" data-field-error hidden></p>
        </div>`;

}

function points(key, name, label, value, max, write) {

    if (!write) {
        return `<span class="flex flex-col text-sm"><span class="text-xs text-[#646464]">${label}</span><b class="font-semibold text-[#1A1C1E]">${value === null || value === undefined ? "–" : fmt(value)}${max === null ? "" : ` / ${fmt(max)}`}</b></span>`;
    }

    const limits = max === null ? "step=\"0.5\"" : `min="0" max="${max}" step="0.5"`;

    return field(key, `${label}${max === null ? "" : ` (out of ${fmt(max)})`}`, `<input type="number" inputmode="decimal" id="gg-${key}" name="${name}" ${limits} value="${value ?? ""}" class="${INPUT}">`);

}

function renderDetail(view) {

    const d = view.detail;
    const write = canEdit(view);
    const { config } = d;
    const groupCriteria = criteriaOf(d, "group");
    const present = d.members.filter((m) => !m.removed);
    const removed = d.members.filter((m) => m.removed);
    const gradable = present.filter((m) => isGradable(view, m));

    const head = d.submission
        ? `<p class="text-sm text-[#646464]">Submitted by ${escapeHtml(d.submission.submittedBy.name)}${d.submission.submittedBy.id === d.group.leader_user_id ? " (leader)" : ""}, ${escapeHtml(formatDateTime(d.submission.submittedAt))} · ${d.submission.isLate ? "late" : "on time"}</p>`
        : `<p class="text-sm text-[#646464]">${config.submissionStyle === "per_member" ? "Each member hands in their own work." : "This group has not handed in work yet."}</p>`;

    const groupPart = config.isRubric
        ? (groupCriteria.length ? `
            <div class="flex flex-col gap-3">
                <h3 class="${LABEL}">Group criteria (applies to all ${plural(present.length, "member", "members")})</h3>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">${groupCriteria.map((c) => points(`group-${c.id}`, `group_${c.id}`, escapeHtml(c.title), d.groupScores[c.id] ?? null, c.max_points, write)).join("")}</div>
            </div>` : "")
        : (config.usesAverage || config.gradingMode === "individual" ? "" : `
            <div class="flex flex-col gap-3">
                <h3 class="${LABEL}">Group score (applies to all ${plural(present.length, "member", "members")})</h3>
                <div class="max-w-xs">${points("group-score", "group_score", "Group score", d.groupScore, d.totalPoints, write)}</div>
            </div>`);

    const thread = d.feedback.length ? `<ul class="flex flex-col gap-2">${d.feedback.map((f) => `
        <li class="rounded-xl bg-gray-50 px-3 py-2 text-sm text-[#3D3D3D]"><span class="text-xs text-[#646464]">${escapeHtml(f.author_name ?? "")} · ${escapeHtml(formatDateTime(f.created_at))}</span><br>${escapeHtml(f.message)}</li>`).join("")}</ul>` : "";

    const feedback = `
        <div class="flex flex-col gap-2">
            <h3 class="${LABEL}">Group feedback (everyone in the group sees this)</h3>
            ${thread || (write ? "" : "<p class=\"text-sm text-[#646464]\">No feedback yet.</p>")}
            ${write ? field("feedback", "Add feedback", `<textarea id="gg-feedback" name="feedback" rows="3" maxlength="5000" class="${INPUT}"></textarea>`) : ""}
        </div>`;

    const rows = present.map((m) => memberRow(view, m, write)).join("");
    const gone = removed.length ? `
        <div class="flex flex-col gap-2">
            <h3 class="${LABEL}">No longer in the group</h3>
            <ul class="flex flex-col">${removed.map((m) => removedRow(view, m)).join("")}</ul>
        </div>` : "";

    const hint = config.allowsAdjustment
        ? "<p class=\"text-xs text-[#646464]\">The adjustment and private note are visible only to that student. A reason is required for any adjustment.</p>"
        : "";

    const actions = [
        write && gradable.length ? `<button type="submit" class="${PRIMARY}">Save grades for ${plural(gradable.length, "member", "members")}</button>` : "",
        d.flags.canWrite && d.flags.graded ? `<button type="button" class="${DANGER}" data-gg-action="reopen">Reopen grading</button>` : "",
    ].join("");

    view.part("[data-gg-detail]").innerHTML = `
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 flex-col gap-1">
                <h2 class="font-display text-lg font-bold text-brand">${escapeHtml(d.group.name)}</h2>
                ${head}
            </div>
            <span class="flex flex-wrap gap-1.5">${badge((STATUS[d.status] ?? STATUS.not_submitted)[0], (STATUS[d.status] ?? STATUS.not_submitted)[1])}${d.submission ? badge(`Attempt ${d.submission.attemptNumber}`, "neutral") : ""}</span>
        </div>
        ${d.submission ? `<div data-gg-work>${d.submission.workHtml}</div>` : ""}
        <form class="flex flex-col gap-5" novalidate data-gg-form>
            ${groupPart}
            ${feedback}
            <div class="flex flex-col gap-2">
                <h3 class="${LABEL}">${memberHeading(d)}</h3>
                <ul class="flex flex-col">${rows}</ul>
                ${hint}
            </div>
            ${gone}
            <p class="text-xs font-medium text-[#C33939]" role="alert" data-form-error hidden></p>
            ${actions ? `<div class="flex flex-wrap items-center gap-3">${actions}</div>` : ""}
            ${write && gradable.length ? "<p class=\"text-xs text-[#646464]\">One save writes every member's grade, or none of them.</p>" : ""}
        </form>`;

    updateTotals(view);

}

function memberHeading(d) {

    if (d.config.isRubric) {
        return criteriaOf(d, "individual").length ? "Members: individual criteria, adjustment and private note" : "Members";
    }

    return d.config.allowsAdjustment ? "Members: adjustment and private note" : d.config.usesAverage ? "Members: each score (the group gets the average)" : d.config.gradingMode === "individual" ? "Members: each scored on their own" : "Members";

}

function memberRow(view, m, write) {

    const d = view.detail;
    const sid = m.student_user_id;
    const name = `<b class="text-sm font-semibold text-[#1A1C1E]">${escapeHtml(m.name)}</b>${m.isLeader ? " <span aria-label=\"leader\">★</span>" : ""}`;
    const flags = [m.pending && !view.decided.has(sid) ? badge("Joined after grading", "warning") : "", m.excused ? badge("Excused", "neutral") : ""].join("");
    const own = m.submission ? `<details class="text-sm"><summary class="cursor-pointer text-brand pointer-coarse:min-h-11">Their work, ${escapeHtml(formatDateTime(m.submission.submittedAt))}${m.submission.isLate ? " · late" : ""}</summary><div class="pt-2">${m.submission.workHtml}</div></details>` : "";
    let body;

    if (!m.hasSubmission) {
        body = "<span class=\"text-sm text-[#646464]\">No hand-in yet</span>";
    } else if (m.pending && !view.decided.has(sid)) {
        body = `<span class="text-sm text-[#646464]">The group was graded before they joined.</span>${d.flags.canWrite ? `<button type="button" class="${SMALL} border border-brand text-brand hover:bg-brand-tint" data-gg-action="late" data-student="${sid}" aria-label="Decide how to grade ${escapeHtml(m.name)}">Decide…</button>` : ""}`;
    } else {
        body = memberInputs(view, m, write);
    }

    return `
        <li class="flex flex-col gap-3 border-t border-gray-100 py-3 first:border-t-0 md:grid md:grid-cols-[minmax(0,10rem)_minmax(0,1fr)_auto] md:items-start md:gap-4" data-gg-member="${sid}">
            <span class="flex flex-wrap items-center gap-1.5">${name}${flags}</span>
            <div class="flex min-w-0 flex-col gap-3">${body}${own}</div>
            <span data-gg-total="${sid}"></span>
        </li>`;

}

function memberInputs(view, m, write) {

    const d = view.detail;
    const sid = m.student_user_id;
    const { config } = d;
    const parts = [];

    if (config.isRubric) {
        for (const c of criteriaOf(d, "individual")) {
            parts.push(points(`ind-${sid}-${c.id}`, `ind_${sid}_${c.id}`, escapeHtml(c.title), m.individualScores[c.id] ?? null, c.max_points, write));
        }
    } else if (config.usesAverage || config.gradingMode === "individual") {
        parts.push(points(`score-${sid}`, `score_${sid}`, "Score", m.individualScore, d.totalPoints, write));
    }

    if (config.allowsAdjustment) {
        if (write) {
            parts.push(points(`adj-${sid}`, `adj_${sid}`, "Adjustment (+ or −)", m.adjustment, null, true));
            parts.push(field(`reason-${sid}`, "Reason for the adjustment", `<input type="text" id="gg-reason-${sid}" name="reason_${sid}" maxlength="500" value="${escapeHtml(m.reason ?? "")}" class="${INPUT}">`));
            parts.push(field(`note-${sid}`, "Private note (optional)", `<textarea id="gg-note-${sid}" name="note_${sid}" rows="2" maxlength="2000" class="${INPUT}">${escapeHtml(m.note ?? "")}</textarea>`));
        } else {
            parts.push(`<span class="text-sm text-[#3D3D3D]">Adjustment ${m.adjustment > 0 ? "+" : ""}${fmt(m.adjustment)}${m.reason ? ` · “${escapeHtml(m.reason)}”` : ""}</span>`);
            parts.push(m.note ? `<span class="text-xs text-[#646464]">Private note: ${escapeHtml(m.note)}</span>` : "");
        }
    }

    return parts.length ? `<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">${parts.join("")}</div>` : "";

}

function removedRow(view, m) {

    const sid = m.student_user_id;

    return `
        <li class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 py-3 first:border-t-0" data-gg-removed="${sid}">
            <span class="flex flex-wrap items-center gap-1.5"><b class="text-sm font-semibold text-[#1A1C1E]">${escapeHtml(m.name)}</b>${badge("No longer in the group", "neutral")}${m.excused ? badge("Excused", "neutral") : ""}</span>
            <span class="flex flex-wrap items-center gap-2">
                ${m.final === null ? "" : badge(`Score ${fmt(m.final)} / ${fmt(view.detail.totalPoints)}`, "neutral")}
                ${view.detail.flags.canWrite ? `<button type="button" class="${SMALL} border border-brand text-brand hover:bg-brand-tint max-sm:w-full" data-gg-action="drop" data-student="${sid}" aria-label="Keep or excuse ${escapeHtml(m.name)}'s score">Keep or excuse…</button>` : ""}
            </span>
        </li>`;

}

/*
|--------------------------------------------------------------------------
| Totals: the same sum the server makes, clamped to 0..total
|--------------------------------------------------------------------------
*/

function liveTotals(view) {

    const d = view.detail;
    const form = view.part("[data-gg-form]");
    const value = (name) => Number(form.elements[name]?.value || 0);
    const total = Number(d.totalPoints);
    const gradable = d.members.filter((m) => isGradable(view, m));
    const { config } = d;
    let groupPart = 0;

    if (config.isRubric) {
        groupPart = criteriaOf(d, "group").reduce((sum, c) => sum + capped(value(`group_${c.id}`), c.max_points), 0);
    } else if (config.usesAverage) {
        groupPart = gradable.length ? gradable.reduce((sum, m) => sum + capped(value(`score_${m.student_user_id}`), total), 0) / gradable.length : 0;
    } else if (config.gradingMode !== "individual") {
        groupPart = capped(value("group_score"), total);
    }

    const out = new Map();

    for (const m of gradable) {
        const sid = m.student_user_id;
        const own = config.isRubric
            ? criteriaOf(d, "individual").reduce((sum, c) => sum + capped(value(`ind_${sid}_${c.id}`), c.max_points), 0)
            : (!config.usesAverage && config.gradingMode === "individual" ? capped(value(`score_${sid}`), total) : 0);
        const adjustment = config.allowsAdjustment ? value(`adj_${sid}`) : 0;

        out.set(sid, capped(groupPart + own + adjustment, total));
    }

    return out;

}

function updateTotals(view) {

    const d = view.detail;
    const live = canEdit(view) ? liveTotals(view) : new Map();

    for (const m of d.members.filter((x) => !x.removed)) {
        const slot = view.part(`[data-gg-total="${m.student_user_id}"]`);
        const shown = live.has(m.student_user_id) ? live.get(m.student_user_id) : m.final;

        if (slot) {
            slot.innerHTML = shown === null || shown === undefined
                ? badge("Not graded", "neutral")
                : badge(`Total ${fmt(shown)} / ${fmt(d.totalPoints)}`, live.has(m.student_user_id) ? "neutral" : "success");
        }
    }

}

/*
|--------------------------------------------------------------------------
| Saving
|--------------------------------------------------------------------------
*/

function gradeInput(view, form) {

    const d = view.detail;
    const { config } = d;
    const number = (name) => Number(form.elements[name]?.value || 0);
    const text = (name) => String(form.elements[name]?.value ?? "").trim();
    const input = { feedback: text("feedback"), members: {} };

    if (config.isRubric) {
        input.group_scores = Object.fromEntries(criteriaOf(d, "group").map((c) => [c.id, number(`group_${c.id}`)]));
    } else if (!config.usesAverage && config.gradingMode !== "individual") {
        input.group_score = number("group_score");
    }

    for (const m of d.members.filter((x) => isGradable(view, x))) {
        const sid = m.student_user_id;
        const entry = {};

        if (config.isRubric && criteriaOf(d, "individual").length) {
            entry.individual = Object.fromEntries(criteriaOf(d, "individual").map((c) => [c.id, number(`ind_${sid}_${c.id}`)]));
        } else if (!config.isRubric && (config.usesAverage || config.gradingMode === "individual")) {
            entry.score = number(`score_${sid}`);
        }

        if (config.allowsAdjustment) {
            entry.adjustment = number(`adj_${sid}`);
            entry.reason = text(`reason_${sid}`);
            entry.note = text(`note_${sid}`);
        }

        input.members[sid] = entry;
    }

    return input;

}

async function save(view, form) {

    const d = view.detail;
    const undecided = d.members.filter((m) => !m.removed && m.pending && !view.decided.has(m.student_user_id));

    if (undecided.length) {
        applyFieldErrors(form, {}, `Decide how to grade ${undecided.map((m) => m.name).join(", ")} first.`);

        return;
    }

    const input = gradeInput(view, form);
    const missing = Object.entries(input.members).filter(([, m]) => m.adjustment && m.reason === "");

    if (missing.length) {
        applyFieldErrors(form, Object.fromEntries(missing.map(([sid]) => [`reason-${sid}`, "Give a reason for this adjustment."])));

        return;
    }

    const button = form.querySelector("button[type=submit]");
    const count = Object.keys(input.members).length;

    button.disabled = true;
    form.setAttribute("aria-busy", "true");

    try {
        const result = await sendGroupGrading(view.id, `/${view.groupId}`, input);

        if (result.ok) {
            view.decided.clear();
            announce(view, `Grades saved for ${plural(count, "member", "members")} of ${d.group.name}`);
            await loadList(view);

            return;
        }

        // The server names the field, not the member: a reason refusal goes beside every adjusted member.
        const errors = result.body.errors ?? {};
        const placed = errors.reason
            ? Object.fromEntries(Object.entries(input.members).filter(([, m]) => m.adjustment).map(([sid]) => [`reason-${sid}`, errors.reason]))
            : {};

        if (Object.keys(placed).length) {
            applyFieldErrors(form, placed);
        } else {
            applyFieldErrors(form, {}, result.body.message ?? "That did not work. Nothing was saved. Please try again.");
        }
    } catch (error) {
        console.error(error);
        applyFieldErrors(form, {}, NETWORK);
    } finally {
        button.disabled = false;
        form.removeAttribute("aria-busy");
    }

}

function announce(view, message) {

    view.part("[data-gg-live]").textContent = message;
    showToast(message, { tone: "success" });

}

/*
|--------------------------------------------------------------------------
| Dialogs and wiring
|--------------------------------------------------------------------------
*/

function openDialog(view, id, title, opener) {

    const el = view.part(`#${id}`);
    const form = el.querySelector("form");

    form.reset();
    applyFieldErrors(form, {});

    if (title) {
        el.querySelector("[data-dialog-title]").textContent = title;
    }

    view.dialogs[id].open(opener);

    return form;

}

const close = (view, id) => view.dialogs[id].close({ instant: true });

function decisionFrom(form, data) {

    const reason = String(data.get("reason") ?? "").trim();

    if (reason === "") {
        applyFieldErrors(form, { reason: REASON });
    }

    return { decision: String(data.get("decision") ?? ""), reason };

}

function onAction(view, button) {

    const sid = Number(button.dataset.student);
    const m = view.detail.members.find((x) => x.student_user_id === sid);

    switch (button.dataset.ggAction) {
        case "reopen":
            openDialog(view, "gg-reopen-confirm", `Reopen grading for ${view.detail.group.name}?`, button);
            break;
        case "late":
            view.pending = { studentId: sid, name: m.name };
            openDialog(view, "gg-late-dialog", `${m.name} joined after grading`, button);
            break;
        case "drop": {
            view.pending = { studentId: sid, name: m.name };
            const slot = openDialog(view, "gg-drop-dialog", `${m.name} left the group`, button).querySelector("[data-gg-drop-score]");
            const score = m.final === null ? "not set" : `${fmt(m.final)} / ${fmt(view.detail.totalPoints)}`;

            slot?.querySelector("[data-gg-drop-line]")?.remove();
            slot?.insertAdjacentHTML("beforeend", `<p class="text-sm text-[#3D3D3D]" data-gg-drop-line>${escapeHtml(m.name)}'s score is ${score}${m.excused ? " and is excused now" : ""}.</p>`);
            break;
        }
    }

}

function bind(view) {

    const { root } = view;

    root.addEventListener("click", (event) => {
        const group = event.target.closest("[data-gg-group]");
        const student = event.target.closest("[data-gg-student]");
        const action = event.target.closest("[data-gg-action]");

        if (group) {
            loadDetail(view, Number(group.dataset.ggGroup));
        } else if (student) {
            view.gradeStudent?.(Number(student.dataset.ggStudent));
        } else if (action && view.detail?.flags.canWrite) {
            onAction(view, action);
        }
    });

    root.addEventListener("input", (event) => {
        if (event.target.closest("[data-gg-form]") && view.detail) {
            updateTotals(view);
        }
    });

    root.addEventListener("submit", (event) => {
        const form = event.target.closest("[data-gg-form]");

        if (form) {
            event.preventDefault();

            if (canEdit(view)) {
                save(view, form);
            }
        }
    });

    const dialogForm = (id) => view.part(`#${id} form`);

    if (dialogForm("gg-reopen-confirm")) {
        onSubmit(dialogForm("gg-reopen-confirm"), async () => {
            const result = await sendGroupGrading(view.id, `/${view.groupId}/reopen`);

            if (result.ok) {
                close(view, "gg-reopen-confirm");
                announce(view, `Grading of ${view.detail.group.name} is open again`);
                await loadList(view);
            }

            return result;
        });
    }

    if (dialogForm("gg-late-dialog")) {
        onSubmit(dialogForm("gg-late-dialog"), async (data) => {
            const body = decisionFrom(dialogForm("gg-late-dialog"), data);

            if (body.reason === "") {
                return null;
            }

            const { studentId, name } = view.pending;
            const result = await sendGroupGrading(view.id, `/${view.groupId}/members/${studentId}/late-decision`, body);

            if (result.ok) {
                close(view, "gg-late-dialog");

                if (body.decision === "separate") {
                    // They stay pending on the server until the group's grades are saved with their scores.
                    view.decided.add(studentId);
                    renderDetail(view);
                    announce(view, `Give ${name} their scores, then save the group's grades`);
                } else {
                    announce(view, `${name} now has the group's grade`);
                    await loadList(view);
                }
            }

            return result;
        });
    }

    if (dialogForm("gg-drop-dialog")) {
        onSubmit(dialogForm("gg-drop-dialog"), async (data) => {
            const body = decisionFrom(dialogForm("gg-drop-dialog"), data);

            if (body.reason === "") {
                return null;
            }

            const { studentId, name } = view.pending;
            const result = await sendGroupGrading(view.id, `/${view.groupId}/members/${studentId}/drop-decision`, body);

            if (result.ok) {
                close(view, "gg-drop-dialog");
                announce(view, body.decision === "excuse" ? `${name}'s score is excused` : `${name}'s score stays on their record`);
                await loadDetail(view, view.groupId);
            }

            return result;
        });
    }

}
