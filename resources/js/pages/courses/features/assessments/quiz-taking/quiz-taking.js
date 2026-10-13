/*
|--------------------------------------------------------------------------
| Quiz Taking (student answering a quiz)
|--------------------------------------------------------------------------
*/

import {
    saveQuizAnswer,
    submitQuizAttempt,
} from "../api.js";

let state = {
    offeringId: null,
    assessmentId: null,
    submitQuiz: null,
};

const saveTimers = new Map();

let timerInterval = null;

export function initializeQuizTaking(offeringId, assessmentId, submitQuiz) {

    state = { offeringId, assessmentId, submitQuiz };

    const form =
        document.querySelector("#quiz-answer-form");

    if (!form) {
        return;
    }

    bindAnswerInputs(form);
    bindSubmit(form);
    bindTimer();

    // Resuming an in-progress attempt — apply the selection cap to
    // whatever's already checked, same as a fresh change event would.
    form
        .querySelectorAll('[data-quiz-question][data-multiple-answer="1"]')
        .forEach((question) => enforceAnswersLimit(question));

    updateProgress();

}

/*
|--------------------------------------------------------------------------
| Answers (autosave, debounced per question)
|--------------------------------------------------------------------------
*/

function bindAnswerInputs(form) {

    form.addEventListener("change", (event) => {

        const choiceInput =
            event.target.closest("[data-quiz-choice-input]");

        if (choiceInput) {

            const question =
                choiceInput.closest("[data-quiz-question]");

            if (question.dataset.multipleAnswer === "1") {

                enforceAnswersLimit(question);

                highlightSelectedChoices(question);

                const selectedIds =
                    getSelectedChoiceIds(question);

                markAnswered(question, selectedIds.length > 0);

                queueAnswerSave(question.dataset.questionId, {
                    selected_choice_ids: selectedIds,
                });

            } else {

                highlightSelectedChoice(question, choiceInput);

                markAnswered(question, true);

                queueAnswerSave(question.dataset.questionId, {
                    selected_choice_id: Number(choiceInput.value),
                });

            }

        }

    });

    form.addEventListener("input", (event) => {

        const textInput =
            event.target.closest("[data-quiz-text-input]");

        if (textInput) {

            const question =
                textInput.closest("[data-quiz-question]");

            markAnswered(question, textInput.value.trim() !== "");

            queueAnswerSave(question.dataset.questionId, {
                response_text: textInput.value,
            });

        }

    });

}

function highlightSelectedChoice(question, selectedInput) {

    question
        .querySelectorAll("[data-quiz-choice-row]")
        .forEach((row) => {

            const isSelected =
                row.querySelector("[data-quiz-choice-input]") === selectedInput;

            row.classList.toggle("border-brand", isSelected);
            row.classList.toggle("bg-brand/5", isSelected);
            row.classList.toggle("text-brand", isSelected);

            row.classList.toggle("border-gray-200", !isSelected);
            row.classList.toggle("text-gray-700", !isSelected);

        });

}

function highlightSelectedChoices(question) {

    question
        .querySelectorAll("[data-quiz-choice-row]")
        .forEach((row) => {

            const isSelected =
                row.querySelector("[data-quiz-choice-input]")?.checked ?? false;

            row.classList.toggle("border-brand", isSelected);
            row.classList.toggle("bg-brand/5", isSelected);
            row.classList.toggle("text-brand", isSelected);

            row.classList.toggle("border-gray-200", !isSelected);
            row.classList.toggle("text-gray-700", !isSelected);

        });

}

function getSelectedChoiceIds(question) {

    return Array.from(
        question.querySelectorAll("[data-quiz-choice-input]:checked")
    ).map((input) => Number(input.value));

}

function enforceAnswersLimit(question) {

    const limit =
        Number(question.dataset.answersCount || 0);

    if (!limit) {
        return;
    }

    const checkedCount =
        question.querySelectorAll("[data-quiz-choice-input]:checked").length;

    // Once the student has checked as many as this question allows, grey
    // out the rest rather than letting them keep piling on selections —
    // matches the "Select N answers" hint above the choices.
    question
        .querySelectorAll("[data-quiz-choice-input]")
        .forEach((input) => {
            input.disabled = !input.checked && checkedCount >= limit;
        });

}

