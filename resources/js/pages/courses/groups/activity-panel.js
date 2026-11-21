import { applyFieldErrors, createDialog } from "../../../shared/dialog.js";
import { showToast } from "../../../shared/toast.js";
import { beginLoad } from "../../../shared/spa/load-guard.js";
import { showLoadError } from "../../../shared/spa/error-state.js";

import { fetchActivityGroups, fetchActivityProposals, sendActivityChange } from "./api.js";
import { applyMove, bindDragMoves, FAILED, fillMoveSelect, groupOf, NETWORK, onSubmit, studentById } from "./board.js";
import { badge, escapeHtml, formatDateTime, renderGroupBoard, SMALL } from "./detail.js";
import { renderDetailSkeleton } from "./skeleton.js";

/*
|--------------------------------------------------------------------------
| The assignment editor's "Group activity" card (screen 2A)
|--------------------------------------------------------------------------
| The server draws the switch, the settings and the dialogs
| (assessments/partials/group-activity-section.php). This fills them from
| the activity-groups JSON, draws the groups with the Groups tab's board and
| saves each setting as it changes. Frozen groups (they have submitted) are
| unlocked or given a late enrollee only through a dialog with a reason.
| With flags.canWrite false nothing can be changed.
|
| The learning item editor uses the same card (data-learning-item-id instead
| of data-assessment-id, screen 2A for items): only grouping and the
| completion setting, no leader, deadline or unlock actions, since an item's
| groups never freeze (D12, D13, D26). Fields and dialogs the server did not
| draw are skipped.
*/

const FLAGS = ["nonleader_can_rename", "nonleader_can_vote", "nonleader_can_leave", "groups_visible"];
const FIELDS = ["ungrouped_policy", "submission_style", "grading_mode", "formation_mode", "signup_opens_at", "signup_closes_at", "min_size", "max_size", "completion_mode", ...FLAGS];
const DIALOGS = ["ga-attach-dialog", "ga-detach-confirm", "ga-policy-confirm", "ga-unlock-dialog", "ga-late-dialog", "ga-name-dialog", "ga-move-dialog", "ga-delete-confirm", "ga-recopy-confirm", "ga-copy-dialog", "ga-decline-dialog", "ga-leader-dialog", "ga-extend-dialog"];
const REASON = "Give a reason. It is kept with the change.";

const byName = (a, b) => a.name.localeCompare(b.name);

export async function initializeActivityGroups(root) {

    if (!root) {
        return;
    }

    const item = root.dataset.learningItemId !== undefined;
    const panel = {
        root,
        item,
        kind: item ? "learning-items" : "assessments",
        id: Number(item ? root.dataset.learningItemId : root.dataset.assessmentId),
        detail: null,
        pending: null,
        dialogs: {},
        part: (selector) => root.querySelector(selector),
    };

    for (const id of DIALOGS) {
        const el = root.querySelector(`#${id}`);

        if (el) {
            panel.dialogs[id] = createDialog(el);
        }
    }

    bind(panel);
    await load(panel);

}

const send = (panel, method, path, body) => sendActivityChange(panel.id, method, path, body, panel.kind);

const canWrite = (panel) => panel.detail?.flags.canWrite === true;

async function load(panel) {

    const status = panel.part("[data-ga-status]");

    for (const selector of ["[data-ga-empty]", "[data-ga-settings]"]) {
        panel.part(selector).hidden = true;
    }

    panel.part("[data-ga-board]").innerHTML = "";

    const guard = beginLoad(status, renderDetailSkeleton("Loading the activity's groups…"), 0);

    try {
        const detail = await fetchActivityGroups(panel.id, panel.kind);

        if (!guard.current()) {
            return;
        }

        guard.finish();
        status.innerHTML = "";
        panel.detail = detail;
        render(panel);
    } catch (error) {
        console.error(error);

        if (guard.current()) {
            guard.finish();
            showLoadError(status, "the activity's groups", () => load(panel));
        }
    }

}

/*
|--------------------------------------------------------------------------
| Drawing
|--------------------------------------------------------------------------
*/

