/*
|--------------------------------------------------------------------------
| Publish / Unpublish
|--------------------------------------------------------------------------
|
| Replaces the old toggle-switch with a single action button, shared by
| modules, learning units and the item row menu's new entry. Used on both
| the Modules list page and the per-module page.
|
*/

import {
    updateModuleStatus,
    updateLearningUnitStatus,
    updateLearningItem,
} from "./api.js";

export function bindPublishToggles(refresh) {

    document.querySelectorAll("[data-module-publish-toggle]").forEach((button) => {
        bindToggle(button, (nextStatus) => updateModuleStatus(button.dataset.moduleId, nextStatus), refresh);
    });

    document.querySelectorAll("[data-unit-publish-toggle]").forEach((button) => {
        bindToggle(button, (nextStatus) => updateLearningUnitStatus(button.dataset.learningUnitId, nextStatus), refresh);
    });

    document.querySelectorAll("[data-item-publish-toggle]").forEach((button) => {
        bindToggle(button, (nextStatus) => updateLearningItem(button.dataset.learningItemId, { status: nextStatus }), refresh);
    });

}

function bindToggle(button, save, refresh) {

    button.addEventListener("click", async () => {

        const nextStatus = button.dataset.status === "published" ? "draft" : "published";

        button.disabled = true;

        try {

            await save(nextStatus);

            await refresh();

        } catch (error) {

            console.error(error);

        } finally {

            button.disabled = false;

        }

    });

}
