const button = document.getElementById("app-menu-button");
const panel = document.getElementById("app-menu-panel");

if (button && panel) {
    const openMenu = () => {
        panel.classList.remove("hidden");
    };

    const closeMenu = () => {
        panel.classList.add("hidden");
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