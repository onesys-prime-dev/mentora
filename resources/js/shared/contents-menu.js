// The module outline (modules/partials/learning-item-panel.php) is a sidebar from lg up and a "Contents" dropdown
// below: its <details data-contents> is opened to match the width, so the sidebar shows in every browser (the
// ::details-content rule in app.css only covers newer ones) and shrinking the window closes the dropdown.
const wide = globalThis.matchMedia?.("(width >= 64rem)");

/** Opens every Contents <details> from lg up and closes it below; call after the outline is swapped in. */
export function syncContents() {
    if (!wide) return;

    document.querySelectorAll("details[data-contents]").forEach((details) => {
        details.open = wide.matches;
    });
}

if (wide) {
    wide.addEventListener("change", syncContents);
    document.addEventListener("content:swapped", syncContents);
    syncContents();
}
