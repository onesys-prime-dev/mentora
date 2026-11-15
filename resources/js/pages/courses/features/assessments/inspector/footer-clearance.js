/*
|--------------------------------------------------------------------------
| Keep a focused field clear of the editor's pinned footer (below lg)
|--------------------------------------------------------------------------
| Below lg the assessment editor's and the learning item editor's Save as Draft /
| Publish footer is pinned to the bottom edge. The page's scroll-mb keeps a focused field clear of it in
| Chromium; WebKit (iPhone) ignores scroll-margin on a tap, so this scrolls the
| overlap away. It measures the caret, not the whole field, so a tap into long
| instructions (one tall contenteditable) or a tall textarea never throws the
| tapped line off the top of the window.
*/

const FIELD = "input, textarea, select, [contenteditable]:not([contenteditable='false'])";

// The editors whose footer is pinned: the assessment editor and the learning item editor.
const EDITOR = "#editor-inspector-panel, [data-learning-item-editor]";

// Bound once per document: the assessments and the modules bundles both bind it, and two listeners would scroll twice.
const bound = new WeakSet();

// Fields this leaves alone: in a dialog, in the open md–lg side panel (it lies over the footer), or the footer's own.
const SKIP = "dialog, [role='dialog'], [aria-modal='true'], [data-side-panel][data-open], footer";

/**
 * How far to scroll down so `rect` ends 8px above the footer, but never so far that its top goes under `top`
 * (the pinned header's bottom). Never negative.
 */
export function clearance(rect, footerTop, top = 0) {
    return Math.max(0, Math.min(rect.bottom - footerTop + 8, rect.top - top));
}

/** The caret's box in a contenteditable (else the line's block, else the field); a form field's own box. */
export function caretRect(field) {
    const selection = field.isContentEditable ? globalThis.getSelection?.() : null;

    if (selection?.rangeCount && field.contains(selection.anchorNode)) {
        const range = selection.getRangeAt(0);
        const rect = range.getClientRects()[0] ?? range.getBoundingClientRect();

        if (rect && rect.height > 0) {
            return rect;
        }

        // A caret in an empty line has no box of its own: use the line's block.
        const node = selection.anchorNode;
        const block = node.nodeType === 1 ? node : node.parentElement;

        if (block && block !== field && field.contains(block)) {
            return block.getBoundingClientRect();
        }
    }

    return field.getBoundingClientRect();
}

/**
 * Binds once on the document. `belowLg` is the media query it acts under; `frame` runs the check after the tap
 * has placed the caret (and after the browser's own scroll).
 */
export function bindFooterClearance({
    doc = document,
    belowLg = globalThis.matchMedia?.("(width < 64rem)"),
    frame = (callback) => (globalThis.requestAnimationFrame ?? setTimeout)(callback),
} = {}) {
    if (bound.has(doc)) {
        return;
    }

    bound.add(doc);

    doc.addEventListener("focusin", (event) => {
        const field = event.target;

        if (!belowLg?.matches || !field.matches?.(FIELD) || field.closest(SKIP)) {
            return;
        }

        const footer = doc.querySelector(EDITOR) && doc.querySelector("[data-publish]")?.closest("footer");

        if (!footer) {
            return;
        }

        frame(() => {
            const header = doc.querySelector("[data-app-header]")?.getBoundingClientRect().bottom ?? 0;
            const by = clearance(caretRect(field), footer.getBoundingClientRect().top, Math.max(0, header));

            if (by > 0) {
                doc.defaultView.scrollBy(0, by);
            }
        });
    });
}