function render(panel) {

    const { config, flags, lockReason, activities } = panel.detail;
    const write = flags.canWrite;
    const toggle = panel.part("[data-ga-toggle]");
    const lock = panel.part("[data-ga-lock]");
    const form = panel.part("[data-ga-settings]");

    toggle.checked = config !== null;
    toggle.disabled = !write;
    lock.hidden = lockReason === "";
    lock.querySelector("[data-lock] > span").textContent = lockReason;

    panel.part("[data-ga-empty]").hidden = config !== null;
    form.hidden = config === null;
    panel.part("[data-ga-fields]").disabled = !write;

    if (config === null) {
        panel.part("[data-ga-board]").innerHTML = "";

        return;
    }

    for (const name of FIELDS.filter((n) => form.elements[n])) {
        setValue(form.elements[name], config[name]);
    }

    showWindow(panel);

    // Re-copy and Copy replace the groups, which a submitted (frozen) group forbids.
    const open = write && !flags.frozenGroups;
    panel.part("[data-ga-action=\"recopy\"]").hidden = !open || !config.source_set_id;
    panel.part("[data-ga-action=\"copy-from\"]").hidden = !open || activities.length === 0;

    renderBoard(panel);
    loadProposals(panel);

}

/*
|--------------------------------------------------------------------------
| Proposals queue (screen 4B, student-proposed mode only)
|--------------------------------------------------------------------------
*/

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

async function loadProposals(panel) {

    const box = panel.part("[data-ga-proposals]");

    if (!box) {
        return;
    }

    box.hidden = panel.detail.config?.formation_mode !== "proposed";

    if (box.hidden) {
        box.innerHTML = "";

        return;
    }

    const guard = beginLoad(box, renderDetailSkeleton("Loading the proposed groups…"), 0);

    try {
        const queue = await fetchActivityProposals(panel.id);

        if (guard.current()) {
            guard.finish();
            box.innerHTML = renderProposals(queue, canWrite(panel));
        }
    } catch (error) {
        console.error(error);

        if (guard.current()) {
            guard.finish();
            showLoadError(box, "the proposed groups", () => loadProposals(panel));
        }
    }

}

function renderProposals({ proposals, minSize, summary }, write) {

    const rows = proposals.map((p) => {
        const count = p.member_names.length;
        const chips = p.member_names.map((n) => `<span class="rounded-lg bg-brand-tint px-2.5 py-1 text-sm text-[#1A1C1E]">${escapeHtml(n)}</span>`).join("");
        const name = escapeHtml(p.name);
        const actions = write ? `
            <div class="flex flex-wrap gap-2 max-sm:w-full max-sm:*:flex-1">
                ${p.meetsMinimum ? `<button type="button" class="${SMALL} bg-brand text-white hover:bg-brand-dark" data-ga-action="approve" data-group="${p.id}" aria-label="Approve ${name}">Approve</button>` : ""}
                <button type="button" class="${SMALL} border border-brand text-brand hover:bg-brand-tint" data-ga-action="decline" data-group="${p.id}" aria-label="Decline ${name}">Decline</button>
            </div>` : "";

        return `
            <li class="flex flex-wrap items-start justify-between gap-3 border-t border-gray-100 py-3 first:border-t-0" data-ga-proposal="${p.id}">
                <div class="flex min-w-0 flex-col gap-1.5">
                    <span class="text-sm text-[#3D3D3D]"><b>${name}</b> proposed by ${escapeHtml(p.proposer)}</span>
                    <span class="flex flex-wrap items-center gap-1.5">${chips}${p.meetsMinimum ? badge(`${count}, minimum met`, "success") : badge(`${count}, below minimum ${minSize}`, "warning")}</span>
                    ${p.pending_names.length ? `<span class="text-xs text-[#646464]">Waiting for: ${p.pending_names.map(escapeHtml).join(", ")}</span>` : ""}
                </div>
                ${actions}
            </li>`;
    }).join("");

    const list = proposals.length
        ? `<ul class="flex flex-col">${rows}</ul>`
        : "<p class=\"text-sm text-[#646464]\">No proposals are waiting. Students propose groups while signup is open.</p>";

    const { belowMinimum, ungrouped } = summary;
    const facts = [
        belowMinimum.length ? badge(`${plural(belowMinimum.length, "group", "groups")} below minimum`, "warning") : "",
        ungrouped.length ? badge(`${plural(ungrouped.length, "student", "students")} ungrouped`, "warning") : "",
    ].join(" ");
    const after = belowMinimum.length || ungrouped.length ? `
        <div class="flex flex-col gap-2 rounded-xl border border-gray-200 bg-gray-50 p-3" data-ga-summary>
            <b class="text-sm text-[#1A1C1E]">Still to sort out</b>
            <span class="flex flex-wrap gap-1.5">${facts}</span>
            ${belowMinimum.length ? `<span class="text-xs text-[#646464]">Below minimum: ${belowMinimum.map((g) => escapeHtml(g.name)).join(", ")}</span>` : ""}
            <span class="text-xs text-[#646464]">Resolve them here, or <a class="font-semibold text-brand underline" href="#ga-board">place students by hand</a>.</span>
        </div>` : "";

    return `<div class="flex flex-col gap-3"><h4 class="font-heading text-xs font-semibold uppercase tracking-wide text-[#646464]">Proposed groups (${proposals.length})</h4>${list}${after}</div>`;

}

