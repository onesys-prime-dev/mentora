/*
|--------------------------------------------------------------------------
| Discussion Learning Item Editor
|--------------------------------------------------------------------------
|
| Autosaves the item's title and the discussion prompt through
| PATCH …/modules/items/{id}; the server keeps the thread in step.
| flushAutosave() lets Save as Draft / Publish save pending edits first.
|
*/

import { updateLearningItem } from "../api.js";

const DELAY_MS = 800;

let timer = null;

let dirty = false;

let saveNow = null;

export function initializeDiscussionEditor() {

    clearTimeout(timer);

    dirty = false;

    saveNow = null;

    const editor = document.querySelector("[data-discussion-editor]");

    if (!editor) {
        return;
    }

    const learningItemId = Number(editor.dataset.learningItemId);

    const titleInput = editor.querySelector("[data-discussion-title]");

    const bodyInput = editor.querySelector("[data-discussion-body]");

    const errorLabel = editor.querySelector("[data-discussion-error]");

    const showError = (message) => {

        errorLabel.textContent = message ?? "";

        errorLabel.classList.toggle("hidden", !message);

    };

    const setText = (selector, text) => {

        document.querySelectorAll(selector).forEach((element) => {
            element.textContent = text;
        });

    };

    // The header and the preview follow what is typed, before it is saved.
    const mirror = () => {

        const title = titleInput.value.trim();

        if (title) {

            setText("[data-item-header-title]", title);

            setText("[data-discussion-preview-title]", title);

        }

        setText("[data-discussion-preview-body]", bodyInput.value.trim() || "No prompt yet.");

    };

    saveNow = async () => {

        if (!dirty) {
            return;
        }

        const title = titleInput.value.trim();

        if (!title) {

            showError("The title cannot be empty.");

            return;

        }

        dirty = false;

        showError("");

        setStatus("Saving...", "bg-blue-500");

        try {

            const result = await updateLearningItem(learningItemId, {
                title,
                discussion_body: bodyInput.value,
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

        mirror();

        setStatus("Unsaved changes", "bg-orange-500");

        clearTimeout(timer);

        timer = setTimeout(() => saveNow?.(), DELAY_MS);

    };

    titleInput.addEventListener("input", queue);

    bodyInput.addEventListener("input", queue);

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
