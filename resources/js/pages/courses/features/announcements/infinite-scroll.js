/*
|--------------------------------------------------------------------------
| Infinite scroll: appends further pages of cards as the sentinel at the
| end of the list enters the viewport, instead of loading everything up
| front.
|--------------------------------------------------------------------------
*/

import { fetchMoreAnnouncements } from "./api.js";

/**
 * @param {HTMLElement} root
 * @param {object} filters current search/sort/pinned filters (without page)
 * @param {() => void} onAppended called after new cards are inserted, so the caller can (re)bind them
 */
export function bindInfiniteScroll(root, filters, onAppended) {

    const list = root.querySelector("[data-announcement-list]");
    const sentinel = list?.querySelector("[data-announcements-sentinel]");
    const loadingIndicator = list?.querySelector("[data-announcements-loading]");

    if (!list || !sentinel) {
        return;
    }

    let hasMore = list.dataset.hasMore === "1";
    let nextPage = parseInt(list.dataset.nextPage, 10) || 2;
    let loading = false;

    if (!hasMore) {
        sentinel.remove();
        return;
    }

    const observer = new IntersectionObserver(async (entries) => {

        if (!entries[0].isIntersecting || loading || !hasMore) {
            return;
        }

        loading = true;
        loadingIndicator?.classList.remove("hidden");

        try {

            const result = await fetchMoreAnnouncements({ ...filters, page: nextPage });

            if (result.html) {
                sentinel.insertAdjacentHTML("beforebegin", result.html);
                onAppended?.();
            }

            hasMore = result.has_more;
            nextPage = result.next_page;

            if (!hasMore) {
                observer.disconnect();
                sentinel.remove();
            }

        } catch (error) {

            console.error(error);

        } finally {

            loading = false;
            loadingIndicator?.classList.add("hidden");

        }

    }, { rootMargin: "200px" });

    observer.observe(sentinel);

}
