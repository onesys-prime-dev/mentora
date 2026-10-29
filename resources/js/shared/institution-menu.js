const STORAGE_KEY = "mentora-institution-menu";

const menu = document.querySelector("[data-institution-menu]");
const toggle = menu?.querySelector("[data-menu-collapse]");

if (menu && toggle) {
    const render = () => {
        const collapsed = menu.getAttribute("data-collapsed") === "true";
        const label = collapsed ? "Expand the menu" : "Collapse the menu";

        toggle.setAttribute("aria-pressed", String(collapsed));
        toggle.setAttribute("aria-label", label);
        toggle.setAttribute("title", label);
    };

    toggle.addEventListener("click", () => {
        const collapsed = menu.getAttribute("data-collapsed") !== "true";

        menu.setAttribute("data-collapsed", String(collapsed));

        try {
            localStorage.setItem(STORAGE_KEY, collapsed ? "collapsed" : "expanded");
        } catch {
            // Storage blocked: the choice lasts until the page changes.
        }

        render();
    });

    render();
}
