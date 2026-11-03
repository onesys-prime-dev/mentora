/*
|--------------------------------------------------------------------------
| Finalize ready sections
|--------------------------------------------------------------------------
|
| The confirm dialog (components/confirm, #finalize-ready-dialog) names the sections that will be finalized.
| Here its form is posted without leaving the page, and the answer (what was finalized, what was skipped and
| why) replaces the question in the same dialog. Closing the dialog then reloads the page, so the cards show
| the new states. A refusal (no permission, viewing as someone, a read-only institution) is shown in the
| dialog and changes nothing. Without JavaScript the form posts normally and the server sends the page back
| with the same sentence.
|
*/

const OFFLINE = "We could not reach the server. Nothing was finalized. Check your connection and try again.";
const FAILED = "That did not work. Nothing was finalized. Please try again.";

/**
 * "Finalized 2 sections. Skipped 1: IT101-B — 1 piece of handed-in work is still waiting to be graded."
 *
 * @param {{ done: Array<{ label: string }>, skipped: Array<{ label: string, reason: string }> }} result
 */
export function resultSentence(result) {
    const done = result.done.length;
    let text = `Finalized ${done} ${done === 1 ? "section" : "sections"}.`;

    if (result.skipped.length > 0) {
        const reasons = result.skipped.map((s) => `${s.label} — ${s.reason.replace(/\.$/, "")}`);
        text += ` Skipped ${result.skipped.length}: ${reasons.join("; ")}.`;
    }

    return text;
}

/**
 * @param {ParentNode} root
 * @param {() => void} reload what happens once the result has been read and the dialog closes
 */
export function bindFinalizeReady(root = document, reload = () => window.location.reload()) {
    const dialog = root.querySelector("#finalize-ready-dialog");
    const form = dialog?.querySelector("form");

    if (!form) {
        return;
    }

    const submit = form.querySelector("[data-confirm-submit]");
    const text = form.querySelector("[data-dialog-text]");
    let finished = false;

    const showError = (message) => {
        let error = form.querySelector("[data-finalize-ready-error]");

        if (!error) {
            error = document.createElement("p");
            error.className = "text-xs font-medium text-[#C33939]";
            error.setAttribute("role", "alert");
            error.dataset.finalizeReadyError = "";
            text?.after(error);
        }

        error.textContent = message;
    };

    dialog.addEventListener("dialog:close", () => {
        if (finished) {
            reload();
        }
    });

    form.addEventListener("submit", async (event) => {
        event.preventDefault();

        if (submit) {
            submit.disabled = true;
        }

        form.setAttribute("aria-busy", "true");

        try {
            const response = await fetch(form.action, {
                method: "POST",
                body: new FormData(form),
                headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });
            const body = await response.json().catch(() => ({}));

            if (!response.ok || !body.success) {
                showError(body.message ?? FAILED);

                return;
            }

            finished = true;
            form.querySelector("[data-finalize-ready-error]")?.remove();

            if (text) {
                text.setAttribute("role", "status");
                text.textContent = resultSentence(body);
            }

            if (submit) {
                submit.hidden = true;
            }

            const close = form.querySelector("[data-dialog-close]");

            if (close) {
                close.textContent = "Done";
                close.focus();
            }
        } catch {
            showError(OFFLINE);
        } finally {
            if (submit) {
                submit.disabled = false;
            }

            form.removeAttribute("aria-busy");
        }
    });
}
