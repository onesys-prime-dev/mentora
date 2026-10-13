/*
|--------------------------------------------------------------------------
| Scroll-to-and-briefly-highlight a row
|--------------------------------------------------------------------------
|
| Shared by every "jump to this specific row on another screen" link: Grade
| Work -> Gradebook roster, and dashboard widgets -> roster / Modules entries.
| One implementation so the highlight treatment stays consistent everywhere.
|
*/

export function highlightRow(selector, {
    highlightClasses = ["ring-2", "ring-brand"],
    durationMs = 1500,
} = {}) {

    const row = document.querySelector(selector);

    if (!row) {
        return null;
    }

    row.scrollIntoView({ behavior: "smooth", block: "center" });

    row.classList.add(...highlightClasses);

    setTimeout(() => row.classList.remove(...highlightClasses), durationMs);

    return row;

}
