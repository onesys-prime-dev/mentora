/*
|--------------------------------------------------------------------------
| Generic Modal Controller
|--------------------------------------------------------------------------
|
| Reusable helper for opening and closing modals.
|
*/

export function bindModal({
    modal,
    openButton = null,
    closeButtons = "[data-close-modal]",
}) {

    const element =
        document.querySelector(modal);

    if (!element) {
        return null;
    }

    function open() {

        element.classList.remove("hidden");
        element.classList.add("flex");

        document.body.classList.add(
            "overflow-hidden"
        );

    }

    function close() {

        element.classList.remove("flex");
        element.classList.add("hidden");

        document.body.classList.remove(
            "overflow-hidden"
        );

    }

    if (openButton) {

        document
            .querySelector(openButton)
            ?.addEventListener(
                "click",
                open
            );

    }

    element
        .querySelectorAll(closeButtons)
        .forEach((button) => {

            button.addEventListener(
                "click",
                close
            );

        });

    element.addEventListener(
        "click",
        (event) => {

            if (event.target === element) {

                close();

            }

        }
    );

    document.addEventListener(
        "keydown",
        (event) => {

            if (
                event.key === "Escape" &&
                !element.classList.contains("hidden")
            ) {

                close();

            }

        }
    );

    return {

        element,

        open,

        close,

        isOpen() {

            return !element.classList.contains(
                "hidden"
            );

        },

    };

}