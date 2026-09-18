import Sortable from "sortablejs";

import { updateModuleWrappers } from "./module-events.js";

import { state } from "./state.js";

import {

    reorderModules,

} from "./api.js";

export function initializeModuleSorting() {

    const container =
        document.querySelector("#modules-container");

    if (!container) {
        return;
    }

    Sortable.create(container, {

        animation: 200,

        handle:
            "[data-module-card-drag-handle]",

        draggable:
            "[data-module-wrapper]",

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

            /*
            |--------------------------------------------------------------------------
            | Update displayed order immediately
            |--------------------------------------------------------------------------
            */

            document

                .querySelectorAll("[data-module-wrapper]")

                .forEach((module, index) => {

                    const label =
                        module.querySelector(
                            "[data-module-order]"
                        );

                    if (label) {

                        label.textContent =
                            `MODULE ${index + 1}`;

                    }

                });

            /*
            |--------------------------------------------------------------------------
            | Build payload
            |--------------------------------------------------------------------------
            */

            const modules =

                Array.from(

                    container.querySelectorAll(
                        "[data-module-wrapper]"
                    )

                ).map((module, index) => ({

                    id:
                        Number(module.dataset.moduleId),

                    order_index:
                        index + 1,

                }));

            await reorderModules(modules);

        },

    });

}