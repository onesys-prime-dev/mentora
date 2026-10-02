/*
|--------------------------------------------------------------------------
| Resources navigation: breadcrumb crumbs + folder (module/unit) rows
|--------------------------------------------------------------------------
*/

/**
 * @param {HTMLElement} root
 * @param {(patch: object) => void} onNavigate params patch, merged by the caller —
 *   every patch here explicitly nulls/clears module/unit/search/type so moving
 *   to a different folder doesn't drag the previous folder's filters along.
 */
export function bindNavigation(root, onNavigate) {

    root.querySelector("[data-resources-crumb-root]")?.addEventListener("click", () => {
        onNavigate({ module: null, unit: null, search: "", type: "" });
    });

    root.querySelector("[data-resources-crumb-module]")?.addEventListener("click", (event) => {
        const moduleId = event.currentTarget.dataset.resourcesCrumbModule;
        onNavigate({ module: moduleId, unit: null, search: "", type: "" });
    });

    root.querySelectorAll("[data-resources-open-module]").forEach((row) => {
        row.addEventListener("click", () => {
            onNavigate({ module: row.dataset.resourcesOpenModule, unit: null, search: "", type: "" });
        });
    });

    root.querySelectorAll("[data-resources-open-unit]").forEach((row) => {
        row.addEventListener("click", () => {
            onNavigate({ unit: row.dataset.resourcesOpenUnit, search: "", type: "" });
        });
    });

}
