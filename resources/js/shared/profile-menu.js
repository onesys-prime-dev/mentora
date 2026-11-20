import { clampToViewport } from "./clamp-panel.js";

// The avatar button and the phone ⋯ button both open the one account panel.
const panel = document.getElementById("profile-menu-panel");
const arrow = document.getElementById("profile-menu-arrow");
const triggers = [...document.querySelectorAll("[data-profile-menu-trigger]")];

if (panel && triggers.length) {
    let opener = null;

    const openMenu = (trigger) => {
        opener = trigger;
        panel.classList.remove("hidden");
        trigger.setAttribute("aria-expanded", "true");
        arrow?.classList.toggle("rotate-180", trigger.id === "profile-menu-button");
        clampToViewport(panel);
    };

    const closeMenu = () => {
        panel.classList.add("hidden");
        triggers.forEach((trigger) => trigger.setAttribute("aria-expanded", "false"));
        arrow?.classList.remove("rotate-180");
    };

    const isOpen = () => !panel.classList.contains("hidden");

    triggers.forEach((trigger) => trigger.addEventListener("click", () => {
        isOpen() ? closeMenu() : openMenu(trigger);
    }));

    // Close when clicking outside the panel and every trigger.
    document.addEventListener("click", (event) => {
        if (!isOpen()) return;

        if (!panel.contains(event.target) && !triggers.some((trigger) => trigger.contains(event.target))) {
            closeMenu();
        }
    });

    // Close on Escape, back on the button that opened it.
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && isOpen()) {
            closeMenu();
            opener?.focus();
        }
    });
}
