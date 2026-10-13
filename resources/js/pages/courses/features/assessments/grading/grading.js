/*
|--------------------------------------------------------------------------
| Grade Work
|--------------------------------------------------------------------------
*/

import {
    startGrading,
    submitGrade,
    reopenGrading,
} from "../api.js";

import { bindModal } from "../modals/index.js";

import { bindFeedbackComposer } from "../feedback/feedback.js";

import { bindQuestionScoring } from "./question-scoring.js";

let state = {
    offeringId: null,
    assessmentId: null,
    refresh: null,
    loadStudent: null,
};

export function initializeGradeWork(offeringId, assessmentId, refresh, loadStudent) {

    state = { offeringId, assessmentId, refresh, loadStudent };

    bindStudentList();
    bindStudentFilterAndSort();
    bindExpandToggle();
    bindGradingModeToggle();
    bindLiveInputs();
    bindSubmit();
    bindAttemptTabs();
    bindRegrade();

    bindFeedbackComposer(offeringId, () => {
        const studentId = selectedStudentId();
        return studentId ? loadStudent(studentId, currentAttemptId()) : null;
    });

    bindQuestionScoring(offeringId, () => {
        const studentId = selectedStudentId();
        return studentId ? loadStudent(studentId, currentAttemptId()) : null;
    });

    // A graded (locked) panel shows the recorded score as rendered.
    if (panelState() !== "locked" && hasAnyExistingScore()) {
        updateScorePreview();
    }

}

function hasAnyExistingScore() {

    const defaultValue =
        document.querySelector("[data-grade-work-default-grading-points]")?.value;

    if (defaultValue !== undefined && defaultValue !== "") {
        return true;
    }

    return Array.from(
        document.querySelectorAll("[data-grade-work-rubric-criteria-points]")
    ).some((input) => Number(input.value) > 0);

}

/*
|--------------------------------------------------------------------------
| Student List
|--------------------------------------------------------------------------
*/

function bindStudentList() {

    document
        .querySelectorAll("[data-grade-work-student]")
        .forEach((row) => {

            row.addEventListener("click", () => {

                const studentId =
                    row.dataset.gradeWorkStudentId;

                if (!studentId) {
                    return;
                }

                loadStudent(studentId);

            });

        });

}

async function loadStudent(studentId, attemptId = null) {

    await state.loadStudent(studentId, attemptId);

}

function panelState() {

    return document
        .querySelector("[data-expandable-grade-work-actions-panel]")
        ?.dataset.panelState ?? "";

}

function selectedStudentId() {

    return document
        .querySelector("[data-grade-work-student-list]")
        ?.dataset.selectedStudentId;

}

function currentAttemptId() {

    return document
        .querySelector("[data-expandable-grade-work-actions-panel]")
        ?.dataset.submissionId || null;

}

/*
|--------------------------------------------------------------------------
| Attempt tabs (students with more than one attempt)
|--------------------------------------------------------------------------
*/

function bindAttemptTabs() {

    document
        .querySelectorAll("[data-grade-work-attempt]")
        .forEach((tab) => {

            tab.addEventListener("click", () => {

                const studentId = selectedStudentId();

                if (studentId) {
                    loadStudent(studentId, tab.dataset.gradeWorkAttempt);
                }

            });

        });

}

/*
|--------------------------------------------------------------------------
| Change a submitted grade (Graded → Grading, after confirming)
|--------------------------------------------------------------------------
*/

function bindRegrade() {

    const openButton =
        document.querySelector("[data-regrade-open]");

    const modal =
        bindModal({
            modal: "#regrade-confirm-modal",
            closeButtons: "[data-close-regrade-modal]",
        });

    if (!openButton || !modal) {
        return;
    }

    const confirmButton =
        modal.element.querySelector("[data-confirm-regrade]");

    const errorLabel =
        modal.element.querySelector("[data-regrade-error]");

    const showError = (message) => {

        errorLabel.textContent = message ?? "";

        errorLabel.classList.toggle("hidden", !message);

    };

    openButton.addEventListener("click", () => {

        showError("");

        modal.open();

    });

    confirmButton?.addEventListener("click", async () => {

        const submissionId = currentAttemptId();

        if (!submissionId) {
            return;
        }

        confirmButton.disabled = true;

        try {

            const result = await reopenGrading(state.offeringId, submissionId);

            if (!result.success) {

                showError(result.message);

                return;

            }

            modal.close();

            // Same student and attempt, now in Grading: open and editable.
            await loadStudent(selectedStudentId(), submissionId);

        }

        catch (error) {

            console.error(error);

            showError("Couldn't reopen this grade. Please try again.");

        }

        finally {

            confirmButton.disabled = false;

        }

    });

}

