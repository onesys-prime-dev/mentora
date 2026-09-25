/*
|--------------------------------------------------------------------------
| Link Learning Item Editor
|--------------------------------------------------------------------------
|
| Autosaves {url, description} into learning_items.content through
| PATCH …/modules/items/{id}; the server only accepts http(s) links.
| flushAutosave() lets Save as Draft / Publish save pending edits first.
|
*/

import { updateLearningItem } from "../api.js";

const DELAY_MS = 800;

let timer = null;

let dirty = false;

let saveNow = null;

export function initializeLinkEditor() {

    clearTimeout(timer);

    dirty = false;

    saveNow = null;

    const editor = document.querySelector("[data-link-editor]");

    if (!editor) {
        return;
    }

    const learningItemId = Number(editor.dataset.learningItemId);

    const urlInput = editor.querySelector("[data-link-url]");

    const descriptionInput = editor.querySelector("[data-link-description]");

    const errorLabel = editor.querySelector("[data-link-error]");

    const showError = (message) => {

        errorLabel.textContent = message ?? "";

        errorLabel.classList.toggle("hidden", !message);

    };

    saveNow = async () => {

        if (!dirty) {
            return;
        }

        const url = urlInput.value.trim();

        if (url && !/^https?:\/\//i.test(url)) {

            showError("Enter a full link starting with http:// or https://.");

            return;

        }

        dirty = false;

        showError("");

        setStatus("Saving...", "bg-blue-500");

        try {

            const result = await updateLearningItem(learningItemId, {
                content: {
                    url,
                    description: descriptionInput.value,
                },
            });

            if (result.success === false) {

                dirty = true;

                showError(result.message);

                setStatus("Save failed", "bg-red-500");

                return;

            }

            setStatus("All changes saved", "bg-emerald-500");

        }

        catch (error) {

            console.error(error);

            dirty = true;

            setStatus("Save failed", "bg-red-500");

        }

    };

    const queue = () => {

        dirty = true;

        setStatus("Unsaved changes", "bg-orange-500");

        clearTimeout(timer);

        timer = setTimeout(() => saveNow?.(), DELAY_MS);

    };

    urlInput.addEventListener("input", queue);

    descriptionInput.addEventListener("input", queue);

}

export async function flushAutosave() {

    clearTimeout(timer);

    await saveNow?.();

}

function setStatus(message, indicatorClass) {

    const messageElement = document.querySelector("[data-save-message]");

    const indicator = document.querySelector("[data-save-indicator]");

    const timeElement = document.querySelector("[data-save-time]");

    if (messageElement) {
        messageElement.textContent = message;
    }

    if (indicator) {
        indicator.className = `h-2 w-2 rounded-full ${indicatorClass}`;
    }

    if (timeElement && message === "All changes saved") {
        timeElement.textContent = `Saved at ${new Date().toLocaleTimeString([], { hour: "numeric", minute: "2-digit" })}`;
    }

}
