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
*/

const BUTTON = "inline-flex items-center justify-center rounded-full px-5 py-2 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50 pointer-coarse:min-h-11 max-sm:w-full";
const PRIMARY = `${BUTTON} bg-brand text-white hover:bg-brand-dark`;
const SECONDARY = `${BUTTON} border border-brand bg-white text-brand hover:bg-brand-tint`;
const LABEL = "font-heading text-xs font-semibold uppercase tracking-wide text-[#646464]";

export async function initializeStudentGroupCard(root) {

    if (!root) {
        return;
    }

    const card = { root, id: Number(root.dataset.assessmentId), switching: false, state: null, dialog: null, lastMessage: null };
    const confirm = root.querySelector("#sg-leave-confirm");

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
        const state = await fetchMyGroup(card.id);

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

    if (!state.inPool) {
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

    return `${notice}${list}${errorSlot}`;

}

function renderMine(card, state) {

    const group = state.myGroup;
    const chips = group.members.map((m) => `<span class="rounded-lg bg-brand-tint px-2.5 py-1 text-sm text-[#3D3D3D]" role="listitem" data-sg-member>${escapeHtml(m.name)}</span>`).join("");
    let notice = "";
    let actions = "";

    if (group.frozen) {
        notice = alertHtml(card, "warning", "Your group has submitted. Membership is frozen. Ask your instructor if someone must change.");
    } else {
        const buttons = [];

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

    return `
        <h3 class="font-heading text-base font-semibold text-brand">Your group: ${escapeHtml(group.name)}</h3>
        <div class="flex flex-wrap gap-2" role="list" aria-label="Members of ${escapeHtml(group.name)}">${chips}</div>
        ${notice}${actions}${switchList}${errorSlot}`;

}

function announce(card, message) {

    part(card, "[data-sg-live]").textContent = message;

}

/** Sends a change; on success toasts and refreshes the card, on a refusal shows its message and keeps the state. Resolves true when done. */
async function change(card, path, body, success) {

    card.lastMessage = null;

    try {
        const result = await sendMyGroup(card.id, path, body);

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

    const name = button.dataset.groupName;

    button.disabled = true;
    await change(card, action, { group_id: Number(button.dataset.groupId) }, action === "join" ? `You joined ${name}.` : `You switched to ${name}.`);

}
