/*
|--------------------------------------------------------------------------
| Messages — menu gestures
|--------------------------------------------------------------------------
| Turns right-click, long-press and the keyboard (ContextMenu, Shift+F10)
| into one callback: onOpen(el, { x, y, trigger }). The native menu is only
| suppressed over a matching element and never inside a text field.
|--------------------------------------------------------------------------
*/

const FIELD = "input, textarea, [contenteditable]";

export function bindMenuTriggers(container, { selector, onOpen, longPressMs = 500 }) {
    let timer = null;
    let swallowClick = false;

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
        onOpen(el, { x: event.clientX, y: event.clientY, trigger: el });
    };

    const onTouchStart = (event) => {
        const el = match(event.target);

        clear();
        if (!el || inField(event.target)) {
            return;
        }

        const touch = event.touches?.[0];
        const x = touch?.clientX ?? 0;
        const y = touch?.clientY ?? 0;

        timer = setTimeout(() => {
            timer = null;
            swallowClick = true;
            onOpen(el, { x, y, trigger: el });
        }, longPressMs);
    };

    const onTouchEnd = () => clear();

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
    container.addEventListener("touchmove", onTouchEnd, { passive: true });
    container.addEventListener("touchend", onTouchEnd);
    container.addEventListener("touchcancel", onTouchEnd);
    container.addEventListener("click", onClick, true);
    container.addEventListener("keydown", onKey);

    return () => {
        clear();
        container.removeEventListener("contextmenu", onContext);
        container.removeEventListener("touchstart", onTouchStart);
        container.removeEventListener("touchmove", onTouchEnd);
        container.removeEventListener("touchend", onTouchEnd);
        container.removeEventListener("touchcancel", onTouchEnd);
        container.removeEventListener("click", onClick, true);
        container.removeEventListener("keydown", onKey);
    };
}
