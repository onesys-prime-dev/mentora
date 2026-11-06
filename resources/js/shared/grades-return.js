/**
 * Grade work opened from the Grades page's To grade queue carries a
 * `return` address so its back link can lead to the queue again. Only a
 * Grades page of this site is accepted; anything else is ignored.
 */
export function safeReturn(value, origin) {
    if (typeof value !== "string" || value === "") {
        return null;
    }

    let url;

    try {
        url = new URL(value, origin);
    } catch {
        return null;
    }

    if (url.origin !== origin) {
        return null;
    }

    if (!/\/(instructor|chairperson)\/grades$/.test(url.pathname)) {
        return null;
    }

    return `${url.pathname}${url.search}${url.hash}`;
}

/** The accepted `return` of the current address, or null. */
export function currentReturn() {
    return safeReturn(
        new URLSearchParams(window.location.search).get("return"),
        window.location.origin
    );
}

export function leaveTo(url) {
    window.location.assign(url);
}
