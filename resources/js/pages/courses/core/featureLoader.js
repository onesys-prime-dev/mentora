import { supersede } from "../../../shared/spa/load-guard.js";

const loaders = {

    overview: () => import("../features/overview/index.js"),

    announcements: () => import("../features/announcements/index.js"),

    modules: () => import("../features/modules/index.js"),

    resources: () => import("../features/resources/index.js"),

    discussions: () => import("../features/discussions/index.js"),

    assessments: () => import("../features/assessments/index.js"),

    grades: () => import("../features/grades/index.js"),

};

// The server's skeleton is only adopted for the load that belongs to the tab
// the page was opened on; the first tab click gives it up.
let firstLoadOfThePage = true;

function adoptServerSkeleton(content) {

    const adopt = firstLoadOfThePage
        && content.dataset.serverSkeleton !== undefined
        && content.dataset.serverSkeleton !== "released";

    firstLoadOfThePage = false;

    if (!adopt) {
        releaseServerSkeleton(content);
    } else {
        // swapContent treats a region marked like this as "still a skeleton".
        content.dataset.spaSkeleton = "";
    }

    return adopt;

}

/** The server's skeleton has served its purpose (content arrived, or a tab click replaced it). */
function releaseServerSkeleton(content) {

    if (content) {
        delete content.dataset.serverSkeleton;

        // Left over only when the screen filled the region without swapContent.
        if (content.dataset.spaSkeleton !== undefined && content.querySelector("[data-server-skeleton-shape]") === null) {
            delete content.dataset.spaSkeleton;
        }
    }

}

// Bumped on every tab change so a slower, older tab can't start (or keep
// painting its shell) after the user has already moved on.
let latestLoad = 0;

/** Marks one course tab as the active one; does not load anything. */
export function highlightFeatureTab(feature)
{
    document
        .querySelectorAll("[data-course-feature]")
        .forEach(button => {
            const isActive = button.dataset.courseFeature === feature;

            button.classList.toggle("bg-white", isActive);
            button.classList.toggle("border-t", isActive);
            button.classList.toggle("border-black/15", isActive);
            button.classList.toggle("!text-black", isActive);

            // The active tab is white, so the dark hover would flip it back.
            button.classList.toggle("hover:bg-blue-800", !isActive);

            button.setAttribute("aria-selected", String(isActive));
        });
}

/**
 * Makes `feature` the active tab (highlight + URL) without loading its
 * list, for a click elsewhere that opens one of its inner screens directly
 * (e.g. an Overview row opening an assessment). Returns a "back" callback
 * that does load that tab's list, so Back lands where the tab says you are.
 */
export function enterFeatureTab(feature)
{
    history.pushState({}, "", `${window.coursesConfig.browserBaseUrl}/${feature}`);

    window.coursesConfig.activeFeature = feature;

    highlightFeatureTab(feature);

    return () => {

        history.pushState({}, "", `${window.coursesConfig.browserBaseUrl}/${feature}`);

        return loadFeature(feature);

    };
}

export async function loadFeature(feature)
{
    const loadId = ++latestLoad;

    const loader = loaders[feature];

    if (!loader) {

        console.error(`Unknown feature: ${feature}`);

        return;

    }

    const content = document.querySelector("#course-content");

    content?.setAttribute("aria-busy", "true");

    if (content) {

        // A slower request from the tab we just left must not paint over this
        // one, and its (now wrong) content shouldn't linger under the new tab.
        supersede(content);

        // On the page's first load the server already put this tab's skeleton in
        // place: keep it (so nothing blinks to empty) and let the screen replace it.
        // Any later load is a client navigation and starts from an empty region.
        if (!adoptServerSkeleton(content)) {
            content.replaceChildren();
        }

    }

    let module;

    try {
        module = await loader();
    } catch (error) {
        console.error(error);
        content?.removeAttribute("aria-busy");
        releaseServerSkeleton(content);
        return;
    }

    if (loadId !== latestLoad) {
        return;
    }

    // Not awaited: the tab highlight below shouldn't wait on the data fetch.
    Promise.resolve(module.initialize())
        .catch((error) => console.error(error))
        .finally(() => {
            if (loadId === latestLoad) {
                content?.removeAttribute("aria-busy");
                releaseServerSkeleton(content);
            }
        });

    highlightFeatureTab(feature);
}

export function initializeFeatureLoader()
{
    loadFeature(window.coursesConfig.activeFeature);
}