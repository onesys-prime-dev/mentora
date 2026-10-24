import { showLoadError } from "../../../../shared/spa/error-state.js";
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

import { loadGradebookRoster } from "../grades/roster/roster.js";

import { bindFeedbackComposer } from "./feedback/feedback.js";

import { highlightRow } from "../../../../shared/spa/highlight-row.js";

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
    initializeAiGeneration,
} from "./ai-generation/ai-generation.js";

import {
    initializeImportExport,
} from "./import-export/import-export.js";

import {
    initializeGradeWork,
} from "./grading/grading.js";

import {
    initializeQuizTaking,
} from "./quiz-taking/quiz-taking.js";

import {
    initializeAssignmentSubmission,
} from "../../../../shared/assignment/submission.js";

import {
    swapContent,
} from "../../../../shared/spa/swap.js";

import {
    renderAssessmentsSkeleton,
    renderAssessmentDetailSkeleton,
    renderGradeWorkSkeleton,
    renderAssessmentListSkeleton,
    rememberAssessmentCount,
    rememberSidebarSections,
} from "./skeleton.js";

export async function initialize() {

    const params =
        new URLSearchParams(window.location.search);

    const assessmentId =
        Number(
            params.get("assessment")
        );

    // Editor, builder and grading are instructor screens; a student who
    // lands on one of those links gets the assessment view instead.
    const isInstructor =
        window.coursesConfig.role === "instructor";

    // The editor and builder change course content, which an institution can take away (courses.manage).
    const canEditContent =
        isInstructor && window.coursesConfig.can?.content !== false;

    const isEditor =
        canEditContent && params.has("editor");

    const isBuilder =
        canEditContent && params.has("builder");

    const isGrading =
        isInstructor && params.has("grade");

    const isView =
        params.has("view");

    const isTaking =
        params.has("take");

    const studentId =
        params.get("student");

    const attemptId =
        params.get("attempt");

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
                { pushState: false, attemptId }
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

    container.innerHTML = renderAssessmentsSkeleton();

    try {

        state.data = await fetchAssessments();

        container.innerHTML =
            state.data.html;

        initializeFeatureBindings();

    }

    catch (error) {

        console.error(error);

        showLoadError(container, "Assessments", initialize);

    }

    finally {

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

        onCreated: (assessmentId) =>
            loadAssessmentEditor(
                window.coursesConfig.offeringId,
                assessmentId,
                initialize
            ),

    });

    /*
    |--------------------------------------------------------------------------
    | Assessment List
    |--------------------------------------------------------------------------
    */

    bindAssessmentLinks(initialize);

    bindListInteractions(initialize);

}

/*
|--------------------------------------------------------------------------
| List page: keyboard access, sidebar shortcuts, filter + search
|--------------------------------------------------------------------------
*/

function bindListInteractions(refresh) {

    const offeringId =
        window.coursesConfig.offeringId;

    // Cards and sidebar rows are role="link" elements: Enter/Space opens them.
    document
        .querySelectorAll('[data-assessments-overview] [role="link"][tabindex]')
        .forEach((row) => {

            row.addEventListener("keydown", (event) => {

                if (event.target !== row || (event.key !== "Enter" && event.key !== " ")) {
                    return;
                }

                event.preventDefault();

                row.click();

            });

        });

    // Student · Up Next: an in-progress quiz resumes straight away.
    document
        .querySelectorAll("[data-take-assessment]")
        .forEach((row) => {

            row.addEventListener("click", () => {

                loadQuizAttempt(
                    offeringId,
                    Number(row.dataset.takeAssessment),
                    refresh
                );

            });

        });

    // Instructor · Ready for Grading: open Grade Work on the student who has
    // been waiting longest.
    document
        .querySelectorAll("[data-grade-assessment]")
        .forEach((row) => {

            row.addEventListener("click", () => {

                loadGradeWork(
                    offeringId,
                    Number(row.dataset.gradeAssessment),
                    row.dataset.gradeStudent || null,
                    refresh
                );

            });

        });

    initializeListFilters();

    rememberSidebarSections(document);

}