function leaderBadge(group) {

    const leader = group.leader?.final ? group.members.find((m) => m.student_user_id === group.leader.id) : null;

    return leader ? `<span data-ga-leader>${badge(`★ Leader: ${leader.name}`, "neutral")}</span>` : "";

}

function renderBoard(panel) {

    const { config, groups, ungrouped } = panel.detail;
    const write = canWrite(panel);
    const placed = groups.reduce((sum, g) => sum + g.members.length, 0);

    panel.part("[data-ga-source]").textContent = `${config.source_set_name ? `Copied from ${config.source_set_name}. Later edits to the set will not change this activity.` : "Groups made for this activity."} ${groups.length} ${groups.length === 1 ? "group" : "groups"}, ${placed} ${placed === 1 ? "student" : "students"}.`;

    panel.part("[data-ga-board]").innerHTML = renderGroupBoard({
        groups,
        ungrouped,
        set: config,
        canEdit: write,
        emptyText: "No groups have been added to this activity.",
        // An item's groups have no leader, deadline or freeze: plain cards.
        decorate: panel.item ? null : (group) => {
            // The instructor can set the leader at any time, frozen or not (D15).
            const setLeader = group.members.length > 0
                ? `<button type="button" class="${SMALL} text-brand hover:bg-brand-tint" data-group-action="set-leader" data-group="${group.id}" aria-label="Set the leader of ${escapeHtml(group.name)}">Set leader</button>`
                : "";

            const extended = group.extension ? `<span data-ga-extension>${badge(`Extended to ${formatDateTime(group.extension.until)}`, "neutral")}</span>` : "";
            const extend = `<button type="button" class="${SMALL} text-brand hover:bg-brand-tint" data-group-action="extend" data-group="${group.id}" aria-label="Extend the deadline of ${escapeHtml(group.name)}">Extend deadline</button>`;

            return group.frozen ? {
                locked: true,
                badge: badge("Frozen", "neutral") + leaderBadge(group) + extended,
                actions: setLeader + extend + `<button type="button" class="${SMALL} text-[#C33939] hover:bg-[#FDEDEC]" data-group-action="unlock" data-group="${group.id}" aria-label="Unlock ${escapeHtml(group.name)}">Unlock</button>`
                    + (ungrouped.length > 0 ? `<button type="button" class="${SMALL} text-brand hover:bg-brand-tint" data-group-action="late-member" data-group="${group.id}" aria-label="Add a late enrollee to ${escapeHtml(group.name)}">Add late enrollee</button>` : ""),
            } : { badge: leaderBadge(group) + extended, actions: setLeader + extend };
        },
    });

}

function announce(panel, message) {

    panel.part("[data-groups-live]").textContent = message;
    showToast(message, { tone: "success" });

}

/*
|--------------------------------------------------------------------------
| Switch and settings
|--------------------------------------------------------------------------
*/

const valueOf = (el) => (el.type === "checkbox" ? el.checked : el.type === "number" ? Number(el.value) : el.value);

const stored = (name, value) => (FLAGS.includes(name) ? (value ? 1 : 0) : value);

function putBack(panel, name) {

    const el = panel.part("[data-ga-settings]").elements[name];

    if (el) {
        setValue(el, panel.detail.config[name]);
    }

    showWindow(panel);

}

