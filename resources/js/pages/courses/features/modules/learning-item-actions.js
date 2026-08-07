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

} from "./api.js";

import {

    flushAutosave as flushReadingAutosave,

} from "./reading/reading-autosave.js";

import {

    flushAutosave as flushVideoAutosave,

} from "./video/video-autosave.js";

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

}