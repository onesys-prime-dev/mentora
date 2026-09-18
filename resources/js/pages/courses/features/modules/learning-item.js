import { 
    fetchLearningItemView, 
    fetchLearningItemEditor 
} from "./api.js";

import { state } from "./state.js";

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
    refresh
) {

    try {

        state.loading = true;

        state.activeLearningItemId =
            learningItemId;

        const response =
            await fetchLearningItemView(
                learningItemId
            );

        const container =
            document.querySelector(
                "#course-content"
            );

        container.innerHTML =
            response.html;

        history.pushState(
            {
                learningItemId,
            },
            "",
            `${window.location.pathname}?item=${learningItemId}`
        );

        bindLearningItemEvents(refresh);

        bindLearningNavigation(refresh);

        bindLearningUnitEvents();

        bindBreadcrumbEvents(refresh);

        initializePdfViewer();

    }

    catch (error) {

        console.error(error);

    }

    finally {

        state.loading = false;

    }

}

export async function loadLearningItemEditor(
    learningItemId,
    refresh
) {

    try {

        state.loading = true;

        state.activeLearningItemId =
            learningItemId;

        const response =
            await fetchLearningItemEditor(
                learningItemId
            );

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

        bindBreadcrumbEvents(refresh);

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

        initializeLearningItemActions(
            learningItemId,
            refresh
        );

    }

    catch (error) {

        console.error(error);

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