function initializeListFilters() {

    const select =
        document.querySelector("[data-assessments-filter]");

    const search =
        document.querySelector("[data-assessments-search]");

    const noMatch =
        document.querySelector("[data-assessments-no-match]");

    const cards = [
        ...document.querySelectorAll("[data-assessments-list] [data-assessment-card]"),
    ];

    if (!select || !search) {
        return;
    }

    // The next page-load skeleton matches this list's size.
    rememberAssessmentCount(cards.length);

    const matches = (card) => {

        const tag = select.value;

        const term = search.value.trim().toLowerCase();

        const tags = (card.dataset.filterTags ?? "").split(" ");

        return (!tag || tags.includes(tag))
            && (!term || (card.dataset.title ?? "").includes(term));

    };

    const apply = () => {

        let shown = 0;

        cards.forEach((card) => {

            const visible = matches(card);

            card.style.display = visible ? "" : "none";

            if (visible) {
                shown++;
            }

        });

        const empty = cards.length > 0 && shown === 0;

        noMatch?.classList.toggle("hidden", !empty);

        noMatch?.classList.toggle("flex", empty);

    };

    // Filtering is client-side and instant, so the skeleton is a brief,
    // list-only flash: the rows are swapped for placeholders, then the
    // filtered rows come back. Nothing outside the list changes.
    const list = document.querySelector("[data-assessments-list]");

    let skeletonTimer = null;

    const applyWithSkeleton = () => {

        if (!list) {
            apply();
            return;
        }

        clearTimeout(skeletonTimer);

        list.parentElement
            ?.querySelector("[data-assessments-list-skeleton]")
            ?.remove();

        list.classList.add("hidden");

        // As many placeholder rows as will actually appear, kept between 1 and 3.
        list.insertAdjacentHTML(
            "beforebegin",
            renderAssessmentListSkeleton(cards.filter(matches).length)
        );

        skeletonTimer = setTimeout(() => {

            list.parentElement
                ?.querySelector("[data-assessments-list-skeleton]")
                ?.remove();

            apply();

            list.classList.remove("hidden");

        }, 350);

    };

    let searchTimer = null;

    select.addEventListener("change", applyWithSkeleton);

    search.addEventListener("input", () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applyWithSkeleton, 250);
    });

    // Instructor · Drafts → "View all" narrows the list to drafts.
    document
        .querySelectorAll("[data-assessments-filter-set]")
        .forEach((button) => {

            button.addEventListener("click", () => {

                select.value = button.dataset.assessmentsFilterSet;

                search.value = "";

                applyWithSkeleton();

                // The list itself is hidden while its skeleton shows, so scroll to its container.
                document
                    .querySelector("[data-assessments-list]")
                    ?.parentElement
                    ?.scrollIntoView({ behavior: "smooth", block: "start" });

            });

        });

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
    { pushState = true, mode = "navigate" } = {}
) {

    const container =
        document.querySelector("#course-content");

    const swapped = await swapContent(
        container,
        async () => (await fetchAssessmentView(offeringId, assessmentId)).html,
        {
            mode,
            skeleton: mode === "navigate" ? renderAssessmentDetailSkeleton() : null,
            forceSkeleton: true,
        }
    );

    if (!swapped) {
        return;
    }

    try {

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

        initializeAssignmentSubmission({

            onSubmitted: () =>
                loadAssessmentView(
                    offeringId,
                    assessmentId,
                    refresh,
                    { pushState: false, mode: "refresh" }
                ),

        });

        bindFeedbackComposer(offeringId, () => loadAssessmentView(
            offeringId,
            assessmentId,
            refresh,
            { pushState: false, mode: "refresh" }
        ));

    } catch (error) {

        console.error(error);

    }

}

