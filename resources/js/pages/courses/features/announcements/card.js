/*
|--------------------------------------------------------------------------
| Announcement card bindings: quick reacts, more-reactions picker, more-
| options (edit/pin/delete), "See more" clamp, and the reply thread.
|--------------------------------------------------------------------------
*/

import { bindActionMenu } from "../modules/action-menus.js";
import {
    reactToAnnouncement,
    togglePinAnnouncement,
    deleteAnnouncement,
    replyToAnnouncement,
    deleteAnnouncementReply,
} from "./api.js";
import { mountEmojiPicker } from "./emoji-picker.js";

export function bindCards(root, refresh, composerApi) {

    bindActionMenu("[data-more-options-toggle]", "[data-more-options-panel]");
    bindActionMenu("[data-emoji-panel-toggle]", "[data-emoji-panel]");

    root.querySelectorAll("[data-announcement-card]").forEach((card) => {

        // Infinite scroll re-invokes this on the same root after appending
        // new cards — skip ones already wired or they'd get bound twice.
        if (card.dataset.cardBound === "1") {
            return;
        }

        card.dataset.cardBound = "1";
        bindCard(card, refresh, composerApi);

    });

}

function bindCard(card, refresh, composerApi) {

    const announcementId = card.dataset.announcementId;

    // Quick reacts
    card.querySelectorAll("[data-react]").forEach((button) => {
        button.addEventListener("click", async () => {
            try {
                await reactToAnnouncement(announcementId, button.dataset.react);
                await refresh();
            } catch (error) {
                console.error(error);
            }
        });
    });

    // More reactions (emoji-mart)
    const emojiPanel = card.querySelector("[data-emoji-panel]");
    if (emojiPanel) {
        mountEmojiPicker(emojiPanel, async (emoji) => {
            try {
                await reactToAnnouncement(announcementId, emoji);
                await refresh();
            } catch (error) {
                console.error(error);
            }
        });
    }

    // More options
    card.querySelector("[data-edit-announcement]")?.addEventListener("click", () => {
        composerApi?.prefill({
            announcementId,
            subject: card.dataset.announcementSubject,
            message: card.dataset.announcementMessage,
            existingAttachments: JSON.parse(card.dataset.announcementAttachments || "[]"),
        });
    });

    card.querySelector("[data-toggle-pin]")?.addEventListener("click", async () => {
        try {
            await togglePinAnnouncement(announcementId);
            await refresh();
        } catch (error) {
            console.error(error);
        }
    });

    card.querySelector("[data-delete-announcement]")?.addEventListener("click", async () => {
        if (!confirm("Delete this announcement? This can't be undone.")) {
            return;
        }
        try {
            await deleteAnnouncement(announcementId);
            await refresh();
        } catch (error) {
            console.error(error);
        }
    });

    // See more (message)
    bindSeeMore(card.querySelector("[data-announcement-message]"));

    // Reply composer — starts as a plain trigger; clicking it swaps in the
    // actual textarea/send form, matching the top-level composer's pattern.
    const replyTrigger = card.querySelector("[data-reply-trigger]");
    const replyForm = card.querySelector("[data-reply-form]");
    const replyInput = card.querySelector("[data-reply-input]");
    const replySend = card.querySelector("[data-reply-send]");

    replyTrigger?.addEventListener("click", () => {
        replyTrigger.classList.add("hidden");
        replyForm?.classList.remove("hidden");
        replyForm?.classList.add("flex");
        replyInput?.focus();
    });

    replyInput?.addEventListener("input", () => {
        replySend.disabled = replyInput.value.trim() === "";
        replyInput.style.height = "auto";
        replyInput.style.height = `${replyInput.scrollHeight}px`;
    });

    replyInput?.addEventListener("keydown", (event) => {
        if (event.key === "Enter" && !event.shiftKey) {
            event.preventDefault();
            replySend?.click();
        }
    });

    replySend?.addEventListener("click", async () => {

        const message = replyInput.value.trim();

        if (message === "") {
            return;
        }

        replySend.disabled = true;

        try {

            const result = await replyToAnnouncement(announcementId, message);

            if (!result.success) {
                alert(result.message);
                return;
            }

            await refresh();

        } catch (error) {

            console.error(error);

        } finally {

            replySend.disabled = false;

        }

    });

    // Existing replies: see-more + delete
    card.querySelectorAll("[data-reply-row]").forEach((row) => {

        bindSeeMore(row.querySelector("[data-reply-message]"));

        row.querySelector("[data-delete-reply]")?.addEventListener("click", async () => {

            if (!confirm("Delete this reply?")) {
                return;
            }

            try {
                await deleteAnnouncementReply(row.dataset.replyId);
                await refresh();
            } catch (error) {
                console.error(error);
            }

        });

    });

}

/** Clamps to ~4 lines; "See more" removes the clamp. */
function bindSeeMore(element) {

    if (!element) {
        return;
    }

    const button = element.nextElementSibling;

    if (!button || !button.hasAttribute("data-see-more")) {
        return;
    }

    element.classList.add("line-clamp-4");

    // Only show the button if the content actually overflows the clamp.
    requestAnimationFrame(() => {

        if (element.scrollHeight > element.clientHeight + 2) {
            button.classList.remove("hidden");
        }

    });

    button.addEventListener("click", () => {
        element.classList.remove("line-clamp-4");
        button.classList.add("hidden");
    });

}
