import {
    fetchAssessments,
    fetchAssessmentView,
    fetchAssessmentEditor,
    fetchQuizBuilder,
    fetchGradeWork,
    fetchQuizAttempt,
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

import {
    initializeQuestions,
} from "./questions/questions.js";

import {
    initializeGradeWork,
} from "./grading/grading.js";

import {
    initializeQuizTaking,
} from "./quiz-taking/quiz-taking.js";

export async function initialize() {

    const params =
        new URLSearchParams(window.location.search);

    const assessmentId =
        Number(
            params.get("assessment")
        );

    const isEditor =
        params.has("editor");

    const isBuilder =
        params.has("builder");

    const isGrading =
        params.has("grade");

    const isView =
        params.has("view");

    const isTaking =
        params.has("take");

    const studentId =
        params.get("student");

    if (assessmentId) {

        const offeringId =
            window.coursesConfig.offeringId;

        if (isBuilder) {

            await loadQuizBuilder(
                offeringId,
                assessmentId,
                initialize,
                { pushState: false }
            );

        }

        else if (isTaking) {

            await loadQuizAttempt(
                offeringId,
                assessmentId,
                initialize,
                { pushState: false }
            );

        }

        else if (isEditor) {

            await loadAssessmentEditor(
                offeringId,
                assessmentId,
                initialize,
                { pushState: false }
            );

        }

        else if (isGrading) {

            await loadGradeWork(
                offeringId,
                assessmentId,
                studentId,
                initialize,
                { pushState: false }
            );

        }

        else if (isView) {

            await loadAssessmentView(
                offeringId,
                assessmentId,
                initialize,
                { pushState: false }
            );

        }

        else if (window.coursesConfig.role === "instructor") {

            await loadGradeWork(
                offeringId,
                assessmentId,
                null,
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
            "[data-assessment]:not([data-edit-assessment-button]):not([data-view-assessment-button]):not(.assessment-nav-link)"
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

                    if (window.coursesConfig.role === "instructor") {

                        loadGradeWork(
                            offeringId,
                            assessmentId,
                            null,
                            refresh
                        );

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

    bindViewAssessmentButtons(refresh);

    bindAssessmentNavLinks(refresh);

}

function bindAssessmentNavLinks(refresh) {

    const offeringId =
        window.coursesConfig.offeringId;

    document
        .querySelectorAll(".assessment-nav-link[data-assessment]")
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

}

function bindViewAssessmentButtons(refresh) {

    const offeringId =
        window.coursesConfig.offeringId;

    document
        .querySelectorAll("[data-view-assessment-button]")
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

                    loadAssessmentView(
                        offeringId,
                        assessmentId,
                        refresh
                    );

                }
            );

        });

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

        document
            .querySelector("[data-take-quiz]")
            ?.addEventListener(
                "click",
                () => {

                    loadQuizAttempt(
                        offeringId,
                        assessmentId,
                        refresh,
                        { pushState: true }
                    );

                }
            );

    } catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load assessment.</p>";

    }

}

export async function loadQuizAttempt(
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
            await fetchQuizAttempt(
                offeringId,
                assessmentId
            );

        container.innerHTML =
            data.html;

        if (pushState) {

            history.pushState(
                { assessmentId, take: true },
                "",
                `${window.location.pathname}?assessment=${assessmentId}&take=1`
            );

        }

        const backToView =
            () => loadAssessmentView(
                offeringId,
                assessmentId,
                refresh,
                { pushState: true }
            );

        document
            .querySelector("[data-back-to-assessment-view]")
            ?.addEventListener(
                "click",
                backToView
            );

        initializeQuizTaking(
            offeringId,
            assessmentId,
            backToView
        );

    } catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load the quiz.</p>";

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

        document
            .querySelector("[data-enter-quiz-builder]")
            ?.addEventListener(
                "click",
                () => {

                    loadQuizBuilder(
                        offeringId,
                        assessmentId,
                        refresh,
                        { pushState: true }
                    );

                }
            );

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

export async function loadQuizBuilder(
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
            await fetchQuizBuilder(
                offeringId,
                assessmentId
            );

        container.innerHTML =
            data.html;

        if (pushState) {

            history.pushState(
                { assessmentId, builder: true },
                "",
                `${window.location.pathname}?assessment=${assessmentId}&builder=1`
            );

        }

        document
            .querySelector("[data-back-to-assessment-editor]")
            ?.addEventListener(
                "click",
                () => {

                    loadAssessmentEditor(
                        offeringId,
                        assessmentId,
                        refresh,
                        { pushState: true }
                    );

                }
            );

        initializeQuestions(
            offeringId,
            assessmentId,
            () => loadQuizBuilder(
                offeringId,
                assessmentId,
                refresh,
                { pushState: false }
            )
        );

    } catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load quiz builder.</p>";

    }

}

export async function loadGradeWork(
    offeringId,
    assessmentId,
    studentId,
    refresh,
    { pushState = true } = {}
) {

    const container =
        document.querySelector("#course-content");

    container.innerHTML = "Loading...";

    try {

        const data =
            await fetchGradeWork(
                offeringId,
                assessmentId,
                studentId
            );

        container.innerHTML =
            data.html;

        if (pushState) {

            const query =
                studentId
                    ? `?assessment=${assessmentId}&grade=1&student=${studentId}`
                    : `?assessment=${assessmentId}&grade=1`;

            history.pushState(
                { assessmentId, grade: true, studentId },
                "",
                `${window.location.pathname}${query}`
            );

        }

        document
            .querySelector("[data-back-to-assessments]")
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

        initializeGradeWork(
            offeringId,
            assessmentId,
            refresh,
            (nextStudentId) => loadGradeWork(
                offeringId,
                assessmentId,
                nextStudentId,
                refresh,
                { pushState: true }
            )
        );

    } catch (error) {

        console.error(error);

        container.innerHTML =
            "<p>Unable to load grade work.</p>";

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
