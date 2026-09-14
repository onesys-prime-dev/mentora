import {
    fetchAssessments,
    fetchAssessmentView,
    fetchAssessmentEditor,
    updateAssessmentInstructions,
    updateSubmissionSettings,
    deleteAssessment,
 } from "./api.js";

import { state } from "./state.js";

import { bindModal } from "./modals/index.js";

import { bindAssessmentCreateModal } from "./modals/assessments.js";

import {
    initializeTipTap as initializeInstructionsTipTap,
    resetContent as resetInstructionsContent,
} from "./instructions/tiptap.js";

import {
    initializeToolbar as initializeInstructionsToolbar,
} from "./instructions/toolbar.js";

import {
    serializeInstructions,
} from "./instructions/serializer.js";

import {
    initializeRubrics,
} from "./rubrics/rubrics.js";

import {
    initializeInspectorPanel,
} from "./inspector/inspector.js";

export async function initialize() {

    const params =
        new URLSearchParams(window.location.search);

    const assessmentId =
        Number(
            params.get("assessment")
        );

    const isEditor =
        params.has("editor");

    if (assessmentId) {

        const offeringId =
            window.coursesConfig.offeringId;

        if (isEditor) {

            await loadAssessmentEditor(
                offeringId,
                assessmentId,
                initialize,
                { pushState: false }
            );

        }

        else {

            await loadAssessmentView(
                offeringId,
                assessmentId,
                initialize,
                { pushState: false }
            );

        }

        return;

    }

    await loadAssessments();

}

async function loadAssessments() {

    state.loading = true;

    const container =
        document.querySelector("#course-content");

    container.innerHTML = "Loading...";

    try {
        state.data = await fetchAssessments();

        container.innerHTML =
            state.data.html;

        initializeFeatureBindings();
    } catch (error) {
        console.error(error);

        container.innerHTML =
            "<p>Unable to load Assessments.</p>";
    } finally {
        state.loading = false;
    }
}

/*
|--------------------------------------------------------------------------
| Bind Everything
|--------------------------------------------------------------------------
*/

function initializeFeatureBindings() {

    /*
    |--------------------------------------------------------------------------
    | Module Modals
    |--------------------------------------------------------------------------
    */

    const assessmentCreateModal =
        bindModal({

            modal:
                "#assessment-create-modal",

            closeButtons:
                "[data-close-assessment-modal]",

        });

    const assessmentEditorModal =
        bindModal({

            modal:
                "#assessment-editor-modal",

            closeButtons:
                "[data-close-assessment-modal]",

        });

    const assessmentDeleteModal =
        bindModal({

            modal:
                "#assessment-delete-modal",

            closeButtons:
                "[data-close-assessment-delete-modal]",

        });

    bindAssessmentCreateModal({

        create:
            assessmentCreateModal,

        refresh:
            initialize,

    });

    /*
    |--------------------------------------------------------------------------
    | Assessment List
    |--------------------------------------------------------------------------
    */

    bindAssessmentLinks(initialize);

}

/*
|--------------------------------------------------------------------------
| Assessment View
|--------------------------------------------------------------------------
*/

function bindAssessmentLinks(refresh) {

    const offeringId =
        window.coursesConfig.offeringId;

    document
        .querySelectorAll(
            "[data-assessment]:not([data-edit-assessment-button])"
        )
        .forEach((element) => {

            element.addEventListener(
                "click",
                () => {

                    const assessmentId =
                        element.dataset.assessment;

                    if (!assessmentId) {
                        return;
                    }

                    loadAssessmentView(
                        offeringId,
                        assessmentId,
                        refresh
                    );

                }
            );

        });

    bindEditAssessmentButtons(refresh);

}

function bindEditAssessmentButtons(refresh) {

    const offeringId =
        window.coursesConfig.offeringId;

    document
        .querySelectorAll("[data-edit-assessment-button]")
        .forEach((element) => {

            element.addEventListener(
                "click",
                (event) => {

                    event.stopPropagation();

                    const assessmentId =
                        element.dataset.assessment;

                    if (!assessmentId) {
                        return;
                    }

                    loadAssessmentEditor(
                        offeringId,
                        assessmentId,
                        refresh
                    );

                }
            );

        });

}

export async function loadAssessmentView(
    offeringId,
    assessmentId,
    refresh,
    { pushState = true } = {}
) {

    const container =
        document.querySelector("#course-content");

    container.innerHTML = "Loading...";

    try {

        const data =
            await fetchAssessmentView(
                offeringId,
                assessmentId
            );

        container.innerHTML =
            data.html;

        if (pushState) {

            history.pushState(
                { assessmentId },
                "",
                `${window.location.pathname}?assessment=${assessmentId}`
            );

        }

        document
            .querySelector("[data-assessments-breadcrumb]")
            ?.addEventListener(
                "click",
                () => {

                    history.pushState(
                        {},
                        "",
                        window.location.pathname
                    );

                    refresh();

                }
            );

        bindAssessmentLinks(refresh);

    } catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load assessment.</p>";

    }

}

