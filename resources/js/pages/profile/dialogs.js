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

/** @param {ParentNode} root */
export function bindProfileDialogs(root = document) {
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
        input.closest("[data-field]")?.querySelector("[data-field-error]")?.before(hint);

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
