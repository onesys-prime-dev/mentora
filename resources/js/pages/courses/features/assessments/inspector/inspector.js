/*
|--------------------------------------------------------------------------
| Assessment Editor Inspector Panel
|--------------------------------------------------------------------------
*/

import { updateAssessment } from "../api.js";

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

    bindSaveActions(
        offeringId,
        assessmentId,
        refresh
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
    refresh
) {

    document
        .querySelector("[data-save-draft]")
        ?.addEventListener(
            "click",
            () => saveAssessment(
                offeringId,
                assessmentId,
                "Draft",
                refresh
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
                refresh
            )
        );

}

async function saveAssessment(
    offeringId,
    assessmentId,
    status,
    refresh
) {

    const payload =
        collectInspectorPayload();

    payload.status = status;

    try {

        await updateAssessment(
            offeringId,
            assessmentId,
            payload
        );

        await refresh();

    } catch (error) {

        console.error(error);

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
