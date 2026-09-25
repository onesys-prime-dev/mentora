const button = document.getElementById("profile-menu-button");
const panel = document.getElementById("profile-menu-panel");
const arrow = document.getElementById("profile-menu-arrow");

if (button && panel && arrow) {
    const openMenu = () => {
        panel.classList.remove("hidden");
        button.setAttribute("aria-expanded", "true");
        arrow.classList.add("rotate-180");
    };

    const closeMenu = () => {
        panel.classList.add("hidden");
        button.setAttribute("aria-expanded", "false");
        arrow.classList.remove("rotate-180");
    };

    const isOpen = () => !panel.classList.contains("hidden");

    button.addEventListener("click", (event) => {
        isOpen() ? closeMenu() : openMenu();
    });

    // Close when clicking outside the button/panel.
    document.addEventListener("click", (event) => {
        if (!isOpen()) return;

        if (!panel.contains(event.target) && !button.contains(event.target)) {
            closeMenu();
        }
    });

    // Close on Escape.
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && isOpen()) {
            closeMenu();
        }
    });
}