import {
    updateLearningUnitStatus,
} from "./api.js";

export function bindLearningUnitStatusToggles() {

    document

        .querySelectorAll(
            "[data-learning-unit-status-toggle]"
        )

        .forEach((button) => {

            button.addEventListener(

                "click",

                async (event) => {

                    event.stopPropagation();

                    const wrapper =

                        button.closest(
                            "[data-learning-unit-wrapper]"
                        );

                    const learningUnitId =
                        Number(
                            wrapper.dataset.learningUnitId
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

                        await updateLearningUnitStatus(

                            learningUnitId,

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

    button.dataset.status =
        status;

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
            "[data-learning-unit-status-knob]"
        );

    knob.classList.toggle(
        "translate-x-[2.25rem]",
        status === "published"
    );

    const label =

        button.querySelector(
            "[data-learning-unit-status-label]"
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