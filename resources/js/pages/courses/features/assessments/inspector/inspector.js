/*
|--------------------------------------------------------------------------
| Assessment Editor Inspector Panel
|--------------------------------------------------------------------------
*/

import { fetchAssignees, saveAssignees, setQuizPassword, updateAssessment } from "../api.js";
import { showToast } from "../../../../../shared/toast.js";
import { bindQuizSecurity } from "./quiz-security.js";
import { bindSpecificStudents } from "./specific-students.js";

export function initializeInspectorPanel(
    offeringId,
    assessmentId,
    refresh
) {

    const panel =
        document.querySelector("#editor-inspector-panel");

    if (!panel) {
        return;
    }

    bindPassingScoreCalculation();

    bindAttemptLimitToggle();

    // The password section of a quiz (nothing happens on an assignment, which has none).
    const security = bindQuizSecurity({
        savePassword: (password) => setQuizPassword(offeringId, assessmentId, password),
        notify: (message, tone) => showToast(message, { tone }),
    });

    // "Specific students" is on every assessment.
    const students = bindSpecificStudents({
        fetchStudents: () => fetchAssignees(offeringId, assessmentId),
        saveStudents: (ids) => saveAssignees(offeringId, assessmentId, ids),
        notify: (message, tone) => showToast(message, { tone }),
    });

    // What stops a save before anything is sent: a password on and not created, or specific students on with
    // nobody chosen. The first one found is said.
    const checks = {
        passwordProblem: () => security?.passwordProblem() ?? students.problem(),
    };

    bindSaveActions(
        offeringId,
        assessmentId,
        refresh,
        checks
    );

}

/*
|--------------------------------------------------------------------------
| Passing Score (computed, read-only)
|--------------------------------------------------------------------------
*/

function bindPassingScoreCalculation() {

    const totalPointsField =
        document.querySelector("[data-inspector-total-points]");

    const passingPercentageField =
        document.querySelector("[data-inspector-passing-percentage]");

    if (!totalPointsField || !passingPercentageField) {
        return;
    }

    const recalculate =
        () => recalculatePassingScore();

    totalPointsField.addEventListener("input", recalculate);

    passingPercentageField.addEventListener("input", recalculate);

}

function recalculatePassingScore() {

    const scoreField =
        document.querySelector("[data-inspector-passing-score]");

    if (!scoreField) {
        return;
    }

    const totalPoints =
        Number(
            document.querySelector("[data-inspector-total-points]")?.value || 0
        );

    const passingPercentageValue =
        document.querySelector("[data-inspector-passing-percentage]")?.value;

    if (passingPercentageValue === "" || passingPercentageValue === undefined) {

        scoreField.value = "";

        return;

    }

    const score =
        (totalPoints * Number(passingPercentageValue)) / 100;

    scoreField.value =
        Number.isInteger(score)
            ? String(score)
            : score.toFixed(2);

}

/*
|--------------------------------------------------------------------------
| Allow Multiple Attempts / Attempt Limit
|--------------------------------------------------------------------------
*/

function bindAttemptLimitToggle() {

    const toggle =
        document.querySelector("[data-inspector-allow-multiple-attempts]");

    const limitField =
        document.querySelector("[data-inspector-attempt-limit]");

    if (!toggle || !limitField) {
        return;
    }

    toggle.addEventListener(
        "change",
        () => {

            if (toggle.checked) {

                limitField.disabled = false;

            }

            else {

                limitField.disabled = true;

                limitField.value = "1";

            }

        }
    );

}

/*
|--------------------------------------------------------------------------
| Save as Draft / Publish
|--------------------------------------------------------------------------
*/

function bindSaveActions(
    offeringId,
    assessmentId,
    refresh,
    security
) {

    document
        .querySelector("[data-save-draft]")
        ?.addEventListener(
            "click",
            () => saveAssessment(
                offeringId,
                assessmentId,
                "Draft",
                refresh,
                security
            )
        );

    document
        .querySelector("[data-publish]")
        ?.addEventListener(
            "click",
            () => saveAssessment(
                offeringId,
                assessmentId,
                "Published",
                refresh,
                security
            )
        );

}

async function saveAssessment(
    offeringId,
    assessmentId,
    status,
    refresh,
    security
) {

    // A password switched on and not yet created is said before anything is sent.
    const problem =
        security?.passwordProblem();

    if (problem) {

        showToast(problem, { tone: "danger" });

        return;

    }

    const payload =
        collectInspectorPayload();

    payload.status = status;

    try {

        const result =
            await updateAssessment(
                offeringId,
                assessmentId,
                payload
            );

        // The server answers 200 with {success: false, message} for settings it refuses (dates that
        // do not add up, a password required before it exists...): the form stays as it is.
        if (result && result.success === false) {

            showToast(result.message || "The settings could not be saved.", { tone: "danger" });

            return;

        }

        await refresh();

    } catch (error) {

        console.error(error);

        showToast("The settings could not be saved. Check your connection and try again.", { tone: "danger" });

    }

}

function collectInspectorPayload() {

    const payload = {};

    document
        .querySelectorAll("[data-inspector-field]")
        .forEach((field) => {

            const key =
                field.dataset.inspectorField;

            if (field.type === "checkbox") {

                payload[key] = field.checked;

            }

            else if (field.type === "number") {

                payload[key] =
                    field.value === "" ? null : Number(field.value);

            }

            else if (field.type === "datetime-local") {

                payload[key] =
                    field.value ? `${field.value.replace("T", " ")}:00` : null;

            }

            else {

                payload[key] = field.value;

            }

        });

    return payload;

}
