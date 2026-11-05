/*
|--------------------------------------------------------------------------
| A quiz's password, in the assessment settings
|--------------------------------------------------------------------------
| The "Require a password" switch, and what sits under it:
|
|   - switched on with no password yet: a Create password button;
|   - with a password: ****** and an edit button (the password itself is never
|     sent back to the page, it is kept as a hash);
|   - both open the same modal: a plain text field pre-filled with a generated
|     password the teacher can erase and replace with their own, Save and Cancel.
|
| Saving sets the password straight away (its own request), so the password is
| only ever sent when it is being set. The switch itself is saved with the rest
| of the settings; switching it on before a password exists is refused there,
| and passwordProblem() lets the page say so first.
*/

import { bindModal } from "../modals/index.js";

// Characters that are easy to read out loud: no 0, O, 1, l or I.
const ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789";
const LENGTH = 10;

/** A password the teacher can read out to a class: ten characters from the browser's random generator. */
export function generatePassword() {

    const limit = 256 - (256 % ALPHABET.length); // bytes above this would favour the first characters
    let out = "";

    while (out.length < LENGTH) {

        const bytes = crypto.getRandomValues(new Uint8Array(LENGTH));

        for (const byte of bytes) {

            if (byte < limit && out.length < LENGTH) {
                out += ALPHABET[byte % ALPHABET.length];
            }

        }

    }

    return out;

}

/**
 * @param {object} options
 * @param {(password: string) => Promise<{success: boolean, message?: string}>} options.savePassword
 * @param {(message: string, tone: string) => void} options.notify
 * @returns {{passwordProblem: () => string|null}}
 */
export function bindQuizSecurity({ savePassword, notify }) {

    const container = document.querySelector("[data-quiz-password]");

    if (!container) {
        return { passwordProblem: () => null };
    }

    const toggle = container.querySelector("[data-password-toggle]");
    const state = container.querySelector("[data-password-state]");
    const create = container.querySelector("[data-password-create]");
    const set = container.querySelector("[data-password-set]");
    const root = document.querySelector("#quiz-password-modal");

    const input = root?.querySelector("[data-password-input]");
    const error = root?.querySelector("[data-password-error]");
    const saveButton = root?.querySelector("[data-password-save]");

    const modal = bindModal({
        modal: "#quiz-password-modal",
        closeButtons: "[data-close-password-modal]",
    });

    const hasPassword = () => container.dataset.hasPassword === "1";

    function render() {

        state?.classList.toggle("hidden", !toggle?.checked);
        create?.classList.toggle("hidden", hasPassword());
        set?.classList.toggle("hidden", !hasPassword());

    }

    function openModal() {

        if (!modal || !input) {
            return;
        }

        input.value = generatePassword();

        if (error) {
            error.textContent = "";
        }

        modal.open();
        input.focus();
        input.select();

    }

    toggle?.addEventListener("change", render);
    create?.addEventListener("click", openModal);
    container.querySelector("[data-password-edit]")?.addEventListener("click", openModal);

    root?.querySelector("[data-password-generate]")?.addEventListener("click", () => {

        input.value = generatePassword();
        input.focus();

    });

    let saving = false;

    async function save() {

        if (saving || !input) {
            return;
        }

        const value = input.value.trim();

        if (value === "") {
            error.textContent = "Type a password, or keep the one suggested.";

            return;
        }

        saving = true;
        saveButton.disabled = true;
        error.textContent = "";

        try {

            const result = await savePassword(value);

            if (result?.success) {

                container.dataset.hasPassword = "1";
                render();
                modal.close();
                notify(result.message || "Password saved.", "success");

            } else {

                error.textContent = result?.message || "The password could not be saved.";

            }

        } catch {

            error.textContent = "The password could not be saved. Check your connection and try again.";

        } finally {

            saving = false;
            saveButton.disabled = false;

        }

    }

    saveButton?.addEventListener("click", save);

    input?.addEventListener("keydown", (event) => {

        if (event.key === "Enter") {
            event.preventDefault();
            save();
        }

    });

    render();

    return {

        /** What stops the settings being saved with the password on and none created, or null. */
        passwordProblem() {

            return toggle?.checked && !hasPassword()
                ? "Create a password for this quiz first, or switch the password off."
                : null;

        },

    };

}
