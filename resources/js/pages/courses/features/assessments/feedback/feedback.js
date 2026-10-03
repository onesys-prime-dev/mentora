/*
|--------------------------------------------------------------------------
| Feedback thread composer
|--------------------------------------------------------------------------
|
| Rendered by score-panel.php on both the student's assessment view and the
| instructor's Grade Work screen. Posting reloads the current screen (same
| no-optimistic-UI approach submitGrade() already uses) rather than patching
| the thread in place, since either caller already has a cheap "reload just
| this screen" function to hand in.
|
*/

import { setBusy } from "../../../../../shared/spa/busy-button.js";
import { postFeedback } from "../api.js";

export function bindFeedbackComposer(offeringId, reload) {

    const panel =
        document.querySelector("[data-feedback-panel]");

    const button =
        panel?.querySelector("[data-feedback-send]");

    if (!panel || !button) {
        return;
    }

    const textarea =
        panel.querySelector("[data-feedback-message]");

    const error =
        panel.querySelector("[data-feedback-error]");

    button.addEventListener("click", async () => {

        const message =
            textarea?.value.trim();

        if (!message) {
            return;
        }

        const submissionId =
            panel.dataset.feedbackSubmissionId;

        setBusy(button, true, "Sending…");

        error.classList.add("hidden");

        try {

            const result = await postFeedback(offeringId, submissionId, message);

            if (result.success === false) {
                error.textContent = result.message;
                error.classList.remove("hidden");
                return;
            }

            await reload();

        } catch (caughtError) {

            console.error(caughtError);

            error.textContent = "Couldn't send that — please try again.";
            error.classList.remove("hidden");

        } finally {

            setBusy(button, false);

        }

    });

}
