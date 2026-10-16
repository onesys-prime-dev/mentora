/*
|--------------------------------------------------------------------------
| Notifications panel — opens under the bell like the app menu.
|--------------------------------------------------------------------------
|
| The newest 7 load when the panel opens; scrolling to the bottom fetches the
| next 7, with skeleton rows in place while that batch loads. The full page
| (/notifications) is one link away.
|
*/

const BATCH_SKELETON_ROWS = 3;

const button = document.getElementById("notifications-button");
const panel = document.getElementById("notifications-panel");

/** The loading shape of one batch, drawn the way a notification row will look. */
export function notificationSkeletonRows(count = BATCH_SKELETON_ROWS) {

    return Array.from({ length: count }, () => `
        <li class="flex animate-pulse gap-3 px-5 py-3" data-notification-skeleton aria-hidden="true">
            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-gray-200"></span>
            <span class="flex min-w-0 flex-1 flex-col gap-2">
                <span class="h-3.5 w-3/4 rounded bg-gray-200"></span>
                <span class="h-3 w-full rounded bg-gray-200"></span>
                <span class="h-2.5 w-16 rounded bg-gray-200"></span>
            </span>
        </li>`).join("");

}

if (button && panel) {

    const scroller = panel.querySelector("[data-notifications-scroll]");
    const list = panel.querySelector("[data-notifications-list]");
    const empty = panel.querySelector("[data-notifications-empty]");
    const errorBox = panel.querySelector("[data-notifications-error]");

    let loaded = false;
    let loading = false;
    let hasMore = true;
    let before = null;

    const isOpen = () => !panel.classList.contains("hidden");

    const openPanel = () => {
        panel.classList.remove("hidden");
        button.setAttribute("aria-expanded", "true");

        if (!loaded) {
            loadBatch();
        }
    };

    const closePanel = () => {
        panel.classList.add("hidden");
        button.setAttribute("aria-expanded", "false");
    };

    async function loadBatch() {

        if (loading || !hasMore) {
            return;
        }

        loading = true;

        errorBox.classList.add("hidden");

        list.insertAdjacentHTML("beforeend", notificationSkeletonRows());

        try {

            const url = new URL(panel.dataset.feedUrl, window.location.origin);

            if (before !== null) {
                url.searchParams.set("before", String(before));
            }

            const response = await fetch(url, {
                headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" },
            });

            if (!response.ok) {
                throw new Error("Unable to load notifications.");
            }

            const result = await response.json();

            list.querySelectorAll("[data-notification-skeleton]").forEach((row) => row.remove());

            list.insertAdjacentHTML("beforeend", result.html);

            hasMore = Boolean(result.has_more);
            before = result.next_before ?? before;
            loaded = true;

            empty.classList.toggle("hidden", list.children.length > 0);

        } catch (error) {

            console.error(error);

            list.querySelectorAll("[data-notification-skeleton]").forEach((row) => row.remove());

            errorBox.classList.remove("hidden");

        } finally {

            loading = false;

        }

        // A short first batch can leave no scrollbar to trigger the next one.
        if (hasMore && loaded && scroller.scrollHeight <= scroller.clientHeight + 1) {
            loadBatch();
        }

    }

    button.addEventListener("click", () => {
        isOpen() ? closePanel() : openPanel();
    });

    scroller.addEventListener("scroll", () => {

        if (scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 24) {
            loadBatch();
        }

    });

    panel.querySelector("[data-notifications-retry]")?.addEventListener("click", () => loadBatch());

    // Close when clicking outside the button/panel.
    document.addEventListener("click", (event) => {
        if (!isOpen()) return;

        if (!panel.contains(event.target) && !button.contains(event.target)) {
            closePanel();
        }
    });

    // Close on Escape.
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && isOpen()) {
            closePanel();
            button.focus();
        }
    });

}
