import { beginLoad } from "../../../../shared/spa/load-guard.js";
import { showLoadError } from "../../../../shared/spa/error-state.js";
import {
    fetchLearningItemView,
    fetchLearningItemEditor
} from "./api.js";

import { state } from "./state.js";
import { renderLearningItemSkeleton, renderLearningItemMainSkeleton } from "./skeleton.js";

import { initializePdfViewer } from "../../../../shared/pdf/viewer.js";

import { bindBreadcrumbEvents } from "./breadcrumb.js";

import {
    initializeTipTap,
} from "./reading/tiptap.js";

import {
    initializeReadingAutosave,
} from "./reading/reading-autosave.js";

import {
    updateLearningItem,
} from "./api.js";

import {
    initializeToolbar,
} from "./reading/toolbar.js";

import {
    bindLearningNavigation,
} from "./navigation.js";

import {
    initializeLearningItemActions,
} from "./learning-item-actions.js";

import {
    initializeVideoEditor,
} from "./video/video.js";

import {
    initializeVideoAutosave,
} from "./video/video-autosave.js";

import {
    bindAssessmentHandoffs,
} from "./assessment-handoff.js";

import {
    initializeAssignmentSubmission,
} from "../../../../shared/assignment/submission.js";

import { bindThread } from "../discussions/thread.js";
import {
    initializeCompletion,
    enableMarkComplete,
} from "./completion/completion.js";

import {
    initializeVideoProgress,
    flushVideoProgress,
} from "./completion/video-progress.js";

import {
    initializePdfEditor,
} from "./pdf/pdf-editor.js";

import {
    initializeLinkEditor,
} from "./link/link-editor.js";

/*
|--------------------------------------------------------------------------
| Learning Items
|--------------------------------------------------------------------------
*/

export function bindLearningItemEvents(refresh) {

    document
        .querySelectorAll("[data-learning-item]")
        .forEach((element) => {

            element.addEventListener(
                "click",
                async (event) => {

                    // Ignore clicks from buttons/links/forms
                    if (
                        event.target.closest(
                            "[data-learning-item-edit-button], button, a, input, select, textarea"
                        )
                    ) {
                        return;
                    }

                    // Locked for this student: not openable (completion.js
                    // clears data-locked once it unlocks).
                    if (element.dataset.locked === "1") {
                        return;
                    }

                    const learningItemId =
                        Number(
                            element.dataset.learningItem
                        );

                    if (!learningItemId) {
                        return;
                    }

                    await loadLearningItem(
                        learningItemId,
                        refresh
                    );

                }
            );

        });

}

export function bindLearningItemEditorEvents(
    refresh
) {

    document
        .querySelectorAll(
            "[data-learning-item-edit-button]"
        )
        .forEach((button) => {

            button.addEventListener(
                "click",
                async (event) => {

                    event.preventDefault();

                    const learningItemId =
                        Number(
                            button.dataset.learningItem
                        );

                    if (!learningItemId) {
                        return;
                    }

                    await loadLearningItemEditor(
                        learningItemId,
                        refresh
                    );

                }
            );

        });

}

export async function loadLearningItem(
    learningItemId,
    refresh,
    { pushState = true } = {}
) {

    let load = null;

    try {

        state.loading = true;

        state.activeLearningItemId =
            learningItemId;

        // Save the last few seconds of watch progress before leaving a video.
        flushVideoProgress();

        const container =
            document.querySelector("#course-content");

        // Previous/Continue stays on this same view — only the header,
        // content, detail-panel and nav need to placeholder while the next
        // item loads, not the whole page (left panel + breadcrumb stay put).
        const mainSection =
            container.querySelector("[data-learning-item-main]");

        load = beginLoad(
            container,
            mainSection ? renderLearningItemMainSkeleton() : renderLearningItemSkeleton(),
            0,
            mainSection ?? container
        );

        const response =
            await fetchLearningItemView(
                learningItemId
            );

        // Superseded by a newer navigation: that one owns the screen now.
        if (!load.current()) {
            return;
        }

        load.finish();

        // Refused: the item is locked. Back to the Modules list with the
        // reason — the URL is replaced so Back / refresh don't loop into it.
        if (response.locked) {

            history.replaceState({}, "", window.location.pathname);

            if (refresh) {
                await refresh();
            }

            showLockedNotice(response, refresh);

            return;

        }

        container.innerHTML =
            response.html;

        if (pushState) {

            history.pushState(
                {
                    learningItemId,
                },
                "",
                `${window.location.pathname}?item=${learningItemId}`
            );

        }

        bindLearningItemEvents(refresh);

        bindLearningNavigation(refresh);

        bindLearningUnitEvents();

        bindBreadcrumbEvents();

        initializePdfViewer();

        bindAssessmentHandoffs();

        initializeCompletion(refresh);

        initializeDiscussionItem();

        initializeVideoProgress();

        initializeAssignmentSubmission({

            onSubmitted: () =>
                loadLearningItem(
                    learningItemId,
                    refresh,
                    { pushState: false }
                ),

        });

    }

    catch (error) {

        console.error(error);

        // Never leave the skeleton up forever (unless a newer load owns it).
        if (!load || load.current()) {
            showLoadError(
                document.querySelector("#course-content"),
                "this learning item",
                () => loadLearningItem(learningItemId, refresh, { pushState: false })
            );
        }

    }

    finally {

        state.loading = false;

    }

}

