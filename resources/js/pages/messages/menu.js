/*
|--------------------------------------------------------------------------
| Messages — context menu
|--------------------------------------------------------------------------
| One floating role="menu" element shared by the chat list and the thread.
| open(items, { x, y, trigger }) renders items from menu-items.js: buttons
| with data-action, separators, a reactions row (data-emoji buttons plus a
| "➕" that expands to the full PALETTE). Keyboard: arrows, Home/End, Enter,
| Space, Escape and Tab (both close). Closes on outside pointerdown, scroll
| and resize, and hands focus back to the trigger.
|--------------------------------------------------------------------------
*/

import { PALETTE } from "./menu-items.js";

const MARGIN = 4;

export function createMenu(doc = document) {
    const win = doc.defaultView ?? window;
    let el = null;
    let trigger = null;
    let teardown = [];

    const isOpen = () => el !== null;

    function buttons() {
        return el ? [...el.querySelectorAll("button")] : [];
    }

    function dispose() {
        teardown.forEach((fn) => fn());
        teardown = [];
        el?.remove();
        el = null;
    }

    function close() {
        if (!el) {
            return;
        }

        dispose();

        const back = trigger;
        trigger = null;

        if (back && typeof back.focus === "function") {
            back.focus();
        }
    }

    function button(label, attrs = {}) {
        const b = doc.createElement("button");
        b.type = "button";
        b.setAttribute("role", "menuitem");
        b.tabIndex = -1;
        b.textContent = label;
        Object.entries(attrs).forEach(([k, v]) => b.setAttribute(k, v));

        return b;
    }

    function reactionsRow(item) {
        const row = doc.createElement("div");
        row.setAttribute("role", "group");
        row.dataset.role = "reactions";

        const emojiButton = (emoji) => {
            const b = button(emoji, { "data-emoji": emoji, "aria-label": emoji });
            if (emoji === item.selected) {
                b.setAttribute("aria-pressed", "true");
            }
            b.addEventListener("click", () => {
                close();
                item.onPick?.(emoji);
            });

            return b;
        };

        (item.emojis ?? []).forEach((emoji) => row.appendChild(emojiButton(emoji)));

        const more = button("➕", { "data-action": "more-reactions", "aria-label": "More reactions", "aria-expanded": "false" });
        more.addEventListener("click", () => {
            if (more.getAttribute("aria-expanded") === "true") {
                return;
            }
            more.setAttribute("aria-expanded", "true");
            const shown = new Set(item.emojis ?? []);
            PALETTE.filter((e) => !shown.has(e)).forEach((e) => row.appendChild(emojiButton(e)));
            row.dataset.expanded = "true";
        });
        row.appendChild(more);

        return row;
    }

    function place(x, y) {
        const rect = el.getBoundingClientRect();
        const w = rect.width || 0;
        const h = rect.height || 0;
        const vw = win.innerWidth;
        const vh = win.innerHeight;

        let left = x;
        let top = y;

        if (left + w > vw - MARGIN) {
            left = x - w;
        }
        if (top + h > vh - MARGIN) {
            top = y - h;
        }

        left = Math.max(MARGIN, Math.min(left, vw - w - MARGIN));
        top = Math.max(MARGIN, Math.min(top, vh - h - MARGIN));

        el.style.left = `${left}px`;
        el.style.top = `${top}px`;
    }

    function onKeydown(event) {
        const list = buttons();
        const index = list.indexOf(doc.activeElement);

        switch (event.key) {
            case "Escape":
                event.preventDefault();
                close();
                break;
            case "Tab":
                close();
                break;
            case "ArrowDown":
                event.preventDefault();
                list[(index + 1) % list.length]?.focus();
                break;
            case "ArrowUp":
                event.preventDefault();
                list[(index <= 0 ? list.length : index) - 1]?.focus();
                break;
            case "Home":
                event.preventDefault();
                list[0]?.focus();
                break;
            case "End":
                event.preventDefault();
                list[list.length - 1]?.focus();
                break;
            case "Enter":
            case " ":
                if (index >= 0) {
                    event.preventDefault();
                    list[index].click();
                }
                break;
            default:
        }
    }

    function open(items, { x = 0, y = 0, trigger: from = null } = {}) {
        dispose();

        trigger = from;
        el = doc.createElement("div");
        el.setAttribute("role", "menu");
        el.dataset.role = "menu";
        el.style.position = "fixed";
        el.style.left = "0px";
        el.style.top = "0px";

        items.forEach((item) => {
            if (item.separator) {
                const hr = doc.createElement("div");
                hr.setAttribute("role", "separator");
                el.appendChild(hr);
            }

            if (item.type === "reactions") {
                el.appendChild(reactionsRow(item));

                return;
            }

            const b = button(item.label, { "data-action": item.id });
            if (item.danger) {
                b.dataset.danger = "true";
            }
            b.addEventListener("click", () => {
                close();
                item.onSelect?.();
            });
            el.appendChild(b);
        });

        doc.body.appendChild(el);
        place(x, y);

        const outside = (event) => {
            if (el && !el.contains(event.target)) {
                close();
            }
        };
        const dismiss = () => close();

        doc.addEventListener("pointerdown", outside, true);
        doc.addEventListener("scroll", dismiss, true);
        win.addEventListener("resize", dismiss);
        el.addEventListener("keydown", onKeydown);
        teardown.push(
            () => doc.removeEventListener("pointerdown", outside, true),
            () => doc.removeEventListener("scroll", dismiss, true),
            () => win.removeEventListener("resize", dismiss),
        );

        buttons()[0]?.focus();
    }

    return { open, close, isOpen };
}