/** The server stores "2030-01-01 08:00:00"; a datetime-local input wants "2030-01-01T08:00". */
function setValue(el, value) {

    if (el.type === "checkbox") {
        el.checked = Number(value) === 1;
    } else if (el.type === "datetime-local") {
        el.value = String(value ?? "").replace(" ", "T").slice(0, 16);
    } else {
        el.value = String(value ?? "");
    }

}

// Only the modes where students act on their own need a window.
function showWindow(panel) {

    panel.part("[data-ga-signup-window]").hidden = !["self_signup", "proposed"].includes(panel.detail.config.formation_mode);

}

/** Saves one setting. A policy change after a freeze first answers confirm_required: ask, then send it confirmed. */
async function saveSetting(panel, name, value, confirmed) {

    const form = panel.part("[data-ga-settings]");
    let result;

    try {
        result = await send(panel, "PUT", "/settings", { changes: { [name]: value }, confirmed });
    } catch (error) {
        console.error(error);
        putBack(panel, name);
        showToast(NETWORK, { tone: "danger" });

        return { ok: false, body: {} };
    }

    const errors = result.body.errors ?? {};

    if (result.ok) {
        panel.detail.config[name] = stored(name, value);
        putBack(panel, name);
        applyFieldErrors(form, {});
        announce(panel, "Group activity settings saved");

        if (name === "formation_mode") {
            loadProposals(panel);
        }
    } else if (errors.confirm_required && !confirmed) {
        putBack(panel, name);
        panel.pending = { kind: "policy", name, value };
        openConfirm(panel, "ga-policy-confirm", `${errors.confirm_required} ${value === "solo"
            ? "Students without a group will be able to work alone and hand in their own work."
            : "Students without a group will have to join one before they can hand in work. Work already handed in stays valid."}`);
    } else {
        putBack(panel, name);
        const key = Object.keys(errors).find((k) => form.querySelector(`[data-field="${k}"]`));

        if (key) {
            applyFieldErrors(form, { [key]: errors[key] });
        } else {
            showToast(result.body.message ?? FAILED, { tone: "danger" });
        }
    }

    return result;

}

function onToggle(panel, toggle) {

    const attached = panel.detail?.config != null;

    // The switch moves only once the server agrees.
    toggle.checked = attached;

    if (!canWrite(panel)) {
        return;
    }

    if (!attached) {
        const { form } = parts(panel, "ga-attach-dialog");
        const options = [new Option("Start from scratch", ""), ...panel.detail.sets.map((s) => new Option(`${s.name} (${s.group_count} ${s.group_count === 1 ? "group" : "groups"})`, String(s.id)))];

        form.elements.set_id.replaceChildren(...options);
        form.elements.set_id.value = panel.detail.sets[0] ? String(panel.detail.sets[0].id) : "";

        if (form.elements.completion_mode) {
            form.elements.completion_mode.value = "each"; // as the item behaves without groups
        }
        applyFieldErrors(form, {});
        panel.dialogs["ga-attach-dialog"].open(toggle);
    } else {
        openConfirm(panel, "ga-detach-confirm", null, toggle);
    }

}

/*
|--------------------------------------------------------------------------
| Dialogs
|--------------------------------------------------------------------------
*/

function parts(panel, id) {

    const el = panel.part(`#${id}`);

    return { el, form: el.querySelector("form"), title: el.querySelector("[data-dialog-title]") };

}

function openConfirm(panel, id, text = null, opener = null, title = null) {

    const { el, form, title: heading } = parts(panel, id);

    applyFieldErrors(form, {});

    if (text !== null) {
        el.querySelector("[data-dialog-text]").textContent = text;
    }

    if (title !== null) {
        heading.textContent = title;
    }

    panel.dialogs[id].open(opener);

}

function openDialog(panel, id, title, opener) {

    const { form, title: heading } = parts(panel, id);

    form.reset();
    applyFieldErrors(form, {});
    heading.textContent = title;
    panel.dialogs[id].open(opener);

    return form;

}

const close = (panel, id) => panel.dialogs[id].close({ instant: true });

const requireReason = (form, data) => {
    const reason = String(data.get("reason") ?? "").trim();

    if (reason === "") {
        applyFieldErrors(form, { reason: REASON });
    }

    return reason;
};

