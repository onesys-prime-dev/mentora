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

import { fetchGradebookRoster, updateManualScore, overrideGrade } from "../api.js";
import { bindModal } from "../../assessments/modals/index.js";

let backToGrades = null;
let overrideModal = null;

const timers = new Map();

export async function loadGradebookRoster(back)
{
    backToGrades = back;

    const container = document.querySelector("#course-content");

    container.innerHTML = "Loading...";

    try {

        const result = await fetchGradebookRoster();

        container.innerHTML = result.html;

        bind(container);

    } catch (error) {

        console.error(error);

        container.innerHTML = "<p>Unable to load the gradebook.</p>";

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

    form.reset();
    form.querySelector("[data-override-student-id]").value = row.dataset.studentId;
    form.querySelector("[data-override-student-name]").textContent = button.dataset.studentName;
    form.querySelector("[data-override-calculated-percentage]").textContent =
        button.dataset.calculatedPercentage ? `${button.dataset.calculatedPercentage}%` : "—";

    if (button.dataset.isOverride === "1") {
        form.querySelector("[data-override-percentage]").value = button.dataset.currentPercentage;
        form.querySelector("[data-override-reason]").value = button.dataset.overrideReason || "";
    }

    form.querySelector("[data-override-error]").classList.add("hidden");

    overrideModal.open();
}

function bindOverrideForm()
{
    const form = document.querySelector("#grade-override-form");

    form?.addEventListener("submit", async (event) => {

        event.preventDefault();

        const studentId = form.querySelector("[data-override-student-id]").value;
        const percentageValue = form.querySelector("[data-override-percentage]").value;
        const reason = form.querySelector("[data-override-reason]").value;
        const error = form.querySelector("[data-override-error]");

        const percentage = percentageValue === "" ? null : Number(percentageValue);

        try {

            const result = await overrideGrade(studentId, percentage, reason);

            if (!result.success) {
                error.textContent = result.message;
                error.classList.remove("hidden");
                return;
            }

            overrideModal?.close();

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
                button.classList.toggle("text-[#0040A6]", percentage === null);

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
