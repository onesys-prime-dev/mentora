/*
|--------------------------------------------------------------------------
| Messages — menu gestures
|--------------------------------------------------------------------------
| Turns right-click, long-press and the keyboard (ContextMenu, Shift+F10)
| into one callback: onOpen(el, { x, y, trigger }). The native menu is only
| suppressed over a matching element and never inside a text field.
|--------------------------------------------------------------------------
*/

const FIELD = "input, textarea, [contenteditable]:not([contenteditable=\"false\"])";
const SLOP = 10;

export function bindMenuTriggers(container, { selector, onOpen, longPressMs = 500 }) {
    let timer = null;
    let swallowClick = false;
    let swallowTimer = null;
    let recentOpen = false;
    let recentTimer = null;
    let startX = 0;
    let startY = 0;

    const match = (target) => (target?.closest ? target.closest(selector) : null);
    const inField = (target) => Boolean(target?.closest?.(FIELD));
    const clear = () => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const onContext = (event) => {
        const el = match(event.target);

        if (!el || inField(event.target) || !container.contains(el)) {
            return;
        }

        event.preventDefault();
        if (recentOpen) {
            return;
        }
        onOpen(el, { x: event.clientX, y: event.clientY, trigger: el });
    };

    const onTouchStart = (event) => {
        const el = match(event.target);

        clear();
        clearTimeout(swallowTimer);
        swallowClick = false;
        if (!el || inField(event.target)) {
            return;
        }

        const touch = event.touches?.[0];
        const x = touch?.clientX ?? 0;
        const y = touch?.clientY ?? 0;
        startX = x;
        startY = y;

        timer = setTimeout(() => {
            timer = null;
            swallowClick = true;
            recentOpen = true;
            clearTimeout(recentTimer);
            recentTimer = setTimeout(() => { recentOpen = false; }, 700);
            onOpen(el, { x, y, trigger: el });
        }, longPressMs);
    };

    const onTouchMove = (event) => {
        const touch = event.touches?.[0];

        if (touch && Math.hypot(touch.clientX - startX, touch.clientY - startY) <= SLOP) {
            return;
        }
        clear();
    };

    const onTouchEnd = () => {
        clear();
        if (swallowClick) {
            clearTimeout(swallowTimer);
            swallowTimer = setTimeout(() => { swallowClick = false; }, 400);
        }
    };

    const onClick = (event) => {
        if (swallowClick) {
            swallowClick = false;
            event.preventDefault();
            event.stopPropagation();
        }
    };

    const onKey = (event) => {
        const isMenuKey = event.key === "ContextMenu" || (event.key === "F10" && event.shiftKey);
        const el = isMenuKey ? match(event.target) : null;

        if (!el || inField(event.target)) {
            return;
        }

        event.preventDefault();
        const rect = el.getBoundingClientRect();
        onOpen(el, { x: rect.left, y: rect.bottom, trigger: el });
    };

    container.addEventListener("contextmenu", onContext);
    container.addEventListener("touchstart", onTouchStart, { passive: true });
    container.addEventListener("touchmove", onTouchMove, { passive: true });
    container.addEventListener("touchend", onTouchEnd);
    container.addEventListener("touchcancel", onTouchEnd);
    container.addEventListener("click", onClick, true);
    container.addEventListener("keydown", onKey);

    return () => {
        clear();
        clearTimeout(swallowTimer);
        clearTimeout(recentTimer);
        container.removeEventListener("contextmenu", onContext);
        container.removeEventListener("touchstart", onTouchStart);
        container.removeEventListener("touchmove", onTouchMove);
        container.removeEventListener("touchend", onTouchEnd);
        container.removeEventListener("touchcancel", onTouchEnd);
        container.removeEventListener("click", onClick, true);
        container.removeEventListener("keydown", onKey);
    };
}
