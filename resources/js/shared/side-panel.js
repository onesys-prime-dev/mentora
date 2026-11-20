/**
 * The secondary pane of a split screen (students list, palette, inspector) as a slide-over panel below lg.
 *
 * Markup contract:
 *   <button data-side-panel-toggle aria-controls="p" aria-expanded="false" class="lg:hidden">
 *   <aside id="p" data-side-panel> ... <button data-side-panel-close> ... </aside>
 *   Add data-side-panel="end" to slide in from the right instead of the left.
 *   Add data-side-panel-stack to keep it an ordinary in-flow pane below md too (the editor settings stack under
 *   the content on a phone), so it is a slide-over at md–lg only.
 *
 * It is NOT modal: nothing is trapped, but Escape and a tap on the backdrop (made here, just before the aside)
 * close it and focus returns to the toggle. A closed panel below lg is inert and aria-hidden. From lg up the
 * aside is an ordinary pane: always closed state, never inert (the CSS drops the transform and the backdrop).
 * bindSidePanels() runs on import and again after content:swapped.
 */
const wide = globalThis.matchMedia?.("(width >= 64rem)");
const narrow = globalThis.matchMedia?.("(width < 48rem)");

const toggleOf = (panel) => document.querySelector(`[data-side-panel-toggle][aria-controls="${panel.id}"]`);

function sync(panel) {
    const pane = wide?.matches || (narrow?.matches && panel.hasAttribute("data-side-panel-stack"));
    if (pane) panel.removeAttribute("data-open");
    const open = panel.hasAttribute("data-open");
    const inert = !open && !pane;

    panel.inert = inert;
    if (inert) panel.setAttribute("aria-hidden", "true");
    else panel.removeAttribute("aria-hidden");
    panel.previousElementSibling?.toggleAttribute("data-open", open);
    toggleOf(panel)?.setAttribute("aria-expanded", String(open));
}

function setOpen(panel, open, returnFocus = false) {
    panel.toggleAttribute("data-open", open);
    sync(panel);
    if (!open && returnFocus) toggleOf(panel)?.focus();
    if (open) {
        if (!panel.hasAttribute("tabindex")) panel.tabIndex = -1;
        (panel.querySelector("a[href], button, input, select, textarea, [tabindex]:not([tabindex='-1'])") ?? panel).focus();
    }
}

export function bindSidePanels(root = document) {
    root.querySelectorAll("[data-side-panel]").forEach((panel) => {
        if (!panel.previousElementSibling?.matches("[data-side-panel-backdrop]")) {
            const backdrop = document.createElement("div");
            backdrop.setAttribute("data-side-panel-backdrop", "");
            panel.before(backdrop);
        }
        sync(panel);
    });
}

document.addEventListener("click", (event) => {
    const target = event.target;
    const toggle = target.closest?.("[data-side-panel-toggle]");
    if (toggle) {
        const panel = document.getElementById(toggle.getAttribute("aria-controls"));
        if (panel) setOpen(panel, !panel.hasAttribute("data-open"));
        return;
    }

    const close = target.closest?.("[data-side-panel-close]");
    if (close) {
        const panel = close.closest("[data-side-panel]");
        if (panel) setOpen(panel, false, true);
        return;
    }

    if (target.matches?.("[data-side-panel-backdrop]")) {
        const panel = target.nextElementSibling;
        if (panel?.matches("[data-side-panel]")) setOpen(panel, false, true);
    }
});

document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape" || event.defaultPrevented) return;
    // A dialog opened from inside the panel takes this Escape for itself; the panel behind it stays open.
    const modal = '[aria-modal="true"], [role="dialog"], dialog';
    const shown = (el) => (el.checkVisibility ? el.checkVisibility({ visibilityProperty: true }) : el.getClientRects().length > 0);
    const openModal = [...document.querySelectorAll(modal)].some((el) => !el.closest("[data-side-panel]") && shown(el));
    if (openModal || event.target.closest?.(modal)) return;
    document.querySelectorAll("[data-side-panel][data-open]").forEach((panel) => setOpen(panel, false, true));
});

for (const query of [wide, narrow]) {
    query?.addEventListener("change", () => document.querySelectorAll("[data-side-panel]").forEach(sync));
}
document.addEventListener("content:swapped", (event) => bindSidePanels(event.detail?.root ?? document));

bindSidePanels();
