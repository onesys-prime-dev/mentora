/*
|--------------------------------------------------------------------------
| Video Autosave
|--------------------------------------------------------------------------
|
| Automatically saves Video settings whenever they change.
|
*/

import {
    collectVideoFormData,
} from "./video.js";

import {
    updateVideoItem,
} from "../api.js";

let learningItemId = null;
let autosaveTimer = null;
let saving = false;

export function initializeVideoAutosave({

    learningItemId: id,
    delay = 1000,

} = {}) {

    learningItemId = id;

    document

        .querySelectorAll(`
            [data-video-source],
            [data-video-file],
            [data-video-url],
            [data-video-autoplay],
            [data-video-controls]
        `)

        .forEach((element) => {

            const event =

                element.type === "url"

                    ? "input"

                    : "change";

            element.addEventListener(
                event,
                queueAutosave
            );

        });

}

export function queueAutosave() {

    updateSaveStatus(
        "Unsaved changes",
        "orange"
    );

    clearTimeout(
        autosaveTimer
    );

    autosaveTimer = setTimeout(
        saveEditor,
        1000
    );

}

export async function flushAutosave() {

    clearTimeout(
        autosaveTimer
    );

    await saveEditor();

}

async function saveEditor() {

    if (saving) {
        return;
    }

    saving = true;

    try {

        updateSaveStatus(
            "Saving...",
            "blue"
        );

        const formData =
            collectVideoFormData();

        await updateVideoItem(
            learningItemId,
            formData
        );

        updateSaveStatus(
            "All changes saved",
            "green"
        );

    }

    catch (error) {

        console.error(error);

        updateSaveStatus(
            "Save failed",
            "red"
        );

    }

    finally {

        saving = false;

    }

}

function updateSaveStatus(
    message,
    color
) {

    const messageElement =

        document.querySelector(
            "[data-save-message]"
        );

    const timeElement =

        document.querySelector(
            "[data-save-time]"
        );

    const indicator =

        document.querySelector(
            "[data-save-indicator]"
        );

    if (messageElement) {

        messageElement.textContent =
            message;

    }

    if (
        timeElement &&
        message === "All changes saved"
    ) {

        const now =
            new Date();

        timeElement.textContent =

            `Saved at ${now.toLocaleTimeString([], {

                hour: "numeric",

                minute: "2-digit",

            })}`;

    }

    if (!indicator) {
        return;
    }

    indicator.className =
        "h-2 w-2 rounded-full";

    switch (color) {

        case "green":

            indicator.classList.add(
                "bg-emerald-500"
            );

            break;

        case "blue":

            indicator.classList.add(
                "bg-blue-500"
            );

            break;

        case "orange":

            indicator.classList.add(
                "bg-orange-500"
            );

            break;

        case "red":

            indicator.classList.add(
                "bg-red-500"
            );

            break;

    }

}