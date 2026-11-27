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

// A sign-out lands here: drop the unsent Messages drafts this browser keeps (messages.js draftKey), so the next
// person to sign in on it never finds them.
try {
    Object.keys(localStorage).filter((key) => key.startsWith("messages:draft:")).forEach((key) => localStorage.removeItem(key));
} catch {
    // No storage, so no drafts.
}
