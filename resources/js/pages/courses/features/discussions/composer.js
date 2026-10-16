/*
|--------------------------------------------------------------------------
| Discussion composer: start a discussion, or edit the opening post of one.
| Reuses the announcement composer's rich-text modules (one TipTap instance
| at a time, which fits: only one composer is ever open on a screen).
|--------------------------------------------------------------------------
*/

import { setBusy } from "../../../../shared/spa/busy-button.js";
import { createThread, updateThread, uploadDiscussionAttachment } from "./api.js";
import { initializeComposerEditor, getComposerEditor, destroyComposerEditor } from "../announcements/composer/tiptap.js";
import { initializeComposerToolbar } from "../announcements/composer/toolbar.js";
import { serializeMessage } from "../announcements/composer/serializer.js";

/**
 * @param {HTMLElement} root
 * @param {(mode: "create"|"edit", threadId: number) => void} onSaved
 */
export function setupComposer(root, onSaved) {

    const host = root.querySelector("[data-discussion-composer]");

    if (!host) {
        return null;
    }

    const heading = host.querySelector("[data-composer-heading]");
    const titleInput = host.querySelector("[data-composer-title]");
    const mount = host.querySelector("#discussion-editor");
    const chips = host.querySelector("[data-composer-attachments]");
    const fileInput = host.querySelector("[data-composer-file-input]");
    const attach = host.querySelector("[data-composer-attach]");
    const submit = host.querySelector("[data-composer-submit]");

    let mode = "create";
    let threadId = null;
    let attachments = [];

    // Editing an opening post swaps the composer in for it; closing puts both back.
    const home = document.createComment("composer-home");
    host.before(home);

    let replaced = null;

    const restore = () => {

        if (replaced) {
            replaced.classList.remove("hidden");
            replaced = null;
        }

        if (home.parentNode && host.previousSibling !== home) {
            home.after(host);
        }

    };

    const refresh = () => {
        submit.disabled = titleInput.value.trim() === "";
    };

    const renderChips = () => {

        chips.innerHTML = "";

        attachments.forEach((file, index) => {

            const chip = document.createElement("div");
            chip.className = "flex items-center gap-2 rounded-full bg-gray-100 px-3 py-1.5 text-xs text-[#646464]";

            const name = document.createElement("span");
            name.className = "max-w-35 truncate";
            name.textContent = file.original_filename;

            const remove = document.createElement("button");
            remove.type = "button";
            remove.setAttribute("aria-label", `Remove ${file.original_filename}`);
            remove.className = "hover:text-[#C33939]";
            remove.textContent = "×";
            remove.onclick = () => {
                attachments.splice(index, 1);
                renderChips();
            };

            chip.append(name, remove);
            chips.appendChild(chip);

        });

    };

    const close = () => {

        destroyComposerEditor();

        restore();

        host.classList.add("hidden");
        host.classList.remove("flex");

        root.querySelector("[data-composer-open]")?.classList.remove("hidden");

    };

    // Assigned (not added) so reopening never stacks duplicate listeners.
    titleInput.oninput = refresh;

    host.querySelectorAll("[data-composer-cancel]").forEach((button) => {
        button.onclick = close;
    });

    attach.onclick = () => fileInput.click();

    fileInput.onchange = async () => {

        for (const file of Array.from(fileInput.files ?? [])) {

            if (attachments.length >= 10) {
                alert("You can attach up to 10 files.");
                break;
            }

            try {

                const result = await uploadDiscussionAttachment(file);

                if (!result.success) {
                    alert(result.message);
                    continue;
                }

                attachments.push(result);
                renderChips();

            } catch (error) {
                console.error(error);
            }

        }

        fileInput.value = "";

    };

    submit.onclick = async () => {

        const title = titleInput.value.trim();

        if (title === "") {
            return;
        }

        setBusy(submit, true, mode === "edit" ? "Saving…" : "Posting…");

        const payload = {
            title,
            body: JSON.stringify(serializeMessage(getComposerEditor())),
            attachment_file_ids: attachments.map((a) => a.file_id),
        };

        try {

            const result = mode === "edit"
                ? await updateThread(threadId, payload)
                : await createThread(payload);

            if (!result.success) {
                alert(result.message);
                return;
            }

            const savedId = mode === "edit" ? threadId : result.thread_id;
            const savedMode = mode;

            close();

            onSaved(savedMode, savedId);

        } catch (error) {

            console.error(error);

        } finally {

            setBusy(submit, false);
            refresh();

        }

    };

    return {

        /** @param {{ mode?: "create"|"edit", threadId?: number, title?: string, message?: string, attachments?: array, replace?: HTMLElement|null }} options */
        open({ mode: m = "create", threadId: id = null, title = "", message = "{}", attachments: existing = [], replace = null } = {}) {

            restore();

            if (replace) {
                replaced = replace;
                replace.classList.add("hidden");
                replace.after(host);
            }

            mode = m;
            threadId = id;
            attachments = existing.map((a) => ({ file_id: a.file_id, original_filename: a.original_filename }));

            heading.textContent = mode === "edit" ? "Edit discussion" : "New discussion";
            submit.textContent = mode === "edit" ? "Save" : "Post";

            titleInput.value = title;

            mount.dataset.message = message || "{}";

            host.classList.remove("hidden");
            host.classList.add("flex");

            root.querySelector("[data-composer-open]")?.classList.add("hidden");

            initializeComposerEditor("discussion-editor");

            // The toolbar closes over the editor it was bound to; a fresh copy
            // drops the previous editor's listeners before binding the new one.
            const toolbar = host.querySelector("[data-composer-toolbar]");
            const fresh = toolbar.cloneNode(true);
            toolbar.replaceWith(fresh);
            initializeComposerToolbar(fresh);

            renderChips();
            refresh();

            host.scrollIntoView({ behavior: "smooth", block: "nearest" });

            titleInput.focus();

        },

        close,

    };

}
