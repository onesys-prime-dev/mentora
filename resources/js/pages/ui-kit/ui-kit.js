// The component gallery: the buttons that show toasts. See UiKitController.
import { showToast } from "../../shared/toast.js";

const messages = {
    success: "Ann Lee was invited.",
    danger: "The import could not be read. Nothing was changed.",
};

for (const button of document.querySelectorAll("[data-kit-toast]")) {
    button.addEventListener("click", () => {
        const tone = button.dataset.kitToast;

        showToast(messages[tone] ?? "Done.", { tone });
    });
}
