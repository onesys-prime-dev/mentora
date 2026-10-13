/*
|--------------------------------------------------------------------------
| Resource row/card bindings: the ⋮ menu and "Share link"
|--------------------------------------------------------------------------
*/

import { bindActionMenu } from "../modules/action-menus.js";

export function bindCards(root) {

    bindActionMenu("[data-resource-menu-toggle]", "[data-resource-menu-panel]");

    root.querySelectorAll("[data-resource-share]").forEach((button) => {

        button.addEventListener("click", async () => {

            // The link is just this same page, scoped to the current unit and
            // pointing at this resource — access is re-verified server-side on
            // every load the same way any other page request is, so sharing it
            // with someone in a different offering is denied the same way.
            const url = new URL(window.location.href);
            url.hash = `resource-${button.dataset.resourceId}`;

            try {
                await navigator.clipboard.writeText(url.toString());
                flashCopied(button);
            } catch (error) {
                console.error(error);
                alert(url.toString());
            }

        });

    });

}

function flashCopied(button) {

    const original = button.textContent;

    button.textContent = "Link copied!";
    button.disabled = true;

    setTimeout(() => {
        button.textContent = original;
        button.disabled = false;
    }, 1500);

}

/** Scrolls to and briefly highlights a resource named in the URL's #resource-{id} hash. */
export function scrollToHashedResource(root) {

    const hash = window.location.hash;

    if (!hash.startsWith("#resource-")) {
        return;
    }

    const target = root.querySelector(`[data-resource-id="${hash.slice("#resource-".length)}"]`);

    if (!target) {
        return;
    }

    target.scrollIntoView({ behavior: "smooth", block: "center" });

    target.classList.add("ring-2", "ring-brand/40");

    setTimeout(() => target.classList.remove("ring-2", "ring-brand/40"), 2000);

}
