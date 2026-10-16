/*
|--------------------------------------------------------------------------
| Gradebook grid (instructor): manual item scores + final-grade override
|--------------------------------------------------------------------------
|
| Rendered by GradingSchemeController::roster into #course-content.
| Manual scores save on change (debounced) and update just that student's
| final-grade cell in place — no full re-render, so the grid keeps focus.
|
*/

import { showLoadError } from "../../../../../shared/spa/error-state.js";
import { renderGradebookSkeleton } from "../skeleton.js";
import { fetchGradebookRoster, updateManualScore, overrideGrade, updateOverrideRequest, withdrawOverrideRequest } from "../api.js";
import { bindModal } from "../../assessments/modals/index.js";
import { overrideDialogState } from "./override-dialog.js";

let backToGrades = null;
let overrideModal = null;

// What the open dialog is for: set when it opens, read when it is submitted or a request is withdrawn.
let dialogState = overrideDialogState("direct", "", {});

const timers = new Map();

export async function loadGradebookRoster(back)
{
    backToGrades = back;

    const container = document.querySelector("#course-content");

    container.innerHTML = renderGradebookSkeleton();

    try {

        const result = await fetchGradebookRoster();

        container.innerHTML = result.html;

        bind(container);

    } catch (error) {

        console.error(error);

        showLoadError(container, "the gradebook", () => loadGradebookRoster(back));

    }
}

function setStatus(status)
{
    const dot = document.querySelector("[data-roster-save-dot]");
    const message = document.querySelector("[data-roster-save-message]");

    if (!dot || !message) {
        return;
    }

    const states = {
        saving: ["bg-amber-400", "Saving…"],
        saved: ["bg-emerald-500", "All changes saved"],
        error: ["bg-red-500", "Couldn't save — check your connection"],
    };

    const [color, text] = states[status];

    dot.className = `h-2 w-2 rounded-full ${color}`;
    message.textContent = text;
}

function bind(container)
{
    container.querySelector("[data-gradebook-roster-back]")
        ?.addEventListener("click", () => backToGrades?.());

    container.querySelectorAll("[data-manual-score-input]").forEach((input) => {

        const row = input.closest("[data-roster-row]");
        const key = `${row.dataset.studentId}:${input.dataset.itemId}`;

        input.addEventListener("input", () => {

            clearTimeout(timers.get(key));

            setStatus("saving");

            timers.set(key, setTimeout(async () => {

                const value = input.value === "" ? null : Number(input.value);

                try {

                    const result = await updateManualScore(input.dataset.itemId, row.dataset.studentId, value);

                    if (result.success === false) {
                        setStatus("error");
                        input.title = result.message;
                        return;
                    }

                    input.title = "";
                    setStatus("saved");

                    updateFinalCell(row, result.percentage);

                } catch (error) {

                    console.error(error);
                    setStatus("error");

                }

            }, 600));

        });

    });

    overrideModal = bindModal({
        modal: "#grade-override-modal",
        closeButtons: "[data-close-grade-override-modal]",
    });

    container.querySelectorAll("[data-open-override]").forEach((button) => {
        button.addEventListener("click", () => openOverrideModal(button));
    });

    bindOverrideForm();
}

function updateFinalCell(row, percentage)
{
    const button = row.querySelector("[data-open-override]");

    if (!button) {
        return;
    }

    const formatted = percentage === null || percentage === undefined
        ? "—"
        : `${Math.round(percentage * 100) / 100}%`;

    button.childNodes[0].textContent = formatted;
    button.dataset.currentPercentage = percentage ?? "";
}

/*
|--------------------------------------------------------------------------
| Override modal
|--------------------------------------------------------------------------
*/

