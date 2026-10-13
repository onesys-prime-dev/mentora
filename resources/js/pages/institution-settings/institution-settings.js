/**
 * The branding tab's live preview: as the colours are typed or picked, the
 * preview box takes them (through the same tokens the whole app reads), and
 * each field says whether it is readable. The server still decides on Save.
 */

import { check, dark, tint } from "../../shared/brand-colours.js";
import { applyBrandTokens, clearBrandTokens } from "../../shared/brand-tokens.js";

function bind(root) {
    const preview = root.querySelector("[data-brand-preview]");
    const inputs = {
        primary: root.querySelector("[data-brand-input='primary']"),
        accent: root.querySelector("[data-brand-input='accent']"),
    };

    if (!preview || !inputs.primary || !inputs.accent) {
        return;
    }

    const render = () => {
        const tokens = {};

        for (const role of ["primary", "accent"]) {
            const result = check(inputs[role].value, role);
            const note = root.querySelector(`[data-brand-note='${role}']`);

            if (note) {
                note.textContent = result.message;
                note.dataset.ok = result.ok ? "true" : "false";
                note.classList.toggle("text-[#C33939]", !result.ok);
                note.classList.toggle("text-[#2E7D4F]", result.ok);
            }

            if (result.ok && result.hex) {
                if (role === "primary") {
                    tokens.brand = result.hex;
                    tokens.brandDark = dark(result.hex);
                    tokens.brandTint = tint(result.hex);
                } else {
                    tokens.accent = result.hex;
                }
            }

            // The colour swatch follows the text box.
            const swatch = root.querySelector(`[data-brand-swatch='${role}']`);

            if (swatch && result.hex) {
                swatch.value = result.hex.toLowerCase();
            }
        }

        clearBrandTokens(preview);
        applyBrandTokens(preview, tokens);
    };

    for (const role of ["primary", "accent"]) {
        inputs[role].addEventListener("input", render);

        const swatch = root.querySelector(`[data-brand-swatch='${role}']`);

        swatch?.addEventListener("input", () => {
            inputs[role].value = swatch.value.toUpperCase();
            render();
        });
    }

    root.querySelector("[data-brand-default]")?.addEventListener("click", (event) => {
        event.preventDefault();
        inputs.primary.value = inputs.primary.dataset.default ?? "#0040A6";
        inputs.accent.value = inputs.accent.dataset.default ?? "#2E7D4F";
        render();
    });

    render();
}

document.querySelectorAll("[data-brand-form]").forEach((form) => bind(form.closest("main") ?? document));

// "Remove announcement": saving it empty is what takes it down (the help text says so too).
document.querySelectorAll("[data-announcement-clear]").forEach((button) => {
    button.addEventListener("click", () => {
        const form = button.closest("form");
        const text = form?.querySelector("textarea[name='text']");

        if (form && text) {
            text.value = "";
            form.requestSubmit();
        }
    });
});