export async function loadLearningItemEditor(
    learningItemId,
    refresh
) {

    let load = null;

    try {

        state.loading = true;

        state.activeLearningItemId =
            learningItemId;

        load = beginLoad(
            document.querySelector("#course-content"),
            renderLearningItemSkeleton(),
            0
        );

        const response =
            await fetchLearningItemEditor(
                learningItemId
            );

        if (!load.current()) {
            return;
        }

        load.finish();

        const container =
            document.querySelector(
                "#course-content"
            );

        container.innerHTML =
            response.html;

        history.pushState(
            {
                learningItemId,
                editor: true,
            },
            "",
            `${window.location.pathname}?item=${learningItemId}&editor=1`
        );

        bindLearningItemEvents(refresh);

        bindLearningNavigation(refresh);

        bindLearningItemEditorEvents(
            refresh
        );

        bindLearningUnitEvents();

        bindBreadcrumbEvents();

        initializePdfViewer();

        if (

            document.querySelector(
                "[data-reading-editor]"
            )

        ) {

            initializeTipTap();

            initializeToolbar();

            initializeReadingAutosave({

                onSave: async (content) => {

                    await updateLearningItem(
                        learningItemId,
                        {
                            content,
                        }
                    );

                },

            });

        }

        if (

            document.querySelector(
                "[data-video-editor]"
            )

        ) {

            initializeVideoEditor();

            initializeVideoAutosave({

                learningItemId,

            });

        }

        initializePdfEditor();

        initializeLinkEditor();

        bindAssessmentHandoffs();

        initializeLearningItemActions(
            learningItemId,
            refresh
        );

    }

    catch (error) {

        console.error(error);

        if (!load || load.current()) {
            showLoadError(
                document.querySelector("#course-content"),
                "this editor",
                () => loadLearningItemEditor(learningItemId, refresh)
            );
        }

    }

    finally {

        state.loading = false;

    }

}

/*
|--------------------------------------------------------------------------
| Learning Units
|--------------------------------------------------------------------------
*/

function bindLearningUnitEvents() {

    document
        .querySelectorAll(
            "[data-learning-unit]"
        )
        .forEach((header) => {

            header.addEventListener(
                "click",
                () => {

                    const content =
                        header.nextElementSibling;

                    const arrow =
                        header.querySelector("svg");

                    if (!content) {
                        return;
                    }

                    const isHidden =
                        content.classList.contains(
                            "hidden"
                        );

                    if (isHidden) {

                        content.classList.remove(
                            "hidden"
                        );

                        arrow?.classList.add(
                            "rotate-180"
                        );

                    }

                    else {

                        content.classList.add(
                            "hidden"
                        );

                        arrow?.classList.remove(
                            "rotate-180"
                        );

                    }

                }
            );

        });

}

/*
|--------------------------------------------------------------------------
| Locked notice
|--------------------------------------------------------------------------
|
| Shown at the top of the Modules list when a student tried to open a
| locked item: why, plus a shortcut to the first missing prerequisite.
|
*/

function showLockedNotice(result, refresh) {

    const container =
        document.querySelector("#course-content");

    if (!container) {
        return;
    }

    container.querySelector("[data-locked-notice]")?.remove();

    const notice = document.createElement("div");

    notice.dataset.lockedNotice = "";
    notice.setAttribute("role", "alert");
    notice.className =
        "mx-10 mt-6 flex items-center gap-3 rounded-2xl border border-[#FFE3A3] bg-[#FFF8E6] px-5 py-4 text-sm text-[#946200]";

    notice.innerHTML = `
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 shrink-0" aria-hidden="true"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        <span class="flex-1" data-locked-notice-message></span>
        <button type="button" data-locked-notice-open class="hidden shrink-0 rounded-lg bg-[#946200] px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-[#7a5100]"></button>
        <button type="button" data-locked-notice-dismiss title="Dismiss" class="shrink-0 rounded-lg p-1 transition hover:bg-[#FFE3A3]">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>`;

    // Text set as text — titles come from course content.
    notice.querySelector("[data-locked-notice-message]").textContent =
        result.message || "This item is locked.";

    const openButton = notice.querySelector("[data-locked-notice-open]");

    if (result.unlock_item_id) {

        openButton.textContent = `Open “${result.unlock_item_title ?? "it"}”`;
        openButton.classList.remove("hidden");

        openButton.addEventListener("click", () => {
            loadLearningItem(result.unlock_item_id, refresh);
        });

    }

    notice
        .querySelector("[data-locked-notice-dismiss]")
        .addEventListener("click", () => notice.remove());

    container.prepend(notice);

}


/**
 * A discussion learning item embeds the same thread screen the Discussions
 * tab uses; replying is what lets a student complete the item.
 */
let repliedListenerBound = false;

function initializeDiscussionItem() {

    const root = document.querySelector("#course-content");

    if (!root?.querySelector("[data-thread-body]")) {
        return;
    }

    bindThread(root, () => {});

    if (!repliedListenerBound) {

        repliedListenerBound = true;

        document.addEventListener("discussions:replied", () => enableMarkComplete());

    }

}
