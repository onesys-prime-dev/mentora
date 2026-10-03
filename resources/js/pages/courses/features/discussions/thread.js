/*
|--------------------------------------------------------------------------
| Thread screen: open a thread, reply, react, mark an answer, delete a reply.
| Opening skeletons only what's below the breadcrumb; every action after that
| patches the DOM in place from its own response — no skeleton, no re-fetch.
|--------------------------------------------------------------------------
*/

import { setBusy } from "../../../../shared/spa/busy-button.js";
import { showLoadError } from "../../../../shared/spa/error-state.js";
import {
    fetchThread,
    postReply,
    removeReply,
    reactTo,
    markAnswer,
    uploadDiscussionAttachment,
    deleteThread,
    setThreadPinned,
    setThreadLocked,
} from "./api.js";
import { setupComposer } from "./composer.js";
import { bindActionMenu } from "../modules/action-menus.js";
import { renderThreadSkeleton } from "./skeleton.js";
import { rememberRows } from "../../../../shared/spa/skeleton-rows.js";

export async function loadThread(threadId, backToList, { pushState = true } = {}) {

    const container = document.querySelector("#course-content");

    container.innerHTML = renderThreadSkeleton();

    // "Discussions" is already real in the skeleton, so going back works while loading.
    container.querySelector("[data-discussions-breadcrumb]")
        ?.addEventListener("click", () => goBack(backToList));

    try {

        const result = await fetchThread(threadId);

        container.innerHTML = result.html;

        if (pushState) {
            history.pushState({}, "", `${window.location.pathname}?thread=${threadId}`);
        }

        bindThread(container, backToList);

    } catch (error) {

        console.error(error);

        showLoadError(container, "this discussion", () => loadThread(threadId, backToList, { pushState: false }));

    }

}

function goBack(backToList) {

    history.pushState({}, "", window.location.pathname);

    backToList();

}

export function bindThread(root, backToList) {

    const body = root.querySelector("[data-thread-body]");

    if (!body) {
        return;
    }

    const threadId = body.dataset.threadId;

    rememberRows("discussion-replies", body.querySelectorAll("[data-reply]").length);

    root.querySelector("[data-discussions-breadcrumb]")
        ?.addEventListener("click", () => goBack(backToList));

    const composer = setupComposer(root, () => loadThread(threadId, backToList, { pushState: false }));

    bindActionMenu("[data-more-options-toggle]", "[data-more-options-panel]");

    // Reactions and reply actions are delegated, so replies appended later just work.
    body.addEventListener("click", async (event) => {

        const edit = event.target.closest("[data-thread-edit]");

        if (edit) {

            let attachments = [];

            try {
                attachments = JSON.parse(body.dataset.threadAttachments ?? "[]");
            } catch (e) {
                console.error(e);
            }

            return composer?.open({
                mode: "edit",
                threadId: Number(threadId),
                title: body.dataset.threadTitle,
                message: body.dataset.threadMessage,
                attachments,
            });
        }

        const pin = event.target.closest("[data-thread-pin]");

        if (pin) {
            return handleToggle(body, threadId, pin, setThreadPinned, "is_pinned");
        }

        const lock = event.target.closest("[data-thread-lock]");

        if (lock) {
            return handleToggle(body, threadId, lock, setThreadLocked, "is_locked");
        }

        const removeThread = event.target.closest("[data-thread-delete]");

        if (removeThread) {
            return handleDeleteThread(threadId, backToList);
        }

        const react = event.target.closest("[data-react]");

        if (react) {
            return handleReact(body, threadId, react);
        }

        const answer = event.target.closest("[data-mark-answer]");

        if (answer) {
            return handleMarkAnswer(body, threadId, answer);
        }

        const del = event.target.closest("[data-delete-reply]");

        if (del) {
            return handleDelete(body, del);
        }

    });

    bindReplyBox(body, threadId);

}

/*
|--------------------------------------------------------------------------
| Moderation — pin / lock patch in place, delete returns to the list
|--------------------------------------------------------------------------
*/

async function handleToggle(body, threadId, button, request, key) {

    const next = button.dataset.value !== "1";

    try {

        const result = await request(Number(threadId), next);

        if (!result.success) {
            alert(result.message);
            return;
        }

        const on = Boolean(result[key]);

        button.dataset.value = on ? "1" : "0";

        if (key === "is_pinned") {
            button.querySelector("[data-pin-label]").textContent = on ? "Unpin" : "Pin";
            body.querySelector("[data-badge-pinned]")?.classList.toggle("hidden", !on);
        } else {
            button.querySelector("[data-lock-label]").textContent = on ? "Unlock" : "Lock";
            body.querySelector("[data-badge-locked]")?.classList.toggle("hidden", !on);
            body.querySelector("[data-locked-notice]")?.classList.toggle("hidden", !on);
            body.querySelector("[data-reply-box-wrapper]")?.classList.toggle("hidden", on);
        }

    } catch (error) {
        console.error(error);
    }

}

async function handleDeleteThread(threadId, backToList) {

    if (!confirm("Delete this discussion and all its replies?")) {
        return;
    }

    try {

        const result = await deleteThread(Number(threadId));

        if (!result.success) {
            alert(result.message);
            return;
        }

        goBack(backToList);

    } catch (error) {
        console.error(error);
    }

}

