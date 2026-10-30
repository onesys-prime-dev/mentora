/**
 * Row selection and the bulk bar of components/data-table.php.
 *
 * Markup contract (inside [data-table]):
 *   input[data-select-all]          the header checkbox
 *   input[data-row-select][value]   one per row
 *   [data-bulk-bar]                 hidden until something is ticked
 *   [data-selected-count]           "3 people selected" (data-noun-one / data-noun-many)
 *   form[data-bulk-form]            gets one hidden ids[] input per ticked row on submit
 *
 * The selection itself is plain state (shared/selection.js).
 */

import { createSelection, selectionLabel } from "./selection.js";

/**
 * @param {HTMLElement} root the [data-table] element
 */
export function bindDataTable(root) {
    const selection = createSelection();
    const rows = () => Array.from(root.querySelectorAll("input[data-row-select]"));
    const all = root.querySelector("input[data-select-all]");
    const bar = root.querySelector("[data-bulk-bar]");
    const count = root.querySelector("[data-selected-count]");

    const render = () => {
        const ids = rows().map((box) => box.value);
        const state = selection.stateOf(ids);

        for (const box of rows()) {
            box.checked = selection.has(box.value);
            box.closest("tr")?.classList.toggle("bg-brand-tint", box.checked);
        }

        if (all) {
            all.checked = state === "all";
            all.indeterminate = state === "some";
        }

        if (bar) {
            bar.hidden = selection.count() === 0;
        }

        if (count) {
            count.textContent = selectionLabel(selection.count(), {
                one: count.dataset.nounOne ?? "row",
                many: count.dataset.nounMany ?? "rows",
            });
        }
    };

    root.addEventListener("change", (event) => {
        const target = event.target;

        if (!(target instanceof HTMLInputElement)) {
            return;
        }

        if (target.matches("[data-row-select]")) {
            selection.set(target.value, target.checked);
        } else if (target.matches("[data-select-all]")) {
            const ids = rows().map((box) => box.value);

            if (target.checked) {
                selection.selectAll(ids);
            } else {
                selection.clear(ids);
            }
        } else {
            return;
        }

        render();
    });

    for (const form of root.querySelectorAll("form[data-bulk-form]")) {
        form.addEventListener("submit", (event) => {
            if (selection.count() === 0) {
                event.preventDefault();

                return;
            }

            for (const stale of form.querySelectorAll('input[name="ids[]"][data-generated]')) {
                stale.remove();
            }

            for (const id of selection.ids()) {
                const input = document.createElement("input");
                input.type = "hidden";
                input.name = "ids[]";
                input.value = id;
                input.setAttribute("data-generated", "");
                form.append(input);
            }
        });
    }

    render();

    return { selection, render };
}

function bindNewTables(root) {
    for (const el of root.querySelectorAll("[data-table]:not([data-table-bound])")) {
        el.dataset.tableBound = "1";
        bindDataTable(el);
    }
}

if (typeof document !== "undefined") {
    bindNewTables(document);

    // A search that swapped in a new table (shared/auto-search.js) needs it wired up too.
    document.addEventListener("content:swapped", (event) => bindNewTables(event.detail.root));
}