function onAction(panel, button) {

    const groupId = Number(button.dataset.group);
    const group = panel.detail.groups.find((g) => g.id === groupId);

    switch (button.dataset.groupAction) {
        case "add-group": {
            const form = openDialog(panel, "ga-name-dialog", "Add group", button);
            form.elements.name.value = `Team ${panel.detail.groups.length + 1}`;
            form.elements.name.select();
            panel.pending = { kind: "add" };
            break;
        }
        case "rename-group": {
            const form = openDialog(panel, "ga-name-dialog", `Rename ${group.name}`, button);
            form.elements.name.value = group.name;
            form.elements.name.select();
            panel.pending = { kind: "rename", groupId };
            break;
        }
        case "delete-group":
            panel.pending = { kind: "delete", groupId };
            openConfirm(panel, "ga-delete-confirm", group.members.length > 0
                ? `Its ${group.members.length} ${group.members.length === 1 ? "member goes" : "members go"} back to Ungrouped.`
                : "It has no members.", button, `Delete ${group.name}?`);
            break;
        case "move": {
            const studentId = Number(button.dataset.student);
            const form = openDialog(panel, "ga-move-dialog", `Move ${studentById(panel.detail, studentId).name}`, button);
            fillMoveSelect(form.elements.to_group, panel.detail.groups, groupOf(panel.detail, studentId), Number(panel.detail.config.max_size), (g) => g.frozen);
            panel.pending = { kind: "move", studentId };
            break;
        }
        case "unlock":
            openDialog(panel, "ga-unlock-dialog", `Unlock ${group.name}?`, button);
            panel.pending = { kind: "unlock", groupId };
            break;
        case "set-leader": {
            const form = openDialog(panel, "ga-leader-dialog", `Set the leader of ${group.name}`, button);
            form.elements.student_user_id.replaceChildren(...group.members.map((s) => new Option(s.name, String(s.student_user_id), false, s.student_user_id === group.leader?.id)));
            panel.pending = { kind: "leader", groupId };
            break;
        }
        case "extend": {
            const form = openDialog(panel, "ga-extend-dialog", `Extend the deadline for ${group.name}`, button);
            const current = group.extension?.until ?? panel.detail.dueDate;

            panel.part("[data-ga-extend-current]").textContent = current
                ? `The current deadline for this group is ${formatDateTime(current)}.`
                : "This assignment has no due date yet.";
            form.elements.until.value = "";
            panel.pending = { kind: "extend", groupId };
            break;
        }
        case "late-member": {
            const form = openDialog(panel, "ga-late-dialog", `Add a late enrollee to ${group.name}`, button);
            form.elements.student_user_id.replaceChildren(...panel.detail.ungrouped.map((s) => new Option(s.name, String(s.student_user_id))));
            panel.pending = { kind: "late", groupId };
            break;
        }
    }

}

/** Approve needs no confirmation: it only makes the proposed group a normal one, which the instructor can still edit. */
async function approve(panel, button) {

    const name = button.closest("[data-ga-proposal]").querySelector("b").textContent;

    button.disabled = true;

    try {
        const result = await send(panel, "POST", `/groups/${button.dataset.group}/approve`);

        if (result.ok) {
            announce(panel, `${name} was approved`);
            await load(panel);

            return;
        }

        showToast(result.body.message ?? FAILED, { tone: "danger" });
    } catch (error) {
        console.error(error);
        showToast(NETWORK, { tone: "danger" });
    }

    button.disabled = false;

}

async function moveStudent(panel, studentId, toGroupId) {

    const { result, student, to, unchanged } = await applyMove(panel.detail, studentId, toGroupId, (method, path, body) => send(panel, method, path, body));

    if (result.ok && !unchanged) {
        renderBoard(panel);
        announce(panel, to ? `${student.name} moved to ${to.name}` : `${student.name} is no longer in a group`);
    }

    return result;

}

/*
|--------------------------------------------------------------------------
| Wiring
|--------------------------------------------------------------------------
*/

