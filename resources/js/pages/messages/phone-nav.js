/*
|--------------------------------------------------------------------------
| Messages — phone navigation (below md)
|--------------------------------------------------------------------------
| The list and the thread are one screen at a time; #messages-app[data-view]
| says which (app.css slides the thread over the list). This module makes
| the hidden pane inert, gives the thread a history entry so the browser's
| Back returns to the list, and turns a rightward swipe on the thread into
| Back. A deep link (/messages/{id}) on a phone becomes two entries, the list
| (/messages) under the thread, so Back from it lands on the list too.
|--------------------------------------------------------------------------
*/

const FIELD = "input, textarea, select, [contenteditable]:not([contenteditable=\"false\"])";
const SWIPE_PX = 80;
const SWIPE_MS = 600;

const phoneQuery = () => globalThis.matchMedia?.("(max-width: 47.999rem)").matches ?? false;

/** Is `el`, or anything between it and `stop`, scrolled sideways (where a swipe belongs to the scroller)? */
function inSideScroller(el, stop) {
    for (let node = el; node && node !== stop; node = node.parentElement) {
        if (node.hasAttribute("data-scroll-x")) {
            return true;
        }
        if (node.scrollWidth > node.clientWidth && /auto|scroll/.test(getComputedStyle(node).overflowX)) {
            return true;
        }
    }

    return false;
}

export function createPhoneNav({ root, isPhone = phoneQuery, onBack }) {
    const threadPane = root.querySelector("[data-pane=thread]");
    let pushed = false;
    let lastId = null;
    let start = null;

    function sync() {
        const phone = isPhone();

        root.querySelectorAll("[data-pane]").forEach((pane) => {
            const hide = phone && pane.dataset.pane !== root.dataset.view;

            pane.toggleAttribute("inert", hide);
            if (hide) {
                pane.setAttribute("aria-hidden", "true");
            } else {
                pane.removeAttribute("aria-hidden");
            }
        });
    }

    function opened(id, { deepLink = false } = {}) {
        lastId = id;
        sync();

        if (!isPhone()) {
            return;
        }

        // Switching threads without going back replaces the entry, so one Back always reaches the list.
        if (pushed) {
            history.replaceState({ messagesThread: id }, "");
        } else if (deepLink) {
            const here = location.href;
            const list = new URL(here);

            list.pathname = list.pathname.replace(new RegExp(`/${id}/?$`), "");
            history.replaceState(null, "", list.href);
            history.pushState({ messagesThread: id }, "", here);
            pushed = true;
            // Arriving on the thread is not a push: no slide on load.
            root.setAttribute("data-instant", "");
            requestAnimationFrame(() => requestAnimationFrame(() => root.removeAttribute("data-instant")));
        } else {
            history.pushState({ messagesThread: id }, "");
            pushed = true;
        }

        // The list (and the row that had focus) is inert now.
        root.querySelector("[data-role=back]")?.focus({ preventScroll: true });
    }

    function closed() {
        sync();

        if (pushed) {
            pushed = false;
            history.back();
        }

        if (isPhone() && lastId !== null) {
            root.querySelector(`[data-role=rows] button[data-id="${lastId}"]`)?.focus({ preventScroll: true });
        }
    }

    const onPop = () => {
        if (!pushed) {
            return;
        }
        pushed = false;
        onBack();
    };

    const onTouchStart = (event) => {
        const t = event.touches?.[0];

        start = null;
        if (!t || !isPhone() || root.dataset.view !== "thread" || event.target.closest?.(FIELD) || inSideScroller(event.target, threadPane)) {
            return;
        }
        start = { x: t.clientX, y: t.clientY, at: event.timeStamp };
    };

    const onTouchEnd = (event) => {
        const t = event.changedTouches?.[0];
        const from = start;

        start = null;
        if (!from || !t || root.dataset.view !== "thread") {
            return;
        }

        const dx = t.clientX - from.x;
        const dy = Math.abs(t.clientY - from.y);

        if (dx >= SWIPE_PX && dy < dx / 2 && event.timeStamp - from.at <= SWIPE_MS) {
            onBack();
        }
    };

    const onCancel = () => { start = null; };

    window.addEventListener("popstate", onPop);
    window.addEventListener("resize", sync);
    threadPane?.addEventListener("touchstart", onTouchStart, { passive: true });
    threadPane?.addEventListener("touchend", onTouchEnd, { passive: true });
    threadPane?.addEventListener("touchcancel", onCancel, { passive: true });
    sync();

    return {
        opened,
        closed,
        destroy() {
            window.removeEventListener("popstate", onPop);
            window.removeEventListener("resize", sync);
            threadPane?.removeEventListener("touchstart", onTouchStart);
            threadPane?.removeEventListener("touchend", onTouchEnd);
            threadPane?.removeEventListener("touchcancel", onCancel);
        },
    };
}
