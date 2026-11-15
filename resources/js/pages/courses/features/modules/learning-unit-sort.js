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

import { bindMoveButtons, orderSaver, refreshMoveButtons } from "./move-buttons.js";

let reload = null;

const MOVE = { row: "[data-learning-unit-row]", attr: "data-learning-unit-move", noun: "Learning unit", status: "[data-module-move-status]" };

/** Saves the order on screen: a drop and the ▲ ▼ buttons share it. */
export const saveLearningUnitOrder = orderSaver(

    (moduleContent) => reorderLearningUnits(

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

        }))

    ),

    () => reload

);

/** @param {() => unknown} [refresh] reloads the module after a failed save */
export function initializeLearningUnitSorting(refresh) {

    reload = refresh ?? null;

    document

        .querySelectorAll("[data-learning-unit-list]")

        .forEach((moduleContent) => {

            bindMoveButtons(moduleContent, { ...MOVE, persist: saveLearningUnitOrder });

            Sortable.create(moduleContent, {

                animation: 200,

                handle:
                    "[data-learning-unit-drag-handle]",

                draggable:
                    "[data-learning-unit-row]",

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

                    // The unit's own wrapper (this read an item's before, which an empty unit does not have).
                    const wrapper =

                        event.item.querySelector(
                            "[data-learning-unit-wrapper]"
                        );

                    state.activeLearningUnitId =
                        Number(
                            wrapper.dataset.learningUnitId
                        );

                    refreshMoveButtons(moduleContent, MOVE);

                    await saveLearningUnitOrder(
                        moduleContent
                    );

                    updateLearningUnitWrappers();

                },

            });

        });

}