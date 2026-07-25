import { fetchModules, fetchLearningItem } from "./api.js";
import { state } from "./state.js";
import { initializePdfViewer } from "../../../../shared/pdf/viewer.js";

export async function initialize() {

    const params = new URLSearchParams(window.location.search);

    const learningItemId = params.get("item");

    if (learningItemId) {

        await loadLearningItem(Number(learningItemId));
        return;

    }

    state.loading = true;

    const container =
        document.querySelector("#course-content");

    container.innerHTML = "Loading...";

    try {

        state.data = await fetchModules();

        container.innerHTML = state.data.html;

        bindModuleEvents();
        bindLearningItemEvents();
        bindBreadcrumbEvents();
        initializePdfViewer();

    }

    catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load Modules.</p>";

    }

    finally {

        state.loading = false;

    }

}

function bindModuleEvents() {

    const modules =
        document.querySelectorAll("[data-module]");

    modules.forEach((module) => {

        module.addEventListener("click", () => {

            const id = module.dataset.module;

            const content =
                document.querySelector(
                    `[data-module-content="${id}"]`
                );

            const arrow =
                document.querySelector(
                    `[data-module-arrow="${id}"]`
                );

            const isOpen =
                content.classList.contains("max-h-[2000px]");

            // Close every module first
            document
                .querySelectorAll("[data-module-content]")
                .forEach((element) => {

                    element.classList.remove(
                        "max-h-[2000px]",
                        "opacity-100",
                        "pb-6"
                    );

                    element.classList.add(
                        "max-h-0",
                        "opacity-0"
                    );

                });

            document
                .querySelectorAll("[data-module-arrow]")
                .forEach((element) => {

                    element.classList.remove("rotate-180");

                });

            if (isOpen) {
                return;
            }

            content.classList.remove(
                "max-h-0",
                "opacity-0"
            );

            content.classList.add(
                "max-h-[2000px]",
                "opacity-100",
                "pb-6"
            );

            arrow.classList.add("rotate-180");

        });

    });

}

async function loadLearningItem(learningItemId) {

    try {

        state.loading = true;

        const response =
            await fetchLearningItem(learningItemId);

        const container =
            document.querySelector("#course-content");

        container.innerHTML = response.html;

        history.pushState(
            { learningItemId },
            "",
            `${window.location.pathname}?item=${learningItemId}`
        );

        bindLearningItemEvents();
        bindLearningUnitEvents();
        bindBreadcrumbEvents();
        initializePdfViewer();

    } finally {

        state.loading = false;

    }

}

function bindLearningItemEvents() {

    document
        .querySelectorAll("[data-learning-item]")
        .forEach((element) => {

            element.addEventListener("click", () => {

                const id =
                    Number(element.dataset.learningItem);

                if (!id) {
                    return;
                }

                loadLearningItem(id);

            });

        });

}

function bindLearningUnitEvents() {

    document
        .querySelectorAll("[data-learning-unit]")
        .forEach((header) => {

            header.addEventListener("click", () => {

                const container =
                    header.nextElementSibling;

                const arrow =
                    header.querySelector("svg");

                const isHidden =
                    container.classList.contains("hidden");

                if (isHidden) {

                    container.classList.remove("hidden");
                    arrow.classList.add("rotate-180");

                } else {

                    container.classList.add("hidden");
                    arrow.classList.remove("rotate-180");

                }

            });

        });

}

function bindBreadcrumbEvents() {

    const breadcrumb =
        document.querySelector("[data-modules-breadcrumb]");

    if (!breadcrumb) {
        return;
    }

    breadcrumb.addEventListener("click", () => {

        history.pushState(
            {},
            "",
            window.location.pathname
        );

        initialize();

    });

}