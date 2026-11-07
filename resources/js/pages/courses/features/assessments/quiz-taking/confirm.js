/*
|--------------------------------------------------------------------------
| A question asked inside the quiz page
|--------------------------------------------------------------------------
| The page carries one dialog (#quiz-confirm, from quiz-answer-form.php). This
| fills it with the words for the moment, opens it, and answers true when the
| confirm button is pressed, false for anything else (the other button, the X,
| Escape, a click outside). Without the dialog it answers false, so nothing
| irreversible happens by accident.
*/

import { createDialog } from "../../../../../shared/dialog.js";

// One controller per dialog element; the page renders a new element each time the quiz is loaded.
const dialogs = new WeakMap();

/**
 * @param {{title: string, text: string, yes: string, no: string}} words
 * @returns {Promise<boolean>}
 */
export function askInQuiz({ title, text, yes, no }) {

    const el = document.querySelector("#quiz-confirm");

    if (!el) {
        return Promise.resolve(false);
    }

    if (!dialogs.has(el)) {
        dialogs.set(el, createDialog(el));
    }

    const dialog = dialogs.get(el);

    el.querySelector("[data-dialog-title]").textContent = title;
    el.querySelector("[data-quiz-confirm-text]").textContent = text;
    el.querySelector("[data-quiz-confirm-yes]").textContent = yes;
    el.querySelector("[data-quiz-confirm-no]").textContent = no;

    return new Promise((resolve) => {

        const confirmButton = el.querySelector("[data-quiz-confirm-yes]");

        const finish = (answer) => {

            confirmButton.removeEventListener("click", onYes);
            el.removeEventListener("dialog:close", onClose);

            resolve(answer);

        };

        const onYes = () => {

            // Closing fires dialog:close, which must not count as a "no".
            confirmButton.removeEventListener("click", onYes);
            el.removeEventListener("dialog:close", onClose);
            dialog.close();

            resolve(true);

        };

        const onClose = () => finish(false);

        confirmButton.addEventListener("click", onYes);
        el.addEventListener("dialog:close", onClose);

        dialog.open();

    });

}