function bind(panel) {

    const { root } = panel;

    panel.part("[data-ga-toggle]").addEventListener("change", (event) => onToggle(panel, event.target));

    panel.part("[data-ga-settings]").addEventListener("change", (event) => {
        const el = event.target;

        if (canWrite(panel) && FIELDS.includes(el.name)) {
            saveSetting(panel, el.name, valueOf(el), false);
        }
    });

    panel.part("[data-ga-settings]").addEventListener("submit", (event) => event.preventDefault());

    root.addEventListener("click", (event) => {
        const action = event.target.closest("[data-ga-action]");
        const button = event.target.closest("[data-group-action]");

        if (!canWrite(panel)) {
            return;
        }

        if (action?.dataset.gaAction === "recopy") {
            openConfirm(panel, "ga-recopy-confirm", `This activity's groups are replaced by a fresh copy of ${panel.detail.config.source_set_name ?? "the set"}'s groups.`, action);
        } else if (action?.dataset.gaAction === "copy-from") {
            const form = openDialog(panel, "ga-copy-dialog", "Copy groups from another activity", action);
            const key = panel.item ? "learning_item_id" : "assessment_id";
            form.elements[`from_${key}`].replaceChildren(...panel.detail.activities.map((a) => new Option(a.title, String(a[key]))));
        } else if (action?.dataset.gaAction === "approve") {
            approve(panel, action);
        } else if (action?.dataset.gaAction === "decline") {
            panel.pending = { kind: "decline", groupId: Number(action.dataset.group), name: action.closest("[data-ga-proposal]").querySelector("b").textContent };
            openDialog(panel, "ga-decline-dialog", `Decline ${panel.pending.name}?`, action);
        } else if (button && root.contains(button)) {
            onAction(panel, button);
        }
    });

    bindDragMoves(root, async (studentId, toGroupId) => {
        if (!canWrite(panel)) {
            return;
        }

        try {
            const result = await moveStudent(panel, studentId, toGroupId);

            if (!result.ok) {
                showToast(result.body.message ?? FAILED, { tone: "danger" });
            }
        } catch (error) {
            console.error(error);
            showToast(NETWORK, { tone: "danger" });
        }
    });

    const form = (id) => panel.part(`#${id} form`);
    // The learning-item card has no unlock, late, extension, leader, decline or policy dialogs.
    const on = (id, handler) => form(id) && onSubmit(form(id), handler);
    const reload = async (id, message) => {
        close(panel, id);
        announce(panel, message);
        await load(panel);
    };

    on("ga-attach-dialog", async (data) => {
        const setId = String(data.get("set_id") ?? "");
        const chosen = { set_id: setId === "" ? null : Number(setId) };
        const result = await send(panel, "POST", "/attach", panel.item ? { ...chosen, completion_mode: String(data.get("completion_mode") ?? ""), settings: {} } : { ...chosen, settings: {} });

        if (result.ok) {
            await reload("ga-attach-dialog", panel.item ? "This item now uses groups" : "This is now a group activity");
        }

        return result;
    });

    on("ga-detach-confirm", async () => {
        const result = await send(panel, "DELETE", "");

        if (result.ok) {
            await reload("ga-detach-confirm", panel.item ? "This item no longer uses groups" : "This assignment no longer uses groups");
        }

        return result;
    });

    on("ga-policy-confirm", async () => {
        const { name, value } = panel.pending;
        const result = await saveSetting(panel, name, value, true);

        if (result.ok) {
            close(panel, "ga-policy-confirm");
        }

        return result;
    });

    on("ga-recopy-confirm", async () => {
        const result = await send(panel, "POST", "/recopy");

        if (result.ok) {
            await reload("ga-recopy-confirm", "The groups were copied again from the set");
        }

        return result;
    });

    on("ga-copy-dialog", async (data) => {
        const key = panel.item ? "from_learning_item_id" : "from_assessment_id";
        const result = await send(panel, "POST", "/copy-from", { [key]: Number(data.get(key)) });

        if (result.ok) {
            await reload("ga-copy-dialog", "The groups were copied from the other activity");
        }

        return result;
    });

    on("ga-name-dialog", async (data) => {
        const name = String(data.get("name") ?? "").trim();
        const { kind, groupId } = panel.pending;
        const result = kind === "add"
            ? await send(panel, "POST", "/groups", { name })
            : await send(panel, "PUT", `/groups/${groupId}`, { name });

        if (result.ok) {
            if (kind === "add") {
                panel.detail.groups.push({ id: Number(result.body.id), name, frozen: false, members: [] });
            } else {
                panel.detail.groups.find((g) => g.id === groupId).name = name;
            }

            renderBoard(panel);
            close(panel, "ga-name-dialog");
            announce(panel, kind === "add" ? `${name} was added` : `The group is now called ${name}`);
        }

        return result;
    });

    on("ga-delete-confirm", async () => {
        const { groupId } = panel.pending;
        const group = panel.detail.groups.find((g) => g.id === groupId);
        const result = await send(panel, "DELETE", `/groups/${groupId}`);

        if (result.ok) {
            panel.detail.groups = panel.detail.groups.filter((g) => g.id !== groupId);
            panel.detail.ungrouped = [...panel.detail.ungrouped, ...group.members].sort(byName);
            renderBoard(panel);
            close(panel, "ga-delete-confirm");
            announce(panel, `${group.name} was deleted`);
        }

        return result;
    });

    on("ga-move-dialog", async (data) => {
        const value = String(data.get("to_group") ?? "");
        const { studentId } = panel.pending;
        const result = await moveStudent(panel, studentId, value === "" ? null : Number(value));

        if (result.ok) {
            close(panel, "ga-move-dialog");
            panel.part(`[data-group-action="move"][data-student="${studentId}"]`)?.focus();
        }

        return result;
    });

    on("ga-unlock-dialog", async (data) => {
        const reason = requireReason(form("ga-unlock-dialog"), data);

        if (reason === "") {
            return null;
        }

        const { groupId } = panel.pending;
        const group = panel.detail.groups.find((g) => g.id === groupId);
        const result = await send(panel, "POST", `/groups/${groupId}/unlock`, { reason });

        if (result.ok) {
            group.frozen = false;
            panel.detail.flags.frozenGroups = panel.detail.groups.some((g) => g.frozen);
            render(panel);
            close(panel, "ga-unlock-dialog");
            announce(panel, `${group.name} is unlocked`);
        }

        return result;
    });

    on("ga-late-dialog", async (data) => {
        const reason = requireReason(form("ga-late-dialog"), data);

        if (reason === "") {
            return null;
        }

        const { groupId } = panel.pending;
        const studentId = Number(data.get("student_user_id"));
        const group = panel.detail.groups.find((g) => g.id === groupId);
        const student = studentById(panel.detail, studentId);
        const result = await send(panel, "POST", `/groups/${groupId}/late-members`, { student_user_id: studentId, reason });

        if (result.ok) {
            panel.detail.ungrouped = panel.detail.ungrouped.filter((s) => s.student_user_id !== studentId);
            group.members = [...group.members, student].sort(byName);
            renderBoard(panel);
            close(panel, "ga-late-dialog");
            announce(panel, `${student.name} was added to ${group.name}`);
        }

        return result;
    });

    on("ga-extend-dialog", async (data) => {
        const until = String(data.get("until") ?? "");
        const reason = requireReason(form("ga-extend-dialog"), data);

        if (until === "" || reason === "") {
            applyFieldErrors(form("ga-extend-dialog"), { ...(until === "" ? { until: "Choose the new date and time." } : {}), ...(reason === "" ? { reason: REASON } : {}) });

            return null;
        }

        const { groupId } = panel.pending;
        const group = panel.detail.groups.find((g) => g.id === groupId);
        const result = await send(panel, "POST", `/groups/${groupId}/extension`, { until, reason });

        if (result.ok) {
            group.extension = { until: `${until.replace("T", " ")}:00`, reason };
            renderBoard(panel);
            close(panel, "ga-extend-dialog");
            announce(panel, `${group.name} has until ${formatDateTime(group.extension.until)}`);
        }

        return result;
    });

    on("ga-decline-dialog", async (data) => {
        const reason = requireReason(form("ga-decline-dialog"), data);

        if (reason === "") {
            return null;
        }

        const { groupId, name } = panel.pending;
        const result = await send(panel, "POST", `/groups/${groupId}/decline`, { reason });

        if (result.ok) {
            await reload("ga-decline-dialog", `${name} was declined`);
        }

        return result;
    });

    on("ga-leader-dialog", async (data) => {
        const studentId = Number(data.get("student_user_id"));
        const { groupId } = panel.pending;
        const group = panel.detail.groups.find((g) => g.id === groupId);
        const result = await send(panel, "POST", `/groups/${groupId}/leader`, { student_user_id: studentId });

        if (result.ok) {
            group.leader = { id: studentId, final: true };
            renderBoard(panel);
            close(panel, "ga-leader-dialog");
            announce(panel, `${studentById(panel.detail, studentId).name} leads ${group.name}`);
        }

        return result;
    });

}
