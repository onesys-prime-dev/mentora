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

import { highlightRow } from "../../../../shared/spa/highlight-row.js";

import {
    bindLearningUnitStatusToggles,
} from "./learning-unit-status.js";

import {
    bindLearningItemActionMenus,
} from "./learning-item-actions-menu.js";

import {
    bindLearningItemDependenciesModal,
} from "./modals/learning-item-dependencies.js";

import {
    flushVideoProgress,
} from "./completion/video-progress.js";

/*
|--------------------------------------------------------------------------
| Initialize
|--------------------------------------------------------------------------
*/

export async function initialize() {

    // Leaving a video item (back to the list, or re-entering) saves its
    // last few seconds of watch progress.
    flushVideoProgress();

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

    // A dashboard widget (e.g. "Unpublished") can deep-link straight into a
    // specific draft module/unit instead of the plain Modules list.
    const moduleId =
        Number(params.get("module")) || null;

    const unitId =
        Number(params.get("unit")) || null;

    if (moduleId || unitId) {

        // Consumed once: strips the query string so navigating away and
        // back (e.g. via a browser back button) doesn't re-trigger it.
        history.replaceState({}, "", window.location.pathname);

        if (moduleId) {
            state.activeModuleId = moduleId;
        }

        await loadModules();

        if (unitId) {

            const unitElement =
                document.querySelector(`[data-learning-unit="${unitId}"]`);

            unitElement
                ?.closest("[data-module]")
                ?.click();

        }

        highlightRow(
            unitId
                ? `[data-learning-unit="${unitId}"]`
                : `[data-module="${moduleId}"]`
        );

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

        onCreated: (learningItemId) =>
            loadLearningItemEditor(
                learningItemId,
                initialize
            ),

    });

    /*
    |--------------------------------------------------------------------------
    | Learning Item Actions (⋮ menu → Edit / Dependencies)
    |--------------------------------------------------------------------------
    */

    bindLearningItemActionMenus();

    bindLearningItemDependenciesModal({

        modal:
            bindModal({

                modal:
                    "#learning-item-dependencies-modal",

                closeButtons:
                    "[data-close-learning-item-dependencies-modal]",

            }),

        refresh:
            initialize,

    });

}