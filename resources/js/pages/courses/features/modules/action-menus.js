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
*/

let documentListenerBound = false;

/**
 * @param {string} triggerSelector e.g. "[data-module-actions]"
 * @param {string} panelSelector   e.g. "[data-module-actions-panel]"
 */
export function bindActionMenu(triggerSelector, panelSelector) {

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
                panel.classList.remove("hidden");
            }

        });

        // Picking an entry closes the menu (the entry's own handler still
        // runs — this listener doesn't stop propagation).
        panel.addEventListener("click", () => {
            panel.classList.add("hidden");
        });

    });

    if (!documentListenerBound) {

        document.addEventListener("click", () => closeAllMenus());

        documentListenerBound = true;

    }

}

export function closeAllActionMenus() {
    closeAllMenus();
}

function closeAllMenus() {

    document
        .querySelectorAll("[data-action-menu-panel]")
        .forEach((panel) => panel.classList.add("hidden"));

}
