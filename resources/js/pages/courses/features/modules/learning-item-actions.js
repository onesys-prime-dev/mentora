/*
|--------------------------------------------------------------------------
| Learning Item Actions
|--------------------------------------------------------------------------
|
| Handles Draft / Publish actions for every learning item type.
|
*/

import {

    updateLearningItem,

    deleteLearningItem,

} from "./api.js";

import {

    bindModal,

} from "./modals/index.js";

import {

    flushAutosave as flushReadingAutosave,

} from "./reading/reading-autosave.js";

import {

    flushAutosave as flushVideoAutosave,

} from "./video/video-autosave.js";

import {

    flushAutosave as flushLinkAutosave,

} from "./link/link-editor.js";

export function initializeLearningItemActions(

    learningItemId,

    refresh

) {

    bindSaveDraft(

        learningItemId,

        refresh

    );

    bindPublish(

        learningItemId,

        refresh

    );

    bindDeleteLearningItem(

        learningItemId,

        refresh

    );

}

/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/

function bindDeleteLearningItem(

    learningItemId,

    refresh

) {

    const modal =
        bindModal({

            modal:
                "#learning-item-delete-modal",

            closeButtons:
                "[data-close-learning-item-delete-modal]",

        });

    if (!modal) {
        return;
    }

    document
        .querySelector("[data-delete-learning-item]")
        ?.addEventListener(
            "click",
            () => {
                modal.open();
            }
        );

    document
        .querySelector("[data-confirm-delete-learning-item]")
        ?.addEventListener(
            "click",
            async () => {

                try {

                    await deleteLearningItem(
                        learningItemId
                    );

                    modal.close();

                    history.pushState(
                        {},
                        "",
                        window.location.pathname
                    );

                    await refresh();

                }

                catch (error) {

                    console.error(error);

                }

            }
        );

}

/*
|--------------------------------------------------------------------------
| Save Draft
|--------------------------------------------------------------------------
*/

function bindSaveDraft(

    learningItemId,

    refresh

) {

    const button =
        document.querySelector(
            "[data-save-draft]"
        );

    if (!button) {
        return;
    }

    button.addEventListener(

        "click",

        async () => {

            try {

                button.disabled = true;

                /*
                |--------------------------------------------------------------------------
                | Save current editor immediately (if one exists)
                |--------------------------------------------------------------------------
                */

                await flushCurrentAutosave();

                /*
                |--------------------------------------------------------------------------
                | Keep item as Draft
                |--------------------------------------------------------------------------
                */

                await updateLearningItem(

                    learningItemId,

                    {

                        status: "draft",

                    }

                );

                /*
                |--------------------------------------------------------------------------
                | Return to Modules
                |--------------------------------------------------------------------------
                */

                history.pushState(

                    {},

                    "",

                    window.location.pathname

                );

                await refresh();

            }

            catch (error) {

                console.error(error);

            }

            finally {

                button.disabled = false;

            }

        }

    );

}

/*
|--------------------------------------------------------------------------
| Publish
|--------------------------------------------------------------------------
*/

function bindPublish(

    learningItemId,

    refresh

) {

    const button =
        document.querySelector(
            "[data-publish]"
        );

    if (!button) {
        return;
    }

    button.addEventListener(

        "click",

        async () => {

            try {

                button.disabled = true;

                /*
                |--------------------------------------------------------------------------
                | Save current editor immediately (if one exists)
                |--------------------------------------------------------------------------
                */

                await flushCurrentAutosave();

                /*
                |--------------------------------------------------------------------------
                | Publish
                |--------------------------------------------------------------------------
                */

                await updateLearningItem(

                    learningItemId,

                    {

                        status: "published",

                    }

                );

                /*
                |--------------------------------------------------------------------------
                | Return to Modules
                |--------------------------------------------------------------------------
                */

                history.pushState(

                    {},

                    "",

                    window.location.pathname

                );

                await refresh();

            }

            catch (error) {

                console.error(error);

            }

            finally {

                button.disabled = false;

            }

        }

    );

}

async function flushCurrentAutosave() {

    if (
        document.querySelector(
            "[data-reading-editor]"
        )
    ) {

        await flushReadingAutosave();

    }

    else if (
        document.querySelector(
            "[data-video-source]"
        )
    ) {

        await flushVideoAutosave();

    }

    else if (
        document.querySelector(
            "[data-link-editor]"
        )
    ) {

        await flushLinkAutosave();

    }

}