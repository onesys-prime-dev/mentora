/**
 * A draggable divider between the two panes of a split screen (lg and up).
 *
 * Markup contract:
 *   <div data-split data-split-key="grade-work-students" data-split-min="16rem" data-split-max="28rem">
 *       <div data-split-start>...</div>
 *       <div data-split-handle role="separator" aria-orientation="vertical" tabindex="0"></div>
 *       <div data-split-end>...</div>
 *   </div>
 *
 * The start pane's width lives in the CSS variable --split-start on the [data-split] element (the CSS reads it
 * from lg up; below lg the handle is hidden). Drag, Arrow keys (16px), Home/End and double click (reset to
 * data-split-default, else the middle) move it, clamped to min/max (rem or px). The width is kept in localStorage["split:" + key].
 * bindSplitDivider() runs on import and again after content:swapped.
 */
const STEP = 16;

const toPx = (value, fallback) => {
    const n = parseFloat(value);
    if (Number.isNaN(n)) return fallback;
    const root = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
    return String(value).trim().endsWith("rem") ? n * root : n;
};

const bounds = (split) => {
    const min = toPx(split.dataset.splitMin, 256);
    return [min, Math.max(min, toPx(split.dataset.splitMax, 448))];
};

const storageKey = (split) => (split.dataset.splitKey ? "split:" + split.dataset.splitKey : null);

const current = (split) => parseFloat(split.style.getPropertyValue("--split-start"));

function setWidth(split, px, save = false) {
    const [min, max] = bounds(split);
    const width = Math.round(Math.min(max, Math.max(min, px)));
    const handle = split.querySelector("[data-split-handle]");

    split.style.setProperty("--split-start", width + "px");
    handle?.setAttribute("aria-valuenow", String(width));
    handle?.setAttribute("aria-valuemin", String(Math.round(min)));
    handle?.setAttribute("aria-valuemax", String(Math.round(max)));
    if (save && storageKey(split)) {
        try {
            localStorage.setItem(storageKey(split), String(width));
        } catch {
            // Storage blocked: the width still applies for this visit.
        }
    }
}

const reset = (split) => {
    const [min, max] = bounds(split);
    setWidth(split, toPx(split.dataset.splitDefault, (min + max) / 2));
    try {
        if (storageKey(split)) localStorage.removeItem(storageKey(split));
    } catch {
        // Nothing saved to remove.
    }
};

export function bindSplitDivider(root = document) {
    root.querySelectorAll("[data-split]").forEach((split) => {
        let saved = NaN;
        try {
            if (storageKey(split)) saved = parseFloat(localStorage.getItem(storageKey(split)));
        } catch {
            // Storage blocked: use the default width.
        }
        if (Number.isNaN(saved)) reset(split);
        else setWidth(split, saved);
    });
}

let drag = null;

document.addEventListener("pointerdown", (event) => {
    const handle = event.target.closest?.("[data-split-handle]");
    const split = handle?.closest("[data-split]");
    if (!split || (event.button ?? 0) !== 0) return;

    drag = { split, x: event.clientX, width: current(split) };
    event.preventDefault();
    handle.focus();
    handle.setPointerCapture?.(event.pointerId);
});

document.addEventListener("pointermove", (event) => {
    if (!drag) return;
    drag.moved ||= event.clientX !== drag.x;
    setWidth(drag.split, drag.width + event.clientX - drag.x);
});

const endDrag = () => {
    // A plain click (no movement) leaves the saved width alone.
    if (drag?.moved) setWidth(drag.split, current(drag.split), true);
    drag = null;
};
document.addEventListener("pointerup", endDrag);
document.addEventListener("pointercancel", endDrag);

document.addEventListener("keydown", (event) => {
    const handle = event.target.closest?.("[data-split-handle]");
    const split = handle?.closest("[data-split]");
    if (!split) return;

    const [min, max] = bounds(split);
    const target = { ArrowLeft: current(split) - STEP, ArrowRight: current(split) + STEP, Home: min, End: max }[event.key];
    if (target === undefined) return;

    event.preventDefault();
    setWidth(split, target, true);
});

document.addEventListener("dblclick", (event) => {
    const split = event.target.closest?.("[data-split-handle]")?.closest("[data-split]");
    if (split) reset(split);
});

document.addEventListener("content:swapped", (event) => bindSplitDivider(event.detail?.root ?? document));

bindSplitDivider();