/*
|--------------------------------------------------------------------------
| Reactions / answer / delete
|--------------------------------------------------------------------------
*/

async function handleReact(body, threadId, button) {

    const group = button.closest("[data-reactions]");
    const replyId = group?.dataset.replyId ? Number(group.dataset.replyId) : null;

    try {

        const result = await reactTo(threadId, replyId, button.dataset.react);

        if (!result.success) {
            return;
        }

        group.querySelectorAll("[data-react]").forEach((b) => {

            const mine = b.dataset.react === result.emoji;

            b.setAttribute("aria-pressed", String(mine));
            b.classList.toggle("bg-[#0040A6]/10", mine);

        });

        renderReactionSummary(group, result.reaction_counts ?? {}, result.emoji);

    } catch (error) {
        console.error(error);
    }

}

function renderReactionSummary(group, counts, own) {

    const summary = group.querySelector("[data-reaction-summary]");

    if (!summary) {
        return;
    }

    summary.innerHTML = "";

    let total = 0;

    Object.entries(counts).forEach(([emoji, count]) => {

        if (Number(count) <= 0) {
            return;
        }

        total += Number(count);

        const pill = document.createElement("span");

        pill.className = "flex items-center gap-1 rounded-full px-2 py-0.5 "
            + (emoji === own ? "bg-[#0040A6]/10 text-[#0040A6]" : "bg-gray-100");

        pill.textContent = `${emoji} ${count}`;

        summary.appendChild(pill);

    });

    summary.classList.toggle("hidden", total === 0);

}

async function handleMarkAnswer(body, threadId, button) {

    const reply = button.closest("[data-reply]");

    try {

        const result = await markAnswer(threadId, Number(reply.dataset.replyId));

        if (!result.success) {
            return;
        }

        body.querySelectorAll("[data-reply]").forEach((r) => {

            const isAnswer = String(result.answer_reply_id) === r.dataset.replyId;

            r.querySelector("[data-answer-badge]")?.classList.toggle("hidden", !isAnswer);

            r.classList.toggle("border-[#2E7D4F]/50", isAnswer);
            r.classList.toggle("bg-[#E8F5EC]/40", isAnswer);
            r.classList.toggle("border-gray-200", !isAnswer);

            const label = r.querySelector("[data-mark-answer-label]");

            if (label) {
                label.textContent = isAnswer ? "Unmark answer" : "Mark as answer";
            }

        });

    } catch (error) {
        console.error(error);
    }

}

async function handleDelete(body, button) {

    if (!confirm("Delete this reply?")) {
        return;
    }

    const reply = button.closest("[data-reply]");

    try {

        const result = await removeReply(Number(reply.dataset.replyId));

        if (!result.success) {
            return;
        }

        reply.remove();

        updateReplyCount(body, result.reply_count);

    } catch (error) {
        console.error(error);
    }

}

function updateReplyCount(body, count) {

    body.querySelector("[data-reply-count]").textContent = String(count);
    body.querySelector("[data-reply-count-label]").textContent = count === 1 ? "Reply" : "Replies";
    body.querySelector("[data-no-replies]")?.classList.toggle("hidden", count !== 0);

}

/*
|--------------------------------------------------------------------------
| Reply box (text + attachments)
|--------------------------------------------------------------------------
*/

function bindReplyBox(body, threadId) {

    const input = body.querySelector("[data-reply-input]");
    const send = body.querySelector("[data-reply-send]");
    const attachButton = body.querySelector("[data-reply-attach]");
    const fileInput = body.querySelector("[data-reply-file-input]");
    const chips = body.querySelector("[data-reply-attachments]");

    if (!input || !send) {
        return; // locked thread
    }

    let attachments = [];

    const refresh = () => {
        send.disabled = input.value.trim() === "";
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
            remove.className = "text-[#646464] hover:text-[#C33939]";
            remove.textContent = "×";
            remove.addEventListener("click", () => {
                attachments.splice(index, 1);
                renderChips();
            });

            chip.append(name, remove);
            chips.appendChild(chip);

        });

    };

    input.addEventListener("input", refresh);

    input.addEventListener("keydown", (event) => {
        if (event.key === "Enter" && !event.shiftKey) {
            event.preventDefault();
            send.click();
        }
    });

    attachButton?.addEventListener("click", () => fileInput?.click());

    fileInput?.addEventListener("change", async () => {

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

    });

    send.addEventListener("click", async () => {

        const text = input.value.trim();

        if (text === "") {
            return;
        }

        setBusy(send, true, "Sending…");

        try {

            const result = await postReply(threadId, text, attachments.map((a) => a.file_id));

            if (!result.success) {
                alert(result.message);
                return;
            }

            body.querySelector("[data-reply-list]").insertAdjacentHTML("beforeend", result.html);

            updateReplyCount(body, result.reply_count);

            bindActionMenu("[data-more-options-toggle]", "[data-more-options-panel]");

            // A discussion learning item completes once the student has replied.
            if (result.completes_item) {
                document.dispatchEvent(new CustomEvent("discussions:replied"));
            }

            input.value = "";
            attachments = [];
            renderChips();

        } catch (error) {

            console.error(error);

        } finally {

            setBusy(send, false);
            refresh();

        }

    });

}
