/*
|--------------------------------------------------------------------------
| Modules Feature
|--------------------------------------------------------------------------
*/

import {
    renderModulesSkeleton,
} from "./skeleton.js";

import { fetchModules } from "./api.js";
import { state } from "./state.js";

import { initializePdfViewer } from "../../../../shared/pdf/viewer.js";

import { bindModuleEvents } from "./module-events.js";

import {
    bindLearningItemEvents,
    bindLearningItemEditorEvents,
    loadLearningItem,
    loadLearningItemEditor,
} from "./learning-item.js";

import { bindBreadcrumbEvents } from "./breadcrumb.js";

import { bindModuleActionMenus } from "./module-actions.js";
import { bindLearningUnitActionMenus } from "./learning-unit-actions.js";

import { bindModal } from "./modals/index.js";

import { bindModuleModals } from "./modals/modules.js";
import { bindLearningUnitModals } from "./modals/learning-units.js";
import { bindLearningItemCreateModal }from "./modals/learning-items.js";

import {
    initializeModuleSorting,
} from "./module-sort.js";

import {
    bindLearningNavigation,
} from "./navigation.js";

import {
    bindLearningUnitEvents,
} from "./learning-unit-events.js";

import {
    initializeLearningUnitSorting,
} from "./learning-unit-sort.js";

import {
    initializeLearningItemSorting,
} from "./learning-item-sort.js";

import {
    bindModuleStatusToggles,
} from "./module-status.js";

import {
    bindLearningUnitStatusToggles,
} from "./learning-unit-status.js";

/*
|--------------------------------------------------------------------------
| Initialize
|--------------------------------------------------------------------------
*/

export async function initialize() {

    const params =
        new URLSearchParams(window.location.search);

    const learningItemId =
        Number(
            params.get("item")
        );

    const isEditor =
        params.has("editor");

    if (learningItemId) {

        if (isEditor) {

            await loadLearningItemEditor(
                learningItemId,
                initialize
            );

        }

        else {

            await loadLearningItem(
                learningItemId,
                initialize
            );

        }

        return;

    }

    await loadModules();

}

/*
|--------------------------------------------------------------------------
| Load Modules
|--------------------------------------------------------------------------
*/

async function loadModules() {

    state.loading = true;

    const container =
        document.querySelector("#course-content");

    container.innerHTML = renderModulesSkeleton();

    try {

        state.data =
            await fetchModules();

        container.innerHTML =
            state.data.html;

        initializeFeatureBindings();

    }

    catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load modules.</p>";

    }

    finally {

        state.loading = false;

    }

}

/*
|--------------------------------------------------------------------------
| Bind Everything
|--------------------------------------------------------------------------
*/

function initializeFeatureBindings() {

    /*
    |--------------------------------------------------------------------------
    | Core UI
    |--------------------------------------------------------------------------
    */

    bindModuleEvents();

    if (state.activeModuleId) {

        const activeModule =

            document.querySelector(
                `[data-module="${state.activeModuleId}"]`
            );

        activeModule?.click();

    }

    bindModuleActionMenus();

    initializeModuleSorting();

    bindModuleStatusToggles();

    bindLearningUnitEvents();

    initializeLearningUnitSorting();

    bindLearningUnitStatusToggles();

    bindLearningUnitActionMenus();

    bindLearningItemEvents(
        initialize
    );

    initializeLearningItemSorting();

    bindLearningNavigation(
        initialize
    );

    bindLearningItemEditorEvents(
        initialize
    );

    bindBreadcrumbEvents(
        initialize
    );

    initializePdfViewer();

    /*
    |--------------------------------------------------------------------------
    | Module Modals
    |--------------------------------------------------------------------------
    */

    const moduleEditorModal =
        bindModal({

            modal:
                "#module-editor-modal",

            closeButtons:
                "[data-close-module-modal]",

        });

    const moduleDeleteModal =
        bindModal({

            modal:
                "#module-delete-modal",

            closeButtons:
                "[data-close-module-delete-modal]",

        });

    bindModuleModals({

        editor:
            moduleEditorModal,

        delete:
            moduleDeleteModal,

        refresh:
            initialize,

    });

    /*
    |--------------------------------------------------------------------------
    | Learning Unit Modals
    |--------------------------------------------------------------------------
    */

    const learningUnitEditorModal =
        bindModal({

            modal:
                "#learning-unit-editor-modal",

            closeButtons:
                "[data-close-learning-unit-modal]",

        });

    const learningUnitDeleteModal =
        bindModal({

            modal:
                "#learning-unit-delete-modal",

            closeButtons:
                "[data-close-learning-unit-delete-modal]",

        });

    bindLearningUnitModals({

        editor:
            learningUnitEditorModal,

        delete:
            learningUnitDeleteModal,

        refresh:
            initialize,

    });

    /*
    |--------------------------------------------------------------------------
    | Learning Item Modals
    |--------------------------------------------------------------------------
    */

    const learningItemCreateModal =
        bindModal({

            modal:
                "#learning-item-create-modal",

            closeButtons:
                "[data-close-learning-item-modal]",

        });

    bindLearningItemCreateModal({

        create:
            learningItemCreateModal,

        refresh:
            initialize,

    });

}