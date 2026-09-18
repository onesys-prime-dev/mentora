/*
|--------------------------------------------------------------------------
| Learning Unit Action Menus
|--------------------------------------------------------------------------
|
| Handles opening and closing the learning unit action dropdown.
|
*/

export function bindLearningUnitActionMenus() {

    const buttons =
        document.querySelectorAll(
            "[data-learning-unit-actions]"
        );

    if (!buttons.length) {
        return;
    }

    buttons.forEach((button) => {

        const wrapper =
            button.closest(".relative");

        const panel =
            wrapper?.querySelector(
                "[data-learning-unit-actions-panel]"
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
            "[data-learning-unit-actions-panel]"
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