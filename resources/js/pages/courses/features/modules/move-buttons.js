/*
|--------------------------------------------------------------------------
| ▲ ▼ move buttons for module, learning unit and learning item lists
|--------------------------------------------------------------------------
|
| A touch screen cannot drag a row by its handle (the handle hides under a
| coarse pointer), so each row gets two buttons (partials/move-buttons.php).
| A press swaps the row with its neighbour by moving the neighbour, so focus
| stays on the pressed button, then announces the move and saves the order
| through the same path the drag's drop uses. Mirrors the Quiz Builder's arrows
| (assessments/questions/questions.js).
|
*/

import { showToast } from "../../../../shared/toast.js";

/**
 * The one save path of a kind of list (a drop and the arrows both use it). One request at a time per list:
 * moves made while a save runs are sent as one more save of the order then on screen, so the server ends on
 * what the list shows. A failed save says so and calls `onFail` (a reload), which puts the server's order back.
 *
 * @param {(list: HTMLElement) => Promise<unknown>} save sends the list's current order
 * @param {() => (() => unknown) | undefined} onFail returns the reload to run after a failed save
 * @returns {(list: HTMLElement) => Promise<void>}
 */
export function orderSaver(save, onFail) {

    const saves = new WeakMap();

    return (list) => {

        const current = saves.get(list) ?? { running: null, again: false };

        saves.set(list, current);

        if (current.running) {
            current.again = true;

            return current.running;
        }

        current.running = (async () => {

            do {
                current.again = false;

                try {
                    await save(list);
                } catch (error) {
                    console.error(error);
                    showToast("The new order could not be saved. Try again.", { tone: "danger" });
                    onFail()?.();

                    return;
                }
            } while (current.again);

        })().finally(() => {
            current.running = null;
        });

        return current.running;

    };

}

/** The list's rows: its own children that match `row` (not the add buttons, empty states or nested lists). */
function rowsOf(list, row) {
    return [...list.children].filter((child) => child.matches(row));
}

/** Disables the first row's ▲ and the last row's ▼. */
export function refreshMoveButtons(list, { row, attr }) {

    const rows = rowsOf(list, row);

    rows.forEach((el, index) => {
        const own = (direction) => [...el.querySelectorAll(`[${attr}="${direction}"]`)].find((b) => b.closest(row) === el);

        own("up")?.toggleAttribute("disabled", index === 0);
        own("down")?.toggleAttribute("disabled", index === rows.length - 1);
    });

}

/**
 * Wires a list's arrows (once per list element).
 *
 * @param {HTMLElement} list
 * @param {{row: string, attr: string, noun: string, status?: string, persist: (list: HTMLElement) => unknown}} options
 *   row: the row selector; attr: the buttons' attribute ("data-module-move"), valued "up" or "down";
 *   noun: "Module" in "Module moved to position 2 of 3."; status: the aria-live element's selector
 */
export function bindMoveButtons(list, options) {

    refreshMoveButtons(list, options);

    if (list.dataset.moveBound === "1") {
        return;
    }

    list.dataset.moveBound = "1";

    list.addEventListener("click", (event) => {

        const button = event.target.closest?.(`[${options.attr}]`);
        const el = button?.closest(options.row);

        if (!button || button.disabled || el?.parentElement !== list) {
            return;
        }

        const rows = rowsOf(list, options.row);
        const index = rows.indexOf(el);
        const direction = button.getAttribute(options.attr);

        if (direction === "up") {
            el.after(rows[index - 1]);
        } else {
            el.before(rows[index + 1]);
        }

        refreshMoveButtons(list, options);

        // Focus stays on the pressed arrow (said outright: a tap in Safari focuses no button). At an end it is
        // disabled and cannot keep focus, so the row's other arrow takes it.
        const other = [...el.querySelectorAll(`[${options.attr}]`)].find((b) => b !== button && b.closest(options.row) === el);
        (button.disabled ? other : button)?.focus();

        const status = options.status ? document.querySelector(options.status) : null;

        if (status) {
            const after = rowsOf(list, options.row);
            status.textContent = `${options.noun} moved to position ${after.indexOf(el) + 1} of ${after.length}.`;
        }

        options.persist(list);

    });

}
