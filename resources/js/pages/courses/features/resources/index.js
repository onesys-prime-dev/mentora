import { fetchResources } from "./api.js";
import { state } from "./state.js";
import { renderResourcesSkeleton, renderResourcesContentSkeleton } from "./skeleton.js";
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
 * own popstate handler (core/navigation.js) on back/forward — those are
 * the only cases that skeleton the whole page, and neither pushes history.
 * A patch only ever comes from this module's own toolbar/nav callbacks
 * (filter, sort, search, list/grid, entering or leaving a folder): those
 * skeleton just the area below the breadcrumb and push a history entry.
 */
export async function initialize(patch = null)
{
    if (patch) {
        return refreshBelowBreadcrumb(patch);
    }

    currentParams = paramsFromUrl();

    state.loading = true;

    const container =
        document.querySelector("#course-content");

    container.innerHTML = renderResourcesSkeleton();

    try {

        state.data = await fetchResources(currentParams);

        container.innerHTML =
            state.data.html;

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

/**
 * Keeps the heading, toolbar and breadcrumb on screen, skeletons only the
 * content below the breadcrumb, then swaps in the fresh breadcrumb, content
 * and (unless the user is typing in it) toolbar — the toolbar's controls
 * differ by level, e.g. type filter and list/grid only exist inside a unit.
 */
async function refreshBelowBreadcrumb(patch)
{
    currentParams = { ...currentParams, ...patch };

    const container = document.querySelector("#course-content");
    const content = container?.querySelector("[data-resources-content]");

    if (!container || !content) {
        return initialize();
    }

    content.innerHTML = renderResourcesContentSkeleton();

    state.loading = true;

    try {

        state.data = await fetchResources(currentParams);

        const parsed = document.createElement("div");
        parsed.innerHTML = state.data.html;

        const rebind = (liveSelector, onNode) => {

            const fresh = parsed.querySelector(liveSelector);
            const live = container.querySelector(liveSelector);

            if (fresh && live) {
                live.replaceWith(fresh);
                onNode(fresh);
            }

        };

        const typing = document.activeElement?.matches("[data-resources-search]");

        if (!typing) {
            rebind("[data-resources-toolbar]", (node) => bindToolbar(node, (p) => initialize(p)));
        }

        rebind("[data-resources-breadcrumb]", (node) => bindNavigation(node, (p) => initialize(p)));

        rebind("[data-resources-content]", (node) => {
            bindNavigation(node, (p) => initialize(p));
            bindCards(node);
            scrollToHashedResource(node);
        });

        pushUrl(currentParams);

    }

    catch (error) {

        console.error(error);

        content.innerHTML = "<p>Unable to load Resources.</p>";

    }

    finally {

        state.loading = false;

    }
}
