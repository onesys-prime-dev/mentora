/*
|--------------------------------------------------------------------------
| Learning Unit Sorting
|--------------------------------------------------------------------------
*/

import Sortable from "sortablejs";

import {
    reorderLearningUnits,
} from "./api.js";

import { state } from "./state.js";

import {
    updateLearningUnitWrappers,
} from "./learning-unit-events.js";

export function initializeLearningUnitSorting() {

    document

        .querySelectorAll("[data-module-content]")

        .forEach((moduleContent) => {

            Sortable.create(moduleContent, {

                animation: 200,

                handle:
                    "[data-learning-unit-drag-handle]",

                draggable:
                    "[data-learning-unit-row]",

                ghostClass:
                    "opacity-50",

                chosenClass:
                    "scale-[1.01]",

                dragClass:
                    "rotate-[1deg]",

                onChoose: (event) => {

                    const wrapper =

                        event.item.querySelector(
                            "[data-learning-unit-wrapper]"
                        );

                    state.activeLearningUnitId =
                        Number(
                            wrapper.dataset.learningUnitId
                        );

                    updateLearningUnitWrappers();

                },

                onEnd: async (event) => {

                    const wrapper =

                        event.item.querySelector(
                            "[data-learning-item-wrapper]"
                        );

                    state.activeLearningUnitId =
                        Number(
                            wrapper.dataset.learningUnitId
                        );

                    updateDisplayedOrder(
                        moduleContent
                    );

                    const units =

                        Array.from(

                            moduleContent.querySelectorAll(
                                "[data-learning-unit-row]"
                            )

                        ).map((row, index) => ({

                            id: Number(

                                row.querySelector(
                                    "[data-learning-unit-wrapper]"
                                ).dataset.learningUnitId

                            ),

                            order_index:
                                index + 1,

                        }));

                    await reorderLearningUnits(
                        units
                    );

                    updateLearningUnitWrappers();

                },

            });

        });

}

function updateDisplayedOrder(
    moduleContent
) {

    const moduleOrder =

        moduleContent

            .closest(
                "[data-module-wrapper]"
            )

            .querySelector(
                "[data-module-order]"
            )

            .textContent

            .replace(
                "MODULE ",
                ""
            );

    moduleContent

        .querySelectorAll(
            "[data-learning-unit-row]"
        )

        .forEach((row, index) => {

            const label = row.querySelector(
                "[data-learning-unit-order]"
            );

            if (!label) {
                return;
            }

            const title =

                label.textContent
                    .split(":")
                    .slice(1)
                    .join(":")
                    .trim();

            label.textContent =
                `Unit ${moduleOrder}.${index + 1}: ${title}`;

        });

}