function bindStudentFilterAndSort() {

    const filter =
        document.querySelector("[data-grade-work-status-filter]");

    const list =
        document.querySelector("[data-grade-work-student-list]");

    filter?.addEventListener("change", () => {

        const value = filter.value;

        list
            ?.querySelectorAll("[data-grade-work-student]")
            .forEach((row) => {

                row.hidden =
                    Boolean(value) && row.dataset.studentStatus !== value;

            });

    });

    let sortAscending = true;

    document
        .querySelector("[data-sort-students]")
        ?.addEventListener("click", () => {

            if (!list) {
                return;
            }

            const rows =
                Array.from(
                    list.querySelectorAll("[data-grade-work-student]")
                );

            rows.sort((a, b) => {

                const result =
                    a.dataset.studentName.localeCompare(b.dataset.studentName);

                return sortAscending ? result : -result;

            });

            rows.forEach((row) => list.appendChild(row));

            sortAscending = !sortAscending;

        });

}

/*
|--------------------------------------------------------------------------
| Expand / Collapse
|--------------------------------------------------------------------------
*/

function bindExpandToggle() {

    const button =
        document.querySelector("[data-expand-grade-work-actions]");

    const panel =
        document.querySelector("[data-expandable-grade-work-actions-panel]");

    const expandedContainer =
        document.querySelector("[data-expanded-grade-work-actions]");

    const icon =
        document.querySelector("[data-expand-grade-work-icon]");

    if (!button || !panel || !expandedContainer) {
        return;
    }

    button.addEventListener("click", async () => {

        const isExpanded =
            button.dataset.expanded === "1";

        if (isExpanded) {

            expandedContainer.classList.add("hidden");
            expandedContainer.classList.remove("flex");

            button.dataset.expanded = "0";

            icon?.classList.remove("rotate-180");

            return;

        }

        if (
            ["Submitted", "Partially Graded"].includes(panel.dataset.submissionStatus)
            && panel.dataset.submissionId
        ) {

            try {

                await startGrading(
                    state.offeringId,
                    panel.dataset.submissionId
                );

                // Whether a quiz's questions can be edited is decided when this view is
                // drawn, and it was drawn before grading started. Draw it again for this
                // student: it comes back in "Grading" with the panel open and the
                // questions editable. (Relabelling the panel alone left a quiz's essay
                // score box disabled until the student was selected again.)
                const studentId = selectedStudentId();

                if (studentId) {

                    await loadStudent(studentId, panel.dataset.submissionId);

                    return;

                }

                panel.dataset.submissionStatus = "Grading";

                setStatusLabel("Grading In Progress", "bg-[#FFF1D6] text-[#946200]");

            } catch (error) {

                console.error(error);

            }

        }

        expandedContainer.classList.remove("hidden");
        expandedContainer.classList.add("flex");

        button.dataset.expanded = "1";

        icon?.classList.add("rotate-180");

    });

}

function setStatusLabel(label, className) {

    const statusLabel =
        document.querySelector("[data-grade-work-status-label]");

    if (!statusLabel) {
        return;
    }

    statusLabel.textContent = label;

    statusLabel.className =
        `rounded-full px-3 py-1 text-xs font-bold ${className}`;

}

/*
|--------------------------------------------------------------------------
| Default / Rubric Toggle
|--------------------------------------------------------------------------
*/

function bindGradingModeToggle() {

    const defaultBtn =
        document.querySelector("[data-default-grade-work-toggle]");

    const rubricBtn =
        document.querySelector("[data-with-rubric-grade-work-toggle]");

    const defaultPanel =
        document.querySelector("[data-default-grading-panel]");

    const rubricPanel =
        document.querySelector("[data-rubric-grading-panel]");

    if (!defaultBtn || !rubricPanel || !defaultPanel) {
        return;
    }

    function activate(mode) {

        const isDefault = mode === "Default";

        defaultPanel.classList.toggle("hidden", !isDefault);
        defaultPanel.classList.toggle("flex", isDefault);

        rubricPanel.classList.toggle("hidden", isDefault);
        rubricPanel.classList.toggle("flex", !isDefault);

        defaultBtn.classList.toggle("bg-white", isDefault);
        defaultBtn.classList.toggle("shadow", isDefault);
        defaultBtn.classList.toggle("text-brand", isDefault);
        defaultBtn.classList.toggle("text-gray-500", !isDefault);

        rubricBtn?.classList.toggle("bg-white", !isDefault);
        rubricBtn?.classList.toggle("shadow", !isDefault);
        rubricBtn?.classList.toggle("text-brand", !isDefault);
        rubricBtn?.classList.toggle("text-gray-500", isDefault);

        defaultPanel.dataset.active = isDefault ? "1" : "0";
        rubricPanel.dataset.active = isDefault ? "0" : "1";

        updateScorePreview();

    }

    defaultBtn.addEventListener("click", () => activate("Default"));

    rubricBtn?.addEventListener("click", () => {

        if (rubricBtn.disabled) {
            return;
        }

        activate("Rubric");

    });

    const hasRubric =
        document.querySelector("[data-expandable-grade-work-actions-panel]")
            ?.dataset.hasRubric === "1";

    activate(hasRubric && rubricHasExistingScores() ? "Rubric" : "Default");

}

