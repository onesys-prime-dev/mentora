/*
|--------------------------------------------------------------------------
| Learning Item Action Menus
|--------------------------------------------------------------------------
|
| Opens and closes the ⋮ dropdown on each learning item row (Edit,
| Dependencies). Mirrors learning-unit-actions.js.
|
*/

let documentListenerBound = false;

export function bindLearningItemActionMenus() {

    document
        .querySelectorAll("[data-learning-item-actions]")
        .forEach((button) => {

            const panel =
                button
                    .closest(".relative")
                    ?.querySelector("[data-learning-item-actions-panel]");

            if (!panel) {
                return;
            }

            button.addEventListener("click", (event) => {

                event.preventDefault();

                event.stopPropagation();

                closeAllMenus(panel);

                panel.classList.toggle("hidden");

            });

            // Picking an entry closes the menu (the entry's own handler
            // still runs — this listener doesn't stop propagation).
            panel.addEventListener("click", () => {

                panel.classList.add("hidden");

            });

        });

    if (!documentListenerBound) {

        document.addEventListener("click", () => closeAllMenus());

        documentListenerBound = true;

    }

}

function closeAllMenus(except = null) {

    document
        .querySelectorAll("[data-learning-item-actions-panel]")
        .forEach((panel) => {

            if (panel !== except) {
                panel.classList.add("hidden");
            }

        });

}
