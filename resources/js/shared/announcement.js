/**
 * The platform announcement line: a person can dismiss it, and it stays
 * dismissed for them until the text changes (a new announcement shows again).
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
 * Binds the dismiss button. Returns the banner element, or null when there is none.
 *
 * @param {ParentNode} [root]
 * @param {Storage | null} [storage]
 */
export function initAnnouncement(root = document, storage = safeStorage()) {
    const banner = root.querySelector("[data-announcement]");

    if (banner === null) {
        return null;
    }

    const key = announcementKey(banner.querySelector("[data-announcement-text]")?.textContent ?? "");

    if (isDismissed(storage, key)) {
        banner.hidden = true;

        return banner;
    }

    banner.querySelector("[data-announcement-dismiss]")?.addEventListener("click", () => {
        banner.hidden = true;
        dismiss(storage, key);
    });

    return banner;
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
