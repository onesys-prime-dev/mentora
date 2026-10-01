/*
|--------------------------------------------------------------------------
| Delete a learning item from its row's ⋮ menu
|--------------------------------------------------------------------------
|
| Reuses the exact same confirmation modal/API call the full-screen editor's
| footer already uses (learning-item-actions.js's bindDeleteLearningItem) —
| this just opens it from a row button instead, remembering which row.
|
*/

import { deleteLearningItem } from "./api.js";
import { bindModal } from "./modals/index.js";

export function bindRowDeleteLearningItem(refresh) {

    const triggers = document.querySelectorAll("[data-row-delete-learning-item]");

    if (triggers.length === 0) {
        return;
    }

    const modal = bindModal({
        modal: "#learning-item-delete-modal",
        closeButtons: "[data-close-learning-item-delete-modal]",
    });

    if (!modal) {
        return;
    }

    let activeItemId = null;

    triggers.forEach((button) => {
        button.addEventListener("click", () => {
            activeItemId = button.dataset.learningItemId;
            modal.open();
        });
    });

    document
        .querySelector("[data-confirm-delete-learning-item]")
        ?.addEventListener("click", async () => {

            if (!activeItemId) {
                return;
            }

            try {

                await deleteLearningItem(activeItemId);

                modal.close();

                await refresh();

            } catch (error) {

                console.error(error);

            }

        });

}
