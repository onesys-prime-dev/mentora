/*
|--------------------------------------------------------------------------
| Announcement card bindings: quick reacts, more-reactions picker, more-
| options (edit/pin/delete), "See more" clamp, and the reply thread.
|
| Reacting, replying and pinning patch the DOM directly from each action's
| own response — no skeleton, no re-fetch, just an instant local update.
| Only posting/deleting an announcement (a real listing change) goes
| through the main-section refresh the caller provides.
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
import { openCardEditor } from "./card-editor.js";

const QUICK_REACTIONS = { like: "👍", heart: "❤️", haha: "😂", wow: "😮" };

export function bindCards(root, refreshMain, composerApi) {

    bindActionMenu("[data-more-options-toggle]", "[data-more-options-panel]");
    bindActionMenu("[data-emoji-panel-toggle]", "[data-emoji-panel]");

    root.querySelectorAll("[data-announcement-card]").forEach((card) => {

        // Infinite scroll re-invokes this on the same root after appending
        // new cards — skip ones already wired or they'd get bound twice.
        if (card.dataset.cardBound === "1") {
            return;
        }

        card.dataset.cardBound = "1";
        bindCard(card, refreshMain, composerApi);

    });

}

function bindCard(card, refreshMain, composerApi) {

    const announcementId = card.dataset.announcementId;

    // Quick reacts + more-reactions picker — instant, local update only.
    card.querySelectorAll("[data-react]").forEach((button) => {
        button.addEventListener("click", () => applyReaction(card, announcementId, button.dataset.react));
    });

    const emojiPanel = card.querySelector("[data-emoji-panel]");
    if (emojiPanel) {
        mountEmojiPicker(emojiPanel, (emoji) => applyReaction(card, announcementId, emoji));
    }

    // More options
    card.querySelector("[data-edit-announcement]")?.addEventListener("click", () => {
        openCardEditor(card, {
            onBeforeOpen: () => composerApi?.close(),
            onSaved: refreshMain,
        });
    });

    card.querySelector("[data-toggle-pin]")?.addEventListener("click", async () => {
        try {
            const result = await togglePinAnnouncement(announcementId);
            if (result.success) {
                applyPinState(card, !!result.is_pinned);
            }
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
            await refreshMain();
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

            appendReply(card, result.html);

            replyInput.value = "";
            replyInput.style.height = "auto";

            // Collapse back to the plain trigger, same as right after load.
            replyForm?.classList.add("hidden");
            replyForm?.classList.remove("flex");
            replyTrigger?.classList.remove("hidden");

        } catch (error) {

            console.error(error);

        } finally {

            replySend.disabled = false;

        }

    });

    // Existing replies: see-more + delete, instant local removal.
    card.querySelectorAll("[data-reply-row]").forEach((row) => bindReplyRow(row));

}

/** React or switch/remove a reaction — patches quick-react highlighting and the count summary from the response alone. */
async function applyReaction(card, announcementId, emoji) {

    try {

        const result = await reactToAnnouncement(announcementId, emoji);

        if (!result.success) {
            return;
        }

        const ownReaction = result.emoji ?? null;

        card.querySelectorAll("[data-react]").forEach((button) => {
            button.classList.toggle("bg-brand/10", button.dataset.react === ownReaction);
        });

        renderReactionSummary(card, result.reaction_counts ?? {});

    } catch (error) {
        console.error(error);
    }

}

function renderReactionSummary(card, counts) {

    const summary = card.querySelector("[data-reaction-summary]");

    if (!summary) {
        return;
    }

    const entries = Object.entries(counts).filter(([, count]) => count > 0);

    if (entries.length === 0) {
        summary.classList.add("hidden");
        summary.innerHTML = "";
        return;
    }

    summary.innerHTML = entries
        .map(([key, count]) => `
            <span class="flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5">
                ${QUICK_REACTIONS[key] ?? key} ${count}
            </span>
        `)
        .join("");

    summary.classList.remove("hidden");

}

/** Toggles the pinned banner, border tint, and the sidebar's pinned list — no reload. */
function applyPinState(card, isPinned) {

    card.querySelector("[data-pinned-banner]")?.classList.toggle("hidden", !isPinned);

    card.classList.toggle("border-brand/30", isPinned);
    card.classList.toggle("border-gray-200", !isPinned);

    const pinLabel = card.querySelector("[data-toggle-pin-label]");
    if (pinLabel) {
        pinLabel.textContent = isPinned ? "Unpin" : "Pin for everyone";
    }

    updateSidebarPinned(card, isPinned);

}

function updateSidebarPinned(card, isPinned) {

    const list = document.querySelector("[data-sidebar-pinned]");

    if (!list) {
        return;
    }

    const announcementId = card.dataset.announcementId;
    const existing = list.querySelector(`[data-scroll-to-announcement="${announcementId}"]`);

    if (isPinned && !existing) {

        list.querySelector("[data-sidebar-pinned-empty]")?.remove();

        let row = list.querySelector("[data-sidebar-pinned-list]");
        if (!row) {
            row = document.createElement("div");
            row.className = "flex flex-col gap-1";
            row.dataset.sidebarPinnedList = "";
            list.appendChild(row);
        }

        const button = document.createElement("button");
        button.type = "button";
        button.dataset.scrollToAnnouncement = announcementId;
        button.className = "flex items-start gap-2 rounded-xl px-2 py-1.5 text-left transition hover:bg-brand/5";
        button.innerHTML = `
            <span class="mt-0.5 shrink-0 text-sm">📌</span>
            <span class="truncate text-sm text-[#1A1C1E]"></span>
        `;
        button.querySelector("span:last-child").textContent = card.dataset.announcementSubject || "";

        button.addEventListener("click", () => {
            card.scrollIntoView({ behavior: "smooth", block: "start" });
        });

        row.appendChild(button);

    } else if (!isPinned && existing) {

        const row = existing.parentElement;
        existing.remove();

        if (row && row.children.length === 0) {

            row.remove();

            const placeholder = document.createElement("span");
            placeholder.className = "text-xs text-[#767676]";
            placeholder.dataset.sidebarPinnedEmpty = "";
            placeholder.textContent = "No pinned announcements yet.";
            list.appendChild(placeholder);

        }

    }

}

function appendReply(card, html) {

    let thread = card.querySelector("[data-reply-thread]");

    if (!thread) {

        thread = document.createElement("div");
        thread.className = "flex flex-col gap-3 border-t border-gray-100 px-6 py-4";
        thread.dataset.replyThread = "";

        card.querySelector("[data-reply-row]")?.before(thread);

    }

    thread.insertAdjacentHTML("beforeend", html);

    const row = thread.lastElementChild;
    if (row) {
        bindReplyRow(row);
    }

}

function bindReplyRow(row) {

    bindSeeMore(row.querySelector("[data-reply-message]"));

    row.querySelector("[data-delete-reply]")?.addEventListener("click", async () => {

        if (!confirm("Delete this reply?")) {
            return;
        }

        try {

            const result = await deleteAnnouncementReply(row.dataset.replyId);

            if (!result.success) {
                return;
            }

            const thread = row.parentElement;
            row.remove();

            if (thread && thread.children.length === 0) {
                thread.remove();
            }

        } catch (error) {
            console.error(error);
        }

    });

}

/** Clamps to ~4 lines; "See more" removes the clamp and becomes "See less". */
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

        const expanded = element.classList.toggle("line-clamp-4") === false;

        button.textContent = expanded ? "See less" : "See more";

    });

}
