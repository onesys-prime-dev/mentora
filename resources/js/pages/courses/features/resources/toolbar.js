/*
|--------------------------------------------------------------------------
| Resources toolbar: search / sort / type filter / list-grid toggle
|--------------------------------------------------------------------------
*/

let searchDebounceTimer;

/**
 * @param {HTMLElement} root
 * @param {(patch: object) => void} onChange partial params patch, merged by the caller
 */
export function bindToolbar(root, onChange) {

    const search = root.querySelector("[data-resources-search]");
    const sortSelect = root.querySelector("[data-resources-sort]");
    const typeSelect = root.querySelector("[data-resources-type]");
    const viewButtons = root.querySelectorAll("[data-resources-view-toggle] [data-view]");

    search?.addEventListener("input", () => {

        clearTimeout(searchDebounceTimer);

        searchDebounceTimer = setTimeout(() => {
            onChange({ search: search.value.trim() });
        }, 350);

    });

    sortSelect?.addEventListener("change", () => {
        onChange({ sort: sortSelect.value });
    });

    typeSelect?.addEventListener("change", () => {
        onChange({ type: typeSelect.value });
    });

    viewButtons.forEach((button) => {
        button.addEventListener("click", () => {
            onChange({ view: button.dataset.view });
        });
    });

}
