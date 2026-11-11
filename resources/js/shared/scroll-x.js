/**
 * The pinned horizontal scroller for grid tables (permission grid now, gradebooks later).
 *
 * Markup contract:
 *   <div data-scroll-x tabindex="0" role="region" aria-label="…"><table>…</table></div>   (the three attributes belong on a table scroller; a tab strip inside a labelled nav omits them)
 *   data-sticky-first   optional: the first column is pinned (its cells carry sticky left-0 and an opaque bg)
 *   data-scroll-current optional: on init, centre the [aria-current="page"] inside (a tab strip; components/tabs sets it)
 *
 * Sets data-fade-start / data-fade-end on the scroller while there is more content before / after the
 * visible part; app.css turns them into edge fades. Re-measures on scroll and on size changes, and is
 * safe to call again for the same root or after SPA swaps (each scroller is bound once).
 */

const bound = new WeakSet();

function measure(el) {
    const max = el.scrollWidth - el.clientWidth;

    // A sub-pixel tolerance: fractional scrollLeft never quite reaches max.
    el.toggleAttribute("data-fade-start", el.scrollLeft > 1);
    el.toggleAttribute("data-fade-end", max > 1 && el.scrollLeft < max - 1);
}

/** Wires every [data-scroll-x] under `root`. */
export function bindScrollX(root = document) {
    for (const el of root.querySelectorAll("[data-scroll-x]")) {
        if (bound.has(el)) {
            continue;
        }

        bound.add(el);
        el.addEventListener("scroll", () => measure(el), { passive: true });

        if (typeof ResizeObserver !== "undefined") {
            const observer = new ResizeObserver(() => measure(el));

            observer.observe(el);

            if (el.firstElementChild) {
                observer.observe(el.firstElementChild);
            }
        }

        measure(el);

        // A tab strip (data-scroll-current, set by components/tabs) opens on its current tab; other scrollers never jump.
        // Computed on the scroller itself so the page never moves (scrollIntoView would scroll ancestors too),
        // from bounding boxes because offsetLeft is against the offset parent, not the scroller.
        const current = el.hasAttribute("data-scroll-current") ? el.querySelector('[aria-current="page"]') : null;

        if (current) {
            const box = el.getBoundingClientRect();
            const cur = current.getBoundingClientRect();
            const left = cur.left - box.left + el.scrollLeft;
            const max = Math.max(0, el.scrollWidth - el.clientWidth);
            el.scrollLeft = Math.min(max, Math.max(0, left - (el.clientWidth - cur.width) / 2));
        }
    }
}

if (typeof document !== "undefined") {
    bindScrollX(document);
    // shared/auto-search.js swaps tables in and says so.
    document.addEventListener("content:swapped", () => bindScrollX(document));
}
