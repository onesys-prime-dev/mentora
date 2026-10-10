import { createSelection, selectionLabel } from "../../shared/selection.js";

/**
 * The users list: tick people, then act on all of them at once.
 *
 * The markup is plain HTML that works without this script (every checkbox is a
 * normal form field and the bulk buttons submit the form). This adds the
 * "select all" box, a count, and keeps the bulk buttons off until someone is
 * ticked.
 *
 * @param {ParentNode} [root]
 */
export function initUserSelection(root = document) {
    const form = root.querySelector("[data-users-bulk]");

    if (form === null) {
        return null;
    }

    const boxes = () => Array.from(form.querySelectorAll("[data-user-select]"));
    const all = form.querySelector("[data-select-all]");
    const label = form.querySelector("[data-selected-count]");
    const actions = Array.from(form.querySelectorAll("[data-bulk-action]"));

    const selection = createSelection(boxes().filter((box) => box.checked).map((box) => box.value));

    const render = () => {
        const rows = boxes().map((box) => box.value);
        const state = selection.stateOf(rows);

        for (const box of boxes()) {
            box.checked = selection.has(box.value);
        }

        if (all !== null) {
            all.checked = state === "all";
            all.indeterminate = state === "some";
        }

        if (label !== null) {
            label.textContent = selectionLabel(selection.count());
        }

        for (const button of actions) {
            button.disabled = selection.count() === 0;
        }
    };

    form.addEventListener("change", (event) => {
        const target = event.target;

        if (!(target instanceof HTMLInputElement)) {
            return;
        }

        if (target.matches("[data-select-all]")) {
            const rows = boxes().map((box) => box.value);

            if (target.checked) {
                selection.selectAll(rows);
            } else {
                selection.clear(rows);
            }
        } else if (target.matches("[data-user-select]")) {
            selection.set(target.value, target.checked);
        }

        render();
    });

    render();

    return selection;
}

if (typeof document !== "undefined") {
    initUserSelection();
}
