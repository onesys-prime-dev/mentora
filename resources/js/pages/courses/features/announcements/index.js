import { fetchAnnouncements } from "./api.js";
import { state } from "./state.js";
import { bindComposer } from "./composer.js";
import { bindCards } from "./card.js";
import { bindSidebar } from "./sidebar.js";
import { bindInfiniteScroll } from "./infinite-scroll.js";
import { renderAnnouncementsSkeleton } from "./skeleton.js";

let currentFilters = { search: "", sort: "newest", pinned: false };

export async function initialize(filterPatch = null)
{
    if (filterPatch) {
        currentFilters = { ...currentFilters, ...filterPatch };
    }

    state.loading = true;

    const container =
        document.querySelector("#course-content");

    container.innerHTML = renderAnnouncementsSkeleton();

    try {

        state.data = await fetchAnnouncements(currentFilters);

        container.innerHTML =
            state.data.html;

        const composerApi = bindComposer(container, () => initialize());

        bindCards(container, () => initialize(), composerApi);

        bindSidebar(container, (patch) => initialize(patch));

        bindInfiniteScroll(
            container,
            currentFilters,
            () => bindCards(container, () => initialize(), composerApi)
        );

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
