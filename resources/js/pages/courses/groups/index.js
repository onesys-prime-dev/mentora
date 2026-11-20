import { beginLoad } from "../../../shared/spa/load-guard.js";
import { showLoadError } from "../../../shared/spa/error-state.js";
import { applyFieldErrors, createDialog } from "../../../shared/dialog.js";
import { showToast } from "../../../shared/toast.js";

import { fetchGroupSet, fetchGroupsTab, sendChange } from "./api.js";
import { renderDetailSkeleton, renderGroupsSkeleton } from "./skeleton.js";
import { renderDetailError, renderSetDetail, SIGNUP_MODES } from "./detail.js";

/*
|--------------------------------------------------------------------------
| The course Groups tab (group activities, screen 1)
|--------------------------------------------------------------------------
| The server draws the set list and the dialogs (features/groups/index.php);
| this draws the chosen set's detail and sends every change as JSON to the
| group-sets endpoints. A member is placed with the "Move…" picker (the
| keyboard and phone path) or by dragging their name onto a group.
*/

const NETWORK = "We could not reach the server. Nothing was saved. Check your connection and try again.";
const FAILED = "That did not work. Nothing was saved. Please try again.";

const state = {
    page: null,
    canEdit: false,
    setId: null,
    detail: null,
    dialogs: {},
    pending: null,
};

export async function initialize({ setId = null } = {}) {

    const container = document.querySelector("#course-content");

    const load = beginLoad(container, renderGroupsSkeleton());

    let data;

    try {
        data = await fetchGroupsTab();
    } catch (error) {
        console.error(error);

        if (load.current()) {
            load.finish();
            showLoadError(container, "Groups", () => initialize({ setId }));
        }

        return;
    }

    if (!load.current()) {
        return;
    }

    load.finish();
    container.innerHTML = data.html;

    await bindPage(container.querySelector("[data-groups-page]"), setId);

}

async function bindPage(page, setId) {

    state.page = page;
    state.canEdit = page?.dataset.canEdit === "1";
    state.detail = null;
    state.setId = null;
    state.dialogs = {};

    if (!page) {
        return;
    }

    page.addEventListener("click", onClick);

    if (state.canEdit) {
        for (const id of ["group-set-dialog", "group-name-dialog", "group-move-dialog", "group-set-archive-confirm", "group-delete-confirm"]) {
            const el = page.querySelector(`#${id}`);

            if (el) {
                state.dialogs[id] = createDialog(el);
            }
        }

        bindForms(page);
        bindDrag(page);
    }

    const first = page.querySelector(`[data-group-set-open="${setId}"]`) ?? page.querySelector("[data-group-set-open]");

    if (first) {
        await openSet(Number(first.dataset.groupSetOpen));
    }

}

/*
|--------------------------------------------------------------------------
| The set detail
|--------------------------------------------------------------------------
*/

const region = () => state.page?.querySelector("[data-group-set-detail]");

export async function openSet(setId) {

    state.setId = setId;

    for (const button of state.page.querySelectorAll("[data-group-set-open]")) {
        const current = Number(button.dataset.groupSetOpen) === setId;

        button.closest("tr")?.classList.toggle("bg-brand-tint", current);

        if (current) {
            button.setAttribute("aria-current", "true");
        } else {
            button.removeAttribute("aria-current");
        }
    }

    const target = region();

    if (!target) {
        return;
    }

    const load = beginLoad(target, renderDetailSkeleton(), 0);

    try {
        const detail = await fetchGroupSet(setId);

        if (!load.current()) {
            return;
        }

        state.detail = detail;
        render();
    } catch (error) {
        console.error(error);

        if (!load.current()) {
            return;
        }

        target.innerHTML = renderDetailError();
        target.querySelector("[data-detail-retry]")?.addEventListener("click", () => openSet(setId));
    }

}

function render() {

    region().innerHTML = renderSetDetail(state.detail, state.canEdit);
    syncListRow();

}

/** The set's row in the list follows what changed here. */
function syncListRow() {

    const { set, groups } = state.detail;
    const placed = groups.reduce((sum, g) => sum + g.members.length, 0);
    const below = groups.filter((g) => g.members.length < Number(set.min_size)).length;
    const groupsCell = state.page.querySelector(`[data-set-groups="${set.id}"]`);
    const placedCell = state.page.querySelector(`[data-set-placed="${set.id}"]`);

    if (groupsCell) {
        groupsCell.textContent = `${groups.length} ${groups.length === 1 ? "group" : "groups"}${below > 0 ? ` · ${below} below minimum` : ""}`;
    }

    if (placedCell) {
        placedCell.textContent = `${placed} of ${state.page.dataset.poolSize ?? placed + state.detail.ungrouped.length}`;
    }

}

