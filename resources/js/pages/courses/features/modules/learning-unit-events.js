/*
|--------------------------------------------------------------------------
| Learning Unit Accordion
|--------------------------------------------------------------------------
*/

import { state } from "./state.js";

export function bindLearningUnitEvents() {

    document
        .querySelectorAll("[data-learning-unit]")
        .forEach((unit) => {

            unit.addEventListener("click", (event) => {

                /*
                |--------------------------------------------------------------------------
                | Ignore drag handle
                |--------------------------------------------------------------------------
                */

                if (
                    event.target.closest(
                        "[data-learning-unit-drag-handle]"
                    )
                ) {
                    return;
                }

                const learningUnitId =
                    unit.dataset.learningUnit;

                toggleLearningUnit(
                    learningUnitId
                );

            });

        });

}

function toggleLearningUnit(
    learningUnitId
) {

    const content =
        document.querySelector(
            `[data-learning-unit-content="${learningUnitId}"]`
        );

    const arrow =
        document.querySelector(
            `[data-learning-unit-arrow="${learningUnitId}"]`
        );

    if (!content || !arrow) {
        return;
    }

    state.activeLearningUnitId =
        Number(learningUnitId);

    updateLearningUnitWrappers();

    const isOpen =
        content.classList.contains(
            "max-h-[3000px]"
        );

    if (isOpen) {

        state.activeLearningUnitId = null;

        updateLearningUnitWrappers();

        closeLearningUnit(
            content,
            arrow
        );

        return;

    }

    openLearningUnit(
        content,
        arrow
    );

}

function openLearningUnit(
    content,
    arrow
) {

    content.classList.remove(
        "max-h-0",
        "opacity-0"
    );

    content.classList.add(
        "max-h-[3000px]",
        "opacity-100"
    );

    arrow.classList.add(
        "rotate-180"
    );

}

function closeLearningUnit(
    content,
    arrow
) {

    content.classList.remove(
        "max-h-[3000px]",
        "opacity-100"
    );

    content.classList.add(
        "max-h-0",
        "opacity-0"
    );

    arrow.classList.remove(
        "rotate-180"
    );

}

export function updateLearningUnitWrappers() {

    document

        .querySelectorAll("[data-learning-unit-row]")

        .forEach((row) => {

            const wrapper =

                row.querySelector(
                    "[data-learning-unit-wrapper]"
                );

            if (!wrapper) {
                return;
            }

            const unitId =
                Number(
                    wrapper.dataset.learningUnitId
                );

            row.classList.toggle(
                "bg-gray-50",
                unitId === state.activeLearningUnitId
            );

            row.classList.toggle(
                "border",
                unitId === state.activeLearningUnitId
            );

            row.classList.toggle(
                "border-gray-200",
                unitId === state.activeLearningUnitId
            );

            row.classList.toggle(
                "rounded-2xl",
                unitId === state.activeLearningUnitId
            );

        });

}