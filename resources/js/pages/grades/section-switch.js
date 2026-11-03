/*
|--------------------------------------------------------------------------
| Switching sections on the Grades page without a reload
|--------------------------------------------------------------------------
|
| A section card is a link (it works without JavaScript). With JavaScript, a click saves any score still
| waiting for its debounce, points the gradebook calls at the chosen section, loads that section's grid
| and redraws the action bar. Everything is derived from the card's id and data attributes, never its text.
|
| A section without a grading scheme (data-has-scheme="0") is never fetched, since opening its grid creates
| a scheme: the grid area says so instead and links to the course, where setting it up is a step of its own.
|
*/

import { setGradesApiBase } from "../courses/features/grades/api.js";
import { downloadReport } from "../courses/features/grades/reports/report-menu.js";
import { flushPendingScores, loadGradebookRoster } from "../courses/features/grades/roster/roster.js";

export const GRID_QUERY = "embedded=1";

// Only the latest switch may touch the page: an earlier one that finishes late is dropped.
let latest = 0;

/**
 * The address of the same page with another section chosen; term, tab and any other parameter stay.
 */
export function sectionHref(currentHref, sectionId)
{
    const url = new URL(currentHref, window.location.origin);

    url.searchParams.set("section", String(sectionId));

    return url.pathname + url.search + url.hash;
}

// "/instructor/api/courses/5" -> "/instructor" (everything before /api/courses/{id}).
const areaOf = (apiBase) => apiBase.replace(/\/api\/courses\/\d+$/, "");

// The section's own Grades tab in its course.
const courseGradesHref = (card, apiBase) => `${areaOf(apiBase)}/courses/${card.dataset.sectionCard}/${card.dataset.slug}/grades`;

function redrawActionBar(card, apiBase)
{
    const id = card.dataset.sectionCard;
    const bar = document.querySelector("[data-section-actions]");

    if (!bar) {
        return;
    }

    bar.dataset.sectionId = id;
    bar.querySelector("[data-action-label]")?.replaceChildren(card.dataset.label ?? "");
    bar.querySelector("[data-action-note]")?.replaceChildren(card.dataset.note ?? "");

    bar.querySelector("[data-download-link=\"class\"]")?.setAttribute("href", `${apiBase}/grades/report/class?format=xlsx`);
    bar.querySelector("[data-download-link=\"summary\"]")?.setAttribute("href", `${apiBase}/grades/report/summary?format=pdf`);
    bar.querySelector("[data-open-in-course]")?.setAttribute("href", courseGradesHref(card, apiBase));

    const finalize = bar.querySelector("[data-open-finalize]");

    if (finalize) {
        // Without a scheme too: the Finalize dialog reads the checklist, and that would create one.
        finalize.hidden = card.dataset.state === "finalized" || card.dataset.offeringStatus !== "active" || card.dataset.hasScheme === "0";
    }
}

// The sentence for a section without a scheme, from the page's template, its link pointing at the course.
function showNoScheme(grid, href)
{
    const template = document.querySelector("[data-no-scheme-template]");
    const state = template ? template.content.cloneNode(true) : document.createTextNode("Grading isn't set up for this section yet.");

    state.querySelector?.("[data-no-scheme-open]")?.setAttribute("href", href);

    grid.replaceChildren(state);
}

/**
 * The action bar's Download links: fetched rather than followed, so a refusal ("no grading yet") is a message,
 * not a page of JSON. Each reads its address when clicked, so it follows a card switch; the plain address
 * stays for a browser without the script, and new-tab clicks are left to the link.
 */
export function bindDownloadLinks(bar = document.querySelector("[data-section-actions]"))
{
    bar?.addEventListener("click", async (event) => {

        const link = event.target.closest("[data-download-link]");

        if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();

        link.closest("details")?.removeAttribute("open");

        await downloadReport(link.getAttribute("href"));

    });
}

// Loads the chosen section's grid into its own slot of the grid, as the newest load: any load still in flight
// from before writes into a slot that is no longer on the page.
export function loadInto(grid)
{
    const slot = document.createElement("div");

    grid.replaceChildren(slot);

    return loadGradebookRoster(null, { container: slot, query: GRID_QUERY });
}

export async function switchSection(card)
{
    const grid = document.querySelector("[data-grades-grid]");

    if (!grid) {
        return;
    }

    const mine = ++latest;

    await flushPendingScores();

    if (mine !== latest) {
        return;
    }

    const id = card.dataset.sectionCard;
    const apiBase = `${areaOf(grid.dataset.apiBase)}/api/courses/${id}`;

    setGradesApiBase(apiBase);

    grid.dataset.apiBase = apiBase;
    grid.dataset.sectionId = id;

    document.querySelectorAll("[data-section-card]").forEach((other) => {
        if (other === card) {
            other.setAttribute("aria-current", "true");
        } else {
            other.removeAttribute("aria-current");
        }
    });

    redrawActionBar(card, apiBase);

    // The Gradebook tab keeps the chosen section, as a reload would have it.
    const tab = document.querySelector("[data-grades-tab=\"gradebook\"]");

    tab?.setAttribute("href", sectionHref(tab.getAttribute("href"), id));

    window.history.replaceState(null, "", sectionHref(window.location.href, id));

    grid.dataset.hasScheme = card.dataset.hasScheme === "0" ? "0" : "1";

    if (grid.dataset.hasScheme === "0") {
        showNoScheme(grid, courseGradesHref(card, apiBase));
        return;
    }

    await loadInto(grid);
}
