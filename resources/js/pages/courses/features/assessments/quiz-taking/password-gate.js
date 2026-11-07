/*
|--------------------------------------------------------------------------
| The password prompt of a quiz that has one
|--------------------------------------------------------------------------
| The page shows a small form instead of the quiz until the right password is
| given. Submitting it sends the password to the server (which starts the
| attempt only if it is right) and shows whatever page comes back: the quiz,
| or this prompt again with the reason ("That password is not right.").
|
| The form is never submitted the ordinary way, and cannot be sent twice while
| a request is on its way.
*/

/**
 * @param {object} options
 * @param {(password: string) => Promise<unknown>} options.submit gives the password to the server and shows its answer
 * @returns {boolean} whether there was a prompt to bind
 */
export function bindPasswordGate({ submit }) {

    const form = document.querySelector("[data-quiz-password-form]");

    if (!form) {
        return false;
    }

    const input = form.querySelector("[data-quiz-password-input]");
    const error = form.querySelector("[data-quiz-password-error]");
    const button = form.querySelector("[data-quiz-password-submit]");

    let sending = false;

    form.addEventListener("submit", async (event) => {

        event.preventDefault();

        if (sending) {
            return;
        }

        const typed = input.value;

        if (typed.trim() === "") {

            error.textContent = "Type the password your teacher gave you.";
            input.setAttribute("aria-invalid", "true");
            input.focus();

            return;

        }

        sending = true;
        button.disabled = true;
        error.textContent = "";
        input.setAttribute("aria-invalid", "false");

        try {

            await submit(typed);

        } catch {

            error.textContent = "The password could not be checked. Check your connection and try again.";

        } finally {

            // When the server's answer replaced this page, the form is gone and this changes nothing visible.
            sending = false;
            button.disabled = false;

        }

    });

    input.focus();

    return true;

}
