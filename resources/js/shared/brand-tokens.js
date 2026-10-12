/**
 * The brand colours as CSS custom properties (resources/css/app.css, @theme).
 *
 * The utilities (bg-brand, text-brand, ring-brand/50 ...) read these at
 * runtime, so changing a property on an element restyles everything inside
 * it. This is the one place JavaScript reads or sets them: a page that shows
 * a live preview of an institution's colours sets them on its preview box,
 * and a chart that needs the colour as a value reads it from here instead of
 * hard-coding a hex.
 */

/** Token name => the custom property it lives in. */
export const BRAND_PROPERTIES = Object.freeze({
    brand: "--color-brand",
    brandDark: "--color-brand-dark",
    brandTint: "--color-brand-tint",
});

/** What the colours are with no institution override (the Mentora brand). */
export const DEFAULT_BRAND = Object.freeze({
    brand: "#0040A6",
    brandDark: "#002068",
    brandTint: "#F2F7FF",
});

/**
 * The brand colours in force for an element, falling back to the default for
 * any that is not set (no stylesheet loaded, a test).
 *
 * @param {Element} [root]
 * @returns {{brand: string, brandDark: string, brandTint: string}}
 */
export function readBrandTokens(root = document.documentElement) {
    const style = getComputedStyle(root);
    const out = {};

    for (const [name, property] of Object.entries(BRAND_PROPERTIES)) {
        const value = style.getPropertyValue(property).trim();

        out[name] = value !== "" ? value : DEFAULT_BRAND[name];
    }

    return out;
}

/**
 * Sets some of the brand colours on an element (a preview box); a name that
 * is not a token is ignored, and a value that is not a hex colour is refused
 * so a typed string can never become a style.
 *
 * @param {HTMLElement} root
 * @param {Partial<{brand: string, brandDark: string, brandTint: string}>} tokens
 * @returns {string[]} the names that were applied
 */
export function applyBrandTokens(root, tokens) {
    const applied = [];

    for (const [name, value] of Object.entries(tokens)) {
        const property = BRAND_PROPERTIES[name];

        if (property === undefined || !/^#[0-9a-fA-F]{6}$/.test(String(value))) {
            continue;
        }

        root.style.setProperty(property, String(value));
        applied.push(name);
    }

    return applied;
}

/**
 * Takes an element's overrides away again.
 *
 * @param {HTMLElement} root
 */
export function clearBrandTokens(root) {
    for (const property of Object.values(BRAND_PROPERTIES)) {
        root.style.removeProperty(property);
    }
}
