import {
    updateModuleStatus,
} from "./api.js";

export function bindModuleStatusToggles() {

    document

        .querySelectorAll(
            "[data-module-status-toggle]"
        )

        .forEach((button) => {

            button.addEventListener(
                "click",
                async (event) => {

                    event.stopPropagation();

                    const module =

                        button.closest(
                            "[data-module-wrapper]"
                        );

                    const moduleId =
                        Number(
                            module.dataset.moduleId
                        );

                    const published =

                        button.dataset.status ===
                        "published";

                    const nextStatus =
                        published
                            ? "draft"
                            : "published";

                    applyStatus(
                        button,
                        nextStatus
                    );

                    try {

                        await updateModuleStatus(
                            moduleId,
                            nextStatus
                        );

                    }

                    catch {

                        applyStatus(
                            button,
                            published
                                ? "published"
                                : "draft"
                        );

                    }

                }

            );

        });

}

function applyStatus(
    button,
    status
) {

    button.dataset.status = status;

    button.classList.toggle(
        "bg-[#0040A6]",
        status === "published"
    );

    button.classList.toggle(
        "bg-gray-300",
        status === "draft"
    );

    const knob =
        button.querySelector(
            "[data-module-status-knob]"
        );

    knob.classList.toggle(
        "translate-x-11",
        status === "published"
    );

    const label =
        button.querySelector(
            "[data-module-status-label]"
        );

    label.textContent =
        status === "draft"
            ? "Draft"
            : "";

    label.classList.toggle(
        "justify-start",
        status === "published"
    );

    label.classList.toggle(
        "justify-end",
        status === "draft"
    );

    label.classList.toggle(
        "text-white",
        status === "published"
    );

    label.classList.toggle(
        "text-gray-700/50",
        status === "draft"
    );

}