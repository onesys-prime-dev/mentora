/*
|--------------------------------------------------------------------------
| Announcement sidebar: search / filter / sort, and pinned-item scroll
|--------------------------------------------------------------------------
*/

let searchDebounceTimer;

/**
 * @param {HTMLElement} root
 * @param {(filters: object) => void} onFilterChange partial filter patch, merged by the caller
 */
export function bindSidebar(root, onFilterChange) {

    const search = root.querySelector("[data-announcements-search]");
    const sortSelect = root.querySelector("[data-announcements-sort]");
    const filterButtons = root.querySelectorAll("[data-filter]");
    const pinnedButtons = root.querySelectorAll("[data-scroll-to-announcement]");

    search?.addEventListener("input", () => {

        clearTimeout(searchDebounceTimer);

        searchDebounceTimer = setTimeout(() => {
            onFilterChange({ search: search.value.trim(), page: 1 });
        }, 350);

    });

    sortSelect?.addEventListener("change", () => {
        onFilterChange({ sort: sortSelect.value, page: 1 });
    });

    filterButtons.forEach((button) => {
        button.addEventListener("click", () => {
            onFilterChange({ pinned: button.dataset.filter === "pinned", page: 1 });
        });
    });

    pinnedButtons.forEach((button) => {

        button.addEventListener("click", () => {

            const target = root.querySelector(
                `[data-announcement-id="${button.dataset.scrollToAnnouncement}"]`
            );

            // The pinned sidebar list isn't filtered/paginated the same way
            // as the feed, so the target may not be on the current page —
            // scroll if it's there, otherwise do nothing rather than error.
            target?.scrollIntoView({ behavior: "smooth", block: "start" });

        });

    });

}
