import { beginLoad } from "../../../../shared/spa/load-guard.js";
import { showLoadError } from "../../../../shared/spa/error-state.js";
import { fetchDiscussions, fetchMoreDiscussions } from "./api.js";
import { state } from "./state.js";
import { renderDiscussionsSkeleton, renderDiscussionListSkeleton } from "./skeleton.js";
import { rememberRows } from "../../../../shared/spa/skeleton-rows.js";
import { loadThread } from "./thread.js";
import { setupComposer } from "./composer.js";

let currentFilters = { search: "", sort: "activity", filter: "all" };

let searchTimer = null;

let openListenerBound = false;

/** Full page: only on first entering the tab or a browser redirect into it. */
export async function initialize()
{
    // A row click asks for its thread; ?thread=ID is the deep link / reload.
    if (!openListenerBound) {

        openListenerBound = true;

        document.addEventListener("discussions:open-thread", (event) => {
            loadThread(event.detail.id, initialize);
        });

    }

    const threadId = Number(new URLSearchParams(window.location.search).get("thread"));

    if (threadId) {
        await loadThread(threadId, initialize, { pushState: false });
        return;
    }

    state.loading = true;

    const container =
        document.querySelector("#course-content");

    const load = beginLoad(container, renderDiscussionsSkeleton());

    try {

        state.data = await fetchDiscussions(currentFilters);

        if (!load.current()) {
            return;
        }

        load.finish();

        container.innerHTML =
            state.data.html;

        bindScreen(container);

    }

    catch (error) {

        console.error(error);

        if (!load.current()) {
            return;
        }

        load.finish();

        showLoadError(container, "Discussions", initialize);

    }

    finally {

        state.loading = false;

    }
}

function bindScreen(container) {

    bindToolbar(container);
    bindResults(container);

    const composer = setupComposer(container, (mode, id) => loadThread(id, initialize));

    container.querySelector("[data-composer-open]")
        ?.addEventListener("click", () => composer?.open({ mode: "create" }));

}

/*
|--------------------------------------------------------------------------
| Toolbar — search, filter, sort. Each change re-renders only the rows.
|--------------------------------------------------------------------------
*/

function bindToolbar(container) {

    const search = container.querySelector("[data-discussions-search]");
    const filter = container.querySelector("[data-discussions-filter]");
    const sort = container.querySelector("[data-discussions-sort]");

    search?.addEventListener("input", () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => refreshResults({ search: search.value.trim() }), 300);
    });

    filter?.addEventListener("change", () => refreshResults({ filter: filter.value }));

    sort?.addEventListener("change", () => refreshResults({ sort: sort.value }));

}

async function refreshResults(patch) {

    currentFilters = { ...currentFilters, ...patch };

    const container = document.querySelector("#course-content");
    const results = container?.querySelector("[data-discussions-results]");

    if (!results) {
        return initialize();
    }

    const load = beginLoad(results, renderDiscussionListSkeleton(), 0);

    try {

        state.data = await fetchDiscussions(currentFilters);

        if (!load.current()) {
            return;
        }

        load.finish();

        const parsed = document.createElement("div");
        parsed.innerHTML = state.data.html;

        const fresh = parsed.querySelector("[data-discussions-results]");

        if (fresh) {
            results.replaceWith(fresh);
            bindResults(container);
        }

    }

    catch (error) {

        console.error(error);

        if (!load.current()) {
            return;
        }

        load.finish();

        showLoadError(results, "Discussions", () => refreshResults({}));

    }

}

/*
|--------------------------------------------------------------------------
| Thread rows — remember the list size, wire clicks, load more on scroll
|--------------------------------------------------------------------------
*/

function bindResults(container) {

    const results = container.querySelector("[data-discussions-results]");

    if (!results) {
        return;
    }

    rememberRows("discussions", results.querySelectorAll("[data-thread-id]").length);

    bindRows(results);
    bindInfiniteScroll(results);

}

function bindRows(root) {

    root.querySelectorAll("[data-thread-open]").forEach((row) => {

        if (row.dataset.rowBound === "1") {
            return;
        }

        row.dataset.rowBound = "1";

        const open = () => document.dispatchEvent(
            new CustomEvent("discussions:open-thread", { detail: { id: row.dataset.threadOpen } })
        );

        row.addEventListener("click", open);

        row.addEventListener("keydown", (event) => {
            if (event.target === row && (event.key === "Enter" || event.key === " ")) {
                event.preventDefault();
                open();
            }
        });

    });

}

function bindInfiniteScroll(results) {

    const sentinel = results.querySelector("[data-discussions-sentinel]");
    const indicator = results.querySelector("[data-discussions-loading]");

    let hasMore = results.dataset.hasMore === "1";
    let nextPage = parseInt(results.dataset.nextPage, 10) || 2;
    let loading = false;

    if (!sentinel) {
        return;
    }

    if (!hasMore) {
        sentinel.remove();
        return;
    }

    const observer = new IntersectionObserver(async (entries) => {

        if (!entries[0].isIntersecting || loading || !hasMore) {
            return;
        }

        loading = true;
        indicator?.classList.remove("hidden");

        try {

            const result = await fetchMoreDiscussions({ ...currentFilters, page: nextPage });

            if (result.html) {
                sentinel.insertAdjacentHTML("beforebegin", result.html);
                bindRows(results);
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
            indicator?.classList.add("hidden");

        }

    }, { rootMargin: "200px" });

    observer.observe(sentinel);

}
