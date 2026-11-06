/*
|--------------------------------------------------------------------------
| The unread count on the notification bell
|--------------------------------------------------------------------------
| The header draws [data-notifications-badge] with the count at page load. The
| toast's 20-second check sets it from the server, and everything that marks a
| notification read or deletes one nudges it at once, on the panel and on the
| Notifications page. It hides at zero and says "99+" past 99.
|--------------------------------------------------------------------------
*/

export function setBellCount(count, doc = document) {
    const badge = doc.querySelector("[data-notifications-badge]");

    if (!badge) {
        return;
    }

    const n = Math.max(0, Math.trunc(Number(count)) || 0);

    badge.dataset.count = String(n);
    badge.textContent = n > 99 ? "99+" : String(n);
    badge.hidden = n === 0;
}

/** Moves the count by `delta` from what the badge shows now. */
export function adjustBellCount(delta, doc = document) {
    const badge = doc.querySelector("[data-notifications-badge]");

    if (badge) {
        setBellCount(Number(badge.dataset.count ?? 0) + delta, doc);
    }
}
