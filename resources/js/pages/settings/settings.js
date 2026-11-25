// The show/hide buttons on the password inputs: the same toggle as the sign-in page.
import "../login/login.js";
import { strength } from "./strength.js";

const input = document.querySelector("[data-password-new]");
const bars = document.querySelectorAll("[data-password-meter] i");
const label = document.querySelector("[data-password-strength]");

if (input && label) {
    const min = Number(input.dataset.min) || 8;

    input.addEventListener("input", () => {
        const { score, word } = strength(input.value, min);

        bars.forEach((bar, i) => {
            bar.classList.toggle("bg-brand", i < score);
            bar.classList.toggle("bg-gray-200", i >= score);
        });
        label.textContent = word;
    });
}
