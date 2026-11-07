/*
|--------------------------------------------------------------------------
| When a quiz can be handed in
|--------------------------------------------------------------------------
| - A required question left unanswered keeps Submit disabled, with a line
|   that says how many are left. (The server refuses it too.)
| - Only optional questions left: Submit asks first, "You have 3 unanswered
|   questions. Submit anyway?".
| - Everything answered: Submit still asks once, because it cannot be undone.
|
| Each question carries data-required and data-answered (quiz-answer-form.php);
| quiz-taking.js keeps data-answered current and calls refresh().
*/

const plural = (count, word) => `${count} ${word}${count === 1 ? "" : "s"}`;

/** @returns {{required: number, optional: number}} how many questions are unanswered, by kind */
export function unanswered(form) {

    const counts = { required: 0, optional: 0 };

    form.querySelectorAll("[data-quiz-question]").forEach((question) => {

        if (question.dataset.answered === "1") {
            return;
        }

        counts[question.dataset.required === "1" ? "required" : "optional"]++;

    });

    return counts;

}

/**
 * @param {object} options
 * @param {HTMLFormElement} options.form
 * @param {HTMLButtonElement|null} options.button the Submit button
 * @param {HTMLElement|null} options.hint says why Submit is disabled
 * @param {(words: {title: string, text: string, yes: string, no: string}) => Promise<boolean>} options.ask
 * @param {() => void} options.submit hands the quiz in
 * @returns {{refresh: () => void}}
 */
export function bindSubmitRules({ form, button, hint, ask, submit }) {

    let asking = false;

    function refresh() {

        const { required } = unanswered(form);

        if (button) {
            button.disabled = required > 0;
        }

        if (hint) {

            hint.textContent = required > 0
                ? `Answer the ${plural(required, "required question")} to submit.`
                : "";

            hint.classList.toggle("hidden", required === 0);

        }

    }

    button?.addEventListener("click", async () => {

        const { required, optional } = unanswered(form);

        // The button is disabled, but a click can still arrive (enabled by hand, a keyboard shortcut).
        if (required > 0 || asking) {
            return;
        }

        asking = true;

        try {

            const confirmed = await ask(
                optional > 0
                    ? {
                        title: "Submit with questions unanswered?",
                        text: `You have ${plural(optional, "unanswered question")}. Submit anyway?`,
                        yes: "Submit anyway",
                        no: "Keep working",
                    }
                    : {
                        title: "Submit your answers?",
                        text: "You won't be able to change them afterward.",
                        yes: "Submit",
                        no: "Keep working",
                    }
            );

            if (confirmed) {
                submit();
            }

        } finally {

            asking = false;

        }

    });

    refresh();

    return { refresh };

}
