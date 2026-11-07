/*
|--------------------------------------------------------------------------
| The student Grades page: a course's row opens in place
|--------------------------------------------------------------------------
|
| The first open fetches the course's small breakdown and keeps it; later opens only show it again. The expanded look
| is CSS on aria-expanded, so this only flips aria-expanded and hidden and fills the slot.
|
*/

import { showLoadError } from "../../shared/spa/error-state.js";

const SKELETON = `
    <div class="flex flex-col gap-3" role="status" aria-busy="true" data-skeleton>
        <span class="sr-only">Loading grades…</span>
        <div class="flex animate-pulse flex-col gap-3" aria-hidden="true">
            <div class="h-4 w-full rounded bg-gray-200"></div>
            <div class="h-4 w-2/3 rounded bg-gray-200"></div>
            <div class="h-4 w-1/3 rounded bg-gray-200"></div>
        </div>
    </div>`;

async function fetchBreakdown(root, offeringId) {

    const response = await fetch(`${root.dataset.breakdownBase}/${offeringId}`, {
        headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
        credentials: "same-origin",
    });

    if (!response.ok) {
        throw new Error(`Breakdown request failed (${response.status})`);
    }

    const payload = await response.json();

    if (!payload.success) {
        throw new Error("Breakdown request was refused");
    }

    return payload.html;

}

function load(root, row, slot) {

    const offeringId = row.dataset.offeringId;

    row.dataset.breakdownState = "loading";
    slot.innerHTML = SKELETON;

    return fetchBreakdown(root, offeringId).then((html) => {

        slot.innerHTML = html;
        row.dataset.breakdownState = "loaded";

        // The fragment is server-rendered without ids, so the toggle is wired to its block here.
        const block = slot.querySelector("[data-categories]");
        const toggle = slot.querySelector("[data-categories-toggle]");

        if (block && toggle) {
            block.id = `grades-categories-${offeringId}`;
            toggle.setAttribute("aria-controls", block.id);
        }

    }).catch((error) => {

        console.error(error);
        row.dataset.breakdownState = "failed";
        showLoadError(slot, "this course's grades", () => load(root, row, slot));

    });

}

function toggleRow(root, button) {

    const row = button.closest("[data-grades-row]");
    const slot = row?.querySelector("[data-breakdown-slot]");

    if (!row || !slot) {
        return;
    }

    const open = button.getAttribute("aria-expanded") !== "true";

    button.setAttribute("aria-expanded", String(open));
    slot.hidden = !open;

    // Fetched on the first open only; a failed or still-loading row is not fetched twice.
    if (open && !row.dataset.breakdownState) {
        load(root, row, slot);
    }

}

function toggleCategories(toggle) {

    const block = toggle.closest("[data-breakdown-slot]")?.querySelector("[data-categories]");

    if (!block) {
        return;
    }

    const open = toggle.getAttribute("aria-expanded") !== "true";

    toggle.setAttribute("aria-expanded", String(open));
    block.hidden = !open;
    toggle.textContent = open ? "Hide categories" : "Show categories";

}

/**
 * @param {HTMLElement} root the rows container ([data-grades-rows])
 */
export function bindStudentRows(root) {

    root.addEventListener("click", (event) => {

        const disclosure = event.target.closest("[data-disclosure]");

        if (disclosure && root.contains(disclosure)) {
            toggleRow(root, disclosure);
            return;
        }

        const categories = event.target.closest("[data-categories-toggle]");

        if (categories && root.contains(categories)) {
            toggleCategories(categories);
        }

    });

}
