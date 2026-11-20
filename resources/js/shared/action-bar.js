/**
 * The expandable sticky action bar (Grade Work score bar, Quiz Builder
 * "Add question" bar on phones).
 *
 * Markup contract:
 *   <div data-action-bar class="sticky bottom-0 ...">
 *       <div id="p" data-action-bar-panel> ... </div>        expands upward
 *       <button data-action-bar-toggle aria-controls="p" aria-expanded="false">
 *   </div>
 *
 * The panel animates via grid rows, so give it ONE child wrapping the content
 * (that child scrolls past min(60dvh, 28rem)).
 *
 * The toggle flips aria-expanded and the bar's data-open (the CSS animates the
 * panel on it). Escape closes the bar and puts focus back on the toggle. Opening
 * one bar closes any other. A collapsed panel is inert, so Tab skips it.
 * bindActionBars() runs on import; call it again after a page swap.
 */
const setOpen = (bar, open) => {
    bar.toggleAttribute("data-open", open);
    bar.querySelector("[data-action-bar-toggle]")?.setAttribute("aria-expanded", String(open));
    const panel = bar.querySelector("[data-action-bar-panel]");
    if (panel) panel.inert = !open;
};

export function bindActionBars(root = document) {
    root.querySelectorAll("[data-action-bar]").forEach((bar) => setOpen(bar, bar.hasAttribute("data-open")));
}

document.addEventListener("click", (event) => {
    const toggle = event.target.closest?.("[data-action-bar-toggle]");
    if (!toggle) return;

    const bar = toggle.closest("[data-action-bar]");
    const open = !bar.hasAttribute("data-open");
    document.querySelectorAll("[data-action-bar][data-open]").forEach((other) => setOpen(other, false));
    setOpen(bar, open);
});

document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") return;

    document.querySelectorAll("[data-action-bar][data-open]").forEach((bar) => {
        setOpen(bar, false);
        bar.querySelector("[data-action-bar-toggle]")?.focus();
    });
});

bindActionBars();