export async function loadAssessmentEditor(
    offeringId,
    assessmentId,
    refresh,
    { pushState = true } = {}
) {

    const container =
        document.querySelector("#course-content");

    container.innerHTML = "Loading...";

    try {

        const data =
            await fetchAssessmentEditor(
                offeringId,
                assessmentId
            );

        container.innerHTML =
            data.html;

        if (pushState) {

            history.pushState(
                { assessmentId, editor: true },
                "",
                `${window.location.pathname}?assessment=${assessmentId}&editor=1`
            );

        }

        document
            .querySelector("[data-assessments-breadcrumb]")
            ?.addEventListener(
                "click",
                () => {

                    history.pushState(
                        {},
                        "",
                        window.location.pathname
                    );

                    refresh();

                }
            );

        bindAssessmentLinks(refresh);

        if (
            document.querySelector(
                "[data-instructions-editor]"
            )
        ) {

            initializeInstructionsTipTap();

            initializeInstructionsToolbar();

            bindInstructionsToggle(
                offeringId,
                assessmentId,
                () => loadAssessmentEditor(
                    offeringId,
                    assessmentId,
                    refresh,
                    { pushState: false }
                )
            );

        }

        if (
            document.querySelector(
                "#rubric-editor-container"
            )
        ) {

            initializeRubrics(
                offeringId,
                assessmentId,
                () => loadAssessmentEditor(
                    offeringId,
                    assessmentId,
                    refresh,
                    { pushState: false }
                )
            );

        }

        if (
            document.querySelector(
                "#submission-type-modal"
            )
        ) {

            bindSubmissionTypeModal(
                offeringId,
                assessmentId,
                () => loadAssessmentEditor(
                    offeringId,
                    assessmentId,
                    refresh,
                    { pushState: false }
                )
            );

        }

        const returnToList =
            () => {

                history.pushState(
                    {},
                    "",
                    window.location.pathname
                );

                return refresh();

            };

        initializeInspectorPanel(
            offeringId,
            assessmentId,
            returnToList
        );

        bindDeleteAssessmentModal(
            offeringId,
            assessmentId,
            returnToList
        );

    } catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load assessment editor.</p>";

    }

}

/*
|--------------------------------------------------------------------------
| Instructions Editor Toggle
|--------------------------------------------------------------------------
*/

function bindInstructionsToggle(
    offeringId,
    assessmentId,
    refreshEditor
) {

    const paragraphContainer =
        document.querySelector(
            "#instructions-paragraph-container"
        );

    const editorContainer =
        document.querySelector(
            "#instructions-editor-container"
        );

    if (!paragraphContainer || !editorContainer) {
        return;
    }

    function showEditor() {

        paragraphContainer.classList.add("hidden");

        editorContainer.classList.remove("hidden");

        editorContainer.classList.add("flex");

    }

    function showParagraph() {

        editorContainer.classList.add("hidden");

        editorContainer.classList.remove("flex");

        paragraphContainer.classList.remove("hidden");

    }

    document
        .querySelector("[data-edit-instructions]")
        ?.addEventListener(
            "click",
            () => {
                showEditor();
            }
        );

    document
        .querySelector("[data-cancel-instructions]")
        ?.addEventListener(
            "click",
            () => {

                resetInstructionsContent();

                showParagraph();

            }
        );

    document
        .querySelector("[data-save-instructions]")
        ?.addEventListener(
            "click",
            async () => {

                const content =
                    serializeInstructions();

                try {

                    await updateAssessmentInstructions(
                        offeringId,
                        assessmentId,
                        content
                    );

                    await refreshEditor();

                } catch (error) {

                    console.error(error);

                }

            }
        );

}

/*
|--------------------------------------------------------------------------
| Delete Assessment Modal
|--------------------------------------------------------------------------
*/

function bindDeleteAssessmentModal(
    offeringId,
    assessmentId,
    returnToList
) {

    const modal =
        bindModal({

            modal:
                "#assessment-delete-modal",

            closeButtons:
                "[data-close-assessment-delete-modal]",

        });

    if (!modal) {
        return;
    }

    document
        .querySelector("[data-delete-assessment]")
        ?.addEventListener(
            "click",
            () => {
                modal.open();
            }
        );

    document
        .querySelector("[data-confirm-delete-assessment]")
        ?.addEventListener(
            "click",
            async () => {

                try {

                    await deleteAssessment(
                        offeringId,
                        assessmentId
                    );

                    modal.close();

                    await returnToList();

                } catch (error) {

                    console.error(error);

                }

            }
        );

}

/*
|--------------------------------------------------------------------------
| Submission Type Modal
|--------------------------------------------------------------------------
*/

function bindSubmissionTypeModal(
    offeringId,
    assessmentId,
    refreshEditor
) {

    const modal =
        bindModal({

            modal:
                "#submission-type-modal",

            closeButtons:
                "[data-close-submission-type-modal]",

        });

    if (!modal) {
        return;
    }

    document
        .querySelector("[data-configure-submission-type]")
        ?.addEventListener(
            "click",
            () => {

                document
                    .querySelectorAll("[data-submission-type-option]")
                    .forEach((checkbox) => {

                        checkbox.checked =
                            checkbox.dataset.checked === "1";

                    });

                modal.open();

            }
        );

    document
        .querySelector("#submission-type-form")
        ?.addEventListener(
            "submit",
            async (event) => {

                event.preventDefault();

                const settings = {};

                document
                    .querySelectorAll("[data-submission-type-option]")
                    .forEach((checkbox) => {

                        settings[checkbox.name] =
                            checkbox.checked;

                    });

                try {

                    await updateSubmissionSettings(
                        offeringId,
                        assessmentId,
                        settings
                    );

                    modal.close();

                    await refreshEditor();

                } catch (error) {

                    console.error(error);

                }

            }
        );

}
