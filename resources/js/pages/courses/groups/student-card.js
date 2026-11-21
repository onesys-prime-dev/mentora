import { applyFieldErrors, createDialog } from "../../../shared/dialog.js";
import { showToast } from "../../../shared/toast.js";
import { beginLoad } from "../../../shared/spa/load-guard.js";
import { showLoadError } from "../../../shared/spa/error-state.js";

import { fetchMyGroup, sendMyGroup } from "./api.js";
import { FAILED, NETWORK, onSubmit } from "./board.js";
import { escapeHtml, formatDateTime } from "./detail.js";

/*
|--------------------------------------------------------------------------
| The student's "Group activity" card (screens 3A, 3C, 3E)
|--------------------------------------------------------------------------
| The server draws the container, the alert templates and the leave
| confirmation (assessments/partials/student-group-card.php); this fills it
| from the my-group JSON. A student only gets their own group (and the open
| groups while they can join), so that is all it can show. Not a group
| activity: the card removes itself and leaves no trace.
|
| On a learning item (data-learning-item-id, screen 3D) the card shows the
| roster and the completion status: each partner's own status under "each",
| or the group's one status under "any", where the footer's Mark complete
| button reads "Mark complete for the group". Being ungrouped never blocks.
*/

const BUTTON = "inline-flex items-center justify-center rounded-full px-5 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50 pointer-coarse:min-h-11 max-sm:w-full";
const PRIMARY = `${BUTTON} bg-brand text-white hover:bg-brand-dark`;
const SECONDARY = `${BUTTON} border border-brand bg-white text-brand hover:bg-brand-tint`;
const LABEL = "font-heading text-xs font-semibold uppercase tracking-wide text-[#646464]";

export async function initializeStudentGroupCard(root) {

    if (!root) {
        return;
    }

    const item = root.dataset.learningItemId !== undefined;
    const card = { root, item, kind: item ? "learning-items" : "assessments", id: Number(item ? root.dataset.learningItemId : root.dataset.assessmentId), switching: false, state: null, dialog: null, dialogs: {}, lastMessage: null };
    const confirm = root.querySelector("#sg-leave-confirm");

    bindFormDialog(card, "rename", (data) => ({ name: String(data.get("name") ?? "").trim() }), (body) => `Your group is now called ${body.name}.`);
    bindFormDialog(card, "propose", (data) => ({ name: String(data.get("name") ?? "").trim(), invitee_ids: data.getAll("invitee_ids").map(Number) }), (body) => `You proposed ${body.name}. Your classmates have been invited.`);

    if (confirm) {
        card.dialog = createDialog(confirm);
        onSubmit(confirm.querySelector("form"), async () => {
            const done = await change(card, "leave", {}, "You left the group.");

            if (done) {
                card.dialog.close({ instant: true });

                return null;
            }

            return { ok: false, body: { message: card.lastMessage } };
        });
    }

    root.addEventListener("click", (event) => onClick(card, event));

    if (item) {
        // completion.js says when this student marked the item complete; under "any" that changed the whole group.
        const refresh = () => (root.isConnected ? load(card, { quiet: true }) : document.removeEventListener("learning-item:completed", refresh));

        document.addEventListener("learning-item:completed", refresh);
    }

    await load(card);

}

const part = (card, selector) => card.root.querySelector(selector);

/** One of the server's alert components (warning, info) with this text. */
function alertHtml(card, tone, text) {

    const holder = document.createElement("div");

    holder.append(part(card, `template[data-sg-alert="${tone}"]`).content.cloneNode(true));
    holder.querySelector("p:last-child").textContent = text;

    return holder.innerHTML;

}

