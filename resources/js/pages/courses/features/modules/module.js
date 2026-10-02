/*
|--------------------------------------------------------------------------
| Single Module Page
|--------------------------------------------------------------------------
|
| Owns the units/items tree moved off the list page: unit/item accordions,
| drag-reorder, instructor actions (edit/publish/delete), and the learning
| item view/editor deep link — all the same bindings index.js used to run,
| just scoped to one module's fetched HTML instead of the whole list.
|
*/

import { fetchModule } from "./api.js";
import { state } from "./state.js";
import { renderModulePageSkeleton } from "./skeleton.js";

import { initializePdfViewer } from "../../../../shared/pdf/viewer.js";

import {
    bindLearningItemEvents,
    bindLearningItemEditorEvents,
    loadLearningItem,
    loadLearningItemEditor,
} from "./learning-item.js";

import { bindBreadcrumbEvents } from "./breadcrumb.js";
import { bindLearningNavigation } from "./navigation.js";

import { bindModal } from "./modals/index.js";
import { bindModuleModals } from "./modals/modules.js";
import { bindLearningUnitModals } from "./modals/learning-units.js";
import { bindLearningItemCreateModal } from "./modals/learning-items.js";
import { bindLearningItemDependenciesModal } from "./modals/learning-item-dependencies.js";

import { bindLearningUnitEvents } from "./learning-unit-events.js";
import { initializeLearningUnitSorting } from "./learning-unit-sort.js";
import { initializeLearningItemSorting } from "./learning-item-sort.js";
import { bindLearningUnitActionMenus } from "./learning-unit-actions.js";
import { bindLearningItemActionMenus } from "./learning-item-actions-menu.js";

import { bindPublishToggles } from "./publish-toggle.js";
import { bindRowDeleteLearningItem } from "./learning-item-row-delete.js";

import { flushVideoProgress } from "./completion/video-progress.js";

export async function loadModule(moduleId, backToList) {

    flushVideoProgress();

    state.loading = true;

    const container = document.querySelector("#course-content");

    container.innerHTML = renderModulePageSkeleton();

    try {

        const result = await fetchModule(moduleId);

        container.innerHTML = result.html;

        history.pushState(
            { moduleId },
            "",
            `${window.location.pathname}?module=${moduleId}&view=1`
        );

        const refresh = () => loadModule(moduleId, backToList);

        bindModuleBindings(moduleId, refresh, backToList);

    } catch (error) {

        console.error(error);

        container.innerHTML = "<p>Unable to load this module.</p>";

    } finally {

        state.loading = false;

    }

}

function bindModuleBindings(moduleId, refresh, backToList) {

    document
        .querySelector("[data-back-to-modules]")
        ?.addEventListener("click", () => {
            history.pushState({}, "", window.location.pathname);
            backToList();
        });

    bindLearningUnitEvents();
    initializeLearningUnitSorting();
    bindLearningUnitActionMenus();

    bindLearningItemEvents(refresh);
    bindLearningItemEditorEvents(refresh);
    initializeLearningItemSorting();
    bindLearningItemActionMenus();

    bindLearningNavigation(refresh);
    bindBreadcrumbEvents();

    initializePdfViewer();

    bindPublishToggles(refresh);
    bindRowDeleteLearningItem(refresh);

    const moduleEditorModal = bindModal({
        modal: "#module-editor-modal",
        closeButtons: "[data-close-module-modal]",
    });

    const moduleDeleteModal = bindModal({
        modal: "#module-delete-modal",
        closeButtons: "[data-close-module-delete-modal]",
    });

    bindModuleModals({
        editor: moduleEditorModal,
        delete: moduleDeleteModal,
        refresh,
        onDeleted: () => {
            history.pushState({}, "", window.location.pathname);
            backToList();
        },
    });

    const learningUnitEditorModal = bindModal({
        modal: "#learning-unit-editor-modal",
        closeButtons: "[data-close-learning-unit-modal]",
    });

    const learningUnitDeleteModal = bindModal({
        modal: "#learning-unit-delete-modal",
        closeButtons: "[data-close-learning-unit-delete-modal]",
    });

    bindLearningUnitModals({
        editor: learningUnitEditorModal,
        delete: learningUnitDeleteModal,
        refresh,
    });

    const learningItemCreateModal = bindModal({
        modal: "#learning-item-create-modal",
        closeButtons: "[data-close-learning-item-modal]",
    });

    bindLearningItemCreateModal({
        create: learningItemCreateModal,
        refresh,
        onCreated: (learningItemId) => loadLearningItemEditor(learningItemId, refresh),
    });

    bindLearningItemDependenciesModal({
        modal: bindModal({
            modal: "#learning-item-dependencies-modal",
            closeButtons: "[data-close-learning-item-dependencies-modal]",
        }),
        refresh,
    });

    // A learning item deep-linked while already on this module page
    // (e.g. a reload with ?item=&editor= still in the URL).
    const params = new URLSearchParams(window.location.search);
    const learningItemId = Number(params.get("item"));

    if (learningItemId) {

        if (params.has("editor")) {
            loadLearningItemEditor(learningItemId, refresh);
        } else {
            loadLearningItem(learningItemId, refresh);
        }

    }

}
