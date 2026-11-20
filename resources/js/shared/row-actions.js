/**
 * The row-actions sheet of components/data-table.php on phones.
 *
 * Markup contract:
 *   button[data-row-actions-open][aria-controls="<sheet id>"][data-row-label]   the ⋯ in a [data-row]
 *   template[data-row-fields]          next to it: the row's columns the phone row leaves out
 *   td[data-role="actions"]            the row's actions
 *   the sheet: a components/dialog.php sheet holding [data-row-actions-fields] and [data-row-actions-slot]
 *
 * Opening moves (not copies) the actions into the sheet and closing moves them back, so their
 * handlers, form values and ids survive. The fields are an inert copy of the template: no ids,
 * names or data-* hooks, and its controls disabled, so nothing clashes with the row's own cells.
 * A link or a non-submit button in the sheet closes it at once and then runs, so an action that
 * opens another dialog finds the sheet gone; a submit button just submits. The moved controls carry
 * data-return-focus (the ⋯'s id) until the sheet next opens, so that other dialog gives focus back to
 * the ⋯. A live search that swaps the table or row away closes the sheet at once.
 *
 * Delegate an action's handler on [data-table] or above, never on its table, tbody or tr: a click in
 * the sheet bubbles through the dialog, not the row, so a handler on the row's table would not fire.
 */

import { createDialog } from "./dialog.js";

/** Per sheet: its dialog, the row it shows, the actions cell whose children it holds, and the marked controls. */
const sheets = new WeakMap();
const bound = new WeakSet();
const OPENERS = "a[href], button, summary, input, select, textarea, [tabindex], [data-dialog-open]";
let seq = 0;
let openSheet = null;

function restore(sheet) {
    const state = sheets.get(sheet);

    if (state?.from) {
        state.from.append(...sheet.querySelector("[data-row-actions-slot]").childNodes);
        state.from = null;
    }

    sheet.querySelector("[data-row-actions-fields]")?.replaceChildren();
}

/** The marks from the sheet's last opening, kept past its close so a dialog an action opened can still use them. */
function unmark(state) {
    state.marked.forEach((el) => el.removeAttribute("data-return-focus"));
    state.marked = [];
}

function stateOf(sheet) {
    if (!sheets.has(sheet)) {
        sheets.set(sheet, { dialog: createDialog(sheet), row: null, from: null, marked: [] });
        sheet.addEventListener("dialog:close", () => {
            restore(sheet);

            if (openSheet === sheet) {
                openSheet = null;
            }
        });
    }

    return sheets.get(sheet);
}

/** A live search swapped the sheet or its row away: close it now, so the page is not left scroll-locked. */
function closeIfGone() {
    const state = openSheet && sheets.get(openSheet);

    if (state && (!openSheet.isConnected || !state.row?.isConnected)) {
        state.dialog.close({ instant: true });
        unmark(state);
    }
}

/** @param {DocumentFragment} fragment */
function inert(fragment) {
    for (const el of fragment.querySelectorAll("*")) {
        for (const { name } of [...el.attributes]) {
            if (["id", "name", "form", "for"].includes(name) || name.startsWith("data-")) {
                el.removeAttribute(name);
            }
        }

        if (el.matches("input, select, textarea, button")) {
            el.disabled = true;
        }
    }

    return fragment;
}

/** @param {HTMLElement} button the ⋯ */
function openFor(button) {
    const sheet = document.getElementById(button.getAttribute("aria-controls") ?? "");
    const row = button.closest("[data-row]");

    if (!sheet || !row) {
        return;
    }

    const state = stateOf(sheet);

    // Reopened during the exit animation: the last row's actions go home first.
    restore(sheet);
    unmark(state);
    state.row = row;
    button.id ||= `row-actions-open-${++seq}`;

    const title = sheet.querySelector("[data-dialog-title]");

    if (title) {
        title.textContent = row.querySelector('[data-role="primary"]')?.textContent.trim() || button.dataset.rowLabel?.trim() || "Row";
    }

    const template = row.querySelector("template[data-row-fields]");

    if (template) {
        sheet.querySelector("[data-row-actions-fields]")?.replaceChildren(inert(template.content.cloneNode(true)));
    }

    const cell = row.querySelector('[data-role="actions"]');

    if (cell) {
        // Back in the hidden cell after an action ran, a control can't take focus when the dialog it opened closes:
        // it hands focus to the ⋯ instead (shared/dialog.js data-return-focus).
        state.marked = [...cell.querySelectorAll(OPENERS)].filter((el) => !el.hasAttribute("data-return-focus"));
        state.marked.forEach((el) => el.setAttribute("data-return-focus", button.id));
        sheet.querySelector("[data-row-actions-slot]").append(...cell.childNodes);
        state.from = cell;
    }

    state.dialog.open(button);
    openSheet = sheet;
}

/** Wires every ⋯ under `root`, now and after SPA swaps (delegated, once per root). */
export function bindRowActions(root = document) {
    if (bound.has(root)) {
        return;
    }

    bound.add(root);

    // Capture: the sheet must be gone before the action's own click handlers run.
    root.addEventListener(
        "click",
        (event) => {
            closeIfGone();

            const opener = event.target.closest?.("[data-row-actions-open]");

            if (opener) {
                openFor(opener);

                return;
            }

            // A link among the fields (a cell's own link) leaves the page too.
            const action = event.target.closest?.(":is([data-row-actions-slot], [data-row-actions-fields]) :is(a[href], button, input[type=submit], input[type=image])");

            // A submit only counts inside a form; a typeless button outside one is a plain button.
            if (!action || (action.form && (action.type === "submit" || action.type === "image"))) {
                return;
            }

            const sheet = action.closest("[data-dialog]");
            sheets.get(sheet)?.dialog.close({ instant: true });
        },
        true,
    );
}

if (typeof document !== "undefined") {
    bindRowActions(document);
    // shared/auto-search.js replaces the [data-table] (sheet included) and then says so.
    document.addEventListener("content:swapped", closeIfGone);
}