async function load(card, { quiet = false } = {}) {

    const body = part(card, "[data-sg-body]");

    if (!quiet) {
        card.root.hidden = false;
        body.innerHTML = "";
    }

    const skeleton = "<div class=\"flex animate-pulse flex-col gap-3\" role=\"status\" aria-busy=\"true\" data-sg-skeleton><span class=\"sr-only\">Loading your group…</span><div class=\"h-5 w-40 rounded bg-gray-200\"></div><div class=\"h-16 rounded-xl bg-gray-200\"></div></div>";
    const guard = beginLoad(body, skeleton, quiet ? 150 : 0);

    try {
        const state = await fetchMyGroup(card.id, card.kind);

        if (!guard.current()) {
            return;
        }

        guard.finish();
        card.state = state;
        render(card);
    } catch (error) {
        console.error(error);

        if (guard.current()) {
            guard.finish();
            showLoadError(body, "your group", () => load(card));
        }
    }

}

/** The signup window in words: "Signup closes 12 Dec 2026, 5:00 PM." / "Signup is closed." / "Signup opens ...". */
function windowText(signup) {

    if (signup.mode !== "self_signup") {
        return "";
    }

    if (signup.isOpen) {
        return signup.closesAt ? `Signup closes ${formatDateTime(signup.closesAt)}.` : "";
    }

    return signup.opensAt && new Date(signup.opensAt.replace(" ", "T")) > new Date() ? `Signup opens ${formatDateTime(signup.opensAt)}.` : "Signup is closed.";

}

function render(card) {

    const state = card.state;

    if (!state.isGroupActivity) {
        card.root.remove();

        return;
    }

    part(card, "[data-sg-body]").innerHTML = state.myGroup ? renderMine(card, state) : renderNotInGroup(card, state);

}

function candidateRow(group, verb) {

    const members = group.member_names.length ? ` · ${group.member_names.map(escapeHtml).join(", ")}` : "";
    const action = group.full
        ? `<button type="button" class="${SECONDARY}" disabled>Full</button>`
        : `<button type="button" class="${PRIMARY}" data-sg-action="${verb}" data-group-id="${group.id}" data-group-name="${escapeHtml(group.name)}">${verb === "join" ? "Join" : "Switch here"}</button>`;

    return `
        <li class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 py-3 first:border-t-0" data-sg-candidate>
            <span class="min-w-0 text-sm text-[#3D3D3D]"><b>${escapeHtml(group.name)}</b> · ${group.members_count} / ${group.max}${members}</span>
            ${action}
        </li>`;

}

const candidateList = (state, verb) => `<ul class="flex flex-col" data-sg-candidates>${state.candidates.map((g) => candidateRow(g, verb)).join("")}</ul>`;

const errorSlot = "<p class=\"text-xs font-medium text-[#C33939]\" role=\"alert\" data-sg-error hidden></p>";

function renderNotInGroup(card, state) {

    const when = windowText(state.signup);
    let notice;

    if (card.item) {
        notice = alertHtml(card, "info", `You're not in a group yet. You can still complete this item on your own. ${when}`.trim());
    } else if (!state.inPool) {
        notice = alertHtml(card, "info", "This activity is not assigned to you, so you do not need a group.");
    } else if (state.blocked) {
        notice = alertHtml(card, "warning", `You're not in a group yet. You can't submit until you join one. ${when}`.trim());
    } else {
        notice = alertHtml(card, "info", `You may work alone or join a group. ${when}`.trim());
    }

    let list = "";

    if (state.inPool && state.candidates.length) {
        list = `<div class="flex flex-col gap-1"><h3 class="${LABEL}">Open groups</h3>${candidateList(state, "join")}</div>`;
    } else if (state.inPool && state.signup.isOpen) {
        list = "<p class=\"text-sm text-[#646464]\">There are no open groups right now.</p>";
    }

    return `${notice}${invitationList(state)}${list}${proposeButton(state)}${errorSlot}`;

}

