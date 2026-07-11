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

            button.classList.toggle(
                "border-b-2",
                button.dataset.courseFeature === feature
            );

        });
}

export function initializeFeatureLoader()
{
    loadFeature(window.coursesConfig.activeFeature);
}