function openOverrideModal(button)
{
    const row = button.closest("[data-roster-row]");
    const form = document.querySelector("#grade-override-form");

    if (!form || !overrideModal) {
        return;
    }

    const grid = button.closest("[data-gradebook-roster]");

    // Where the institution has deans a change to a final grade is a request; the grid says so.
    dialogState = overrideDialogState(grid?.dataset.overrideMode ?? "direct", grid?.dataset.overrideRoute ?? "", button.dataset);

    const modal = document.querySelector("#grade-override-modal");
    const percentage = form.querySelector("[data-override-percentage]");
    const reason = form.querySelector("[data-override-reason]");

    form.reset();
    form.querySelector("[data-override-student-id]").value = row.dataset.studentId;
    form.querySelector("[data-override-student-name]").textContent = button.dataset.studentName;
    form.querySelector("[data-override-calculated-percentage]").textContent =
        button.dataset.calculatedPercentage ? `${button.dataset.calculatedPercentage}%` : "—";

    percentage.value = dialogState.percentage;
    reason.value = dialogState.reason;
    percentage.placeholder = dialogState.placeholder;
    percentage.readOnly = dialogState.readOnly;
    reason.readOnly = dialogState.readOnly;

    modal.querySelector("[data-override-title]").textContent = dialogState.title;

    const note = modal.querySelector("[data-override-note]");
    note.textContent = dialogState.note;
    note.classList.toggle("hidden", dialogState.note === "");

    const status = modal.querySelector("[data-override-status]");
    status.textContent = dialogState.statusText;
    status.classList.toggle("hidden", dialogState.statusText === "");

    const submit = modal.querySelector("[data-override-submit]");
    submit.textContent = dialogState.submitLabel;
    submit.classList.toggle("hidden", dialogState.readOnly);

    modal.querySelector("[data-override-withdraw]").classList.toggle("hidden", !dialogState.canWithdraw);

    form.querySelector("[data-override-error]").classList.add("hidden");

    overrideModal.open();
}

async function withdrawRequest()
{
    const error = document.querySelector("#grade-override-form [data-override-error]");

    try {

        const result = await withdrawOverrideRequest(dialogState.requestId);

        if (!result.success) {
            error.textContent = result.message;
            error.classList.remove("hidden");
            return;
        }

        overrideModal?.close();

        // The grid is the server's word on where every request stands.
        await loadGradebookRoster(backToGrades);

    } catch (withdrawError) {

        console.error(withdrawError);
        error.textContent = "Couldn't withdraw the request.";
        error.classList.remove("hidden");

    }
}

function bindOverrideForm()
{
    const form = document.querySelector("#grade-override-form");

    document.querySelector("[data-override-withdraw]")?.addEventListener("click", withdrawRequest);

    form?.addEventListener("submit", async (event) => {

        event.preventDefault();

        const studentId = form.querySelector("[data-override-student-id]").value;
        const percentageValue = form.querySelector("[data-override-percentage]").value;
        const reason = form.querySelector("[data-override-reason]").value;
        const error = form.querySelector("[data-override-error]");

        const percentage = percentageValue === "" ? null : Number(percentageValue);

        if (dialogState.readOnly) {
            return;
        }

        try {

            // A request that was sent back is edited and sent again; anything else is a new change.
            const result = dialogState.requestId
                ? await updateOverrideRequest(dialogState.requestId, percentage, reason)
                : await overrideGrade(studentId, percentage, reason);

            if (!result.success) {
                error.textContent = result.message;
                error.classList.remove("hidden");
                return;
            }

            overrideModal?.close();

            // Where the institution has deans nothing changed in the grade: it is a request now.
            // Show where it stands, as the server has it, rather than faking a new grade.
            if (dialogState.mode === "request") {
                await loadGradebookRoster(backToGrades);
                return;
            }

            const row = document.querySelector(`[data-roster-row][data-student-id="${studentId}"]`);
            const button = row?.querySelector("[data-open-override]");

            if (button) {

                button.dataset.isOverride = percentage !== null ? "1" : "0";
                button.dataset.currentPercentage = percentage ?? button.dataset.calculatedPercentage;
                button.dataset.overrideReason = reason;

                button.childNodes[0].textContent = button.dataset.currentPercentage
                    ? `${button.dataset.currentPercentage}%`
                    : "—";

                button.classList.toggle("text-[#946200]", percentage !== null);
                button.classList.toggle("text-brand", percentage === null);

                let badge = button.querySelector("span");

                if (percentage !== null && !badge) {
                    badge = document.createElement("span");
                    badge.className = "rounded-full bg-[#FFF1D6] px-1.5 py-0.5 text-[10px] font-bold text-[#946200]";
                    badge.textContent = "Override";
                    button.append(badge);
                } else if (percentage === null && badge) {
                    badge.remove();
                }

            }

        } catch (submitError) {

            console.error(submitError);
            error.textContent = "Couldn't save the override.";
            error.classList.remove("hidden");

        }

    });
}
