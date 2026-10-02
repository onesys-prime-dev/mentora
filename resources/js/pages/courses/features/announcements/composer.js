/*
|--------------------------------------------------------------------------
| Announcement composer binding
|--------------------------------------------------------------------------
*/

import { createAnnouncement, updateAnnouncement, uploadAnnouncementAttachment } from "./api.js";
import { initializeComposerEditor, getComposerEditor, resetComposerEditor } from "./composer/tiptap.js";
import { initializeComposerToolbar } from "./composer/toolbar.js";
import { serializeMessage, plainTextFrom } from "./composer/serializer.js";
import { mountEmojiPicker } from "./emoji-picker.js";
import { parseInstructions as parseMessage } from "../assessments/instructions/parser.js";
import { closeAllActionMenus } from "../modules/action-menus.js";

export function bindComposer(root, refresh) {

    const composer = root.querySelector("[data-announcement-composer]");

    if (!composer) {
        return null;
    }

    const wrapper = root.querySelector("[data-composer-wrapper]");
    const toggleButton = root.querySelector("[data-composer-toggle]");

    const openComposer = () => {
        wrapper?.classList.remove("hidden");
        toggleButton?.classList.add("hidden");
    };

    const closeComposer = () => {
        wrapper?.classList.add("hidden");
        toggleButton?.classList.remove("hidden");
    };

    initializeComposerEditor("announcement-editor");
    initializeComposerToolbar(composer);

    const subjectInput = composer.querySelector("[data-composer-subject]");
    const submitButton = composer.querySelector("[data-composer-submit]");
    const closeIcon = composer.querySelector("[data-composer-close-icon]");
    const trashIcon = composer.querySelector("[data-composer-trash-icon]");
    const attachmentsBox = composer.querySelector("[data-composer-attachments]");
    const fileInput = composer.querySelector("[data-composer-file-input]");

    let attachments = []; // [{file_id, original_filename}]

    toggleButton?.addEventListener("click", () => {
        openComposer();
        subjectInput.focus();
    });

    const updateDirtyState = () => {

        const hasContent = subjectInput.value.trim() !== "" || plainTextFrom(getComposerEditor()) !== "";

        closeIcon.classList.toggle("hidden", hasContent);
        trashIcon.classList.toggle("hidden", !hasContent);

        submitButton.disabled = subjectInput.value.trim() === "";

    };

    subjectInput.addEventListener("input", updateDirtyState);

    getComposerEditor()?.on("update", updateDirtyState);

    composer.querySelector("[data-composer-dismiss]")?.addEventListener("click", () => {

        const hasContent = subjectInput.value.trim() !== "" || plainTextFrom(getComposerEditor()) !== "";

        if (hasContent && !confirm("Discard this draft?")) {
            return;
        }

        subjectInput.value = "";
        resetComposerEditor();
        attachments = [];
        renderAttachments();
        updateDirtyState();
        composer.dataset.mode = "create";
        composer.dataset.announcementId = "";
        closeComposer();

    });

    // Attachments
    composer.querySelector("[data-composer-attach]")?.addEventListener("click", () => fileInput?.click());

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

    // Emoji (inserts into the message, at the cursor)
    const emojiPanel = composer.querySelector("[data-composer-emoji-panel]");

    composer.querySelector("[data-composer-emoji-toggle]")?.addEventListener("click", (event) => {

        event.preventDefault();

        const wasHidden = emojiPanel.classList.contains("hidden");

        closeAllActionMenus();

        if (wasHidden) {
            emojiPanel.classList.remove("hidden");
            mountEmojiPicker(emojiPanel, (emoji) => {
                getComposerEditor()?.chain().focus().insertContent(emoji).run();
                updateDirtyState();
            });
        }

        event.stopPropagation();

    });

    // Submit (create or edit, depending on the composer's current mode)
    submitButton?.addEventListener("click", async () => {

        const subject = subjectInput.value.trim();

        if (subject === "") {
            return;
        }

        const payload = {
            subject,
            message: JSON.stringify(serializeMessage(getComposerEditor())),
            attachment_file_ids: attachments.map((a) => a.file_id),
        };

        submitButton.disabled = true;

        try {

            const mode = composer.dataset.mode;
            const announcementId = composer.dataset.announcementId;

            const result = mode === "edit"
                ? await updateAnnouncement(announcementId, payload)
                : await createAnnouncement(payload);

            if (!result.success) {
                alert(result.message);
                return;
            }

            subjectInput.value = "";
            resetComposerEditor();
            attachments = [];
            renderAttachments();
            composer.dataset.mode = "create";
            composer.dataset.announcementId = "";

            await refresh();

        } catch (error) {

            console.error(error);

        } finally {

            submitButton.disabled = false;
            updateDirtyState();

        }

    });

    updateDirtyState();

    return {

        /** Fills the composer for editing an existing post. */
        prefill({ announcementId, subject, message, existingAttachments = [] }) {

            openComposer();

            composer.dataset.mode = "edit";
            composer.dataset.announcementId = announcementId;

            subjectInput.value = subject;

            let parsed = {};
            try {
                parsed = JSON.parse(message || "{}");
            } catch (e) {
                console.error("Invalid announcement message JSON", e);
            }

            getComposerEditor()?.commands.setContent(parseMessage(parsed));

            attachments = existingAttachments.map((a) => ({ file_id: a.file_id, original_filename: a.original_filename }));
            renderAttachments();

            composer.scrollIntoView({ behavior: "smooth", block: "start" });
            subjectInput.focus();

            updateDirtyState();

        },

    };

}
