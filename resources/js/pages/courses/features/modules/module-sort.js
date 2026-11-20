import Sortable from "sortablejs";

import { updateModuleWrappers } from "./module-events.js";

import { state } from "./state.js";

import {

    reorderModules,

} from "./api.js";

import { bindMoveButtons, orderSaver, refreshMoveButtons } from "./move-buttons.js";

let reload = null;

const MOVE = { row: "[data-module-wrapper]", attr: "data-module-move", noun: "Module", status: "[data-module-move-status]" };

/*
|--------------------------------------------------------------------------
| Save the order on screen (a drop and the ▲ ▼ buttons share it)
|--------------------------------------------------------------------------
|
| Module numbers skip drafts, so they can't be recomputed from position
| alone client-side; they'll be correct again on the next reload.
|
*/

export const saveModuleOrder = orderSaver(

    (container) => reorderModules(

        Array.from(

            container.querySelectorAll(
                "[data-module-wrapper]"
            )

        ).map((module, index) => ({

            id:
                Number(module.dataset.moduleId),

            order_index:
                index + 1,

        }))

    ),

    () => reload

);

/** @param {() => unknown} [refresh] reloads the list after a failed save */
export function initializeModuleSorting(refresh) {

    const container =
        document.querySelector("#modules-container");

    if (!container) {
        return;
    }

    reload = refresh ?? null;

    bindMoveButtons(container, { ...MOVE, persist: saveModuleOrder });

    Sortable.create(container, {

        animation: 200,

        handle:
            "[data-module-card-drag-handle]",

        draggable:
            "[data-module-wrapper]",

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

            state.activeModuleId =
                Number(event.item.dataset.moduleId);

            updateModuleWrappers();

        },

        onEnd: async (event) => {

            /*
            |--------------------------------------------------------------------------
            | Keep dropped module active
            |--------------------------------------------------------------------------
            */

            state.activeModuleId =
                Number(event.item.dataset.moduleId);

            updateModuleWrappers();

            refreshMoveButtons(container, MOVE);

            await saveModuleOrder(container);

        },

    });

}