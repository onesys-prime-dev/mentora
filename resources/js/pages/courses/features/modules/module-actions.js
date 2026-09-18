/*
|--------------------------------------------------------------------------
| Module Action Menus
|--------------------------------------------------------------------------
|
| Handles opening and closing the module action dropdown.
|
*/

export function bindModuleActionMenus() {

    const buttons =
        document.querySelectorAll(
            "[data-module-actions]"
        );

    if (!buttons.length) {
        return;
    }

    buttons.forEach((button) => {

        const wrapper =
            button.closest(".relative");

        const panel =
            wrapper?.querySelector(
                "[data-module-actions-panel]"
            );

        if (!panel) {
            return;
        }

        button.addEventListener(
            "click",
            (event) => {

                event.preventDefault();

                event.stopPropagation();

                closeAllMenus(panel);

                panel.classList.toggle(
                    "hidden"
                );

            }
        );

    });

    document.addEventListener(
        "click",
        () => {

            closeAllMenus();

        }
    );

}

function closeAllMenus(
    except = null
) {

    document
        .querySelectorAll(
            "[data-module-actions-panel]"
        )
        .forEach((panel) => {

            if (panel === except) {
                return;
            }

            panel.classList.add(
                "hidden"
            );

        });

}