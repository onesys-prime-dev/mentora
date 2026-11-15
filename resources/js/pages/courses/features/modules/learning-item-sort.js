/*
|--------------------------------------------------------------------------
| Learning Item Sorting
|--------------------------------------------------------------------------
*/

import Sortable from "sortablejs";

import {

    reorderLearningItems,

} from "./api.js";

import { bindMoveButtons, orderSaver, refreshMoveButtons } from "./move-buttons.js";

let isDragging = false;

let reload = null;

const MOVE = { row: "[data-learning-item-row]", attr: "data-learning-item-move", noun: "Learning item", status: "[data-module-move-status]" };

/** Saves one unit's order on screen: a drop and the ▲ ▼ buttons share it. */
export const saveLearningItemOrder = orderSaver(

    (container) => reorderLearningItems(

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

        }))

    ),

    () => reload

);

/** @param {() => unknown} [refresh] reloads the module after a failed save */
export function initializeLearningItemSorting(refresh) {

    reload = refresh ?? null;

    document

        .querySelectorAll("[data-learning-unit-items]")

        .forEach((container) => {

            bindMoveButtons(container, { ...MOVE, persist: saveLearningItemOrder });

            Sortable.create(container, {

                animation: 200,

                handle:
                    "[data-learning-item-drag-handle]",

                draggable:
                    "[data-learning-item-row]",

                // A touch on a hybrid screen has to rest on the handle before it drags, so a swipe still scrolls.
                delay: 200,

                delayOnTouchOnly: true,

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

                    refreshMoveButtons(container, MOVE);

                    await saveLearningItemOrder(
                        container
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