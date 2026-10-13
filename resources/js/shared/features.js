/**
 * Feature flags in the browser.
 *
 * The server works out which flags are on for the signed-in person's
 * institution and prints them on the page as JSON in the body's
 * data-features attribute. This reads that, so browser code asks the same
 * question the server does and gets the same answer.
 *
 * A flag the page does not mention is off, and a missing or unreadable
 * attribute means every flag is off.
 */

/**
 * @param {HTMLElement | null} [root] the element carrying data-features (the page body by default)
 * @returns {Record<string, boolean>} flag key -> on
 */
export function enabledFeatures(root = document.body) {
    const raw = root?.dataset?.features;

    if (!raw) {
        return {};
    }

    try {
        const parsed = JSON.parse(raw);

        if (parsed === null || typeof parsed !== "object" || Array.isArray(parsed)) {
            return {};
        }

        return Object.fromEntries(Object.entries(parsed).map(([key, value]) => [key, value === true]));
    } catch {
        return {};
    }
}

/**
 * @param {string} key the flag, e.g. "sis.sync"
 * @param {HTMLElement | null} [root]
 * @returns {boolean}
 */
export function isFeatureEnabled(key, root = document.body) {
    return enabledFeatures(root)[key] === true;
}
