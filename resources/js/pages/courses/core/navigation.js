import { loadFeature } from "./featureLoader.js";

export function initializeNavigation()
{
    document
        .querySelectorAll("[data-course-feature]")
        .forEach(button => {

            button.addEventListener("click", () => {

                const feature =
                    button.dataset.courseFeature;

                history.pushState(

                    {},

                    "",

                    `${window.coursesConfig.browserBaseUrl}/${feature}`

                );

                window.coursesConfig.activeFeature =
                    feature;

                loadFeature(feature);

            });

        });

    window.addEventListener(

        "popstate",

        () => {

            const feature =
                location.pathname
                    .split("/")
                    .at(-1);

            window.coursesConfig.activeFeature =
                feature;

            loadFeature(feature);

        }

    );
}