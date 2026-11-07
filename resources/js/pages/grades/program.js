/*
|--------------------------------------------------------------------------
| The chairperson's Program tab (and the dean's College view): a row opens that section's grid, read-only
|--------------------------------------------------------------------------
|
| The dean's page draws no Edit as chairperson, so nothing there ever unlocks; its grid base is dean/api/courses.
|
| A row of the Sections table is a link (it works without JavaScript). With JavaScript, a click saves any score
| still waiting for its debounce, points the gradebook calls at the chosen section and loads its grid with
| embedded=1&readonly=1, so the grid can be looked at but not changed.
|
| "Edit as chairperson" asks first (the app's dialog: every change is recorded in the audit log and the instructor
| can see it). Continue reloads the grid without readonly and shows Done; Done, or opening another row, returns
| to read-only. Only the page's own button unlocks anything, and the server decides whether it is there.
|
| A row switch also copies the section's nudge data (label, state, instructors, waiting, oldest days, term end) to
| the action bar, where nudge.js reads it, and hides Nudge and Edit as chairperson for a finalized section.
|
| The live search swaps the content below its box and tells the page with "content:swapped": the new grid panel
| is a placeholder until it is loaded again here.
|
*/

import { setGradesApiBase } from "../courses/features/grades/api.js";
import { createDialog } from "../../shared/dialog.js";
import { flushPendingScores } from "../courses/features/grades/roster/roster.js";
import { GRID_QUERY, areaOf, loadInto, sectionHref, showNoScheme } from "./section-switch.js";

export const READONLY_QUERY = `${GRID_QUERY}&readonly=1`;

// Only the latest open may touch the page: an earlier one that finishes late is dropped.
let latest = 0;

let confirmDialog = null;

const gridOf = () => document.querySelector("[data-grades-grid]");

const queryFor = (grid) => (grid.dataset.readonly === "1" ? READONLY_QUERY : GRID_QUERY);

// What Nudge reads from the action bar, copied from the opened row (data-* names, camelCased).
const NUDGE_DATA = ["label", "state", "instructors", "waiting", "oldestDays", "termEnd"];

const sentence = (label) => `You're editing ${label} as chairperson. Every change is recorded in the audit log and the instructor can see it.`;

// The confirm dialog, built once from the same pieces as components/confirm (the dialog needs no form here).
function askToEdit(label, trigger)
{
    if (!confirmDialog?.el.isConnected) {
        const el = document.createElement("div");

        el.id = "edit-as-chair-dialog";
        el.hidden = true;
        el.className = "hidden fixed inset-0 z-50 items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4";
        el.dataset.dialog = "";
        el.dataset.editConfirm = "";
        el.setAttribute("role", "dialog");
        el.setAttribute("aria-modal", "true");
        el.setAttribute("aria-labelledby", "edit-as-chair-dialog-title");
        el.innerHTML = `
            <div class="flex max-h-full w-full flex-col gap-5 overflow-y-auto rounded-t-2xl bg-white p-6 shadow-xl sm:max-w-sm sm:rounded-2xl" tabindex="-1">
                <h2 id="edit-as-chair-dialog-title" class="text-lg font-bold text-brand">Edit as chairperson</h2>
                <p class="text-sm text-[#3D3D3D]" data-dialog-text></p>
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>
                    <button type="button" data-edit-continue class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">Continue</button>
                </div>
            </div>`;

        document.body.append(el);

        confirmDialog = { el, dialog: createDialog(el) };
    }

    const { el, dialog } = confirmDialog;

    el.querySelector("[data-dialog-text]").textContent = sentence(label);

    return new Promise((resolve) => {
        const finish = (answer) => {
            el.removeEventListener("dialog:close", onClose);
            el.querySelector("[data-edit-continue]").removeEventListener("click", onContinue);
            resolve(answer);
        };
        const onContinue = () => {
            finish(true);
            dialog.close();
        };
        const onClose = () => finish(false);

        el.querySelector("[data-edit-continue]").addEventListener("click", onContinue);
        el.addEventListener("dialog:close", onClose, { once: true });

        dialog.open(trigger);
    });
}

// The action bar for the mode: Edit as chairperson or Done, and the Read-only label.
function showMode(editing)
{
    const edit = document.querySelector("[data-edit-as-chair]");
    const done = document.querySelector("[data-edit-done]");
    const label = document.querySelector("[data-readonly-label]");

    if (done) {
        done.hidden = !editing;
    }

    if (edit) {
        edit.hidden = editing || edit.dataset.unavailable === "1";
    }

    if (label) {
        label.hidden = editing;
    }
}

