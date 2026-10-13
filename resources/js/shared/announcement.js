/**
 * The announcement lines (the platform's, and the person's institution's
 * under it): a person can dismiss each, and it stays dismissed for them until
 * its text changes (a new announcement shows again).
 *
 * The line is plain HTML that works without this script; this only adds the
 * dismiss button. Dismissals live in the browser's localStorage, keyed by the
 * announcement text, and a browser that blocks storage simply shows it again.
 */

const PREFIX = "announcement-dismissed:";

/**
 * A short key for an announcement's text.
 *
 * @param {string} text
 */
export function announcementKey(text) {
    let hash = 5381;

    for (const char of text) {
        hash = ((hash << 5) + hash + char.codePointAt(0)) | 0;
    }

    return PREFIX + (hash >>> 0).toString(36);
}

/**
 * @param {Storage | null} storage
 * @param {string} key
 */
export function isDismissed(storage, key) {
    try {
        return storage?.getItem(key) === "1";
    } catch {
        return false;
    }
}

/**
 * @param {Storage | null} storage
 * @param {string} key
 */
export function dismiss(storage, key) {
    try {
        storage?.setItem(key, "1");
    } catch {
        // Storage unavailable: it will simply show again next time.
    }
}

/**
 * Binds the dismiss button of every announcement line on the page. Returns
 * the first banner element, or null when there is none.
 *
 * @param {ParentNode} [root]
 * @param {Storage | null} [storage]
 */
export function initAnnouncement(root = document, storage = safeStorage()) {
    const banners = Array.from(root.querySelectorAll("[data-announcement]"));

    for (const banner of banners) {
        const key = announcementKey(banner.querySelector("[data-announcement-text]")?.textContent ?? "");

        if (isDismissed(storage, key)) {
            banner.hidden = true;

            continue;
        }

        banner.querySelector("[data-announcement-dismiss]")?.addEventListener("click", () => {
            banner.hidden = true;
            dismiss(storage, key);
        });
    }

    return banners[0] ?? null;
}

function safeStorage() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

if (typeof document !== "undefined") {
    initAnnouncement();
}