function rubricHasExistingScores() {

    return Array.from(
        document.querySelectorAll("[data-grade-work-rubric-criteria-points]")
    ).some((input) => Number(input.value) > 0);

}

/*
|--------------------------------------------------------------------------
| Live Inputs (points, sliders, validation, score preview)
|--------------------------------------------------------------------------
*/

function bindLiveInputs() {

    const totalPoints =
        Number(
            document.querySelector("[data-expandable-grade-work-actions-panel]")
                ?.dataset.totalPoints || 0
        );

    const defaultInput =
        document.querySelector("[data-grade-work-default-grading-points]");

    const defaultSlider =
        document.querySelector("[data-grade-work-default-grading-slider]");

    defaultInput?.addEventListener("input", () => {

        const clamped =
            clamp(defaultInput.value, 0, totalPoints);

        defaultInput.value = clamped;

        if (defaultSlider) {
            defaultSlider.value = clamped;
        }

        updateScorePreview();

    });

    defaultSlider?.addEventListener("input", () => {

        if (defaultInput) {
            defaultInput.value = defaultSlider.value;
        }

        updateScorePreview();

    });

    document
        .querySelectorAll("[data-rubric-criterion]")
        .forEach((row) => {

            const maxPoints =
                Number(row.dataset.maxPoints || 0);

            const input =
                row.querySelector("[data-grade-work-rubric-criteria-points]");

            const slider =
                row.querySelector("[data-grade-work-rubric-criteria-slider]");

            input?.addEventListener("input", () => {

                const clamped =
                    clamp(input.value, 0, maxPoints);

                input.value = clamped;

                if (slider) {
                    slider.value = clamped;
                }

                updateScorePreview();

            });

            slider?.addEventListener("input", () => {

                if (input) {
                    input.value = slider.value;
                }

                updateScorePreview();

            });

        });

}

function clamp(value, min, max) {

    const number =
        Number(value);

    if (Number.isNaN(number)) {
        return min;
    }

    return Math.min(Math.max(number, min), max);

}

function isRubricModeActive() {

    return document
        .querySelector("[data-rubric-grading-panel]")
        ?.dataset.active === "1";

}

function updateScorePreview() {

    const preview =
        document.querySelector("[data-grade-work-score-preview]");

    if (!preview) {
        return;
    }

    const totalPoints =
        Number(
            document.querySelector("[data-expandable-grade-work-actions-panel]")
                ?.dataset.totalPoints || 0
        );

    let score = 0;

    if (isRubricModeActive()) {

        document
            .querySelectorAll("[data-grade-work-rubric-criteria-points]")
            .forEach((input) => {
                score += Number(input.value || 0);
            });

    } else {

        score =
            Number(
                document.querySelector("[data-grade-work-default-grading-points]")?.value || 0
            );

    }

    preview.textContent = `${score}/${totalPoints}`;

}

/*
|--------------------------------------------------------------------------
| Submit Grade
|--------------------------------------------------------------------------
*/

function bindSubmit() {

    document
        .querySelector("[data-grade-work-submit-grade]")
        ?.addEventListener("click", async () => {

            const panel =
                document.querySelector("[data-expandable-grade-work-actions-panel]");

            const submissionId =
                panel?.dataset.submissionId;

            if (!submissionId) {
                return;
            }

            const feedback =
                document.querySelector("[data-grade-work-feedback]")?.value ?? "";

            const rubricMode =
                isRubricModeActive();

            const payload = {
                grading_mode: rubricMode ? "Rubric" : "Default",
                feedback,
            };

            if (rubricMode) {

                const rubricScores = {};

                document
                    .querySelectorAll("[data-rubric-criterion]")
                    .forEach((row) => {

                        const criterionId =
                            row.dataset.criterionId;

                        const input =
                            row.querySelector("[data-grade-work-rubric-criteria-points]");

                        rubricScores[criterionId] =
                            Number(input?.value || 0);

                    });

                payload.rubric_scores = rubricScores;

            } else {

                payload.score =
                    Number(
                        document.querySelector("[data-grade-work-default-grading-points]")?.value || 0
                    );

            }

            try {

                await submitGrade(
                    state.offeringId,
                    submissionId,
                    payload
                );

                // Stay on the attempt just graded; it now shows as locked.
                const studentId = selectedStudentId();

                if (studentId) {
                    await loadStudent(studentId, submissionId);
                }

            } catch (error) {

                console.error(error);

            }

        });

}
