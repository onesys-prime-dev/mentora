/*
|--------------------------------------------------------------------------
| The instructor Grades page
|--------------------------------------------------------------------------
|
| Fills the chosen section's grid, switches sections in place, finalizes from the page, and finalizes every
| ready section at once.
|
*/

import { setGradesApiBase } from "../courses/features/grades/api.js";
import { bindFinalizeDialog } from "../courses/features/grades/finalize/finalize-dialog.js";
import { bindFinalizeReady } from "./finalize-ready.js";
import { bindDownloadLinks, loadInto, switchSection } from "./section-switch.js";
import { bindNudge } from "./nudge.js";
import { bindProgram } from "./program.js";
import { bindStudentRows } from "./student-rows.js";

// Finalize ready sections: its confirm dialog, posted in place, the result shown in it.
bindFinalizeReady();

const grid = document.querySelector("[data-grades-grid]");

// The chairperson's Program tab owns its read-only grid (program.js): it is never loaded editable here.
if (grid?.dataset.readonly === "1") {
    bindProgram(document.body);
    bindNudge(document.body);
} else if (grid) {

    setGradesApiBase(grid.dataset.apiBase);

    // A section without a grading scheme is not fetched (that would create one); the page already says so.
    if (grid.dataset.hasScheme !== "0") {
        loadInto(grid);
    }

    bindDownloadLinks();

    // Bound once: it reads the section's address when it opens, so it follows each switch.
    bindFinalizeDialog(() => window.location.reload());

    document.querySelector("[data-section-cards]")?.addEventListener("click", (event) => {

        const card = event.target.closest("[data-section-card]");

        // Leave new-tab and new-window clicks to the link itself.
        if (!card || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();

        switchSection(card).catch((error) => console.error(error));

    });

}

// The student page: each course row opens in place.
const studentRows = document.querySelector("[data-grades-rows]");

if (studentRows) {
    bindStudentRows(studentRows);
}
