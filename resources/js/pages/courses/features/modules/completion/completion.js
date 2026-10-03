/*
|--------------------------------------------------------------------------
| Learning Item Completion (students)
|--------------------------------------------------------------------------
|
| "Mark as Completed" in the item footer (partials/navigations.php). On
| success the button turns into a disabled "✓ Completed", Continue
| appears, and the side panel / header / progress bar update in place —
| including unlocking items that were waiting on this one. The server
| decides whether completing is allowed; a disabled button is a hint.
|
*/

import { markLearningItemComplete } from "../api.js";

export function initializeCompletion(refresh) {

    document
        .querySelector("[data-back-to-modules]")
        ?.addEventListener("click", async () => {

            history.pushState({}, "", window.location.pathname);

            await refresh();

        });

    const button = document.querySelector("[data-mark-complete]");

    if (!button) {
        return;
    }

    button.addEventListener("click", async () => {

        if (button.disabled || button.dataset.completed === "1") {
            return;
        }

        button.disabled = true;

        try {

            const result = await markLearningItemComplete(
                Number(button.dataset.learningItemId),
                Number(document.querySelector("[data-continue][data-next-item-id]")?.dataset.nextItemId) || null
            );

            if (!result.success) {

                showHint(result.message);

                button.disabled = false;

                return;

            }

            showCompleted(button, result);

        }

        catch (error) {

            console.error(error);

            showHint("Couldn't save your progress. Please try again.");

            button.disabled = false;

        }

    });

}

/**
 * Called by video-progress.js once 90% has been watched.
 */
export function enableMarkComplete() {

    const button = document.querySelector("[data-mark-complete]");

    if (!button || button.dataset.completed === "1") {
        return;
    }

    button.disabled = false;

    document.querySelector("[data-complete-hint]")?.classList.add("hidden");

}

function showHint(message) {

    const hint = document.querySelector("[data-complete-hint]");

    if (!hint) {
        return;
    }

    hint.textContent = message;

    hint.classList.remove("hidden");

}

function showCompleted(button, result) {

    button.dataset.completed = "1";

    button.disabled = true;

    button.querySelector("[data-mark-complete-label]").textContent = "Completed";

    button.querySelector("[data-mark-complete-icon]")?.classList.remove("hidden");

    document.querySelector("[data-complete-hint]")?.classList.add("hidden");

    document.querySelector("[data-continue]")?.classList.remove("hidden");

    updateContinueLock(result);

    document.querySelector('[data-completion-badge="completed"]')?.classList.remove("hidden");

    document.querySelector('[data-completion-badge="pending"]')?.classList.add("hidden");

    markPanelItem(result.itemId);

    (result.unlockedItemIds ?? []).forEach(unlockPanelItem);

    if (result.module) {

        const bar = document.querySelector("[data-module-progress-bar]");

        const label = document.querySelector("[data-module-progress-label]");

        if (bar) {
            bar.style.width = `${result.module.progress}%`;
        }

        if (label) {
            label.textContent = `${result.module.progress}%`;
        }

    }

}

function markPanelItem(itemId) {

    const item = document.querySelector(`[data-panel-item="${itemId}"]`);

    const circle = item?.querySelector("[data-item-status-circle]");

    if (!circle) {
        return;
    }

    circle.classList.remove("border-[#999696]");

    circle.classList.add("border-[#7DB080]", "bg-[#E6F4EA]");

    circle.querySelector("[data-item-status-check]")?.classList.remove("hidden");

    circle.querySelector("[data-item-status-lock]")?.classList.add("hidden");

}

/**
 * Continue stays disabled (lock + hint) while the next item is still
 * locked; the server tells us after each completion.
 */
function updateContinueLock(result) {

    const button = document.querySelector("[data-continue][data-next-item-id]");

    if (!button || result.nextLocked === undefined) {
        return;
    }

    const hint = document.querySelector("[data-continue-lock-hint]");

    if (result.nextLocked) {

        button.disabled = true;
        button.dataset.nextLocked = "1";
        button.title = result.nextLockHint ?? "";
        button.querySelector("[data-continue-lock-icon]")?.classList.remove("hidden");

        if (hint) {
            hint.textContent = result.nextLockHint ?? "";
            hint.classList.remove("hidden");
        }

        return;
    }

    button.disabled = false;
    delete button.dataset.nextLocked;
    button.removeAttribute("title");
    button.querySelector("[data-continue-lock-icon]")?.classList.add("hidden");
    hint?.remove();

    unlockPanelItem(button.dataset.nextItemId);

}

function unlockPanelItem(itemId) {

    // Panel row and Modules-list row alike become clickable again.
    document
        .querySelectorAll(`[data-panel-item="${itemId}"], [data-learning-item="${itemId}"][data-locked]`)
        .forEach((item) => {

            item.querySelector("[data-item-status-lock]")?.classList.add("hidden");

            item.querySelector("[data-item-title]")?.classList.remove("text-[#767676]");

            delete item.dataset.locked;
            item.removeAttribute("aria-disabled");
            item.removeAttribute("title");
            item.classList.remove("cursor-not-allowed");
            item.classList.add("cursor-pointer", "hover:bg-[#ECEEF4]");

        });

}
