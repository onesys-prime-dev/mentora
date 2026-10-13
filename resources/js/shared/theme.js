/**
 * Light and dark appearance.
 *
 * The choice is the person's, kept in this browser (localStorage) and applied
 * as `data-theme` on <html>. A small script in the head (layouts/partials/head.php)
 * applies it before the page paints so there is no flash; this module is the
 * button and the state behind it. Light is the default: nothing changes until
 * someone asks for dark.
 *
 * Only pages whose <html> carries `data-themeable` take part (the app and the
 * administration shells); the sign-in pages stay as they are.
 */

export const STORAGE_KEY = "mentora-theme";

/** @param {Document} [doc] @returns {'light'|'dark'} */
export function currentTheme(doc = document) {
    return doc.documentElement.dataset.theme === "dark" ? "dark" : "light";
}

/**
 * Applies a theme to the page and remembers it, when it can.
 *
 * @param {'light'|'dark'} theme
 * @param {Document} [doc]
 * @param {Storage | null} [storage]
 */
export function applyTheme(theme, doc = document, storage = safeStorage()) {
    const next = theme === "dark" ? "dark" : "light";

    doc.documentElement.dataset.theme = next;

    try {
        storage?.setItem(STORAGE_KEY, next);
    } catch {
        // Private windows and blocked storage: the choice lasts for this page only.
    }

    for (const button of doc.querySelectorAll("[data-theme-toggle]")) {
        const dark = next === "dark";

        button.setAttribute("aria-pressed", dark ? "true" : "false");
        button.setAttribute("aria-label", dark ? "Dark mode is on. Switch to light mode" : "Dark mode is off. Switch to dark mode");
        button.setAttribute("title", dark ? "Switch to light mode" : "Switch to dark mode");
    }

    return next;
}

/** @param {Document} [doc] */
export function toggleTheme(doc = document) {
    return applyTheme(currentTheme(doc) === "dark" ? "light" : "dark", doc);
}

function safeStorage() {
    try {
        return typeof localStorage === "undefined" ? null : localStorage;
    } catch {
        return null;
    }
}

/** @param {Document} [doc] */
export function bindThemeToggle(doc = document) {
    const buttons = Array.from(doc.querySelectorAll("[data-theme-toggle]"));

    for (const button of buttons) {
        button.addEventListener("click", () => toggleTheme(doc));
    }

    // Bring the buttons' words in line with what the head script applied.
    if (buttons.length > 0) {
        applyTheme(currentTheme(doc), doc, null);
    }

    return buttons.length;
}

if (typeof document !== "undefined") {
    bindThemeToggle(document);
}
