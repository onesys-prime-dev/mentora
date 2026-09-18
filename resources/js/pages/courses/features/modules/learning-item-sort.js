/*
|--------------------------------------------------------------------------
| Learning Item Sorting
|--------------------------------------------------------------------------
*/

import Sortable from "sortablejs";

import {

    reorderLearningItems,

} from "./api.js";

let isDragging = false;

export function initializeLearningItemSorting() {

    document

        .querySelectorAll("[data-learning-unit-items]")

        .forEach((container) => {

            Sortable.create(container, {

                animation: 200,

                handle:
                    "[data-learning-item-drag-handle]",

                draggable:
                    "[data-learning-item-row]",

                ghostClass:
                    "opacity-50",

                chosenClass:
                    "scale-[1.01]",

                dragClass:
                    "rotate-[1deg]",

                onChoose: (event) => {

                    isDragging = false;

                    event.item.classList.add(
                        "bg-gray-50",
                        "border",
                        "border-gray-200",
                        "rounded-2xl"
                    );

                },

                onStart: () => {

                    isDragging = true;

                },

                onUnchoose: (event) => {

                    if (!isDragging) {

                        event.item.classList.remove(
                            "bg-gray-50",
                            "border",
                            "border-gray-200",
                            "rounded-2xl"
                        );

                    }

                },

                onEnd: async (event) => {

                    const items =

                        Array.from(

                            container.querySelectorAll(
                                "[data-learning-item-row]"
                            )

                        ).map((row, index) => ({

                            id: Number(

                                row.querySelector(
                                    "[data-learning-item-wrapper]"
                                ).dataset.learningItem

                            ),

                            order_index:
                                index + 1,

                        }));

                    await reorderLearningItems(
                        items
                    );

                    event.item.classList.remove(
                        "bg-gray-50",
                        "border",
                        "border-gray-200",
                        "rounded-2xl"
                    );

                    isDragging = false;

                },

            });

        });

}