const groupOf = (studentId) => state.detail.groups.find((g) => g.members.some((m) => m.student_user_id === studentId)) ?? null;

const studentById = (studentId) => groupOf(studentId)?.members.find((m) => m.student_user_id === studentId)
    ?? state.detail.ungrouped.find((m) => m.student_user_id === studentId);

const byName = (a, b) => a.name.localeCompare(b.name);

function announce(message) {

    const live = state.page.querySelector("[data-groups-live]");

    if (live) {
        live.textContent = message;
    }

    showToast(message, { tone: "success" });

}

/**
 * Puts a student in a group (toGroupId), or takes them out of their group (null).
 * Resolves the server's answer; the page changes only when it said yes.
 */
export async function moveStudent(studentId, toGroupId) {

    const setId = state.detail.set.id;
    const from = groupOf(studentId);
    const to = toGroupId === null ? null : state.detail.groups.find((g) => g.id === toGroupId) ?? null;

    if ((from?.id ?? null) === (to?.id ?? null)) {
        return { ok: true, status: 200, body: {} };
    }

    const result = from === null
        ? await sendChange("POST", `/${setId}/groups/${to.id}/members`, { student_user_id: studentId })
        : to === null
            ? await sendChange("DELETE", `/${setId}/members/${studentId}`)
            : await sendChange("POST", `/${setId}/members/move`, { student_user_id: studentId, to_group_id: to.id });

    if (!result.ok) {
        return result;
    }

    const student = studentById(studentId);

    if (from) {
        from.members = from.members.filter((m) => m.student_user_id !== studentId);
    } else {
        state.detail.ungrouped = state.detail.ungrouped.filter((m) => m.student_user_id !== studentId);
    }

    (to ? to.members : state.detail.ungrouped).push(student);
    (to ? to.members : state.detail.ungrouped).sort(byName);

    render();
    announce(to ? `${student.name} moved to ${to.name}` : `${student.name} is no longer in a group`);

    return result;

}

/*
|--------------------------------------------------------------------------
| Buttons
|--------------------------------------------------------------------------
*/

function onClick(event) {

    const open = event.target.closest("[data-group-set-open]");

    if (open) {
        openSet(Number(open.dataset.groupSetOpen));

        return;
    }

    const button = event.target.closest("[data-group-action]");

    if (!button || !state.canEdit) {
        return;
    }

    const groupId = Number(button.dataset.group);
    const group = state.detail?.groups.find((g) => g.id === groupId);

    switch (button.dataset.groupAction) {
        case "new-set":
            openSetDialog(null, button);
            break;
        case "edit-set":
            openSetDialog(state.detail.set, button);
            break;
        case "archive-set":
            openConfirm("group-set-archive-confirm", { kind: "archive" }, `Archive ${state.detail.set.name}?`, "It leaves this list. Its groups and members are kept.", button);
            break;
        case "add-group":
            openNameDialog({ kind: "add" }, "Add group", `Team ${state.detail.groups.length + 1}`, "Add group", button);
            break;
        case "rename-group":
            openNameDialog({ kind: "rename", groupId }, `Rename ${group.name}`, group.name, "Save", button);
            break;
        case "delete-group":
            openConfirm("group-delete-confirm", { kind: "delete", groupId }, `Delete ${group.name}?`, group.members.length > 0
                ? `Its ${group.members.length} ${group.members.length === 1 ? "member goes" : "members go"} back to Ungrouped.`
                : "It has no members.", button);
            break;
        case "move":
            openMoveDialog(Number(button.dataset.student), button);
            break;
    }

}

function dialogParts(id) {

    const el = state.page.querySelector(`#${id}`);

    return { el, form: el.querySelector("form"), title: el.querySelector("[data-dialog-title]"), label: el.querySelector("[data-dialog-label]") };

}

