/*
|--------------------------------------------------------------------------
| Modules Feature — list page
|--------------------------------------------------------------------------
|
| Units/items now live on the per-module page (module.js); this page only
| shows module summaries. The learning-item view/editor deep link
| (?item=&editor=) is still handled here because a page reload/breadcrumb
| can land back on this bundle before navigating into a module.
|
*/

import {
    renderModulesSkeleton,
} from "./skeleton.js";

import { fetchModules } from "./api.js";
import { state } from "./state.js";
import { rememberRows } from "../../../../shared/spa/skeleton-rows.js";

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

import { bindModal } from "./modals/index.js";

import { bindModuleModals } from "./modals/modules.js";

import {
    initializeModuleSorting,
} from "./module-sort.js";

import {
    bindLearningNavigation,
} from "./navigation.js";

import { bindPublishToggles } from "./publish-toggle.js";

import { loadModule } from "./module.js";

import { highlightRow } from "../../../../shared/spa/highlight-row.js";

import {
    flushVideoProgress,
} from "./completion/video-progress.js";

/*
|--------------------------------------------------------------------------
| Initialize
|--------------------------------------------------------------------------
*/

export async function initialize() {

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
            await loadLearningItemEditor(learningItemId, initialize);
        } else {
            await loadLearningItem(learningItemId, initialize);
        }

        return;

    }

    const moduleId =
        Number(params.get("module")) || null;

    // ?module=<id>&view=1 — navigated into that module's own page (e.g. a
    // reload while on it, or "back" from a learning item inside it).
    if (moduleId && params.get("view") === "1") {
        await loadModule(moduleId, initialize);
        return;
    }

    // A dashboard widget (e.g. "Unpublished") can deep-link to a specific
    // draft module on the list; units/items now live on that module's own
    // page, so a unit deep-link isn't resolvable from the list alone and
    // is dropped gracefully rather than guessed at.
    if (moduleId) {

        history.replaceState({}, "", window.location.pathname);

        await loadModules();

        highlightRow(`[data-module-wrapper][data-module-id="${moduleId}"]`);

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

    rememberRows("modules", document.querySelectorAll("[data-module-wrapper]").length);

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

    bindPublishToggles(loadModules);

    document.querySelectorAll("[data-go-to-module]").forEach((button) => {
        button.addEventListener("click", () => {
            loadModule(button.dataset.goToModule, initialize);
        });
    });

    bindLearningItemEvents(initialize);

    bindLearningNavigation(initialize);

    bindLearningItemEditorEvents(initialize);

    bindBreadcrumbEvents();

    initializePdfViewer();

    const moduleEditorModal =
        bindModal({
            modal: "#module-editor-modal",
            closeButtons: "[data-close-module-modal]",
        });

    const moduleDeleteModal =
        bindModal({
            modal: "#module-delete-modal",
            closeButtons: "[data-close-module-delete-modal]",
        });

    bindModuleModals({
        editor: moduleEditorModal,
        delete: moduleDeleteModal,
        refresh: initialize,
    });

}