/** Invitations to a proposed group, each with Accept and Decline (mode D). */
function invitationList(state) {

    const invitations = state.pendingInvitations ?? [];

    if (!invitations.length) {
        return "";
    }

    const rows = invitations.map((i) => `
        <li class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 py-3 first:border-t-0" data-sg-invitation>
            <span class="min-w-0 text-sm text-[#3D3D3D]"><b>${escapeHtml(i.name)}</b> · ${escapeHtml(i.proposer)} invited you${i.members.length ? ` · ${i.members.map(escapeHtml).join(", ")}` : ""}</span>
            <span class="flex flex-wrap gap-2 max-sm:w-full max-sm:flex-col">
                <button type="button" class="${PRIMARY}" data-sg-action="accept-invite" data-group-id="${i.groupId}" data-group-name="${escapeHtml(i.name)}">Accept</button>
                <button type="button" class="${SECONDARY}" data-sg-action="decline-invite" data-group-id="${i.groupId}" data-group-name="${escapeHtml(i.name)}">Decline</button>
            </span>
        </li>`).join("");

    return `<div class="flex flex-col gap-1"><h3 class="${LABEL}">Invitations</h3><ul class="flex flex-col">${rows}</ul></div>`;

}

const proposeButton = (state) => (state.canPropose ? `<div><button type="button" class="${SECONDARY}" data-sg-action="propose">Propose a new group</button></div>` : "");

const memberName = (group, id) => group.members.find((m) => m.id === id)?.name ?? "a former member";

/** "Leader: Ana Cruz", or the leading nominee with "3 of 4 agreed" and Agree, plus Nominate while the group may vote. */
function leaderRow(group) {

    const { leader } = group;
    const total = group.members.length;
    const top = [...leader.votes].sort((a, b) => b.count - a.count)[0] ?? null;
    const lines = [];

    if (leader.final && leader.id !== null) {
        lines.push(`<span class="rounded-full bg-brand-tint px-3 py-1 text-xs font-bold text-brand">Leader: ${escapeHtml(memberName(group, leader.id))}</span>`);
    } else if (top) {
        const name = escapeHtml(memberName(group, top.candidateId));
        const agree = group.canVote && leader.myVote !== top.candidateId
            ? `<button type="button" class="${SECONDARY}" data-sg-action="agree" data-candidate="${top.candidateId}" data-candidate-name="${name}">Agree</button>`
            : "";

        lines.push(`<div class="flex flex-wrap items-center justify-between gap-3"><span class="text-sm text-[#3D3D3D]">Nominated: ${name} · ${top.count} of ${total} agreed</span>${agree}</div>`);
    } else {
        lines.push("<span class=\"text-sm text-[#646464]\">No leader yet. Nominate a member; more than half of the group must agree.</span>");
    }

    if (group.canVote) {
        const options = group.members.map((m) => `<option value="${m.id}"${m.id === leader.myVote ? " selected" : ""}>${escapeHtml(m.name)}</option>`).join("");

        lines.push(`
            <div class="flex flex-wrap items-center gap-2 max-sm:flex-col max-sm:items-stretch">
                <label class="sr-only" for="sg-nominee-${group.id}">Nominate a leader</label>
                <select id="sg-nominee-${group.id}" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-[#3D3D3D] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11" data-sg-nominee>${options}</select>
                <button type="button" class="${SECONDARY}" data-sg-action="nominate">Nominate</button>
            </div>`);
    }

    return `<div class="flex flex-col gap-2" data-sg-leader><h3 class="${LABEL}">Leader</h3>${lines.join("")}</div>`;

}