async function openRow(row)
{
    const grid = gridOf();

    if (!grid) {
        return;
    }

    const mine = ++latest;

    // A score typed just before the click goes to the section it was typed in: the base still points there.
    await flushPendingScores();

    if (mine !== latest) {
        return;
    }

    const id = row.dataset.sectionId;
    const apiBase = `${areaOf(grid.dataset.apiBase)}/api/courses/${id}`;
    const area = areaOf(grid.dataset.apiBase);

    setGradesApiBase(apiBase);

    grid.dataset.apiBase = apiBase;
    grid.dataset.sectionId = id;
    grid.dataset.readonly = "1";

    document.querySelectorAll("[data-program-row]").forEach((other) => {
        if (other === row) {
            other.setAttribute("aria-current", "true");
        } else {
            other.removeAttribute("aria-current");
        }
    });

    const bar = document.querySelector("[data-program-actions]");
    const hasScheme = row.dataset.hasScheme !== "0";
    const inCourse = `${area}/courses/${id}/${row.dataset.slug}/grades`;

    if (bar) {
        bar.dataset.sectionId = id;

        for (const key of NUDGE_DATA) {
            bar.dataset[key] = row.dataset[key] ?? "";
        }

        bar.querySelector("[data-action-label]")?.replaceChildren(row.dataset.label ?? "");
        bar.querySelector("[data-action-title]")?.replaceChildren(row.querySelector("[title]")?.getAttribute("title") ?? "");
        bar.querySelector("[data-open-in-course]")?.setAttribute("href", inCourse);

        const edit = bar.querySelector("[data-edit-as-chair]");

        if (edit) {
            // Nothing to edit without grading, in a closed section, or in a finalized one (edits are refused).
            edit.dataset.unavailable = !hasScheme || row.dataset.offeringStatus !== "active" || row.dataset.state === "finalized" ? "1" : "";
        }

        showMode(false);
    }

    // A finalized section has nothing to nudge about.
    const nudge = document.querySelector("[data-nudge]");

    if (nudge) {
        nudge.hidden = row.dataset.state === "finalized";
    }

    window.history.replaceState(null, "", sectionHref(window.location.href, id));

    grid.dataset.hasScheme = hasScheme ? "1" : "0";

    if (!hasScheme) {
        showNoScheme(grid, inCourse);
        return;
    }

    await loadInto(grid, READONLY_QUERY);
}

async function edit(trigger)
{
    const grid = gridOf();

    // The server only draws the button for someone who may edit; without it nothing unlocks.
    if (!grid || !document.querySelector("[data-edit-as-chair]")) {
        return;
    }

    const label = document.querySelector("[data-program-actions] [data-action-label]")?.textContent ?? "";

    if (!(await askToEdit(label, trigger))) {
        return;
    }

    ++latest;

    grid.dataset.readonly = "0";
    showMode(true);

    await loadInto(grid, queryFor(grid));
}

async function finishEditing()
{
    const grid = gridOf();

    if (!grid) {
        return;
    }

    const mine = ++latest;

    await flushPendingScores();

    if (mine !== latest) {
        return;
    }

    grid.dataset.readonly = "1";
    showMode(false);

    await loadInto(grid, queryFor(grid));
}

// Loads a grid panel the server drew (first view, or after the live search swapped it in).
function loadPanel()
{
    const grid = gridOf();

    if (!grid || grid.dataset.programLoaded === "1") {
        return;
    }

    grid.dataset.programLoaded = "1";
    ++latest;

    setGradesApiBase(grid.dataset.apiBase);

    if (grid.dataset.hasScheme !== "0") {
        loadInto(grid, queryFor(grid)).catch((error) => console.error(error));
    }
}

/**
 * @param {HTMLElement} root the page; it holds the rows, the action bar and the grid, whatever a search swaps in
 */
export function bindProgram(root)
{
    root.addEventListener("click", (event) => {

        const row = event.target.closest("[data-program-row]");

        if (row) {
            // Leave new-tab and new-window clicks to the link itself, and every click when the page has no grid to
            // fill (the dean without courses.view): the link opens the page on that section.
            if (!gridOf() || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();

            openRow(row).catch((error) => console.error(error));

            return;
        }

        const editButton = event.target.closest("[data-edit-as-chair]");

        if (editButton) {
            edit(editButton).catch((error) => console.error(error));

            return;
        }

        if (event.target.closest("[data-edit-done]")) {
            finishEditing().catch((error) => console.error(error));
        }

    });

    // A live search replaced the panel: the new grid is a placeholder until loaded again.
    document.addEventListener("content:swapped", loadPanel);

    loadPanel();
}
