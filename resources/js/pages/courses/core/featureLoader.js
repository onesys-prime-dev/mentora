const loaders = {

    overview: () => import("../features/overview/index.js"),

    announcements: () => import("../features/announcements/index.js"),

    modules: () => import("../features/modules/index.js"),

    resources: () => import("../features/resources/index.js"),

    discussions: () => import("../features/discussions/index.js"),

    assessments: () => import("../features/assessments/index.js"),

    grades: () => import("../features/grades/index.js"),

};

export async function loadFeature(feature)
{
    const loader = loaders[feature];

    if (!loader) {

        console.error(`Unknown feature: ${feature}`);

        return;

    }

    const module = await loader();

    module.initialize();

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

export function initializeFeatureLoader()
{
    loadFeature(window.coursesConfig.activeFeature);
}