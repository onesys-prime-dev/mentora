const STORAGE_KEY = "mentora-institution-menu";

// lg to xl: the menu is an icon rail, and the collapse button peeks it open over the page instead.
const RAIL = "(min-width: 64rem) and (max-width: 79.999rem)";

// jsdom and older engines reject some selectors; treat those as "not matching".
const matches = (el, selector) => {
    try {
        return el.matches(selector);
    } catch {
        return false;
    }
};

/**
 * Binds a side menu (partials/institution-menu, partials/admin-menu). At xl its
 * [data-menu-collapse] button collapses it to the rail and remembers the choice.
 * At lg to xl the rail peeks open on hover or keyboard focus (app.css) or on
 * data-peek="true" (the button); data-peek="false" (Escape, the button again, a
 * click outside) closes it over hover and focus until the pointer or focus leaves.
 */
export function bindSideMenu(menu) {
    const toggle = menu.querySelector("[data-menu-collapse]");
    const rail = window.matchMedia?.(RAIL);
    const inRail = () => rail?.matches === true;
    // A tap leaves :hover stuck on touch screens, so only a real hover counts (as in app.css).
    const canHover = window.matchMedia?.("(hover: hover)");

    const isOpen = () => {
        const peek = menu.dataset.peek;

        if (peek === "true") return true;
        if (peek === "false") return false;

        return (canHover?.matches === true && matches(menu, ":hover")) || matches(menu, ":has(:focus-visible)");
    };

    const render = () => {
        if (!toggle) return;

        const narrow = inRail() ? !isOpen() : menu.dataset.collapsed === "true";
        const label = narrow ? "Expand the menu" : "Collapse the menu";

        toggle.setAttribute("aria-pressed", String(narrow));
        toggle.setAttribute("aria-label", label);
        toggle.setAttribute("title", label);
    };

    const setPeek = (value) => {
        if (value === null) menu.removeAttribute("data-peek");
        else menu.setAttribute("data-peek", value);
        render();
    };

    // The closed state only has to outlast the hover or focus that would reopen it. Either way the
    // hover or focus may have opened or closed the rail, so the toggle's label follows.
    const clearClosed = () => {
        if (menu.dataset.peek === "false") setPeek(null);
        else render();
    };

    toggle?.addEventListener("click", () => {
        if (inRail()) {
            setPeek(isOpen() ? "false" : "true");
            return;
        }

        const collapsed = menu.dataset.collapsed !== "true";

        menu.setAttribute("data-collapsed", String(collapsed));

        try {
            localStorage.setItem(STORAGE_KEY, collapsed ? "collapsed" : "expanded");
        } catch {
            // Storage blocked: the choice lasts until the page changes.
        }

        render();
    });

    // Focus stays where it is: the "false" state keeps the rail closed around it.
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && inRail() && (isOpen() || menu.contains(document.activeElement))) setPeek("false");
    });

    document.addEventListener("click", (event) => {
        if (menu.dataset.peek === "true" && !menu.contains(event.target)) setPeek("false");
    });

    menu.addEventListener("pointerenter", clearClosed);
    menu.addEventListener("pointerleave", clearClosed);
    menu.addEventListener("focusin", render);
    menu.addEventListener("focusout", (event) => {
        if (!menu.contains(event.relatedTarget)) clearClosed();
    });

    rail?.addEventListener?.("change", () => setPeek(null));

    render();
}

document.querySelectorAll("[data-institution-menu], [data-admin-menu]").forEach(bindSideMenu);
