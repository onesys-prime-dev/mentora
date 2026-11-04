/*
|--------------------------------------------------------------------------
| Messages — the unread badge on the Messages tile
|--------------------------------------------------------------------------
| The tile (components/app-tile.php) renders [data-messages-badge] with the
| count at page load. This keeps it fresh: every 30 s, slower while the tab
| is hidden, for as long as the person has the page open. It stays out of
| the Messages page, which gets the count with its own list refresh, and it
| stops for good when the server says Messages is not available to them.
| A failed request keeps the number already on screen.
|--------------------------------------------------------------------------
*/

import { createPoller, watchVisibility } from "../pages/messages/poller.js";

export function startBadgePolling(doc = document, fetchImpl = (...args) => fetch(...args)) {
    const badges = [...doc.querySelectorAll("[data-messages-badge]")];
    const url = badges[0]?.dataset.url;

    if (!url || doc.getElementById("messages-app")) {
        return () => {};
    }

    const show = (count) => badges.forEach((badge) => {
        badge.textContent = count > 99 ? "99+" : String(count);
        badge.hidden = count <= 0;
    });

    const poller = createPoller({
        visible: () => doc.visibilityState !== "hidden",
        fast: 30000,
        slow: 60000,
        task: async () => {
            const response = await fetchImpl(url, { headers: { Accept: "application/json" }, credentials: "same-origin" }).catch(() => null);

            if (response === null) {
                return "error";
            }

            if (response.status === 404) {
                return "gone";
            }

            const data = await response.json().catch(() => null);

            if (response.status !== 200 || typeof data?.unread !== "number") {
                return "error";
            }

            show(data.unread);

            return "ok";
        },
    });

    const unwatch = watchVisibility(poller, doc);

    poller.start();

    return () => {
        poller.stop();
        unwatch();
    };
}

if (typeof document !== "undefined") {
    startBadgePolling();
}