function openSetDialog(set, opener) {

    const { form, title, label } = dialogParts("group-set-dialog");

    form.reset();
    applyFieldErrors(form, {});
    title.textContent = set ? "Edit group set" : "New group set";
    label.textContent = set ? "Save" : "Create set";

    if (set) {
        form.elements.name.value = set.name;
        form.elements.formation_mode.value = set.formation_mode;
        form.elements.min_size.value = set.min_size;
        form.elements.max_size.value = set.max_size;
        form.elements.signup_opens_at.value = toLocalInput(set.signup_opens_at);
        form.elements.signup_closes_at.value = toLocalInput(set.signup_closes_at);
    }

    showSignupWindow(form);
    state.pending = { kind: set ? "edit" : "create" };
    state.dialogs["group-set-dialog"].open(opener);

}

function openNameDialog(pending, heading, value, buttonLabel, opener) {

    const { form, title, label } = dialogParts("group-name-dialog");

    form.reset();
    applyFieldErrors(form, {});
    title.textContent = heading;
    label.textContent = buttonLabel;
    form.elements.name.value = value;
    state.pending = pending;
    state.dialogs["group-name-dialog"].open(opener);
    form.elements.name.select();

}

function openConfirm(id, pending, heading, text, opener) {

    const { el, form, title } = dialogParts(id);

    applyFieldErrors(form, {});
    title.textContent = heading;
    el.querySelector("[data-dialog-text]").textContent = text;
    state.pending = pending;
    state.dialogs[id].open(opener);

}

function openMoveDialog(studentId, opener) {

    const { form, title } = dialogParts("group-move-dialog");
    const student = studentById(studentId);
    const current = groupOf(studentId);
    const max = Number(state.detail.set.max_size);
    const select = form.elements.to_group;

    applyFieldErrors(form, {});
    title.textContent = `Move ${student.name}`;

    const options = state.detail.groups.map((g) => {
        const option = new Option(`${g.name} (${g.members.length} / ${max})`, String(g.id));
        option.disabled = g.id !== current?.id && g.members.length >= max;
        option.selected = g.id === current?.id;

        return option;
    });

    // Ungrouped is where a member goes when taken out of their group.
    const none = new Option("Ungrouped (not in a group)", "");
    none.selected = current === null;

    select.replaceChildren(...options, none);

    if (current === null) {
        // Start on the first group with room, so Move does something.
        const free = options.find((o) => !o.disabled);

        if (free) {
            free.selected = true;
        }
    }

    state.pending = { kind: "move", studentId };
    state.dialogs["group-move-dialog"].open(opener);

}

/*
|--------------------------------------------------------------------------
| Forms
|--------------------------------------------------------------------------
*/

/** "2026-12-12 17:00:00" -> "2026-12-12T17:00" for a datetime-local field, and back. */
const toLocalInput = (value) => (value ? String(value).replace(" ", "T").slice(0, 16) : "");
const fromLocalInput = (value) => (value ? `${value.replace("T", " ")}:00` : null);

function showSignupWindow(form) {

    form.querySelector("[data-signup-window]").hidden = !SIGNUP_MODES.includes(form.elements.formation_mode.value);

}

/** Disables the buttons while it runs; shows a refusal beside the fields, keeping what was typed. */
function onSubmit(form, send) {

    form.addEventListener("submit", async (event) => {

        event.preventDefault();

        const buttons = Array.from(form.querySelectorAll("button[type=submit]"));

        buttons.forEach((b) => (b.disabled = true));
        form.setAttribute("aria-busy", "true");

        try {
            const result = await send(new FormData(form));

            if (result && !result.ok) {
                applyFieldErrors(form, result.body.errors ?? {}, result.body.message ?? FAILED);
            }
        } catch (error) {
            console.error(error);
            applyFieldErrors(form, {}, NETWORK);
        } finally {
            buttons.forEach((b) => (b.disabled = false));
            form.removeAttribute("aria-busy");
        }

    });

}

function closeDialog(id) {

    state.dialogs[id].close({ instant: true });

}

