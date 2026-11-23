/*
|--------------------------------------------------------------------------
| Moving students on a group board
|--------------------------------------------------------------------------
| Shared by the Groups tab (a set's groups) and the assignment editor's
| activity groups. Both answer the same paths under their own base:
| POST groups/{id}/members, POST members/move, DELETE members/{student}.
*/

import { applyFieldErrors } from "../../../shared/dialog.js";

export const NETWORK = "We could not reach the server. Nothing was saved. Check your connection and try again.";
export const FAILED = "That did not work. Nothing was saved. Please try again.";

const byName = (a, b) => a.name.localeCompare(b.name);

/** Disables the buttons while send(formData) runs; shows a refusal beside the fields, keeping what was typed. */
export function onSubmit(form, send) {

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

export const groupOf = (detail, studentId) => detail.groups.find((g) => g.members.some((m) => m.student_user_id === studentId)) ?? null;

export const studentById = (detail, studentId) => groupOf(detail, studentId)?.members.find((m) => m.student_user_id === studentId)
    ?? detail.ungrouped.find((m) => m.student_user_id === studentId);

/**
 * Fills the Move… picker: every group (full ones, and those closed(g) says no to, cannot be chosen) and
 * Ungrouped. An ungrouped student starts on the first group with room, so Move does something.
 */
export function fillMoveSelect(select, groups, current, max, closed = () => false) {

    const options = groups.map((g) => {
        const option = new Option(`${g.name} (${g.members.length} / ${max})`, String(g.id));
        option.disabled = g.id !== current?.id && (g.members.length >= max || closed(g));
        option.selected = g.id === current?.id;

        return option;
    });

    const none = new Option("Ungrouped (not in a group)", "");
    none.selected = current === null;

    select.replaceChildren(...options, none);

    if (current === null) {
        const free = options.find((o) => !o.disabled);

        if (free) {
            free.selected = true;
        }
    }

}

/**
 * Puts a student in a group (toGroupId), or takes them out of theirs (null), through send(method, path, body).
 * The detail changes only when the server said yes. Resolves { result, student, to }.
 */
export async function applyMove(detail, studentId, toGroupId, send) {

    const from = groupOf(detail, studentId);
    const to = toGroupId === null ? null : detail.groups.find((g) => g.id === toGroupId) ?? null;
    const student = studentById(detail, studentId);

    if ((from?.id ?? null) === (to?.id ?? null)) {
        return { result: { ok: true, status: 200, body: {} }, student, to, unchanged: true };
    }

    const result = from === null
        ? await send("POST", `/groups/${to.id}/members`, { student_user_id: studentId })
        : to === null
            ? await send("DELETE", `/members/${studentId}`)
            : await send("POST", "/members/move", { student_user_id: studentId, to_group_id: to.id });

    if (result.ok) {
        if (from) {
            from.members = from.members.filter((m) => m.student_user_id !== studentId);
        } else {
            detail.ungrouped = detail.ungrouped.filter((m) => m.student_user_id !== studentId);
        }

        (to ? to.members : detail.ungrouped).push(student);
        (to ? to.members : detail.ungrouped).sort(byName);
    }

    return { result, student, to };

}

const HOVER = ["ring-2", "ring-brand/50"];

/** Drag a member chip onto a group or Ungrouped; move(studentId, groupId|null) does the rest. Move… is the keyboard path. */
export function bindDragMoves(page, move) {

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

    page.addEventListener("drop", (event) => {
        const zone = event.target.closest?.("[data-drop-zone]");
        const studentId = Number(event.dataTransfer.getData("text/plain"));

        clear();

        if (!zone || !studentId) {
            return;
        }

        event.preventDefault();
        move(studentId, zone.dataset.dropZone === "" ? null : Number(zone.dataset.dropZone));
    });

}