function markAnswered(question, isAnswered) {

    const wasAnswered =
        question.dataset.answered === "1";

    question.dataset.answered = isAnswered ? "1" : "0";

    if (wasAnswered !== isAnswered) {
        updateProgress();
    }

}

function queueAnswerSave(questionId, payload, delay = 600) {

    clearTimeout(saveTimers.get(questionId));

    setSaveStatus("saving");

    const submissionId =
        document.querySelector("#quiz-answer-form")?.dataset.submissionId;

    const timer = setTimeout(async () => {

        try {

            await saveQuizAnswer(
                state.offeringId,
                submissionId,
                questionId,
                payload
            );

            setSaveStatus("saved");

        } catch (error) {

            console.error(error);

            setSaveStatus("error");

        }

    }, delay);

    saveTimers.set(questionId, timer);

}

function setSaveStatus(status) {

    const indicator =
        document.querySelector("[data-take-quiz-save-indicator]");

    const message =
        document.querySelector("[data-take-quiz-save-message]");

    if (!indicator || !message) {
        return;
    }

    indicator.className = "h-2 w-2 rounded-full";

    if (status === "saving") {

        indicator.classList.add("bg-orange-500");

        message.textContent = "Saving...";

    } else if (status === "error") {

        indicator.classList.add("bg-red-500");

        message.textContent = "Save failed";

    } else {

        indicator.classList.add("bg-emerald-500");

        message.textContent = "All changes saved";

    }

}

/*
|--------------------------------------------------------------------------
| Progress
|--------------------------------------------------------------------------
*/

function updateProgress() {

    const questions =
        Array.from(document.querySelectorAll("[data-quiz-question]"));

    const answered =
        questions.filter((question) => question.dataset.answered === "1").length;

    const label =
        document.querySelector("[data-quiz-progress-label]");

    const bar =
        document.querySelector("[data-quiz-progress-bar]");

    if (label) {
        label.textContent = `${answered} of ${questions.length} answered`;
    }

    if (bar) {

        const percentage =
            questions.length > 0
                ? (answered / questions.length) * 100
                : 0;

        bar.style.width = `${percentage}%`;

    }

}

/*
|--------------------------------------------------------------------------
| Timer
|--------------------------------------------------------------------------
*/

function bindTimer() {

    const timerEl =
        document.querySelector("[data-quiz-timer]");

    if (!timerEl) {
        return;
    }

    const endsAt =
        new Date(timerEl.dataset.endsAt).getTime();

    const valueEl =
        document.querySelector("[data-quiz-timer-value]");

    const form =
        document.querySelector("#quiz-answer-form");

    function tick() {

        const remainingMs =
            endsAt - Date.now();

        if (remainingMs <= 0) {

            clearInterval(timerInterval);

            if (valueEl) {
                valueEl.textContent = "0:00";
            }

            if (form) {
                submitNow(form);
            }

            return;

        }

        const totalSeconds =
            Math.floor(remainingMs / 1000);

        const minutes =
            Math.floor(totalSeconds / 60);

        const seconds =
            totalSeconds % 60;

        if (valueEl) {
            valueEl.textContent = `${minutes}:${String(seconds).padStart(2, "0")}`;
        }

    }

    tick();

    timerInterval = setInterval(tick, 1000);

}

/*
|--------------------------------------------------------------------------
| Submit
|--------------------------------------------------------------------------
*/

function bindSubmit(form) {

    document
        .querySelector("[data-submit-quiz]")
        ?.addEventListener("click", () => {

            const confirmed =
                window.confirm("Submit your answers? You won't be able to change them afterward.");

            if (!confirmed) {
                return;
            }

            submitNow(form);

        });

}

async function submitNow(form) {

    if (timerInterval) {
        clearInterval(timerInterval);
    }

    try {

        await submitQuizAttempt(
            state.offeringId,
            form.dataset.submissionId
        );

        await state.submitQuiz();

    } catch (error) {

        console.error(error);

    }

}
