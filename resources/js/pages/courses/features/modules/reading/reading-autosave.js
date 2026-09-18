/*
|--------------------------------------------------------------------------
| Reading Autosave
|--------------------------------------------------------------------------
|
| Automatically saves TipTap content whenever the editor changes.
|
*/

import { getEditor } from "./tiptap.js";

import { serializeReading } from "./serializer.js";

let autosaveTimer = null;

let saving = false;

let currentOnSave = null;

let currentDelay = 1000;

export function initializeReadingAutosave({

    onSave,

    delay = 1000,

} = {}) {

    currentOnSave = onSave;

    currentDelay = delay;

    const editor = getEditor();

    if (!editor || !onSave) {
        return;
    }

    editor.off("update");

    editor.on(

        "update",

        () => {

            queueAutosave();

        }

    );

}

export function queueAutosave() {

    if (!currentOnSave) {
        return;
    }

    updateSaveStatus(

        "Unsaved changes",

        "orange"

    );

    clearTimeout(
        autosaveTimer
    );

    autosaveTimer = setTimeout(

        async () => {

            await saveEditor(
                currentOnSave
            );

        },

        currentDelay

    );

}

export async function flushAutosave() {

    clearTimeout(
        autosaveTimer
    );

    if (!currentOnSave) {
        return;
    }

    await saveEditor(
        currentOnSave
    );

}

async function saveEditor(
    onSave
) {

    if (saving) {

        return;

    }

    saving = true;

    try {

        updateSaveStatus(

            "Saving...",

            "blue"

        );

        const content =
            serializeReading();

        await onSave(
            content
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

        message ===
            "All changes saved"

    ) {

        const now =
            new Date();

        timeElement.textContent =

            `Saved at ${now.toLocaleTimeString(

                [],

                {

                    hour: "numeric",

                    minute: "2-digit",

                }

            )}`;

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