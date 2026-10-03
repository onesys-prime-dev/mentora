/*
|--------------------------------------------------------------------------
| Action-level loading for a button (save, post, send, publish …)
|--------------------------------------------------------------------------
|
| A user-triggered action never gets a skeleton: the button itself says what
| is happening and ignores further clicks. setBusy(button, true, "Posting…")
| swaps the label and disables it; setBusy(button, false) puts the original
| label back. aria-busy tells assistive tech the control is working.
|
*/

export function setBusy(button, busy, label = null) {

    if (busy) {

        if (button.dataset.idleLabel === undefined) {
            button.dataset.idleLabel = button.textContent;
        }

        if (label) {
            button.textContent = label;
        }

        button.disabled = true;
        button.setAttribute("aria-busy", "true");

        return;

    }

    if (button.dataset.idleLabel !== undefined) {
        button.textContent = button.dataset.idleLabel;
        delete button.dataset.idleLabel;
    }

    button.disabled = false;
    button.removeAttribute("aria-busy");

}