function renderMine(card, state) {

    const group = state.myGroup;
    const chips = group.members.map((m) => `<span class="rounded-lg bg-brand-tint px-2.5 py-1 text-sm text-[#3D3D3D]" role="listitem" data-sg-member>${memberChip(m)}</span>`).join("");
    let notice = "";
    let actions = "";

    const proposed = group.status === "proposed";

    if (group.frozen) {
        notice = alertHtml(card, "warning", "Your group has submitted. Membership is frozen. Ask your instructor if someone must change.");
    } else {
        const buttons = [];

        if (group.canRename) {
            buttons.push(`<button type="button" class="${SECONDARY}" data-sg-action="rename">Rename group</button>`);
        }

        if (state.candidates.length) {
            buttons.push(`<button type="button" class="${SECONDARY}" data-sg-action="toggle-switch" aria-expanded="${card.switching}">Switch group</button>`);
        }

        if (group.canLeave) {
            buttons.push(`<button type="button" class="${SECONDARY}" data-sg-action="leave">Leave group</button>`);
        } else if (group.canLeaveReason) {
            notice = `<p class="text-xs text-[#646464]" data-sg-reason>${escapeHtml(group.canLeaveReason)}</p>`;
        }

        actions = buttons.length ? `<div class="flex flex-wrap gap-3 max-sm:flex-col">${buttons.join("")}</div>` : "";
    }

    const switchList = card.switching && !group.frozen && state.candidates.length
        ? `<div class="flex flex-col gap-1"><h3 class="${LABEL}">Switch to</h3>${candidateList(state, "switch")}</div>`
        : "";

    const pending = group.proposal?.pending ?? [];
    const waiting = proposed
        ? `<div class="flex flex-col gap-2" data-sg-waiting>${alertHtml(card, "info", "Waiting for your instructor to approve this group.")}${pending.length ? `<p class="text-sm text-[#646464]">Invited: ${pending.map(escapeHtml).join(", ")}</p>` : ""}</div>`
        : "";

    const extended = group.extension
        ? `<div data-sg-extension>${alertHtml(card, "info", `Deadline extended for your group to ${formatDateTime(group.extension.until)}. Reason: ${group.extension.reason}`)}</div>`
        : "";

    return `
        <h3 class="font-heading text-base font-semibold text-brand">Your group: ${escapeHtml(group.name)}</h3>
        <div class="flex flex-wrap gap-2" role="list" aria-label="Members of ${escapeHtml(group.name)}">${chips}</div>
        ${card.item ? completionRow(card, state) : ""}${extended}${waiting}${proposed || !group.leader ? "" : leaderRow(group)}${notice}${actions}${switchList}${errorSlot}`;

}

/** "You (Dee Park)" for me; under "each" on an item, each member's own status after a dot. */
function memberChip(member) {

    const name = member.isMe ? `You (${escapeHtml(member.name)})` : escapeHtml(member.name);

    if (member.completed === undefined) {
        return name;
    }

    return `${name} · ${member.completed ? "<span class=\"font-semibold text-[#5C9F72]\">Done</span>" : "<span class=\"text-[#646464]\">Not done yet</span>"}`;

}

/** The item's completion status for the group, and the footer button's words under "any" (D13). */
function completionRow(card, state) {

    const group = state.myGroup;
    const any = state.completionMode === "any";
    const button = document.querySelector(`[data-mark-complete][data-learning-item-id="${card.id}"][data-completed="0"] [data-mark-complete-label]`);

    if (button) {
        button.textContent = any ? "Mark complete for the group" : "Mark as Completed";
    }

    if (!any) {
        return "<p class=\"text-sm text-[#646464]\" data-sg-completion>Each member marks this done on their own.</p>";
    }

    if (group.completed) {
        return `<div data-sg-completion>${alertHtml(card, "info", group.completedBy ? `Marked complete by ${group.completedBy} for the group.` : "Completed for the group.")}</div>`;
    }

    return "<p class=\"text-sm text-[#646464]\" data-sg-completion>Any member can mark this done for the group.</p>";

}

function announce(card, message) {

    part(card, "[data-sg-live]").textContent = message;

}

/** Sends a change; on success toasts and refreshes the card, on a refusal shows its message and keeps the state. Resolves true when done. */
async function change(card, path, body, success) {

    card.lastMessage = null;

    try {
        const result = await sendMyGroup(card.id, path, body, card.kind);

        if (result.ok) {
            card.switching = false;
            showToast(success, { tone: "success" });
            announce(card, success);
            await load(card, { quiet: true });

            return true;
        }

        card.lastMessage = result.body.message ?? FAILED;
    } catch (error) {
        console.error(error);
        card.lastMessage = NETWORK;
    }

    showToast(card.lastMessage, { tone: "danger" });
    announce(card, card.lastMessage);
    await load(card, { quiet: true });

    const box = part(card, "[data-sg-error]");

    if (box) {
        box.textContent = card.lastMessage;
        box.hidden = false;
    }

    return false;

}

