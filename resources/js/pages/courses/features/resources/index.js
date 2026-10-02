import { fetchResources } from "./api.js";
import { state } from "./state.js";
import { renderResourcesSkeleton } from "./skeleton.js";
import { bindToolbar } from "./toolbar.js";
import { bindNavigation } from "./navigation.js";
import { bindCards, scrollToHashedResource } from "./card.js";

let currentParams = { module: null, unit: null, search: "", sort: "name", type: "", view: "list" };

function paramsFromUrl() {

    const query = new URLSearchParams(window.location.search);

    return {
        module: query.get("module") || null,
        unit: query.get("unit") || null,
        search: query.get("search") || "",
        sort: query.get("sort") || "name",
        type: query.get("type") || "",
        view: query.get("view") || "list",
    };

}

function pushUrl(params) {

    const query = new URLSearchParams();

    if (params.module) { query.set("module", params.module); }
    if (params.unit) { query.set("unit", params.unit); }
    if (params.search) { query.set("search", params.search); }
    if (params.sort && params.sort !== "name") { query.set("sort", params.sort); }
    if (params.type) { query.set("type", params.type); }
    if (params.view && params.view !== "list") { query.set("view", params.view); }

    const qs = query.toString();

    history.pushState({}, "", window.location.pathname + (qs ? `?${qs}` : ""));

}

/**
 * Called with no args both on first entering this tab AND by the shell's
 * own popstate handler (core/navigation.js) on back/forward — in neither
 * case should we push a new history entry, only read the URL as it is.
 * A patch only ever comes from this module's own toolbar/nav callbacks,
 * which is exactly when a new entry is wanted.
 */
export async function initialize(patch = null)
{
    currentParams = patch
        ? { ...currentParams, ...patch }
        : paramsFromUrl();

    state.loading = true;

    const container =
        document.querySelector("#course-content");

    container.innerHTML = renderResourcesSkeleton();

    try {

        state.data = await fetchResources(currentParams);

        container.innerHTML =
            state.data.html;

        if (patch) {
            pushUrl(currentParams);
        }

        bindToolbar(container, (toolbarPatch) => initialize(toolbarPatch));
        bindNavigation(container, (navPatch) => initialize(navPatch));
        bindCards(container);

        scrollToHashedResource(container);

    }

    catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load Resources.</p>";

    }

    finally {

        state.loading = false;

    }
}
