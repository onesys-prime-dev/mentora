/**
 * The profile edit dialogs: the custom-pronouns toggle and a live character count.
 * Submitting and showing the server's errors is shared/dialog.js (form[data-dialog-form]).
 */

/** Shows the custom text box only while "Custom…" is chosen. */
export function syncCustomPronouns(select, box) {
    const custom = select.value === "custom";

    box.hidden = !custom;

    return custom;
}

/** "12 / 300", or "12 / 300, 5 over" once past the limit. */
export function countText(length, max) {
    return length > max ? `${length} / ${max}, ${length - max} over` : `${length} / ${max}`;
}

/**
 * The correction dialog's chosen detail: its current value for "Now", and whether the email note shows.
 *
 * @param {HTMLSelectElement} select
 */
export function correctionChoice(select) {
    const option = select.selectedOptions[0];

    return { now: option?.dataset.now ?? "", email: select.value === "email" };
}

/** @param {ParentNode} root */
export function bindProfileDialogs(root = document) {
    for (const select of root.querySelectorAll("[data-correction-field]")) {
        const form = select.form;
        const now = form?.querySelector("[data-correction-now]");
        const note = form?.querySelector("[data-correction-email-note]");
        const sync = () => {
            const choice = correctionChoice(select);

            if (now) {
                now.value = choice.now;
            }
            if (note) {
                note.hidden = !choice.email;
            }
        };

        select.addEventListener("change", sync);
        form?.addEventListener("reset", () => setTimeout(sync));
        sync();
    }

    for (const select of root.querySelectorAll("[data-pronoun-select]")) {
        const box = select.closest("[data-field]")?.querySelector("[data-pronoun-custom]");

        if (!box) {
            continue;
        }

        const sync = () => {
            if (syncCustomPronouns(select, box) && select.dataset.touched === "1") {
                box.querySelector("input")?.focus();
            }
        };

        select.addEventListener("change", () => {
            select.dataset.touched = "1";
            sync();
        });
        // The dialog resets its form each time it opens.
        select.form?.addEventListener("reset", () => setTimeout(() => {
            select.dataset.touched = "0";
            sync();
        }));
        sync();
    }

    for (const input of root.querySelectorAll("form[data-profile-form] [maxlength]")) {
        const max = Number(input.getAttribute("maxlength"));
        const hint = document.createElement("p");

        hint.className = "text-right text-xs text-[#646464]";
        hint.setAttribute("aria-live", "off");

        // The custom-pronoun hint lives in (and hides with) its box.
        if (input.closest("[data-pronoun-custom]")) {
            input.after(hint);
        } else {
            input.closest("[data-field]")?.querySelector("[data-field-error]")?.before(hint);
        }

        const update = () => {
            hint.textContent = countText(input.value.length, max);
        };

        input.addEventListener("input", update);
        input.form?.addEventListener("reset", () => setTimeout(update));
        update();
    }
}

if (typeof document !== "undefined") {
    bindProfileDialogs();
}