async function onClick(card, event) {

    const button = event.target.closest("[data-sg-action]");

    if (!button || button.disabled) {
        return;
    }

    const action = button.dataset.sgAction;

    if (action === "toggle-switch") {
        card.switching = !card.switching;
        render(card);

        return;
    }

    if (action === "leave") {
        const open = card.state.signup.isOpen;

        part(card, "#sg-leave-confirm [data-dialog-text]").textContent = `You will leave ${card.state.myGroup.name}. ${open ? "You can join another group while signup is open." : "Signup is closed, so you may not be able to join another group."}`;
        applyFieldErrors(part(card, "#sg-leave-confirm form"), {});
        card.dialog.open(button);

        return;
    }

    if (action === "rename") {
        openFormDialog(card, "rename", button).elements.name.value = card.state.myGroup.name;

        return;
    }

    if (action === "propose") {
        const { minSize, maxSize } = card.state.signup;
        const form = openFormDialog(card, "propose", button);
        const invitable = card.state.invitable ?? [];

        form.querySelector("[data-sg-size-hint]").textContent = minSize === maxSize ? `Groups have ${minSize} members, you included.` : `Groups have ${minSize} to ${maxSize} members, you included.`;
        form.querySelector("[data-sg-invitees]").innerHTML = invitable.length
            ? invitable.map((s) => `<label class="flex items-center gap-2 py-1 text-sm text-[#3D3D3D] pointer-coarse:min-h-11"><input type="checkbox" name="invitee_ids" value="${s.id}" class="h-4 w-4 rounded border-gray-300 text-brand focus-visible:ring-2 focus-visible:ring-brand/50"> ${escapeHtml(s.name)}</label>`).join("")
            : "<p class=\"text-sm text-[#646464]\">Nobody else is free to invite right now. You can still propose the group on your own.</p>";

        return;
    }

    if (action === "agree" || action === "nominate") {
        const select = part(card, "[data-sg-nominee]");
        const candidate = action === "agree" ? Number(button.dataset.candidate) : Number(select.value);
        const name = action === "agree" ? button.dataset.candidateName : select.selectedOptions[0]?.textContent ?? "";

        button.disabled = true;
        await change(card, "vote", { candidate_id: candidate }, action === "agree" ? `You agreed to ${name} as leader.` : `You nominated ${name} as leader.`);

        return;
    }

    const name = button.dataset.groupName;
    const groupId = Number(button.dataset.groupId);

    button.disabled = true;

    if (action === "accept-invite" || action === "decline-invite") {
        const accept = action === "accept-invite";

        await change(card, `invitations/${groupId}/${accept ? "accept" : "decline"}`, {}, accept ? `You joined ${name}.` : `You declined the invitation to ${name}.`);

        return;
    }

    await change(card, action, { group_id: groupId }, action === "join" ? `You joined ${name}.` : `You switched to ${name}.`);

}

function openFormDialog(card, key, opener) {

    const form = part(card, `#sg-${key}-dialog form`);

    form.reset();
    applyFieldErrors(form, {});
    card.dialogs[key].open(opener);

    return form;

}

/** The rename and propose dialogs: send what the form holds; done closes, toasts, announces and refreshes; a refusal stays in the dialog. */
function bindFormDialog(card, key, read, success) {

    const el = card.root.querySelector(`#sg-${key}-dialog`);

    if (!el) {
        return;
    }

    card.dialogs[key] = createDialog(el);

    onSubmit(el.querySelector("form"), async (data) => {
        const body = read(data);
        const result = await sendMyGroup(card.id, key, body, card.kind);

        if (result.ok) {
            const message = success(body);

            card.dialogs[key].close({ instant: true });
            showToast(message, { tone: "success" });
            announce(card, message);
            await load(card, { quiet: true });
        }

        return result;
    });

}
