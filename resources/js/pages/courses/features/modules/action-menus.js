/*
|--------------------------------------------------------------------------
| Shared ⋮ action-menu open/close
|--------------------------------------------------------------------------
|
| Module, learning-unit and learning-item each have their own ⋮ dropdown.
| Previously each ran its own independent close-on-outside-click logic, and
| each trigger's stopPropagation() blocked the *other two* systems from
| ever seeing the click — opening one menu wouldn't close another already
| open. One shared panel attribute + one document listener fixes that.
|
| A "floating" menu (the three above) is not drawn inside its row. A row sits in
| cards that clip what overflows them (a menu opened on the last item was cut off
| by the card, or hidden under the next row), so while it is open the panel is
| moved to the page itself and placed from the button: under it when there is room,
| over it when there is not, kept inside the screen, and put back when it closes.
| It follows the button if the page scrolls or the window changes size.
|
*/

let documentListenerBound = false;

const GAP = 6;
const MARGIN = 8;

// Floating panels that are open: panel -> what it needs to go back where it was.
const floating = new Map();

/**
 * Where a menu goes, from the button and the room around it. Under the button
 * when it fits, otherwise over it, otherwise on the side with more room (and the
 * menu then scrolls inside). Right edges line up, and it never leaves the screen.
 *
 * @param {{top: number, bottom: number, left: number, right: number}} anchor the button's box
 * @param {{width: number, height: number}} size the menu's own size
 * @param {{width: number, height: number}} viewport the visible screen
 * @returns {{top: number, left: number, placement: "below"|"above", maxHeight: number|null}}
 */
export function computeMenuPosition(anchor, size, viewport, { gap = GAP, margin = MARGIN } = {}) {

    const roomBelow = viewport.height - anchor.bottom - gap - margin;
    const roomAbove = anchor.top - gap - margin;

    const placement = size.height <= roomBelow || roomBelow >= roomAbove ? "below" : "above";
    const room = placement === "below" ? roomBelow : roomAbove;
    const height = Math.min(size.height, Math.max(room, 0));

    const top = placement === "below"
        ? anchor.bottom + gap
        : anchor.top - gap - height;

    const left = Math.min(
        Math.max(anchor.right - size.width, margin),
        Math.max(viewport.width - size.width - margin, margin)
    );

    return {
        top: Math.max(top, margin),
        left,
        placement,
        maxHeight: height < size.height ? Math.max(room, 0) : null,
    };

}

/**
 * @param {string} triggerSelector e.g. "[data-module-actions]"
 * @param {string} panelSelector   e.g. "[data-module-actions-panel]"
 * @param {{floating?: boolean}} [options] floating: draw the panel over the page, placed from the button
 */
export function bindActionMenu(triggerSelector, panelSelector, { floating: floats = false } = {}) {

    document.querySelectorAll(triggerSelector).forEach((button) => {

        // Re-binding after an incremental DOM append (e.g. infinite scroll)
        // must skip triggers already wired, or they'd get a second listener.
        if (button.dataset.actionMenuBound === "1") {
            return;
        }

        const panel =
            button.closest(".relative")?.querySelector(panelSelector);

        if (!panel) {
            return;
        }

        button.dataset.actionMenuBound = "1";

        button.addEventListener("click", (event) => {

            event.preventDefault();
            event.stopPropagation();

            const wasHidden = panel.classList.contains("hidden");

            closeAllMenus();

            if (wasHidden) {

                if (floats) {
                    openFloating(button, panel);
                } else {
                    panel.classList.remove("hidden");
                }

            }

        });

        // Picking an entry closes the menu (the entry's own handler still
        // runs — this listener doesn't stop propagation).
        panel.addEventListener("click", () => {
            closeMenu(panel);
        });

    });

    if (!documentListenerBound) {

        document.addEventListener("click", () => closeAllMenus());

        document.addEventListener("keydown", (event) => {

            if (event.key === "Escape" && floating.size > 0) {

                const [{ button }] = floating.values();

                closeAllMenus();
                button.focus();

            }

        });

        // The page moved under an open menu: keep it on its button.
        const follow = () => floating.forEach(({ button }, panel) => place(button, panel));

        window.addEventListener("scroll", follow, { capture: true, passive: true });
        window.addEventListener("resize", follow);

        documentListenerBound = true;

    }

}

export function closeAllActionMenus() {
    closeAllMenus();
}

function closeAllMenus() {

    document
        .querySelectorAll("[data-action-menu-panel]")
        .forEach((panel) => closeMenu(panel));

}

function closeMenu(panel) {

    panel.classList.add("hidden");

    const open = floating.get(panel);

    if (!open) {
        return;
    }

    // Back into its row, with nothing of the placement left on it. If the row is gone (the list was
    // redrawn while the menu was open) there is nowhere to put it back, and it goes too.
    if (open.marker.isConnected) {
        open.marker.replaceWith(panel);
    } else {
        panel.remove();
    }

    panel.removeAttribute("style");
    delete panel.dataset.placement;
    open.button.setAttribute("aria-expanded", "false");

    floating.delete(panel);

}

function openFloating(button, panel) {

    const marker = document.createComment("action-menu");

    panel.replaceWith(marker);
    document.body.append(panel);

    floating.set(panel, { button, marker });

    button.setAttribute("aria-expanded", "true");

    // Measured while it is on the page but not yet seen.
    panel.style.position = "fixed";
    panel.style.visibility = "hidden";
    panel.classList.remove("hidden");

    place(button, panel);

    panel.style.visibility = "";

}

function place(button, panel) {

    // Its natural size, not the one a previous placement squeezed it to.
    panel.style.maxHeight = "";
    panel.style.overflowY = "";

    const box = button.getBoundingClientRect();

    const spot = computeMenuPosition(
        { top: box.top, bottom: box.bottom, left: box.left, right: box.right },
        { width: panel.offsetWidth, height: panel.offsetHeight },
        { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight }
    );

    panel.style.position = "fixed";
    panel.style.top = `${spot.top}px`;
    panel.style.left = `${spot.left}px`;
    panel.style.right = "auto";
    panel.style.bottom = "auto";
    panel.style.zIndex = "60";

    if (spot.maxHeight !== null) {
        panel.style.maxHeight = `${spot.maxHeight}px`;
        panel.style.overflowY = "auto";
    }

    panel.dataset.placement = spot.placement;

}
