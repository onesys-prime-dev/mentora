import { fetchAnnouncements } from "./api.js";
import { state } from "./state.js";
import { bindComposer } from "./composer.js";
import { bindCards } from "./card.js";
import { bindSidebar } from "./sidebar.js";
import { bindInfiniteScroll } from "./infinite-scroll.js";
import { rememberRows } from "../../../../shared/spa/skeleton-rows.js";
import { renderAnnouncementsSkeleton, renderAnnouncementsMainSkeleton } from "./skeleton.js";

let currentFilters = { search: "", sort: "newest", pinned: false };

function bindEverything(container) {

    rememberRows("announcements", container.querySelectorAll("[data-announcement-card]").length);

    const composerApi = bindComposer(container, refreshMain);

    bindCards(container, refreshMain, composerApi);

    bindSidebar(container, (patch) => refreshFiltered(patch));

    bindInfiniteScroll(
        container,
        currentFilters,
        () => bindCards(container, refreshMain, composerApi)
    );

}

/** A post to land on, e.g. from a Calendar link (?announcement=ID) — consumed once. */
function takeFocusedAnnouncement() {

    const params = new URLSearchParams(window.location.search);
    const id = Number(params.get("announcement"));

    if (!id) {
        return 0;
    }

    params.delete("announcement");

    const qs = params.toString();
    history.replaceState({}, "", window.location.pathname + (qs ? `?${qs}` : ""));

    return id;

}

function scrollToFocused(container, id) {

    const card = container.querySelector(`[data-announcement-card][data-announcement-id="${id}"]`);

    if (!card) {
        return;
    }

    card.scrollIntoView({ behavior: "smooth", block: "center" });

    card.classList.add("ring-2", "ring-[#0040A6]/40");

    setTimeout(() => card.classList.remove("ring-2", "ring-[#0040A6]/40"), 2500);

}

/** Full page — only on first entering this tab or a browser redirect into it. */
export async function initialize()
{
    const focusId = takeFocusedAnnouncement();

    state.loading = true;

    const container =
        document.querySelector("#course-content");

    container.innerHTML = renderAnnouncementsSkeleton();

    try {

        state.data = await fetchAnnouncements({ ...currentFilters, focus: focusId });

        container.innerHTML =
            state.data.html;

        bindEverything(container);

        if (focusId) {
            scrollToFocused(container, focusId);
        }

    }

    catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load Announcements.</p>";

    }

    finally {

        state.loading = false;

    }
}

/** Search/sort/pinned-filter changes: skeleton the main column only. */
async function refreshFiltered(patch) {
    currentFilters = { ...currentFilters, ...patch };
    await refreshMainAndSidebar();
}

/** Posting or deleting an announcement: same scoping as a filter change. */
async function refreshMain() {
    await refreshMainAndSidebar();
}

/**
 * Shows the skeleton only inside [data-announcements-main] while
 * re-fetching, then swaps in both the fresh main content and the fresh
 * sidebar (the sidebar itself never shows a skeleton — pinned-list/active-
 * filter changes are a small, instant swap, not a loading state).
 */
async function refreshMainAndSidebar() {

    const container = document.querySelector("#course-content");
    const main = container?.querySelector("[data-announcements-main]");

    if (!container || !main) {
        return initialize();
    }

    main.innerHTML = renderAnnouncementsMainSkeleton();

    try {

        state.data = await fetchAnnouncements(currentFilters);

        const parsed = document.createElement("div");
        parsed.innerHTML = state.data.html;

        const newMain = parsed.querySelector("[data-announcements-main]");
        const newSidebar = parsed.querySelector("[data-announcements-sidebar]");

        const liveMain = container.querySelector("[data-announcements-main]");
        if (newMain && liveMain) {
            liveMain.replaceWith(newMain);
        }

        const liveSidebar = container.querySelector("[data-announcements-sidebar]");
        if (newSidebar && liveSidebar) {
            liveSidebar.replaceWith(newSidebar);
        }

        bindEverything(container);

    }

    catch (error) {

        console.error(error);

        main.innerHTML = "<p>Unable to load Announcements.</p>";

    }

}