function bindForms(page) {

    const setForm = page.querySelector("[data-group-set-form]");

    setForm.elements.formation_mode.addEventListener("change", () => showSignupWindow(setForm));

    onSubmit(setForm, async (data) => {
        const signup = SIGNUP_MODES.includes(data.get("formation_mode"));
        const body = {
            name: data.get("name"),
            formation_mode: data.get("formation_mode"),
            min_size: Number(data.get("min_size")),
            max_size: Number(data.get("max_size")),
            signup_opens_at: signup ? fromLocalInput(data.get("signup_opens_at")) : null,
            signup_closes_at: signup ? fromLocalInput(data.get("signup_closes_at")) : null,
        };
        const editing = state.pending?.kind === "edit";
        const result = editing
            ? await sendChange("PUT", `/${state.detail.set.id}`, body)
            : await sendChange("POST", "", body);

        if (result.ok) {
            closeDialog("group-set-dialog");
            showToast(editing ? `${body.name} was saved` : `${body.name} was created`, { tone: "success" });
            await initialize({ setId: editing ? state.detail.set.id : result.body.id });
        }

        return result;
    });

    onSubmit(page.querySelector("[data-group-name-form]"), async (data) => {
        const name = String(data.get("name") ?? "").trim();
        const setId = state.detail.set.id;
        const { kind, groupId } = state.pending;
        const result = kind === "add"
            ? await sendChange("POST", `/${setId}/groups`, { name })
            : await sendChange("PUT", `/${setId}/groups/${groupId}`, { name });

        if (result.ok) {
            if (kind === "add") {
                state.detail.groups.push({ id: Number(result.body.id), name, members: [] });
            } else {
                state.detail.groups.find((g) => g.id === groupId).name = name;
            }

            render();
            closeDialog("group-name-dialog");
            showToast(kind === "add" ? `${name} was added` : `The group is now called ${name}`, { tone: "success" });
        }

        return result;
    });

    onSubmit(page.querySelector("[data-group-move-form]"), async (data) => {
        const value = String(data.get("to_group") ?? "");
        const { studentId } = state.pending;
        const result = await moveStudent(studentId, value === "" ? null : Number(value));

        if (result.ok) {
            closeDialog("group-move-dialog");
            region().querySelector(`[data-group-action="move"][data-student="${studentId}"]`)?.focus();
        }

        return result;
    });

    onSubmit(page.querySelector("#group-set-archive-confirm form"), async () => {
        const set = state.detail.set;
        const result = await sendChange("POST", `/${set.id}/archive`);

        if (result.ok) {
            closeDialog("group-set-archive-confirm");
            showToast(`${set.name} was archived`, { tone: "success" });
            await initialize();
        }

        return result;
    });

    onSubmit(page.querySelector("#group-delete-confirm form"), async () => {
        const { groupId } = state.pending;
        const group = state.detail.groups.find((g) => g.id === groupId);
        const result = await sendChange("DELETE", `/${state.detail.set.id}/groups/${groupId}`);

        if (result.ok) {
            state.detail.groups = state.detail.groups.filter((g) => g.id !== groupId);
            state.detail.ungrouped = [...state.detail.ungrouped, ...group.members].sort(byName);
            render();
            closeDialog("group-delete-confirm");
            showToast(`${group.name} was deleted`, { tone: "success" });
        }

        return result;
    });

}

/*
|--------------------------------------------------------------------------
| Drag and drop (an extra: Move… is the path for keyboards and phones)
|--------------------------------------------------------------------------
*/

const HOVER = ["ring-2", "ring-brand/50"];

function bindDrag(page) {

    const clear = () => page.querySelectorAll("[data-drop-zone]").forEach((z) => z.classList.remove(...HOVER));

    page.addEventListener("dragstart", (event) => {
        const chip = event.target.closest?.("[data-member][draggable=true]");

        if (chip) {
            event.dataTransfer.setData("text/plain", chip.dataset.member);
            event.dataTransfer.effectAllowed = "move";
        }
    });

    page.addEventListener("dragover", (event) => {
        const zone = event.target.closest?.("[data-drop-zone]");

        if (zone) {
            event.preventDefault();
            event.dataTransfer.dropEffect = "move";
            clear();
            zone.classList.add(...HOVER);
        }
    });

    page.addEventListener("dragend", clear);

    page.addEventListener("drop", async (event) => {
        const zone = event.target.closest?.("[data-drop-zone]");
        const studentId = Number(event.dataTransfer.getData("text/plain"));

        clear();

        if (!zone || !studentId || !state.detail) {
            return;
        }

        event.preventDefault();

        try {
            const result = await moveStudent(studentId, zone.dataset.dropZone === "" ? null : Number(zone.dataset.dropZone));

            if (!result.ok) {
                showToast(result.body.message ?? FAILED, { tone: "danger" });
            }
        } catch (error) {
            console.error(error);
            showToast(NETWORK, { tone: "danger" });
        }
    });

}
