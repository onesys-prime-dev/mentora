/*
|--------------------------------------------------------------------------
| Discussion Learning Item Editor
|--------------------------------------------------------------------------
|
| Uses the Discussions tab's own composer (title, rich-text prompt,
| attachments), always open, and autosaves it through
| PATCH …/modules/items/{id}; the server keeps the thread in step.
| flushAutosave() lets Save as Draft / Publish save pending edits first.
|
*/

import { updateLearningItem } from "../api.js";
import { setupComposer } from "../../discussions/composer.js";

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

    // The course's own address (role prefix and slug), as the tabs use.
    const threadLink = editor.querySelector("[data-discussion-thread-link]");

    if (threadLink) {
        threadLink.href = `${window.coursesConfig.browserBaseUrl}/discussions?thread=${threadLink.dataset.threadId}`;
    }

    const errorLabel = editor.querySelector("[data-discussion-error]");

    const showError = (message) => {

        errorLabel.textContent = message ?? "";

        errorLabel.classList.toggle("hidden", !message);

    };

    let composer = null;

    let ready = false;

    saveNow = async () => {

        if (!dirty || !composer) {
            return;
        }

        const { title, body, attachment_file_ids: attachmentIds } = composer.payload();

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
                discussion_body: body,
                attachment_file_ids: attachmentIds,
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

        // Loading the saved content into the editor is not an edit.
        if (!ready || !composer) {
            return;
        }

        dirty = true;

        const { title } = composer.payload();

        if (title) {

            document.querySelectorAll("[data-item-header-title]").forEach((element) => {
                element.textContent = title;
            });

        }

        setStatus("Unsaved changes", "bg-orange-500");

        clearTimeout(timer);

        timer = setTimeout(() => saveNow?.(), DELAY_MS);

    };

    composer = setupComposer(editor, () => {}, { onChange: queue });

    let attachments = [];

    try {
        attachments = JSON.parse(editor.dataset.discussionAttachments ?? "[]");
    } catch (error) {
        console.error(error);
    }

    composer?.open({
        mode: "edit",
        title: editor.dataset.discussionTitle ?? "",
        message: editor.dataset.discussionMessage ?? "{}",
        attachments,
    });

    // The editor fires update events while the saved content is being set.
    setTimeout(() => {
        ready = true;
    }, 0);

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
