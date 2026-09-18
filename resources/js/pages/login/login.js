/**
 * Password visibility toggle.
 *
 * Scoped to [data-password-toggle] rather than a fixed id so the same
 * markup pattern works on the register page later without a second script.
 */
document.querySelectorAll("[data-password-toggle]").forEach((button) => {
    const input = document.getElementById(button.dataset.passwordToggle);

    if (!input) {
        return;
    }

    const shownIcon = button.querySelector('[data-password-icon="shown"]');
    const hiddenIcon = button.querySelector('[data-password-icon="hidden"]');

    button.addEventListener("click", () => {
        const isRevealed = input.type === "text";

        input.type = isRevealed ? "password" : "text";
        button.setAttribute("aria-pressed", String(!isRevealed));
        button.setAttribute("aria-label", isRevealed ? "Show password" : "Hide password");

        shownIcon?.classList.toggle("hidden", !isRevealed);
        hiddenIcon?.classList.toggle("hidden", isRevealed);
    });
});