export async function loadQuizAttempt(
    offeringId,
    assessmentId,
    refresh,
    { pushState = true, mode = "navigate" } = {}
) {

    const container =
        document.querySelector("#course-content");

    const swapped = await swapContent(
        container,
        async () => (await fetchQuizAttempt(offeringId, assessmentId)).html,
        {
            mode,
            skeleton: mode === "navigate" ? renderAssessmentDetailSkeleton() : null,
            forceSkeleton: true,
        }
    );

    if (!swapped) {
        return;
    }

    try {

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

    }

}

export async function loadAssessmentEditor(
    offeringId,
    assessmentId,
    refresh,
    { pushState = true, mode = "navigate" } = {}
) {

    const container =
        document.querySelector("#course-content");

    const swapped = await swapContent(
        container,
        async () => (await fetchAssessmentEditor(offeringId, assessmentId)).html,
        {
            mode,
            skeleton: mode === "navigate" ? renderAssessmentDetailSkeleton() : null,
            forceSkeleton: true,
        }
    );

    if (!swapped) {
        return;
    }

    try {

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
                    { pushState: false, mode: "refresh" }
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
                    { pushState: false, mode: "refresh" }
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
                    { pushState: false, mode: "refresh" }
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

    }

}

export async function loadQuizBuilder(
    offeringId,
    assessmentId,
    refresh,
    { pushState = true, mode = "navigate" } = {}
) {

    const container =
        document.querySelector("#course-content");

    const swapped = await swapContent(
        container,
        async () => (await fetchQuizBuilder(offeringId, assessmentId)).html,
        {
            mode,
            skeleton: mode === "navigate" ? renderAssessmentDetailSkeleton() : null,
            forceSkeleton: true,
        }
    );

    if (!swapped) {
        return;
    }

    try {

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

        const reloadBuilder =
            () => loadQuizBuilder(
                offeringId,
                assessmentId,
                refresh,
                { pushState: false, mode: "refresh" }
            );

        initializeQuestions(
            offeringId,
            assessmentId,
            reloadBuilder
        );

        initializeAiGeneration(
            offeringId,
            assessmentId
        );

        initializeImportExport(
            offeringId,
            assessmentId,
            reloadBuilder
        );

    } catch (error) {

        console.error(error);

    }

}

export async function loadGradeWork(
    offeringId,
    assessmentId,
    studentId,
    refresh,
    { pushState = true, mode = "navigate", attemptId = null } = {}
) {

    const container =
        document.querySelector("#course-content");

    const swapped = await swapContent(
        container,
        async () => (await fetchGradeWork(offeringId, assessmentId, studentId, attemptId)).html,
        {
            mode,
            skeleton: mode === "navigate" ? renderGradeWorkSkeleton() : null,
            forceSkeleton: true,
        }
    );

    if (!swapped) {
        return;
    }

    try {

        if (pushState) {

            const query =
                `?assessment=${assessmentId}&grade=1`
                + (studentId ? `&student=${studentId}` : "")
                + (studentId && attemptId ? `&attempt=${attemptId}` : "");

            history.pushState(
                { assessmentId, grade: true, studentId, attemptId },
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

        document
            .querySelector("[data-view-student-grade]")
            ?.addEventListener(
                "click",
                (event) => viewStudentGrade(event.currentTarget.dataset.viewStudentGrade, refresh)
            );

        initializeGradeWork(
            offeringId,
            assessmentId,
            refresh,
            // Switching students (and reloading after a grade) re-renders
            // the same screen: keep the scroll position, no dimming.
            (nextStudentId, nextAttemptId = null) => loadGradeWork(
                offeringId,
                assessmentId,
                nextStudentId,
                refresh,
                { pushState: true, mode: "refresh", attemptId: nextAttemptId }
            )
        );

    } catch (error) {

        console.error(error);

    }

}

/**
 * Grade Work → "View full grade": opens the gradebook roster and briefly
 * highlights the student's row, so an instructor closing out a grade can
 * jump straight to how it affected that student's final grade.
 */
async function viewStudentGrade(studentId, refresh)
{
    await loadGradebookRoster(refresh);

    highlightRow(`[data-roster-row][data-student-id="${studentId}"]`);
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
