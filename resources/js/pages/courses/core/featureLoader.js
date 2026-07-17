const loaders = {

    home: () => import("../features/home/index.js"),

    modules: () => import("../features/modules/index.js"),

    resources: () => import("../features/resources/index.js"),

    discussions: () => import("../features/discussions/index.js"),

    assessments: () => import("../features/assessments/index.js"),

    grade: () => import("../features/grade/index.js"),

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
        });
}

export function initializeFeatureLoader()
{
    loadFeature(window.coursesConfig.activeFeature);
}