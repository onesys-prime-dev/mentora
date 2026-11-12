/**
 * Phone tabs for dashboards (components/phone-tabs).
 *
 * Markup contract:
 *   [data-phone-tabs] > [role=tablist] > button[role=tab][data-phone-tab="key"]
 *   any element with data-phone-panel="key"; the active tab's panels carry data-phone-active
 *   (app.css hides the other panels below lg).
 *
 * Click, ArrowLeft/ArrowRight (wrapping), Home and End select a tab: aria-selected, roving tabindex and
 * data-phone-active on that key's panels. Each [data-phone-tabs] is bound once, so calling again is safe.
 */

const bound = new WeakSet();

function select(root, tab, focus) {
    const key = tab.dataset.phoneTab;
    const panels = [...document.querySelectorAll(`[data-phone-panel="${CSS.escape(key)}"]`)];

    // A tab nothing belongs to would blank the dashboard.
    if (panels.length === 0) {
        return;
    }

    for (const other of root.querySelectorAll("[data-phone-tab]")) {
        const on = other === tab;

        other.setAttribute("aria-selected", on ? "true" : "false");
        other.tabIndex = on ? 0 : -1;
    }

    for (const panel of document.querySelectorAll("[data-phone-panel]")) {
        panel.toggleAttribute("data-phone-active", panels.includes(panel));
    }

    if (focus) {
        tab.focus();
    }
}

/** Wires every [data-phone-tabs] under `root`. */
export function bindPhoneTabs(root = document) {
    for (const el of root.querySelectorAll("[data-phone-tabs]")) {
        if (bound.has(el)) {
            continue;
        }

        bound.add(el);

        el.addEventListener("click", (event) => {
            const tab = event.target.closest("[data-phone-tab]");

            if (tab) {
                select(el, tab, false);
            }
        });

        el.addEventListener("keydown", (event) => {
            const tabs = [...el.querySelectorAll("[data-phone-tab]")];
            const at = tabs.indexOf(event.target.closest("[data-phone-tab]"));
            const next = { ArrowRight: at + 1, ArrowLeft: at - 1, Home: 0, End: tabs.length - 1 }[event.key];

            if (at < 0 || next === undefined) {
                return;
            }

            event.preventDefault();
            select(el, tabs[(next + tabs.length) % tabs.length], true);
        });
    }
}

if (typeof document !== "undefined") {
    bindPhoneTabs(document);
    document.addEventListener("content:swapped", () => bindPhoneTabs(document));
}
