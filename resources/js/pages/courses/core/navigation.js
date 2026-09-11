import { loadFeature } from "./featureLoader.js";

export function initializeNavigation()
{
    document.addEventListener("click", (event) => {

        const button = event.target.closest("[data-course-feature]");

        if (!button) {
            return;
        }

        const feature = button.dataset.courseFeature;

        history.pushState(
            {},
            "",
            `${window.coursesConfig.browserBaseUrl}/${feature}`
        );

        window.coursesConfig.activeFeature = feature;

        loadFeature(feature);
    });

    window.addEventListener("popstate", () => {

        const feature = location.pathname.split("/").at(-1);

        window.coursesConfig.activeFeature = feature;

        loadFeature(feature);
    });
}