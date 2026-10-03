/*
|--------------------------------------------------------------------------
| In-card announcement editing — "Edit" converts the existing card itself
| into a composer in place, rather than opening a second composer
| elsewhere on the page. Reuses the same TipTap/toolbar/serializer modules
| the top-level composer uses (there is only ever one editor instance at a
| time — see composer/tiptap.js — so only one of "the top composer" or "a
| card's inline editor" can be open at once; callers coordinate that).
|--------------------------------------------------------------------------
*/

import { setBusy } from "../../../../shared/spa/busy-button.js";
import { updateAnnouncement, uploadAnnouncementAttachment } from "./api.js";
import { initializeComposerEditor, getComposerEditor, destroyComposerEditor } from "./composer/tiptap.js";
import { initializeComposerToolbar } from "./composer/toolbar.js";
import { serializeMessage } from "./composer/serializer.js";
import { mountEmojiPicker } from "./emoji-picker.js";
import { closeAllActionMenus } from "../modules/action-menus.js";

let activeCard = null;
let activeCleanup = null;

/** Closes whichever card is currently in edit mode, if any. */
export function closeActiveCardEdit() {
    activeCleanup?.();
}

/**
 * @param {HTMLElement} card
 * @param {{ onBeforeOpen?: () => void, onSaved: () => Promise<void> | void }} options
 */
export function openCardEditor(card, { onBeforeOpen, onSaved } = {}) {

    if (activeCard === card) {
        return;
    }

    closeActiveCardEdit();
    onBeforeOpen?.();

    const display = card.querySelector("[data-card-display]");
    const editorRoot = card.querySelector("[data-card-editor]");

    if (!display || !editorRoot) {
        return;
    }

    const announcementId = card.dataset.announcementId;
    const mount = editorRoot.querySelector("[data-card-editor-mount]");
    const mountId = `announcement-editor-card-${announcementId}`;

    mount.id = mountId;
    mount.dataset.message = card.dataset.announcementMessage || "{}";

    display.classList.add("hidden");
    editorRoot.classList.remove("hidden");
    editorRoot.classList.add("flex");

    initializeComposerEditor(mountId);
    initializeComposerToolbar(editorRoot);

    const subjectInput = editorRoot.querySelector("[data-card-editor-subject]");
    const saveButton = editorRoot.querySelector("[data-card-editor-save]");
    const cancelButton = editorRoot.querySelector("[data-card-editor-cancel]");
    const attachmentsBox = editorRoot.querySelector("[data-card-editor-attachments]");
    const fileInput = editorRoot.querySelector("[data-card-editor-file-input]");
    const attachButton = editorRoot.querySelector("[data-card-editor-attach]");
    const emojiToggle = editorRoot.querySelector("[data-card-editor-emoji-toggle]");
    const emojiPanel = editorRoot.querySelector("[data-card-editor-emoji-panel]");

    subjectInput.value = card.dataset.announcementSubject || "";

    let attachments = [];
    try {
        attachments = JSON.parse(card.dataset.announcementAttachments || "[]")
            .map((a) => ({ file_id: a.file_id, original_filename: a.original_filename }));
    } catch (e) {
        console.error(e);
    }

    function renderAttachments() {

        attachmentsBox.innerHTML = "";

        attachments.forEach((file, index) => {

            const chip = document.createElement("div");
            chip.className = "flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-xs text-gray-600";
            chip.innerHTML = `<span class="max-w-[140px] truncate">${file.original_filename}</span>`;

            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "text-gray-400 hover:text-red-500";
            remove.textContent = "×";
            remove.addEventListener("click", () => {
                attachments.splice(index, 1);
                renderAttachments();
            });

            chip.appendChild(remove);
            attachmentsBox.appendChild(chip);

        });

    }

    renderAttachments();

    attachButton?.addEventListener("click", () => fileInput?.click());

    fileInput?.addEventListener("change", async () => {

        for (const file of Array.from(fileInput.files ?? [])) {

            if (attachments.length >= 10) {
                alert("You can attach up to 10 files.");
                break;
            }

            try {

                const result = await uploadAnnouncementAttachment(file);

                if (!result.success) {
                    alert(result.message);
                    continue;
                }

                attachments.push(result);
                renderAttachments();

            } catch (error) {
                console.error(error);
            }

        }

        fileInput.value = "";

    });

    emojiToggle?.addEventListener("click", (event) => {

        event.preventDefault();

        const wasHidden = emojiPanel.classList.contains("hidden");

        closeAllActionMenus();

        if (wasHidden) {
            emojiPanel.classList.remove("hidden");
            mountEmojiPicker(emojiPanel, (emoji) => {
                getComposerEditor()?.chain().focus().insertContent(emoji).run();
            });
        }

        event.stopPropagation();

    });

    function cleanup() {

        destroyComposerEditor();

        editorRoot.classList.add("hidden");
        editorRoot.classList.remove("flex");
        display.classList.remove("hidden");

        activeCard = null;
        activeCleanup = null;

    }

    cancelButton?.addEventListener("click", cleanup);

    saveButton?.addEventListener("click", async () => {

        const subject = subjectInput.value.trim();

        if (subject === "") {
            return;
        }

        setBusy(saveButton, true, "Saving…");

        try {

            const payload = {
                subject,
                message: JSON.stringify(serializeMessage(getComposerEditor())),
                attachment_file_ids: attachments.map((a) => a.file_id),
            };

            const result = await updateAnnouncement(announcementId, payload);

            if (!result.success) {
                alert(result.message);
                return;
            }

            cleanup();

            await onSaved?.();

        } catch (error) {

            console.error(error);

        } finally {

            setBusy(saveButton, false);

        }

    });

    activeCard = card;
    activeCleanup = cleanup;

    subjectInput.focus();

}
