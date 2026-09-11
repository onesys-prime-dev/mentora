import { fetchModules, fetchLearningItem } from "./api.js";
import { state } from "./state.js";

export async function initialize() {

    state.loading = true;

    const container =
        document.querySelector("#course-content");

    container.innerHTML = "Loading...";

    try {

        state.data = await fetchModules();

        container.innerHTML = state.data.html;

        bindModuleEvents();
        bindLearningItemEvents();

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

function bindLearningItemEvents() {

    document
        .querySelectorAll("[data-learning-item]")
        .forEach((item) => {

            item.addEventListener("click", () => {

                loadLearningItem(
                    Number(item.dataset.learningItem)
                );

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

    } catch (error) {

        console.error(error);

    } finally {

        state.loading = false;

    }

}